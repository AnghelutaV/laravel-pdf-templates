@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <x-pdf-templates::header :title="$template->name" :back="$ptRoute('index')" badge="wysiwyg">
            <select class="pt-select" id="pt-page-size" title="{{ __('pdf-templates::ui.page_title') }}" aria-label="{{ __('pdf-templates::ui.page_title') }}">
                <option value="">{{ __('pdf-templates::ui.page') }}</option>
                <option value="210x297">A4 {{ __('pdf-templates::ui.portrait') }}</option>
                <option value="297x210">A4 {{ __('pdf-templates::ui.landscape') }}</option>
                <option value="148x210">A5 {{ __('pdf-templates::ui.portrait') }}</option>
                <option value="210x148">A5 {{ __('pdf-templates::ui.landscape') }}</option>
                <option value="216x279">Letter</option>
            </select>
            <label class="pt-btn pt-file" title="{{ __('pdf-templates::ui.background_pdf_title') }}">
                {{ __('pdf-templates::ui.background_pdf') }}
                <input type="file" id="pt-base-pdf" accept="application/pdf">
            </label>
            <label class="pt-btn pt-file" title="{{ __('pdf-templates::ui.import_title') }}">
                {{ __('pdf-templates::ui.import') }}
                <input type="file" id="pt-import" accept="application/json,.json">
            </label>
            <a class="pt-btn" href="{{ $ptRoute('export', $template) }}">{{ __('pdf-templates::ui.export') }}</a>
            <button type="button" class="pt-btn" id="pt-preview" title="{{ __('pdf-templates::ui.preview_title') }}">{{ __('pdf-templates::ui.preview') }}</button>
            <a class="pt-btn" href="{{ $ptRoute('test', $template) }}">{{ __('pdf-templates::ui.test') }}</a>
            <button type="button" class="pt-btn pt-btn-primary" id="pt-save" disabled>{{ __('pdf-templates::ui.save') }} <span style="opacity: .7;">(Ctrl+S)</span></button>
        </x-pdf-templates::header>

        <div class="pt-card">
            <div class="pt-card-body">
                @include('pdf-templates::partials.settings')
                <p class="pt-muted" style="margin: 12px 0 0;">{!! __('pdf-templates::ui.wysiwyg_help') !!}</p>
            </div>
        </div>

        <div class="pt-designer" id="pt-designer">
            <div class="pt-designer-loading">{{ __('pdf-templates::ui.loading_designer') }}</div>
        </div>

        <dialog class="pt-dialog" id="pt-preview-dialog">
            <div class="pt-card-head">
                <h2>{{ __('pdf-templates::ui.preview_title') }}</h2>
                <button type="button" class="pt-btn" onclick="this.closest('dialog').close()" aria-label="{{ __('pdf-templates::ui.cancel') }}">&times;</button>
            </div>
            <iframe class="pt-frame" id="pt-preview-frame" title="{{ __('pdf-templates::ui.preview') }}"></iframe>
        </dialog>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { Designer, plugins, generatePdf } from 'pdf-templates/pdfme';
        import { sampleFromTemplate, resizeTemplate, readFileAsDataUrl, notify, saveTemplate, showPdf, onSaveShortcut } from 'pdf-templates';

        const t = @json(__('pdf-templates::ui'));
        const updateUrl = @json($ptRoute('update', $template));
        const sampleRaw = @json($template->sample_data);
        const template = JSON.parse(@json($template->schema ?: \AnghelutaV\PdfTemplates\Models\PdfTemplate::BLANK_SCHEMA));
        const fields = ['pt-name', 'pt-slug', 'pt-description', 'pt-active'].map((id) => document.getElementById(id));
        const saveBtn = document.getElementById('pt-save');
        let dirty = false;

        const container = document.getElementById('pt-designer');
        container.innerHTML = '';
        const designer = new Designer({
            domContainer: container,
            template,
            plugins,
            options: { lang: @json($designerLang), zoomLevel: 1, sidebarOpen: true },
        });

        const markDirty = (value) => {
            dirty = value;
            saveBtn.disabled = !value;
        };
        designer.onChangeTemplate(() => markDirty(true));
        fields.forEach((el) => el.addEventListener('input', () => markDirty(true)));

        async function save() {
            saveBtn.disabled = true;
            const [name, slug, description, active] = fields;
            try {
                const res = await saveTemplate(updateUrl, {
                    schema: JSON.stringify(designer.getTemplate()),
                    name: name.value,
                    slug: slug.value,
                    description: description.value,
                    active: active.checked ? 1 : 0,
                });
                markDirty(false);
                notify(t.saved_at.replace(':time', res.updated_at));
            } catch (e) {
                saveBtn.disabled = false;
                notify(t.save_error.replace(':error', e.message), 'danger');
            }
        }
        saveBtn.addEventListener('click', save);
        designer.onSaveTemplate(save);
        onSaveShortcut(save);
        window.addEventListener('beforeunload', (e) => {
            if (dirty) e.preventDefault();
        });

        const replaceTemplate = (next) => {
            designer.updateTemplate(next);
            markDirty(true);
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
            replaceTemplate({ ...designer.getTemplate(), basePdf: await readFileAsDataUrl(file) });
            e.target.value = '';
        });

        document.getElementById('pt-import').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            try {
                const imported = JSON.parse(await file.text());
                if (!imported.basePdf || !Array.isArray(imported.schemas)) throw new Error('basePdf / schemas missing');
                replaceTemplate(imported);
            } catch (err) {
                notify(t.invalid_file.replace(':error', err.message), 'danger');
            }
            e.target.value = '';
        });

        // Preview with the saved test data, falling back to the field contents
        document.getElementById('pt-preview').addEventListener('click', async () => {
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
    </script>
@endpush
