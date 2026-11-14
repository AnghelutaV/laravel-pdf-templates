<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('pdf-templates.table', 'pdf_templates');
        $versions = config('pdf-templates.history.table', 'pdf_template_versions');

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'settings')) {
            Schema::table($table, function (Blueprint $table) {
                // HTML mode page setup (margins, header / footer, word break), JSON
                $table->longText('settings')->nullable()->after('orientation');
            });
        }

        if (! Schema::hasTable($versions)) {
            // Earlier versions of a template, one row per save that changed its content
            Schema::create($versions, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id')->index();
                $table->string('name');
                $table->longText('schema')->nullable();
                $table->longText('html')->nullable();
                $table->longText('css')->nullable();
                $table->string('paper', 10)->nullable();
                $table->string('orientation', 10)->nullable();
                $table->longText('settings')->nullable();
                $table->longText('sample_data')->nullable();
                $table->string('author')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pdf-templates.history.table', 'pdf_template_versions'));

        $table = config('pdf-templates.table', 'pdf_templates');
        if (Schema::hasColumn($table, 'settings')) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('settings'));
        }
    }
};
