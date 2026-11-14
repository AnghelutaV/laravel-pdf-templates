<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Renderers\WysiwygRenderer;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts / stops the bundled Node renderer from the admin UI (API page), as a background process that outlives the
 * request; its PID and output go to storage/. Only for a renderer on this machine (127.0.0.1 / localhost) and when
 * config pdfme.manage is on. In production a process manager (Supervisor, systemd, pm2) is the better home for it:
 * it restarts the renderer after a crash or a reboot.
 */
class RendererProcess
{
    const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    public function dir(): string
    {
        return (string) realpath(__DIR__.'/../renderer');
    }

    public function url(): string
    {
        return rtrim((string) (config('pdf-templates.pdfme.renderer_url') ?: 'http://127.0.0.1:3311'), '/');
    }

    /** @return array{0: string, 1: int} host and port the renderer listens on, from the renderer URL */
    public function address(): array
    {
        $parts = parse_url($this->url());

        return [$parts['host'] ?? '127.0.0.1', (int) ($parts['port'] ?? 3311)];
    }

    public function isLocal(): bool
    {
        return in_array(strtolower($this->address()[0]), self::LOCAL_HOSTS, true);
    }

    // Start / stop buttons: on in config and for a renderer on this machine only
    public function manageable(): bool
    {
        return (bool) config('pdf-templates.pdfme.manage', true) && $this->isLocal();
    }

    public function pidFile(): string
    {
        return storage_path('app/pdf-templates-renderer.pid');
    }

    public function logFile(): string
    {
        return storage_path('logs/pdf-templates-renderer.log');
    }

    public function node(): string
    {
        return (string) (config('pdf-templates.pdfme.node') ?: 'node');
    }

    public function pid(): ?int
    {
        $pid = File::exists($this->pidFile()) ? (int) trim(File::get($this->pidFile())) : 0;

        return $pid > 0 && $this->alive($pid) ? $pid : null;
    }

    // Everything the API page shows about the renderer
    public function status(): array
    {
        return [
            'url' => $this->url(),
            'configured' => WysiwygRenderer::configured(),
            'reachable' => $this->reachable(),
            'pid' => $this->pid(),
            'manageable' => $this->manageable(),
            'local' => $this->isLocal(),
            'installed' => is_dir($this->dir().'/node_modules/@pdfme/generator'),
            'node' => $this->nodeVersion(),
            'log' => $this->logTail(),
        ];
    }

    public function reachable(): bool
    {
        return WysiwygRenderer::reachable($this->url());
    }

    public function nodeVersion(): ?string
    {
        try {
            $process = new Process([$this->node(), '--version'], null, null, null, 5);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function logTail(int $lines = 40): string
    {
        if (! File::exists($this->logFile())) {
            return '';
        }
        $content = File::get($this->logFile());

        return implode("\n", array_slice(explode("\n", rtrim($content)), -$lines));
    }

    /**
     * Installs the npm dependencies when they are missing, starts the renderer in the background and waits until
     * it answers /health. Returns the PID.
     *
     * @throws RuntimeException with the reason (and the last log lines) when it does not come up
     */
    public function start(int $wait = 10): int
    {
        if (! $this->manageable()) {
            throw new RuntimeException(__('pdf-templates::ui.renderer_not_manageable'));
        }
        if ($this->reachable()) {
            throw new RuntimeException(__('pdf-templates::ui.renderer_already_running', ['url' => $this->url()]));
        }
        if (! $this->nodeVersion()) {
            throw new RuntimeException(__('pdf-templates::ui.renderer_no_node', ['node' => $this->node()]));
        }

        File::ensureDirectoryExists(dirname($this->logFile()));
        File::ensureDirectoryExists(dirname($this->pidFile()));
        $this->install();

        [$host, $port] = $this->address();
        $env = array_merge(getenv(), [
            'HOST' => $host === 'localhost' ? '127.0.0.1' : trim($host, '[]'),
            'PORT' => (string) $port,
            'PDFME_RENDERER_TOKEN' => (string) config('pdf-templates.pdfme.renderer_token'),
            'PDFME_DECIMAL_SEPARATOR' => (string) config('pdf-templates.html.decimal_separator', ','),
            'PDFME_THOUSANDS_SEPARATOR' => (string) config('pdf-templates.html.thousands_separator', ' '),
            'CHROME_PATH' => (string) config('pdf-templates.chrome.path'),
        ]);
        File::append($this->logFile(), '['.now()->toDateTimeString()."] starting on {$host}:{$port}\n");

        $windows = PHP_OS_FAMILY === 'Windows';
        $descriptors = [
            0 => ['file', $windows ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $this->logFile(), 'a'],
            2 => ['file', $this->logFile(), 'a'],
        ];
        // Its own console (Windows) / no hang-up signal (Unix): it keeps running when this request ends
        $command = $windows ? [$this->node(), 'server.mjs'] : ['nohup', $this->node(), 'server.mjs'];
        $process = proc_open($command, $descriptors, $pipes, $this->dir(), $env, $windows ? ['create_new_console' => true, 'bypass_shell' => true] : []);
        if (! is_resource($process)) {
            throw new RuntimeException(__('pdf-templates::ui.renderer_start_failed', ['log' => $this->logTail(10)]));
        }
        $pid = (int) proc_get_status($process)['pid'];
        File::put($this->pidFile(), (string) $pid);

        for ($i = 0; $i < $wait * 4; $i++) {
            usleep(250_000);
            if ($this->reachable()) {
                return $pid;
            }
            if (! proc_get_status($process)['running']) {
                break;
            }
        }

        throw new RuntimeException(__('pdf-templates::ui.renderer_start_failed', ['log' => $this->logTail(10)]));
    }

    // Stops the renderer started from here (the PID in storage/); true when one was stopped
    public function stop(): bool
    {
        $pid = $this->pid();
        File::delete($this->pidFile());
        if (! $pid) {
            return false;
        }

        $command = PHP_OS_FAMILY === 'Windows' ? ['taskkill', '/PID', (string) $pid, '/T', '/F'] : ['kill', (string) $pid];
        (new Process($command, null, null, null, 10))->run();
        File::append($this->logFile(), '['.now()->toDateTimeString()."] stopped (pid {$pid})\n");

        for ($i = 0; $i < 20 && $this->alive($pid); $i++) {
            usleep(100_000);
        }

        return true;
    }

    // npm install when the dependencies are missing (first start, or after a package update added one)
    protected function install(): void
    {
        $needed = ['@pdfme/generator', '@pdfme/converter', 'puppeteer-core'];
        $missing = array_filter($needed, fn ($package) => ! is_dir($this->dir()."/node_modules/{$package}"));
        if (! $missing) {
            return;
        }

        $npm = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
        $process = new Process([$npm, 'install', '--omit=dev', '--no-audit', '--no-fund'], $this->dir(), null, null, 300);
        $process->run(function (string $type, string $output): void {
            File::append($this->logFile(), $output);
        });
        if (! $process->isSuccessful()) {
            throw new RuntimeException(__('pdf-templates::ui.renderer_install_failed', ['log' => $this->logTail(10)]));
        }
    }

    protected function alive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['tasklist', '/FI', "PID eq {$pid}", '/NH', '/FO', 'CSV'], null, null, null, 10);
            $process->run();

            return str_contains($process->getOutput(), "\"{$pid}\"");
        }

        return function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir("/proc/{$pid}");
    }
}
