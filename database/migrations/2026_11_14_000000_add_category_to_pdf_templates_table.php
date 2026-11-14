<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('pdf-templates.table', 'pdf_templates');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'category')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            // Optional free-text group ("Invoices", "Contracts"), used to filter the list
            $table->string('category', 100)->nullable()->index()->after('description');
        });
    }

    public function down(): void
    {
        $table = config('pdf-templates.table', 'pdf_templates');

        if (Schema::hasColumn($table, 'category')) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('category'));
        }
    }
};
