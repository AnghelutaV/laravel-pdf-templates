{{--
    Editor chrome shared by both editors: slim strip (back to list, menu, name, unsaved dot, save, full screen),
    off-canvas menu (back link, the slot's sections, settings, theme) and the "leave without saving?" dialog.
    Wired by editorChrome() from helpers.js. The page wraps it in <div class="pt pt-compact pt-focus" id="pt-shell">.
    <x-pdf-templates::editor :template="$template" :help="...">drawer sections</x-pdf-templates::editor>
--}}
@props(['template', 'help' => null])
@php
    $mode = __('pdf-templates::ui.mode_' . $template->mode);
    // Leave the package for the host app (config back_url); url(): "adminer" → https://host/adminer
    $exit = config('pdf-templates.ui.back_url') ? url(config('pdf-templates.ui.back_url')) : null;
    $exitLabel = config('pdf-templates.ui.back_label') ?? __('pdf-templates::ui.back');
@endphp
<div class="pt-strip">
    <a class="pt-btn pt-btn-ghost pt-btn-icon" href="{{ $ptRoute('index') }}" data-pt-leave
        data-pt-tip="{{ __('pdf-templates::ui.title') }}" aria-label="{{ __('pdf-templates::ui.title') }}">
        <x-pdf-templates::icon name="arrow-left" />
    </a>
    <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" id="pt-menu"
        data-pt-tip="{{ __('pdf-templates::ui.menu') }}" aria-label="{{ __('pdf-templates::ui.menu') }}">
        <x-pdf-templates::icon name="menu" />
    </button>
    <span class="pt-id">#{{ $template->id }}</span>
    <span class="pt-strip-title pt-ellipsis" id="pt-strip-title">{{ $template->name }}</span>
    <span class="pt-badge pt-badge-{{ $template->mode }}">{{ $mode }}</span>
    <span class="pt-dirty pt-hidden" id="pt-dirty">{{ __('pdf-templates::ui.unsaved') }}</span>
    <span class="pt-strip-spacer"></span>
    <button type="button" class="pt-btn pt-btn-primary pt-btn-sm" id="pt-save" disabled data-pt-tip="Ctrl+S">
        <x-pdf-templates::icon name="save" :size="14" /> {{ __('pdf-templates::ui.save') }}
    </button>
    <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" id="pt-fullscreen"
        data-pt-tip="{{ __('pdf-templates::ui.fullscreen') }}" aria-label="{{ __('pdf-templates::ui.fullscreen') }}">
        <x-pdf-templates::icon name="maximize" data-pt-fs="off" />
        <x-pdf-templates::icon name="minimize" data-pt-fs="on" class="pt-hidden" />
    </button>
</div>

<dialog class="pt-drawer" id="pt-drawer" aria-label="{{ __('pdf-templates::ui.menu') }}"
    @if ($errors->any()) data-open @endif
    @if (session('pdf-templates.status')) data-status="{{ session('pdf-templates.status') }}" @endif>
    <div class="pt-drawer-inner">
        <div class="pt-drawer-head">
            {{-- Editors go back to the template list; leaving the package (config back_url) is done from the list --}}
            <a class="pt-btn pt-btn-ghost pt-btn-sm" href="{{ $ptRoute('index') }}" data-pt-leave>
                <x-pdf-templates::icon name="arrow-left" /> {{ __('pdf-templates::ui.title') }}
            </a>
            <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" data-pt-close
                data-pt-tip="{{ __('pdf-templates::ui.close') }}" aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" /></button>
        </div>

        <div class="pt-drawer-body">
            <div class="pt-drawer-title">
                <span class="pt-id">#{{ $template->id }}</span>
                <h1 class="pt-ellipsis">{{ $template->name }}</h1>
                <span class="pt-badge pt-badge-{{ $template->mode }}">{{ $mode }}</span>
            </div>

            @if ($errors->any())
                <div class="pt-alert pt-alert-danger" role="alert">{{ implode("\n", $errors->all()) }}</div>
            @endif

            {{ $slot }}

            <section class="pt-drawer-section">
                <h3>{{ __('pdf-templates::ui.settings') }}</h3>
                @include('pdf-templates::partials.settings', ['stacked' => true, 'help' => $help])
            </section>
        </div>

        <div class="pt-drawer-foot">
            @if ($exit)
                {{-- data-pt-leave: unsaved changes → "save & leave"; otherwise data-pt-exit → "leave PDF templates?" --}}
                <a class="pt-btn pt-btn-ghost pt-btn-sm" href="{{ $exit }}" data-pt-leave data-pt-exit
                    data-pt-tip="{{ __('pdf-templates::ui.exit_title') }}">
                    <x-pdf-templates::icon name="log-out" :size="14" /> {{ $exitLabel }}
                </a>
            @else
                <span class="pt-muted">Ctrl+S — {{ __('pdf-templates::ui.save') }}</span>
            @endif
            <button type="button" class="pt-btn pt-theme-toggle" data-pt-theme-toggle></button>
        </div>
    </div>
</dialog>

<dialog class="pt-dialog pt-dialog-sm" id="pt-leave-dialog">
    <div class="pt-dialog-body">
        <h2>{{ __('pdf-templates::ui.leave_title') }}</h2>
        <p class="pt-muted" style="margin: 0;">{{ __('pdf-templates::ui.leave_text') }}</p>
    </div>
    <div class="pt-dialog-foot">
        <button type="button" class="pt-btn pt-btn-ghost" data-pt-leave-cancel>{{ __('pdf-templates::ui.cancel') }}</button>
        <button type="button" class="pt-btn pt-btn-danger" data-pt-leave-discard>{{ __('pdf-templates::ui.leave_discard') }}</button>
        <button type="button" class="pt-btn pt-btn-primary" data-pt-leave-save>
            <x-pdf-templates::icon name="save" :size="14" /> {{ __('pdf-templates::ui.leave_save') }}
        </button>
    </div>
</dialog>

@if ($exit)
    @include('pdf-templates::partials.exit-dialog')
@endif
