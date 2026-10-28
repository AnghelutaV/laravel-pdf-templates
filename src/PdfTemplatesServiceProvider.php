<?php

namespace AnghelutaV\PdfTemplates;

use AnghelutaV\PdfTemplates\Console\ExamplesCommand;
use AnghelutaV\PdfTemplates\Console\ExportCommand;
use AnghelutaV\PdfTemplates\Console\ImportCommand;
use AnghelutaV\PdfTemplates\Console\InstallCommand;
use AnghelutaV\PdfTemplates\Console\RenderCommand;
use AnghelutaV\PdfTemplates\Console\RendererCommand;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class PdfTemplatesServiceProvider extends ServiceProvider
{
    // Package JS/CSS, served by AssetController
    public static function assetPath(string $file): string
    {
        return __DIR__.'/../resources/assets/'.basename($file);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pdf-templates.php', 'pdf-templates');

        $this->app->singleton(TemplateEngine::class, fn () => new TemplateEngine(
            (string) config('pdf-templates.html.decimal_separator', ','),
            (string) config('pdf-templates.html.thousands_separator', ' '),
        ));
        $this->app->singleton(PdfTemplates::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pdf-templates');
        // $ptRoute('edit', $template): route names follow the configurable prefix
        View::composer('pdf-templates::*', fn ($view) => $view->with(
            'ptRoute',
            fn (string $name, mixed $parameters = []) => route(config('pdf-templates.routes.name', 'pdf-templates.').$name, $parameters),
        ));
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'pdf-templates');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('pdf-templates.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
        if (config('pdf-templates.api.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/pdf-templates.php' => config_path('pdf-templates.php')], 'pdf-templates-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/pdf-templates')], 'pdf-templates-views');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/pdf-templates')], 'pdf-templates-lang');

            $this->commands([
                InstallCommand::class,
                ExamplesCommand::class,
                RendererCommand::class,
                RenderCommand::class,
                ExportCommand::class,
                ImportCommand::class,
            ]);
        }
    }
}
