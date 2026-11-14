<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\Models\PdfTemplateApiKey;
use Illuminate\Console\Command;

class ApiKeyCommand extends Command
{
    protected $signature = 'pdf-templates:api-key
        {name? : Who the key is for (an app, a customer)}
        {--revoke= : Revoke the key with this id}
        {--list : List the keys}';

    protected $description = 'Create, list or revoke API keys for the PDF API';

    public function handle(): int
    {
        if ($this->option('list')) {
            $this->table(['ID', 'Name', 'Key', 'Last used', 'Revoked'], PdfTemplateApiKey::orderBy('id')->get()->map(fn (PdfTemplateApiKey $key) => [
                $key->id, $key->name, $key->prefix.'…', $key->last_used_at?->diffForHumans() ?? '—', $key->revoked_at?->toDateString() ?? '',
            ]));

            return self::SUCCESS;
        }

        if ($id = $this->option('revoke')) {
            $key = PdfTemplateApiKey::find($id);
            if (! $key) {
                $this->components->error("No key with id {$id}.");

                return self::FAILURE;
            }
            $key->update(['revoked_at' => now()]);
            $this->components->info("Key \"{$key->name}\" revoked.");

            return self::SUCCESS;
        }

        $name = trim((string) ($this->argument('name') ?: $this->ask('Who is the key for?')));
        if ($name === '') {
            $this->components->error('A name is required.');

            return self::FAILURE;
        }
        [, $plain] = PdfTemplateApiKey::generate($name);
        $this->components->info("API key for \"{$name}\" (shown only now, keep it somewhere safe):");
        $this->line("  {$plain}");
        $this->newLine();
        $this->line('  curl -X POST '.route(config('pdf-templates.routes.name', 'pdf-templates.').'api', 'your-template').' \\');
        $this->line("    -H \"X-API-KEY: {$plain}\" -H \"Content-Type: application/json\" --data '{}' -o document.pdf");

        return self::SUCCESS;
    }
}
