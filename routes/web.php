<?php

use AnghelutaV\PdfTemplates\Http\Controllers\AssetController;
use AnghelutaV\PdfTemplates\Http\Controllers\AssistantController;
use AnghelutaV\PdfTemplates\Http\Controllers\ImageController;
use AnghelutaV\PdfTemplates\Http\Controllers\TemplateController;
use AnghelutaV\PdfTemplates\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

Route::middleware([...config('pdf-templates.routes.middleware', ['web']), Authorize::class])
    ->prefix(config('pdf-templates.routes.prefix', 'pdf-templates'))
    ->name(config('pdf-templates.routes.name', 'pdf-templates.'))
    ->group(function () {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::post('/assistant', [AssistantController::class, 'create'])->name('assistant.create')
            ->middleware('throttle:'.config('pdf-templates.ai.throttle', '20,1'));
        Route::get('/images', [ImageController::class, 'index'])->name('images.index');
        Route::post('/images', [ImageController::class, 'store'])->name('images.store');
        Route::get('/images/{name}', [ImageController::class, 'show'])->where('name', '[A-Za-z0-9._-]+')->name('images.show');
        Route::delete('/images/{name}', [ImageController::class, 'destroy'])->where('name', '[A-Za-z0-9._-]+')->name('images.destroy');
        Route::get('/assets/{file}', AssetController::class)->where('file', '[a-z0-9.-]+')->name('asset');
        Route::get('/{template}/edit', [TemplateController::class, 'edit'])->name('edit');
        Route::put('/{template}', [TemplateController::class, 'update'])->name('update');
        Route::delete('/{template}', [TemplateController::class, 'destroy'])->name('destroy');
        Route::post('/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('duplicate');
        Route::get('/{template}/test', [TemplateController::class, 'test'])->name('test');
        Route::get('/{template}/export', [TemplateController::class, 'export'])->name('export');
        Route::post('/{template}/render', [TemplateController::class, 'render'])->name('render')
            ->middleware('throttle:'.config('pdf-templates.routes.render_throttle', '120,1'));
        Route::post('/{template}/assistant', [AssistantController::class, 'edit'])->name('assistant')
            ->middleware('throttle:'.config('pdf-templates.ai.throttle', '20,1'));
    });
