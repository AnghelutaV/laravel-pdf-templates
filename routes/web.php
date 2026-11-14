<?php

use AnghelutaV\PdfTemplates\Http\Controllers\ApiAdminController;
use AnghelutaV\PdfTemplates\Http\Controllers\AssetController;
use AnghelutaV\PdfTemplates\Http\Controllers\AssistantController;
use AnghelutaV\PdfTemplates\Http\Controllers\FontController;
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
        Route::post('/images/import', [ImageController::class, 'import'])->name('images.import');
        Route::get('/fonts', [FontController::class, 'index'])->name('fonts.index');
        Route::post('/fonts', [FontController::class, 'store'])->name('fonts.store');
        Route::post('/fonts/google', [FontController::class, 'google'])->name('fonts.google');
        Route::delete('/fonts', [FontController::class, 'destroy'])->name('fonts.destroy');
        Route::get('/api', [ApiAdminController::class, 'index'])->name('api.index');
        Route::post('/api/keys', [ApiAdminController::class, 'storeKey'])->name('api.keys.store');
        Route::put('/api/keys/{key}', [ApiAdminController::class, 'updateKey'])->name('api.keys.update');
        Route::delete('/api/keys/{key}', [ApiAdminController::class, 'revokeKey'])->name('api.keys.revoke');
        Route::get('/api/postman', [ApiAdminController::class, 'postman'])->name('api.postman');
        Route::get('/api/renders/{render}/again', [ApiAdminController::class, 'renderAgain'])->name('api.renders.again')
            ->middleware('throttle:'.config('pdf-templates.routes.render_throttle', '120,1'));
        Route::get('/api/renderer', [ApiAdminController::class, 'rendererStatus'])->name('api.renderer');
        Route::post('/api/renderer/start', [ApiAdminController::class, 'rendererStart'])->name('api.renderer.start');
        Route::post('/api/renderer/stop', [ApiAdminController::class, 'rendererStop'])->name('api.renderer.stop');
        Route::get('/assets/{file}', AssetController::class)->where('file', '[a-z0-9.-]+')->name('asset');
        Route::get('/{template}/edit', [TemplateController::class, 'edit'])->name('edit');
        Route::put('/{template}', [TemplateController::class, 'update'])->name('update');
        Route::delete('/{template}', [TemplateController::class, 'destroy'])->name('destroy');
        Route::post('/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('duplicate');
        Route::get('/{template}/test', [TemplateController::class, 'test'])->name('test');
        Route::get('/{template}/export', [TemplateController::class, 'export'])->name('export');
        Route::post('/{template}/versions/{version}/restore', [TemplateController::class, 'restore'])->name('versions.restore');
        Route::post('/{template}/render', [TemplateController::class, 'render'])->name('render')
            ->middleware('throttle:'.config('pdf-templates.routes.render_throttle', '120,1'));
        Route::post('/{template}/assistant', [AssistantController::class, 'edit'])->name('assistant')
            ->middleware('throttle:'.config('pdf-templates.ai.throttle', '20,1'));
    });
