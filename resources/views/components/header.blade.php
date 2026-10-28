@props(['title', 'back' => null, 'badge' => null])
@php($back = $back ?? config('pdf-templates.ui.back_url'))
@php($label = config('pdf-templates.ui.back_label') ?? __('pdf-templates::ui.back'))

<div class="pt-bar"
    style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; flex-wrap: nowrap;">

    <!-- Stânga: Butonul Back, Titlul și Badge-ul -->
    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; overflow: hidden;">
        @if ($back)
            <a class="pt-btn" href="{{ $back }}" title="{{ $label }}" aria-label="{{ $label }}"
                style="padding: 4px 10px; font-size: 12px; white-space: nowrap;">&larr; {{ $label }}</a>
        @endif
        <h1
            style="font-size: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin: 0; display: flex; align-items: center; gap: 8px;">
            {{ $title }}
            @if ($badge)
                <span class="pt-badge pt-badge-{{ $badge }}"
                    style="font-size: 10px; padding: 1px 6px;">{{ __('pdf-templates::ui.mode_' . $badge) }}</span>
            @endif
        </h1>
    </div>

    <!-- Dreapta: Slotul (toate butoanele tale) + Butonul de temă -->
    <div class="pt-actions" style="display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; margin-bottom: 0;">
        {{ $slot }}
        <button type="button" class="pt-btn pt-theme-toggle" data-pt-theme-toggle
            style="padding: 3px 6px; height: 28px;">◐</button>
    </div>
</div>

@if (session('pdf-templates.status'))
    <div class="pt-alert pt-alert-success" role="status">{{ session('pdf-templates.status') }}</div>
@endif
@if ($errors->any())
    <div class="pt-alert pt-alert-danger" role="alert">{{ implode("\n", $errors->all()) }}</div>
@endif
