<?php

namespace AnghelutaV\PdfTemplates\Console;

use AnghelutaV\PdfTemplates\ApiRenders;
use Illuminate\Console\Command;

// Schedule daily: Schedule::command('pdf-templates:prune')->daily();
class PruneCommand extends Command
{
    protected $signature = 'pdf-templates:prune';

    protected $description = 'Delete expired API files (api.files_ttl) and old render log rows (api.log_days)';

    public function handle(ApiRenders $renders): int
    {
        [$files, $rows] = $renders->prune();
        $this->components->info("Deleted {$files} expired file(s) and {$rows} old log row(s).");

        return self::SUCCESS;
    }
}
