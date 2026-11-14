{{-- HTML mode: margins, header / footer and word break for the whole PDF (pdf_templates.settings, see PageSetup).
     Wired by pageSetup() from html-tools.js; fields are read by their data-pt-setup path. --}}
<section class="pt-drawer-section" id="pt-page-setup">
    <h3>{{ __('pdf-templates::ui.section_static') }}</h3>
    <div class="pt-stack">
        @if (\AnghelutaV\PdfTemplates\Renderers\ChromeRenderer::enabled())
            {{-- Config chrome.enabled: each template picks dompdf (fast, no browser) or Chrome (modern CSS) --}}
            <label class="pt-label" for="pt-engine">{{ __('pdf-templates::ui.engine') }}</label>
            <select class="pt-select" id="pt-engine" data-pt-setup="engine">
                <option value="">{{ __('pdf-templates::ui.engine_dompdf') }}</option>
                <option value="chrome">{{ __('pdf-templates::ui.engine_chrome') }}</option>
            </select>
            <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.engine_hint') }}</p>
        @endif
        <span class="pt-label">{{ __('pdf-templates::ui.margins') }}</span>
        <div class="pt-margins">
            @foreach (['top', 'right', 'bottom', 'left'] as $side)
                <label>
                    <span>{{ __('pdf-templates::ui.margin_' . $side) }}</span>
                    <input type="number" class="pt-input" min="0" max="150" step="1" data-pt-setup="margins.{{ $loop->index }}" placeholder="CSS">
                </label>
            @endforeach
        </div>
        <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.margins_html_hint') }}</p>

        @foreach (['header', 'footer'] as $band)
            <div class="pt-band pt-sub">
                <span class="pt-label">{{ __('pdf-templates::ui.band_' . $band) }} — {{ __('pdf-templates::ui.band_texts') }}</span>
                <select class="pt-select" data-pt-setup="{{ $band }}.font_size" aria-label="{{ __('pdf-templates::ui.font_size') }}"
                    data-pt-tip="{{ __('pdf-templates::ui.font_size') }}">
                    @foreach ([7, 8, 9, 10, 11, 12, 14] as $size)
                        <option value="{{ $size }}" @selected($size === 9)>{{ $size }} pt</option>
                    @endforeach
                </select>
            </div>
            <div class="pt-margins pt-band-slots">
                @foreach (['left', 'center', 'right'] as $slot)
                    <label>
                        <span>{{ __('pdf-templates::ui.slot_' . $slot) }}</span>
                        <input type="text" class="pt-input" maxlength="500" data-pt-setup="{{ $band }}.{{ $slot }}" data-pt-setup-text autocomplete="off"
                            placeholder="{{ $band === 'footer' && $slot === 'right' ? '{currentPage} / {totalPages}' : __('pdf-templates::ui.band_text') }}">
                    </label>
                @endforeach
            </div>
            <details class="pt-band-custom">
                <summary>{{ __('pdf-templates::ui.band_custom', ['band' => __('pdf-templates::ui.band_' . $band)]) }}</summary>
                <textarea class="pt-textarea pt-code" rows="5" spellcheck="false" maxlength="20000" data-pt-setup="{{ $band }}.html" data-pt-setup-text
                    placeholder='<table style="width: 100%"><tr><td>{company}</td><td style="text-align: right">{currentPage} / {totalPages}</td></tr></table>'></textarea>
                <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.band_custom_hint') }}</p>
            </details>
        @endforeach
        <p class="pt-muted pt-hint">
            {{ __('pdf-templates::ui.band_vars') }}
            @foreach (['{currentPage}', '{totalPages}', '{date}', '{dateTime}'] as $variable)
                <button type="button" class="pt-var" data-pt-setup-var="{{ $variable }}">{{ $variable }}</button>
            @endforeach
        </p>

        <div class="pt-band">
            <label class="pt-label" for="pt-word-break">{{ __('pdf-templates::ui.word_break') }}</label>
            <select class="pt-select" id="pt-word-break" data-pt-setup="word_break">
                @foreach (\AnghelutaV\PdfTemplates\PageSetup::WORD_BREAKS as $break)
                    <option value="{{ $break === 'normal' ? '' : $break }}">{{ __('pdf-templates::ui.word_break_' . str_replace('-', '_', $break)) }}</option>
                @endforeach
            </select>
        </div>
    </div>
</section>

{{-- Fonts dompdf can use: upload a .ttf or fetch a family from Google Fonts; wired by fontLibrary() --}}
<section class="pt-drawer-section" id="pt-fonts" data-url="{{ $ptRoute('fonts.index') }}" data-google="{{ $ptRoute('fonts.google') }}">
    <h3>{{ __('pdf-templates::ui.fonts') }}</h3>
    <div class="pt-stack">
        <ul class="pt-history" id="pt-fonts-list"></ul>
        <div class="pt-band">
            <input type="text" class="pt-input" id="pt-font-google" maxlength="63" placeholder="Open Sans · Lato · Roboto">
            <button type="button" class="pt-btn" id="pt-font-google-add">{{ __('pdf-templates::ui.fonts_google') }}</button>
        </div>
        <details class="pt-band-custom">
            <summary>{{ __('pdf-templates::ui.fonts_upload') }}</summary>
            <div class="pt-stack" style="margin-top: 8px;">
                <input type="text" class="pt-input" id="pt-font-family" maxlength="63" placeholder="{{ __('pdf-templates::ui.fonts_family') }}">
                <select class="pt-select pt-btn-block" id="pt-font-variant" aria-label="{{ __('pdf-templates::ui.fonts_variant') }}">
                    <option value="normal,normal">Regular</option>
                    <option value="bold,normal">Bold</option>
                    <option value="normal,italic">Italic</option>
                    <option value="bold,italic">Bold italic</option>
                </select>
                <label class="pt-btn pt-btn-block pt-file">
                    <x-pdf-templates::icon name="upload" :size="15" /> {{ __('pdf-templates::ui.fonts_file') }}
                    <input type="file" id="pt-font-file" accept=".ttf,.otf,font/ttf,font/otf">
                </label>
            </div>
        </details>
        <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.fonts_library_hint') }}</p>
    </div>
</section>
