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
        // Lucide monitor / sun / moon
        var svg = function (paths) { return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>'; };
        var icons = {
            auto: svg('<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>'),
            light: svg('<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>'),
            dark: svg('<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>')
        };
        var labels = @json(['auto' => __('pdf-templates::ui.theme_auto'), 'light' => __('pdf-templates::ui.theme_light'), 'dark' => __('pdf-templates::ui.theme_dark')]);
        var read = function () { try { return localStorage.getItem('pt-theme') || 'auto'; } catch (e) { return 'auto'; } };
        var apply = function (theme) {
            if (theme === 'auto') delete root.dataset.ptTheme; else root.dataset.ptTheme = theme;
            document.querySelectorAll('[data-pt-theme-toggle]').forEach(function (button) {
                button.innerHTML = icons[theme];
                button.dataset.ptTip = labels[theme];
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

    // Tooltips: [data-pt-tip="text"] → dark bubble above the element (below when there is no room). Positioned in JS
    // so cards with overflow:hidden don't clip it; lives in the full screen element when there is one.
    (function () {
        var tip, target;
        var show = function (el) {
            target = el;
            if (!tip) {
                tip = document.createElement('div');
                tip.className = 'pt-tip';
                tip.setAttribute('role', 'tooltip');
            }
            tip.textContent = el.dataset.ptTip;
            tip.classList.remove('pt-tip-on');
            (document.fullscreenElement || document.body).appendChild(tip);
            var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
            var below = r.top < h + 12;
            var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
            tip.style.left = left + 'px';
            tip.style.top = (below ? r.bottom + 8 : r.top - h - 8) + 'px';
            tip.style.setProperty('--pt-tip-x', (r.left + r.width / 2 - left) + 'px');
            tip.classList.toggle('pt-tip-below', below);
            requestAnimationFrame(function () { tip.classList.add('pt-tip-on'); });
        };
        var hide = function () {
            target = null;
            if (tip) tip.remove();
        };
        var tipOf = function (node) { return node && node.closest ? node.closest('[data-pt-tip]') : null; };
        document.addEventListener('mouseover', function (e) {
            var el = tipOf(e.target);
            if (el && el !== target) show(el);
            else if (!el && target) hide();
        });
        document.addEventListener('focusin', function (e) {
            var el = tipOf(e.target);
            if (el && el.matches(':focus-visible')) show(el);
        });
        document.addEventListener('focusout', hide);
        // After a click the label may change (theme, full screen) or a dialog may open: refresh or drop the bubble
        document.addEventListener('click', function () {
            var el = target;
            if (el) setTimeout(function () { el.isConnected && el.matches(':hover') ? show(el) : hide(); }, 0);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
        document.addEventListener('scroll', hide, true);
        document.addEventListener('fullscreenchange', hide);
    })();

    // Dismissible alerts: [data-pt-dismiss] hides its .pt-alert (the page shows it again on the next error)
    document.addEventListener('click', function (e) {
        var button = e.target.closest && e.target.closest('[data-pt-dismiss]');
        if (button) button.closest('.pt-alert').classList.add('pt-hidden');
    });
</script>
<script type="importmap">
    {
        "imports": {
            "@pdfme/common": "{{ $esm('common') }}",
            "@pdfme/schemas": "{{ $esm('schemas') }}",
            "@pdfme/generator": "{{ $esm('generator') }}",
            "@pdfme/ui": "{{ $esm('ui') }}",
            "pdf-templates": "{{ $asset('helpers.js') }}",
            "pdf-templates/pdfme": "{{ $asset('pdfme.js') }}",
            "pdf-templates/ai": "{{ $asset('ai.js') }}",
            "pdf-templates/images": "{{ $asset('images.js') }}"
        }
    }
</script>
