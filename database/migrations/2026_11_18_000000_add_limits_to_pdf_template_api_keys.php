<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('pdf-templates.api.keys_table', 'pdf_template_api_keys');
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'per_minute')) {
            return;
        }

        // Limits per client key (admin UI → API): requests per minute, documents per calendar month; null = no limit
        Schema::table($table, function (Blueprint $table) {
            $table->unsignedInteger('per_minute')->nullable()->after('prefix');
            $table->unsignedInteger('per_month')->nullable()->after('per_minute');
        });
    }

    public function down(): void
    {
        $table = config('pdf-templates.api.keys_table', 'pdf_template_api_keys');
        if (Schema::hasColumn($table, 'per_minute')) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['per_minute', 'per_month']));
        }
    }
};
