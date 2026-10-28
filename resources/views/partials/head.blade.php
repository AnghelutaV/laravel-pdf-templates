{{-- Package CSS + import map for pdfme (ES modules from the CDN, no build step). Pushed into the host's "styles" stack. --}}
@php
    $version = config('pdf-templates.pdfme.version', '6.2.8');
    $cdn = rtrim(config('pdf-templates.pdfme.cdn', 'https://esm.sh'), '/');
    // Built in PHP: in Blade "@{{ }}" is the escape for a literal "{{ }}", so "pkg@{{ $v }}" would not be echoed
    $esm = fn($package) => $cdn . '/@pdfme/' . $package . '@' . $version;
    // File mtime as cache buster: assets are cached for a day, edits must still show up
    $asset = fn($file) => $ptRoute('asset', $file) . '?v=' . @filemtime(\AnghelutaV\PdfTemplates\PdfTemplatesServiceProvider::assetPath($file));
@endphp
<link rel="stylesheet" href="{{ $asset('pdf-templates.css') }}">
<script>
    // Theme: auto (OS) | light | dark, kept in localStorage; applied before paint. Toggle: [data-pt-theme-toggle]
    (function () {
        var root = document.documentElement, order = ['auto', 'light', 'dark'];
        var icons = { auto: '◐', light: '☀', dark: '☾' };
        var labels = @json(['auto' => __('pdf-templates::ui.theme_auto'), 'light' => __('pdf-templates::ui.theme_light'), 'dark' => __('pdf-templates::ui.theme_dark')]);
        var read = function () { try { return localStorage.getItem('pt-theme') || 'auto'; } catch (e) { return 'auto'; } };
        var apply = function (theme) {
            if (theme === 'auto') delete root.dataset.ptTheme; else root.dataset.ptTheme = theme;
            document.querySelectorAll('[data-pt-theme-toggle]').forEach(function (button) {
                button.textContent = icons[theme];
                button.title = labels[theme];
                button.setAttribute('aria-label', labels[theme]);
            });
        };
        apply(read());
        document.addEventListener('DOMContentLoaded', function () { apply(read()); });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-pt-theme-toggle]')) return;
            var next = order[(order.indexOf(read()) + 1) % order.length];
            try { localStorage.setItem('pt-theme', next); } catch (err) {}
            apply(next);
        });
    })();
</script>
<script type="importmap">
    {
        "imports": {
            "@pdfme/common": "{{ $esm('common') }}",
            "@pdfme/schemas": "{{ $esm('schemas') }}",
            "@pdfme/generator": "{{ $esm('generator') }}",
            "@pdfme/ui": "{{ $esm('ui') }}",
            "pdf-templates": "{{ $asset('helpers.js') }}",
            "pdf-templates/pdfme": "{{ $asset('pdfme.js') }}"
        }
    }
</script>
