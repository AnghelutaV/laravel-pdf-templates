<?php

namespace AnghelutaV\PdfTemplates\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

// Runs the Node renderer used for server-side WYSIWYG generation (installs its npm dependencies first)
class RendererCommand extends Command
{
    protected $signature = 'pdf-templates:renderer
        {--host=127.0.0.1 : Interface to listen on}
        {--port=3311 : Port to listen on}
        {--install : Only install the npm dependencies, do not start}';

    protected $description = 'Start the pdfme renderer (Node) for server-side WYSIWYG PDF generation';

    public function handle(): int
    {
        $dir = realpath(__DIR__.'/../../renderer');

        if ($this->option('install') || ! is_dir($dir.'/node_modules')) {
            $this->components->info('Installing renderer dependencies (npm install)…');
            if (! $this->runProcess(['npm', 'install', '--omit=dev', '--no-audit', '--no-fund'], $dir)) {
                $this->components->error('npm install failed. Node.js >= 18 and npm must be on the PATH.');

                return self::FAILURE;
            }
            if ($this->option('install')) {
                return self::SUCCESS;
            }
        }

        $host = $this->option('host');
        $port = $this->option('port');
        $this->components->info("Renderer: http://{$host}:{$port} — set PDFME_RENDERER_URL to this address.");

        return $this->runProcess(['node', 'server.mjs'], $dir, [
            'HOST' => $host,
            'PORT' => $port,
            'PDFME_RENDERER_TOKEN' => (string) config('pdf-templates.pdfme.renderer_token'),
        ]) ? self::SUCCESS : self::FAILURE;
    }

    protected function runProcess(array $command, string $cwd, array $env = []): bool
    {
        // npm is npm.cmd on Windows
        if (PHP_OS_FAMILY === 'Windows' && $command[0] === 'npm') {
            $command[0] = 'npm.cmd';
        }

        $process = new Process($command, $cwd, $env ?: null, null, null);
        $process->run(fn ($type, $output) => $this->output->write($output));

        return $process->isSuccessful();
    }
}
