<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('pdf-templates.table', 'pdf_templates');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // wysiwyg = pdfme designer (schema), html = HTML/CSS + {placeholders} rendered by dompdf
            $table->string('mode', 10)->default('wysiwyg');
            $table->string('description')->nullable();
            $table->longText('schema')->nullable();
            $table->longText('html')->nullable();
            $table->longText('css')->nullable();
            $table->string('paper', 10)->default('a4');
            $table->string('orientation', 10)->default('portrait');
            $table->longText('sample_data')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pdf-templates.table', 'pdf_templates'));
    }
};
