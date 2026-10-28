{{-- Name / slug / description / active, folded into one line so the editor gets the space (the ids are read by the WYSIWYG JSON save).
     Optional: $help (HTML shown under the fields). --}}
<details class="pt-settings" @if ($errors->any()) open @endif>
    <summary>
        <strong>{{ __('pdf-templates::ui.settings') }}</strong>
        <code>{{ $template->slug }}</code>
        <span class="pt-badge {{ $template->active ? 'pt-badge-on' : '' }}">{{ $template->active ? __('pdf-templates::ui.active') : __('pdf-templates::ui.inactive') }}</span>
        @if ($template->description)
            <span class="pt-muted pt-ellipsis">{{ $template->description }}</span>
        @endif
    </summary>
    <div class="pt-settings-body">
        <div class="pt-grid">
            <div class="pt-col-4">
                <label class="pt-label" for="pt-name">{{ __('pdf-templates::ui.name') }}</label>
                <input type="text" class="pt-input" id="pt-name" name="name" value="{{ old('name', $template->name) }}" required>
            </div>
            <div class="pt-col-3">
                <label class="pt-label" for="pt-slug">{{ __('pdf-templates::ui.slug') }} <span class="pt-muted">{{ __('pdf-templates::ui.slug_hint') }}</span></label>
                <input type="text" class="pt-input" id="pt-slug" name="slug" value="{{ old('slug', $template->slug) }}" required>
            </div>
            <div class="pt-col-4">
                <label class="pt-label" for="pt-description">{{ __('pdf-templates::ui.description') }}</label>
                <input type="text" class="pt-input" id="pt-description" name="description" value="{{ old('description', $template->description) }}">
            </div>
            <div class="pt-col-1">
                <span class="pt-label">{{ __('pdf-templates::ui.active') }}</span>
                <input type="hidden" name="active" value="0">
                <label class="pt-check" style="margin-top: 8px;">
                    <input type="checkbox" id="pt-active" name="active" value="1" @checked($template->active)>
                </label>
            </div>
        </div>
        @isset($help)
            <p class="pt-muted" style="margin: 10px 0 0;">{!! $help !!}</p>
        @endisset
    </div>
</details>
