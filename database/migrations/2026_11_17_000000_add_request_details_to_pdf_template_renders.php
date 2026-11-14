<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('pdf-templates.api.renders_table', 'pdf_template_renders');
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'data')) {
            return;
        }

        // Log details: the JSON sent (config api.log_data, up to api.log_data_kb), the query options and the client
        Schema::table($table, function (Blueprint $table) {
            $table->longText('data')->nullable()->after('documents');
            $table->text('options')->nullable()->after('data');
            $table->string('user_agent', 255)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        $table = config('pdf-templates.api.renders_table', 'pdf_template_renders');
        if (Schema::hasColumn($table, 'data')) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['data', 'options', 'user_agent']));
        }
    }
};
