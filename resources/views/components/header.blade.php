{{-- <x-pdf-templates::header :title="..." :back="url" badge="html|wysiwyg">actions</x-pdf-templates::header> --}}
@props(['title', 'back' => null, 'badge' => null])
@php($back = $back ?? config('pdf-templates.back_url'))
<div class="pt-bar">
    @if ($back)
        <a class="pt-btn" href="{{ $back }}" title="{{ __('pdf-templates::ui.back') }}" aria-label="{{ __('pdf-templates::ui.back') }}">&larr;</a>
    @endif
    <h1>{{ $title }}
        @if ($badge)
            <span class="pt-badge pt-badge-{{ $badge }}">{{ __('pdf-templates::ui.mode_' . $badge) }}</span>
        @endif
    </h1>
    <div class="pt-actions">
        {{ $slot }}
        <button type="button" class="pt-btn pt-theme-toggle" data-pt-theme-toggle>◐</button>
    </div>
</div>

@if (session('pdf-templates.status'))
    <div class="pt-alert pt-alert-success" role="status">{{ session('pdf-templates.status') }}</div>
@endif
@if ($errors->any())
    <div class="pt-alert pt-alert-danger" role="alert">{{ implode("\n", $errors->all()) }}</div>
@endif
