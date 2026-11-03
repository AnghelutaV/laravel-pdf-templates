{{--
    AI assistant panel (Copilot-like, right side), rendered by the editor component inside #pt-shell
    so it stays visible in full screen. Wired by assistant() from ai.js.
    No <form>: the HTML editor already sits in one.
--}}
@props(['mode'])
@php
    $suggestions = __('pdf-templates::ui.ai_suggestions_' . $mode);
    $model = config('pdf-templates.ai.model') ?: config('pdf-templates.ai.provider');
@endphp
<aside class="pt-ai" id="pt-ai" hidden aria-label="{{ __('pdf-templates::ui.ai_title') }}">
    <div class="pt-ai-head">
        <span class="pt-ai-logo"><x-pdf-templates::icon name="sparkles" :size="15" /></span>
        <strong>{{ __('pdf-templates::ui.ai_title') }}</strong>
        <span class="pt-strip-spacer"></span>
        <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" data-pt-ai-new
            data-pt-tip="{{ __('pdf-templates::ui.ai_new_chat') }}" aria-label="{{ __('pdf-templates::ui.ai_new_chat') }}">
            <x-pdf-templates::icon name="rotate-ccw" :size="15" />
        </button>
        <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" data-pt-ai-close
            data-pt-tip="{{ __('pdf-templates::ui.close') }}" aria-label="{{ __('pdf-templates::ui.close') }}">
            <x-pdf-templates::icon name="x" :size="15" />
        </button>
    </div>

    <div class="pt-ai-log" id="pt-ai-log" aria-live="polite">
        <div class="pt-ai-empty" id="pt-ai-empty">
            <span class="pt-ai-logo pt-ai-logo-lg"><x-pdf-templates::icon name="sparkles" :size="22" /></span>
            <p>{{ __('pdf-templates::ui.ai_intro_' . $mode) }}</p>
            <div class="pt-ai-suggestions">
                @foreach (is_array($suggestions) ? $suggestions : [] as $suggestion)
                    <button type="button" class="pt-ai-suggestion" data-pt-ai-suggest>{{ $suggestion }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="pt-ai-composer">
        <div class="pt-ai-file pt-hidden" id="pt-ai-file-chip">
            <x-pdf-templates::icon name="paperclip" :size="13" />
            <span class="pt-ellipsis"></span>
            <button type="button" aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" :size="12" /></button>
        </div>
        <textarea class="pt-ai-input" id="pt-ai-input" rows="3" placeholder="{{ __('pdf-templates::ui.ai_placeholder') }}"
            aria-label="{{ __('pdf-templates::ui.ai_placeholder') }}"></textarea>
        <div class="pt-ai-actions">
            <label class="pt-btn pt-btn-ghost pt-btn-icon" data-pt-tip="{{ __('pdf-templates::ui.ai_attach') }}">
                <x-pdf-templates::icon name="paperclip" :size="15" />
                <input type="file" id="pt-ai-file" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf" hidden>
            </label>
            <span class="pt-muted pt-ai-model pt-ellipsis">{{ $model }}</span>
            <span class="pt-strip-spacer"></span>
            <button type="button" class="pt-btn pt-btn-primary pt-btn-icon pt-ai-send" id="pt-ai-send"
                data-pt-tip="{{ __('pdf-templates::ui.ai_send') }}" aria-label="{{ __('pdf-templates::ui.ai_send') }}">
                <x-pdf-templates::icon name="send" :size="15" class="pt-ai-send-icon" />
                <x-pdf-templates::icon name="square" :size="13" class="pt-ai-stop-icon" />
            </button>
        </div>
        <p class="pt-muted pt-ai-note">{{ __('pdf-templates::ui.ai_note') }}</p>
    </div>
</aside>
