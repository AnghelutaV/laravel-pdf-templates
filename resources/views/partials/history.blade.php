{{-- Earlier versions of the template (one per save that changed its content); wired by editorChrome() in helpers.js.
     Buttons, not forms: the HTML editor is itself inside a <form>. --}}
<section class="pt-drawer-section" id="pt-history">
    <h3>{{ __('pdf-templates::ui.history') }}</h3>
    @if (($versions ?? collect())->isEmpty())
        <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.history_empty') }}</p>
    @else
        <ul class="pt-history">
            @foreach ($versions as $version)
                <li>
                    <span>
                        <strong>{{ $version->created_at?->format('d.m.Y H:i') }}</strong>
                        <span class="pt-muted">{{ $version->created_at?->diffForHumans() }}@if ($version->author) · {{ $version->author }}@endif</span>
                    </span>
                    <button type="button" class="pt-btn pt-btn-sm"
                        data-pt-restore="{{ $ptRoute('versions.restore', [$template, $version]) }}">
                        <x-pdf-templates::icon name="rotate-ccw" :size="13" /> {{ __('pdf-templates::ui.version_restore') }}
                    </button>
                </li>
            @endforeach
        </ul>
        <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.history_hint') }}</p>
    @endif
</section>
