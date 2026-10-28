<?php

use AnghelutaV\PdfTemplates\Http\Controllers\AssetController;
use AnghelutaV\PdfTemplates\Http\Controllers\TemplateController;
use AnghelutaV\PdfTemplates\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

Route::middleware([...config('pdf-templates.routes.middleware', ['web']), Authorize::class])
    ->prefix(config('pdf-templates.routes.prefix', 'pdf-templates'))
    ->name(config('pdf-templates.routes.name', 'pdf-templates.'))
    ->group(function () {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::get('/assets/{file}', AssetController::class)->where('file', '[a-z0-9.-]+')->name('asset');
        Route::get('/{template}/edit', [TemplateController::class, 'edit'])->name('edit');
        Route::put('/{template}', [TemplateController::class, 'update'])->name('update');
        Route::delete('/{template}', [TemplateController::class, 'destroy'])->name('destroy');
        Route::post('/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('duplicate');
        Route::get('/{template}/test', [TemplateController::class, 'test'])->name('test');
        Route::get('/{template}/export', [TemplateController::class, 'export'])->name('export');
        Route::post('/{template}/render', [TemplateController::class, 'render'])->name('render');
    });
