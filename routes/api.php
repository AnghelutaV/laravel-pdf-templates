<?php

use AnghelutaV\PdfTemplates\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// API key auth (X-API-KEY), stateless: no session, no CSRF
Route::middleware(config('pdf-templates.api.middleware', ['api']))
    ->post(config('pdf-templates.api.prefix', 'api/pdf-templates').'/{slug}', ApiController::class)
    ->name(config('pdf-templates.routes.name', 'pdf-templates.').'api');
