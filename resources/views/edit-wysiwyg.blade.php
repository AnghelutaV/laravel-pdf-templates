@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php($portrait = __('pdf-templates::ui.portrait'))
    @php($landscape = __('pdf-templates::ui.landscape'))
    {{-- Editor first: a slim strip + the designer; everything else lives in the off-canvas menu --}}
    <div class="pt pt-compact pt-focus" id="pt-shell" data-csrf="{{ csrf_token() }}" data-pt-images="{{ $ptRoute('images.index') }}">
        <x-pdf-templates::editor :template="$template" :help="__('pdf-templates::ui.wysiwyg_help')" :versions="$versions ?? null">
            <section class="pt-drawer-section" id="pt-section-check">
                <h3>{{ __('pdf-templates::ui.section_check') }}</h3>
                <div class="pt-stack">
                    <button type="button" class="pt-btn pt-btn-block" id="pt-preview">
                        <x-pdf-templates::icon name="eye" :size="15" /> {{ __('pdf-templates::ui.preview') }}
                    </button>
                    <a class="pt-btn pt-btn-block" href="{{ $ptRoute('test', $template) }}" data-pt-leave>
                        <x-pdf-templates::icon name="flask" :size="15" /> {{ __('pdf-templates::ui.test') }}
                    </a>
                    <button type="button" class="pt-btn pt-btn-block" id="pt-json">
                        <x-pdf-templates::icon name="braces" :size="15" /> {{ __('pdf-templates::ui.json_title') }}
                    </button>
                </div>
            </section>

            <section class="pt-drawer-section" id="pt-section-page">
                <h3>{{ __('pdf-templates::ui.section_page') }}</h3>
                <div class="pt-stack">
                    <select class="pt-select pt-btn-block" id="pt-page-size" aria-label="{{ __('pdf-templates::ui.page_title') }}">
                        <option value="">{{ __('pdf-templates::ui.page_title') }}</option>
                        <option value="210x297">A4 · {{ $portrait }}</option>
                        <option value="297x210">A4 · {{ $landscape }}</option>
                        <option value="148x210">A5 · {{ $portrait }}</option>
                        <option value="210x148">A5 · {{ $landscape }}</option>
                        <option value="216x279">Letter</option>
                    </select>
                    <label class="pt-btn pt-btn-block pt-file" data-pt-tip="{{ __('pdf-templates::ui.background_pdf_title') }}">
                        <x-pdf-templates::icon name="layers" :size="15" /> {{ __('pdf-templates::ui.background_pdf') }}
                        <input type="file" id="pt-base-pdf" accept="application/pdf">
                    </label>
                    <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.background_pdf_title') }}</p>
                    <button type="button" class="pt-btn pt-btn-block" id="pt-add-page">
                        <x-pdf-templates::icon name="plus" :size="15" /> {{ __('pdf-templates::ui.add_page') }}
                    </button>
                    <p class="pt-muted pt-hint">{!! __('pdf-templates::ui.pages_bar') !!}</p>
                </div>
            </section>

            {{-- Page margins + header / footer for the whole PDF (pdfme padding and staticSchema): blank pages only --}}
            <section class="pt-drawer-section" id="pt-page-setup">
                <h3>{{ __('pdf-templates::ui.section_static') }}</h3>
                <div class="pt-stack">
                    <span class="pt-label">{{ __('pdf-templates::ui.margins') }}</span>
                    <div class="pt-margins">
                        @foreach (['top', 'right', 'bottom', 'left'] as $side)
                            <label>
                                <span>{{ __('pdf-templates::ui.margin_' . $side) }}</span>
                                <input type="number" class="pt-input" min="0" max="150" step="1" data-pt-margin="{{ $loop->index }}">
                            </label>
                        @endforeach
                    </div>

                    {{-- Standard header / footer: left, center and right text, repeated on every page --}}
                    @foreach (['header', 'footer'] as $band)
                        <div class="pt-band pt-sub">
                            <span class="pt-label">{{ __('pdf-templates::ui.band_' . $band) }} — {{ __('pdf-templates::ui.band_texts') }}</span>
                            <select class="pt-select" data-pt-band="{{ $band }}" data-pt-slot="fontSize" aria-label="{{ __('pdf-templates::ui.font_size') }}"
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
                                    <input type="text" class="pt-input" data-pt-band="{{ $band }}" data-pt-slot="{{ $slot }}" autocomplete="off"
                                        placeholder="{{ $band === 'footer' && $slot === 'right' ? '{currentPage} / {totalPages}' : __('pdf-templates::ui.band_text') }}">
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                    <p class="pt-muted pt-hint">
                        {{ __('pdf-templates::ui.band_vars') }}
                        @foreach (['{currentPage}', '{totalPages}', '{date}', '{dateTime}'] as $variable)
                            <button type="button" class="pt-var" data-pt-var="{{ $variable }}">{{ $variable }}</button>
                        @endforeach
                    </p>

                    <button type="button" class="pt-btn pt-btn-block" id="pt-static">
                        <x-pdf-templates::icon name="layout" :size="15" /> {{ __('pdf-templates::ui.static_edit') }}
                    </button>
                    <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.static_hint') }}</p>
                </div>
            </section>

            @include('pdf-templates::partials.images')

            <section class="pt-drawer-section" id="pt-section-file">
                <h3>{{ __('pdf-templates::ui.section_file') }}</h3>
                <div class="pt-stack pt-stack-2">
                    <label class="pt-btn pt-file" data-pt-tip="{{ __('pdf-templates::ui.import_title') }}">
                        <x-pdf-templates::icon name="upload" :size="15" /> {{ __('pdf-templates::ui.import') }}
                        <input type="file" id="pt-import" accept="application/json,.json">
                    </label>
                    <a class="pt-btn" href="{{ $ptRoute('export', $template) }}">
                        <x-pdf-templates::icon name="download" :size="15" /> {{ __('pdf-templates::ui.export') }}
                    </a>
                </div>
            </section>
        </x-pdf-templates::editor>

        {{-- Only while the header / footer layer is on screen instead of the pages (menu → custom header & footer) --}}
        <div class="pt-layer-bar pt-layer-on pt-hidden" id="pt-static-bar">
            <x-pdf-templates::icon name="layout" :size="15" />
            <span class="pt-layer-hint">
                {!! __('pdf-templates::ui.static_bar') !!}
                <strong class="pt-hidden" id="pt-static-margins">{{ __('pdf-templates::ui.static_no_margins') }}</strong>
            </span>
            <button type="button" class="pt-btn pt-btn-sm" id="pt-static-texts">
                <x-pdf-templates::icon name="menu" :size="14" /> {{ __('pdf-templates::ui.static_texts') }}
            </button>
            <button type="button" class="pt-btn pt-btn-primary pt-btn-sm" id="pt-static-done">{{ __('pdf-templates::ui.static_done') }}</button>
        </div>

        <div class="pt-designer" id="pt-designer">
            <div class="pt-designer-loading">{{ __('pdf-templates::ui.loading_designer') }}</div>
        </div>

        {{-- Test data and the raw pdfme template as JSON, to try things out; nothing is stored until Save --}}
        <dialog class="pt-dialog" id="pt-json-dialog">
            <div class="pt-tabs" id="pt-json-tabs" role="tablist">
                <button type="button" class="pt-tab" role="tab" data-tab="pt-json-data"><x-pdf-templates::icon name="braces" :size="14" /> {{ __('pdf-templates::ui.tab_data') }}</button>
                <button type="button" class="pt-tab" role="tab" data-tab="pt-json-template"><x-pdf-templates::icon name="code" :size="14" /> {{ __('pdf-templates::ui.json_template') }}</button>
                <div class="pt-tabs-tools">
                    <button type="button" class="pt-btn pt-btn-sm" id="pt-json-format">{{ __('pdf-templates::ui.format') }}</button>
                    <button type="button" class="pt-btn pt-btn-sm" id="pt-json-apply">{{ __('pdf-templates::ui.json_apply') }}</button>
                    <button type="button" class="pt-btn pt-btn-primary pt-btn-sm" id="pt-json-preview">
                        <x-pdf-templates::icon name="eye" :size="14" /> {{ __('pdf-templates::ui.json_apply_preview') }}
                    </button>
                    <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" onclick="this.closest('dialog').close()"
                        data-pt-tip="{{ __('pdf-templates::ui.close') }}" aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" /></button>
                </div>
            </div>
            <div class="pt-json-body">
                <div id="pt-json-data">
                    <textarea class="pt-textarea pt-code" id="pt-json-sample" spellcheck="false" aria-label="{{ __('pdf-templates::ui.tab_data') }}"></textarea>
                </div>
                <div id="pt-json-template">
                    <textarea class="pt-textarea pt-code" id="pt-json-schema" spellcheck="false" aria-label="{{ __('pdf-templates::ui.json_template') }}"></textarea>
                </div>
                <p class="pt-muted pt-hint">{{ __('pdf-templates::ui.json_hint') }}</p>
            </div>
        </dialog>

        <dialog class="pt-dialog" id="pt-preview-dialog">
            <div class="pt-card-head">
                <h2>{{ __('pdf-templates::ui.preview_title') }}</h2>
                <button type="button" class="pt-btn pt-btn-ghost pt-btn-icon" onclick="this.closest('dialog').close()"
                    data-pt-tip="{{ __('pdf-templates::ui.close') }}" aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" /></button>
            </div>
            <iframe class="pt-frame" id="pt-preview-frame" title="{{ __('pdf-templates::ui.preview') }}"></iframe>
        </dialog>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { Designer, plugins, generatePdf, useBarcodeTool } from 'pdf-templates/pdfme';
        import {
            sampleFromTemplate,
            resizeTemplate,
            readFileAsDataUrl,
            notify,
            saveTemplate,
            showPdf,
            fillViewport,
            editorChrome,
            resolveImages,
            unresolveImages,
            staticLayer,
            isBlankPage,
            fieldListDelete,
            fieldImageSource,
            readStandard,
            writeStandard,
            tabs,
            codeTextarea
        } from 'pdf-templates';
        import { imageLibrary, imageSize } from 'pdf-templates/images';
        import { designerMenu, fieldDataBox, logicalNames } from 'pdf-templates/designer-tools';

        const fitDesigner = fillViewport(document.getElementById('pt-designer'), { gap: 0 });

        const t = @json(__('pdf-templates::ui'));
        const updateUrl = @json($ptRoute('update', $template));
        // Test data: saved with the template, can be changed by the AI assistant
        let sampleRaw = @json($template->sample_data);
        // Library images are stored as pdf-asset:… references; the designer needs the images themselves
        // Older code128 / ean13… fields become fields of the one Barcode tool
        const template = useBarcodeTool(await resolveImages(JSON.parse(@json($template->schema ?: \AnghelutaV\PdfTemplates\Models\PdfTemplate::BLANK_SCHEMA))));
        // The template as it is saved (and sent to the AI): library images back to references
        const storedTemplate = () => unresolveImages(layer.get());
        const fields = ['pt-name', 'pt-slug', 'pt-description', 'pt-active', 'pt-category'].map((id) => document.getElementById(id));

        const container = document.getElementById('pt-designer');
        container.innerHTML = '';
        const designer = new Designer({
            domContainer: container,
            template,
            plugins,
            options: {
                lang: @json($designerLang),
                zoomLevel: 1,
                // Wider than the default 45px: when the tool list scrolls, the scrollbar must not cover the icons
                leftSidebarWidth: 58,
                sidebarOpen: true
            },
        });

        // Templates put in later (JSON dialog, AI assistant, history) may have old barcode fields too
        const updateTemplate = designer.updateTemplate.bind(designer);
        designer.updateTemplate = (next) => updateTemplate(useBarcodeTool(next));

        // Header / footer layer: the whole template (pages + header / footer) is read and written through it
        const layer = staticLayer(designer);

        async function save() {
            const [name, slug, description, active, category] = fields;
            try {
                const res = await saveTemplate(updateUrl, {
                    schema: JSON.stringify(storedTemplate()),
                    name: name.value,
                    slug: slug.value,
                    description: description.value,
                    category: category.value,
                    active: active.checked ? 1 : 0,
                    sample_data: sampleRaw,
                });
                chrome.setDirty(false);
                notify(t.saved_at.replace(':time', res.updated_at));
                return true;
            } catch (e) {
                notify(t.save_error.replace(':error', e.message), 'danger');
                return false;
            }
        }

        const chrome = editorChrome({ save, t });
        // A new field is named after what it is (chart_1, barcode_2…), not field24: its data key in the API
        const knownFields = new Set();
        logicalNames(designer.getTemplate(), knownFields);
        designer.onChangeTemplate(() => {
            chrome.setDirty(true);
            const renamed = logicalNames(designer.getTemplate(), knownFields);
            if (renamed) setTimeout(() => designer.updateTemplate(renamed));
        });
        designer.onSaveTemplate(() => document.getElementById('pt-save').click());
        fields.forEach((el) => el.addEventListener('input', () => chrome.setDirty(true)));

        // Delete a field straight from the Field List (trash button on hover)
        fieldListDelete(designer, container, { label: t.delete_field, onDelete: () => chrome.setDirty(true) });

        // Source of the selected image in the "Edit Field" panel; "Replace…" opens the image library, where the next
        // click puts the chosen image into that field (see imageLibrary below)
        let replaceField = null;
        const replaceImage = (name) => {
            document.getElementById('pt-menu').click();
            replaceField = name;
            const section = document.getElementById('pt-images');
            if (section.classList.contains('pt-collapsed')) section.querySelector('h3').click();
            section.scrollIntoView({ block: 'start' });
            notify(t.field_source_pick.replace(':name', name));
        };
        fieldImageSource(designer, container, { t, onReplace: replaceImage });
        document.getElementById('pt-drawer').addEventListener('close', () => replaceField = null);

        // Right click on a field: duplicate, order, delete, replace image, "Show only if…", table columns from data.
        // The Edit Field panel shows the same condition / columns with an Edit button.
        designerMenu({ designer, container, t, onChange: () => chrome.setDirty(true), onReplaceImage: replaceImage });
        fieldDataBox(designer, container, { t, onChange: () => chrome.setDirty(true), sample: () => sampleRaw });

        const replaceTemplate = (next) => {
            layer.set(next);
            chrome.setDirty(true);
            chrome.closeDrawer();
            syncLayer();
        };

        // Header / footer: the designer shows either the pages or the layer repeated on every page
        const staticBar = document.getElementById('pt-static-bar');
        const margins = [...document.querySelectorAll('[data-pt-margin]')];

        // Standard header / footer inputs (left, center, right + font size per band)
        const bandInputs = [...document.querySelectorAll('[data-pt-band]')];

        function syncLayer() {
            const whole = layer.get();
            const blank = isBlankPage(whole.basePdf);
            const standard = readStandard(whole);
            staticBar.classList.toggle('pt-hidden', !layer.editing());
            // No top / bottom margin: whatever is drawn in the header / footer would sit on the page content
            const [top = 0, , bottom = 0] = (blank && whole.basePdf.padding) || [];
            document.getElementById('pt-static-margins').classList.toggle('pt-hidden', !blank || (top >= 10 && bottom >= 10));
            // Header / footer, margins and automatic page flow need a blank page (not a background PDF)
            document.getElementById('pt-static').disabled = !blank;
            margins.forEach((input, i) => {
                input.disabled = !blank;
                input.value = blank ? (whole.basePdf.padding?.[i] ?? 0) : '';
            });
            bandInputs.forEach((input) => {
                input.disabled = !blank;
                // Not the field being typed in
                if (input !== document.activeElement) input.value = standard[input.dataset.ptBand][input.dataset.ptSlot];
            });
            fitDesigner();
        }

        // Margins and the standard header / footer are written together: the texts sit in the middle of the margins
        const applyPageSetup = () => {
            const current = layer.get();
            if (!isBlankPage(current.basePdf)) return;

            const standard = readStandard(current);
            bandInputs.forEach((input) => standard[input.dataset.ptBand][input.dataset.ptSlot] = input.value);
            layer.set(writeStandard({
                ...current,
                basePdf: { ...current.basePdf, padding: margins.map((el) => Math.max(0, Number(el.value) || 0)) }
            }, standard));
            chrome.setDirty(true);
        };
        margins.forEach((input) => input.addEventListener('change', applyPageSetup));
        bandInputs.forEach((input) => input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', applyPageSetup));

        // {currentPage} … chips: inserted at the caret of the header / footer text used last
        let lastBandInput = bandInputs.find((input) => input.dataset.ptBand === 'footer' && input.dataset.ptSlot === 'right');
        bandInputs.filter((input) => input.tagName === 'INPUT').forEach((input) => input.addEventListener('focus', () => lastBandInput = input));
        document.querySelectorAll('[data-pt-var]').forEach((chip) => chip.addEventListener('click', () => {
            const input = lastBandInput;
            if (input.disabled) return;
            const start = input.selectionStart ?? input.value.length;
            input.setRangeText(chip.dataset.ptVar, start, input.selectionEnd ?? start, 'end');
            input.focus();
            applyPageSetup();
        }));

        const editLayer = (on) => {
            // Switching the view is not a change of the template
            const dirty = chrome.isDirty();
            if (!layer.edit(on)) notify(t.static_needs_blank, 'danger');
            chrome.setDirty(dirty);
            chrome.closeDrawer();
            syncLayer();
        };
        document.getElementById('pt-static').addEventListener('click', () => editLayer(!layer.editing()));
        document.getElementById('pt-static-done').addEventListener('click', () => editLayer(false));
        // Header / footer texts and margins live in the menu: open it there
        document.getElementById('pt-static-texts').addEventListener('click', () => {
            document.getElementById('pt-menu').click();
            const section = document.getElementById('pt-page-setup');
            if (section.classList.contains('pt-collapsed')) section.querySelector('h3').click();
            section.scrollIntoView({ block: 'start' });
            section.querySelector('[data-pt-margin]')?.focus();
        });
        document.getElementById('pt-menu').addEventListener('click', syncLayer);
        syncLayer();

        // A new empty page at the end (fixed layout; tables, lists and expanding text add their own pages)
        document.getElementById('pt-add-page').addEventListener('click', () => {
            const current = layer.get();
            if (layer.editing()) editLayer(false);
            layer.set({ ...current, schemas: [...current.schemas, []] });
            chrome.setDirty(true);
            chrome.closeDrawer();
            notify(t.page_added.replace(':n', current.schemas.length + 1));
        });

        document.getElementById('pt-page-size').addEventListener('change', (e) => {
            const value = e.target.value;
            e.target.value = '';
            if (!value || !confirm(t.page_confirm)) return;

            const [width, height] = value.split('x').map(Number);
            replaceTemplate(resizeTemplate(layer.get(), width, height));
        });

        document.getElementById('pt-base-pdf').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            // A background PDF has no header / footer layer: it goes away with the blank page
            replaceTemplate({
                ...layer.get(),
                basePdf: await readFileAsDataUrl(file)
            });
            e.target.value = '';
        });

        document.getElementById('pt-import').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            try {
                const imported = JSON.parse(await file.text());
                if (!imported.basePdf || !Array.isArray(imported.schemas)) throw new Error(
                    'basePdf / schemas missing');
                replaceTemplate(await resolveImages(imported));
            } catch (err) {
                notify(t.invalid_file.replace(':error', err.message), 'danger');
            }
            e.target.value = '';
        });

        document.getElementById('pt-preview').addEventListener('click', async () => {
            chrome.closeDrawer();
            const current = layer.get();
            let data;
            try {
                data = sampleRaw ? JSON.parse(sampleRaw) : {};
            } catch {
                data = {};
            }
            if (!Array.isArray(data) && !Object.keys(data).length) data = sampleFromTemplate(current);

            try {
                showPdf(document.getElementById('pt-preview-frame'), await generatePdf(current, data));
                document.getElementById('pt-preview-dialog').showModal();
            } catch (err) {
                notify(t.render_error.replace(':error', err.message), 'danger');
            }
        });

        // JSON dialog: the test data and the raw pdfme template, editable by hand. "Apply" puts them in the editor
        // as unsaved changes (like the AI assistant does); the template is only replaced when its text was changed.
        const jsonDialog = document.getElementById('pt-json-dialog');
        const jsonSample = document.getElementById('pt-json-sample');
        const jsonSchema = document.getElementById('pt-json-schema');
        const pretty = (value) => JSON.stringify(value, null, 2);
        let jsonOpened = { sample: '', schema: '' };
        tabs(document.getElementById('pt-json-tabs'));
        [jsonSample, jsonSchema].forEach(codeTextarea);

        document.getElementById('pt-json').addEventListener('click', () => {
            let data = null;
            try {
                data = sampleRaw ? JSON.parse(sampleRaw) : null;
            } catch {
                // keep the raw text below
            }
            const empty = data === null || (!Array.isArray(data) && typeof data === 'object' && !Object.keys(data).length);
            jsonSample.value = data === null && sampleRaw ? sampleRaw : pretty(empty ? sampleFromTemplate(layer.get()) : data);
            jsonSchema.value = pretty(storedTemplate());
            jsonOpened = { sample: jsonSample.value, schema: jsonSchema.value };
            jsonDialog.showModal();
        });

        const parseJson = (textarea) => {
            try {
                return JSON.parse(textarea.value);
            } catch (err) {
                notify(t.invalid_json.replace(':error', err.message), 'danger');
                textarea.focus();
                return undefined;
            }
        };

        async function applyJson() {
            const data = parseJson(jsonSample);
            if (data === undefined) return false;

            if (jsonSchema.value !== jsonOpened.schema) {
                const next = parseJson(jsonSchema);
                if (next === undefined) return false;
                if (!next.basePdf || !Array.isArray(next.schemas)) {
                    notify(t.invalid_file.replace(':error', 'basePdf / schemas missing'), 'danger');
                    return false;
                }
                layer.set(await resolveImages(next));
                syncLayer();
                chrome.setDirty(true);
            }
            if (jsonSample.value !== jsonOpened.sample) {
                sampleRaw = JSON.stringify(data);
                chrome.setDirty(true);
            }
            jsonOpened = { sample: jsonSample.value, schema: jsonSchema.value };

            return true;
        }

        document.getElementById('pt-json-format').addEventListener('click', () => {
            const textarea = jsonSample.closest('.pt-hidden') ? jsonSchema : jsonSample;
            const value = parseJson(textarea);
            if (value !== undefined) textarea.value = pretty(value);
        });
        document.getElementById('pt-json-apply').addEventListener('click', async () => {
            if (await applyJson()) jsonDialog.close();
        });
        document.getElementById('pt-json-preview').addEventListener('click', async () => {
            if (!await applyJson()) return;
            jsonDialog.close();
            document.getElementById('pt-preview').click();
        });

        // Image library: a click places the image on the first page, or in the header / footer while that layer is
        // on screen (fixed image, 40 mm wide, aspect kept); it is saved as a pdf-asset:… reference.
        // SVG goes into pdfme's SVG field as markup.
        imageLibrary({
            t,
            async insert(image) {
                // What the designer shows (pages or header / footer); names are unique in the whole template
                const current = designer.getTemplate();
                const whole = layer.get();
                const page = current.basePdf?.padding ? current.basePdf : { padding: [10, 10, 10, 10] };
                const names = new Set([...whole.schemas.flat(), ...(whole.basePdf?.staticSchema ?? [])].map((field) => field.name));
                const base = image.name.replace(/\.[^.]+$/, '').replace(/[^A-Za-z0-9_]+/g, '_') || 'image';
                let fieldName = base;
                for (let i = 2; names.has(fieldName); i++) fieldName = `${base}_${i}`;

                const svg = /\.svg$/i.test(image.name);
                const content = svg ? await (await fetch(image.url)).text() : (await resolveImages({ c: image.ref })).c;
                const { width, height } = await imageSize(image.url);
                // "Replace…" on a field: same place and width, the height follows the new image
                const target = replaceField && current.schemas.flat().find((field) => field.name === replaceField);
                if (target) {
                    target.type = svg ? 'svg' : 'image';
                    target.content = content;
                    target.height = Math.round(target.width * height / width * 10) / 10;
                    designer.updateTemplate(current);
                    chrome.setDirty(true);
                    chrome.closeDrawer();
                    notify(t.field_source_replaced.replace(':name', target.name));
                    return;
                }
                const w = 40;
                current.schemas[0].push({
                    name: fieldName,
                    type: svg ? 'svg' : 'image',
                    readOnly: true,
                    content,
                    position: { x: page.padding[3] ?? 10, y: page.padding[0] ?? 10 },
                    width: w,
                    height: Math.round(w * height / width * 10) / 10,
                });
                designer.updateTemplate(current);
                chrome.setDirty(true);
                chrome.closeDrawer();
                notify(t.image_inserted.replace(':name', image.name));
            },
        });

        @if ($aiEnabled)
            // AI assistant: the new pdfme template goes into the designer as unsaved changes
            const { assistant } = await import('pdf-templates/ai');
            const name = document.getElementById('pt-name');
            assistant({
                url: @json($ptRoute('assistant', $template)),
                t,
                key: {{ $template->id }},
                initial: @json(session('pdf-templates.ai_history', [])),
                getDraft: () => ({ schema: JSON.stringify(storedTemplate()), sample_data: sampleRaw ?? '', name: name.value }),
                async applyDraft(patch) {
                    if ('schema' in patch) {
                        layer.set(await resolveImages(JSON.parse(patch.schema)));
                        syncLayer();
                    }
                    if ('sample_data' in patch) sampleRaw = patch.sample_data;
                    if ('name' in patch) {
                        name.value = patch.name;
                        name.dispatchEvent(new Event('input'));
                    }
                    chrome.setDirty(true);
                },
            });
        @endif
    </script>
@endpush
