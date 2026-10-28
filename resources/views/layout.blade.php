{{-- Standalone layout, used when config('pdf-templates.layout') is null --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pdf-templates::ui.title') }}</title>
    <style>
        body { margin: 0; background: #f4f6f8; }
        @media (prefers-color-scheme: dark) { html:not([data-pt-theme="light"]) body { background: #0f1419; } }
        html[data-pt-theme="dark"] body { background: #0f1419; }
    </style>
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
