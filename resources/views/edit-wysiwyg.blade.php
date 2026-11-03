@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php($portrait = __('pdf-templates::ui.portrait'))
    @php($landscape = __('pdf-templates::ui.landscape'))
    {{-- Editor first: a slim strip + the designer; everything else lives in the off-canvas menu --}}
    <div class="pt pt-compact pt-focus" id="pt-shell" data-csrf="{{ csrf_token() }}" data-pt-images="{{ $ptRoute('images.index') }}">
        <x-pdf-templates::editor :template="$template" :help="__('pdf-templates::ui.wysiwyg_help')">
            <section class="pt-drawer-section">
                <h3>{{ __('pdf-templates::ui.section_check') }}</h3>
                <div class="pt-stack">
                    <button type="button" class="pt-btn pt-btn-block" id="pt-preview">
                        <x-pdf-templates::icon name="eye" :size="15" /> {{ __('pdf-templates::ui.preview') }}
                    </button>
                    <a class="pt-btn pt-btn-block" href="{{ $ptRoute('test', $template) }}" data-pt-leave>
                        <x-pdf-templates::icon name="flask" :size="15" /> {{ __('pdf-templates::ui.test') }}
                    </a>
                </div>
            </section>

            <section class="pt-drawer-section">
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
                </div>
            </section>

            @include('pdf-templates::partials.images')

            <section class="pt-drawer-section">
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

        <div class="pt-designer" id="pt-designer">
            <div class="pt-designer-loading">{{ __('pdf-templates::ui.loading_designer') }}</div>
        </div>

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
        import { Designer, plugins, generatePdf } from 'pdf-templates/pdfme';
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
            unresolveImages
        } from 'pdf-templates';
        import { imageLibrary, imageSize } from 'pdf-templates/images';

        fillViewport(document.getElementById('pt-designer'), { gap: 0 });

        const t = @json(__('pdf-templates::ui'));
        const updateUrl = @json($ptRoute('update', $template));
        // Test data: saved with the template, can be changed by the AI assistant
        let sampleRaw = @json($template->sample_data);
        // Library images are stored as pdf-asset:… references; the designer needs the images themselves
        const template = await resolveImages(JSON.parse(@json($template->schema ?: \AnghelutaV\PdfTemplates\Models\PdfTemplate::BLANK_SCHEMA)));
        // The template as it is saved (and sent to the AI): library images back to references
        const storedTemplate = () => unresolveImages(designer.getTemplate());
        const fields = ['pt-name', 'pt-slug', 'pt-description', 'pt-active'].map((id) => document.getElementById(id));

        const container = document.getElementById('pt-designer');
        container.innerHTML = '';
        const designer = new Designer({
            domContainer: container,
            template,
            plugins,
            options: {
                lang: @json($designerLang),
                zoomLevel: 1,
                sidebarOpen: true
            },
        });

        async function save() {
            const [name, slug, description, active] = fields;
            try {
                const res = await saveTemplate(updateUrl, {
                    schema: JSON.stringify(storedTemplate()),
                    name: name.value,
                    slug: slug.value,
                    description: description.value,
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
        designer.onChangeTemplate(() => chrome.setDirty(true));
        designer.onSaveTemplate(() => document.getElementById('pt-save').click());
        fields.forEach((el) => el.addEventListener('input', () => chrome.setDirty(true)));

        const replaceTemplate = (next) => {
            designer.updateTemplate(next);
            chrome.setDirty(true);
            chrome.closeDrawer();
        };

        document.getElementById('pt-page-size').addEventListener('change', (e) => {
            const value = e.target.value;
            e.target.value = '';
            if (!value || !confirm(t.page_confirm)) return;

            const [width, height] = value.split('x').map(Number);
            replaceTemplate(resizeTemplate(designer.getTemplate(), width, height));
        });

        document.getElementById('pt-base-pdf').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            replaceTemplate({
                ...designer.getTemplate(),
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
            const current = designer.getTemplate();
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

        // Image library: a click places the image on the first page (fixed image, 40 mm wide, aspect kept);
        // it is saved as a pdf-asset:… reference. SVG goes into pdfme's SVG field as markup.
        imageLibrary({
            t,
            async insert(image) {
                const current = designer.getTemplate();
                const page = current.basePdf?.padding ? current.basePdf : { padding: [10, 10, 10, 10] };
                const names = new Set(current.schemas.flat().map((field) => field.name));
                const base = image.name.replace(/\.[^.]+$/, '').replace(/[^A-Za-z0-9_]+/g, '_') || 'image';
                let fieldName = base;
                for (let i = 2; names.has(fieldName); i++) fieldName = `${base}_${i}`;

                const svg = /\.svg$/i.test(image.name);
                const content = svg ? await (await fetch(image.url)).text() : (await resolveImages({ c: image.ref })).c;
                const { width, height } = await imageSize(image.url);
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
                replaceTemplate(current);
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
                    if ('schema' in patch) designer.updateTemplate(await resolveImages(JSON.parse(patch.schema)));
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
