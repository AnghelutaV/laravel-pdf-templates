<?php

use AnghelutaV\PdfTemplates\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// API key auth (X-API-KEY or Bearer), stateless: no session, no CSRF. See ApiController for the endpoints.
$name = config('pdf-templates.routes.name', 'pdf-templates.');

Route::middleware(config('pdf-templates.api.middleware', ['api']))
    ->prefix(config('pdf-templates.api.prefix', 'api/pdf-templates'))
    ->group(function () use ($name) {
        Route::get('/templates', [ApiController::class, 'templates'])->name($name.'api.templates');
        Route::get('/templates/{slug}', [ApiController::class, 'template'])->name($name.'api.template');
        Route::get('/renders/{render}', [ApiController::class, 'status'])->name($name.'api.status');
        // Before /{slug}: a template called "batch" is not reachable through the API
        Route::post('/batch', [ApiController::class, 'batch'])->name($name.'api.batch');
        Route::post('/{slug}', [ApiController::class, 'render'])->name($name.'api');
    });

// Download links of output=url / async renders: signed and expiring, no key (they are handed to browsers, mails…)
Route::middleware(['signed'])
    ->prefix(config('pdf-templates.api.prefix', 'api/pdf-templates'))
    ->get('/files/{render}', [ApiController::class, 'file'])
    ->name($name.'api.file');
