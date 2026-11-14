<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $keys = config('pdf-templates.api.keys_table', 'pdf_template_api_keys');
        $renders = config('pdf-templates.api.renders_table', 'pdf_template_renders');

        if (! Schema::hasTable($keys)) {
            // API keys per client (one per app / customer); only a hash of the key is kept
            Schema::create($keys, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('key_hash', 64)->unique();
                // First characters of the key, to recognise it in the list
                $table->string('prefix', 16);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($renders)) {
            // One row per API render: who, which template, how long, the stored file of url / async renders.
            // The data itself is not kept.
            Schema::create($renders, function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('template_id')->nullable()->index();
                $table->string('template_slug');
                $table->unsignedBigInteger('api_key_id')->nullable()->index();
                $table->string('status', 16)->default('queued');
                $table->string('output', 16)->default('pdf');
                $table->string('format', 8)->default('pdf');
                $table->unsignedInteger('documents')->default(1);
                $table->unsignedInteger('bytes')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('error')->nullable();
                $table->string('disk')->nullable();
                $table->string('path')->nullable();
                $table->string('filename')->nullable();
                $table->string('webhook', 2048)->nullable();
                $table->string('webhook_status', 32)->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pdf-templates.api.renders_table', 'pdf_template_renders'));
        Schema::dropIfExists(config('pdf-templates.api.keys_table', 'pdf_template_api_keys'));
    }
};
