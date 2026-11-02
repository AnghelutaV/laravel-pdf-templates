{{-- Standalone layout, used when config('pdf-templates.layout') is null --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pdf-templates::ui.title') }}</title>
    <style>body { margin: 0; min-height: 100vh; }</style>
    @stack('styles')
</head>
<body class="pt-body">
    @yield('content')
    @stack('scripts')
</body>
</html>
