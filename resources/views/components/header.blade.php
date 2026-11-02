{{-- <x-pdf-templates::header :title="..." :back="url" badge="html|wysiwyg" subtitle="..." :count="n">actions</x-pdf-templates::header> --}}
@props(['title', 'back' => null, 'badge' => null, 'subtitle' => null, 'count' => null])
@php
    // No :back given → the link leaves the package (config back_url): confirm first
    $exits = $back === null;
    // url(): "admin" or "/admin" → https://host/admin (not relative to the current page); full URLs stay as they are
    $back = ($back = $back ?? config('pdf-templates.ui.back_url')) ? url($back) : null;
    $label = config('pdf-templates.ui.back_label') ?? __('pdf-templates::ui.back');
@endphp
<header class="pt-bar">
    <div class="pt-bar-title">
        @if ($back)
            <a class="pt-btn pt-btn-ghost pt-btn-sm" href="{{ $back }}"
                @if ($exits) data-pt-exit @endif>
                <x-pdf-templates::icon name="arrow-left" />
                <span>{{ $label }}</span>
            </a>
            <span class="pt-bar-sep" aria-hidden="true"></span>
        @endif
        <div class="pt-bar-text">
            <h1>
                <span>{{ $title }}</span>
                @if ($count !== null)
                    <span class="pt-badge">{{ $count }}</span>
                @endif
                @if ($badge)
                    <span class="pt-badge pt-badge-{{ $badge }}">{{ __('pdf-templates::ui.mode_' . $badge) }}</span>
                @endif
            </h1>
            @if ($subtitle)
                <p class="pt-bar-sub">{{ $subtitle }}</p>
            @endif
        </div>
    </div>
    <div class="pt-actions">
        {{ $slot }}
        <button type="button" class="pt-btn pt-theme-toggle" data-pt-theme-toggle></button>
    </div>
</header>

@if ($back && $exits)
    @include('pdf-templates::partials.exit-dialog')
@endif

@if (session('pdf-templates.status'))
    <div class="pt-alert pt-alert-success pt-alert-dismissible" role="status">
        <span>{{ session('pdf-templates.status') }}</span>
        <button type="button" class="pt-alert-close" data-pt-dismiss aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" :size="14" /></button>
    </div>
@endif
@if ($errors->any())
    <div class="pt-alert pt-alert-danger pt-alert-dismissible" role="alert">
        <span>{{ implode("\n", $errors->all()) }}</span>
        <button type="button" class="pt-alert-close" data-pt-dismiss aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" :size="14" /></button>
    </div>
@endif
