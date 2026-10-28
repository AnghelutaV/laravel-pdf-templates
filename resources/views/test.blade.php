@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php
        $isHtml = $template->isHtml();
        $apiUrl = $ptRoute('api', $template->slug);
        $serverReady = $isHtml || $rendererConfigured;
    @endphp
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <x-pdf-templates::header :title="__('pdf-templates::ui.test_title', ['name' => $template->name])" :back="$ptRoute('edit', $template)" :badge="$template->mode">
            <code>{{ $template->slug }}</code>
            <a class="pt-btn" href="{{ $ptRoute('edit', $template) }}">{{ __('pdf-templates::ui.edit') }}</a>
        </x-pdf-templates::header>

        <div class="pt-split">
            <div>
                <div class="pt-card">
                    <div class="pt-card-head">
                        <h2>{{ __('pdf-templates::ui.json_data') }}</h2>
                        <div class="pt-actions">
                            <button type="button" class="pt-btn" id="pt-format">{{ __('pdf-templates::ui.format') }}</button>
                            @unless ($isHtml)
                                <button type="button" class="pt-btn" id="pt-fields" title="{{ __('pdf-templates::ui.fields_title') }}">{{ __('pdf-templates::ui.fields') }}</button>
                            @endunless
                            <button type="button" class="pt-btn" id="pt-save-sample">{{ __('pdf-templates::ui.save_sample') }}</button>
                        </div>
                    </div>
                    <div class="pt-card-body">
                        <textarea class="pt-textarea pt-code" id="pt-data" spellcheck="false" style="min-height: 420px;" aria-label="JSON">{{ $template->sample_data ?: '{}' }}</textarea>
                        <div class="pt-muted" id="pt-json-error" style="color: var(--pt-danger);"></div>
                        <div class="pt-actions" style="margin-top: 10px;">
                            @unless ($isHtml)
                                <button type="button" class="pt-btn pt-btn-primary" id="pt-browser">{{ __('pdf-templates::ui.generate_browser') }}</button>
                            @endunless
                            <button type="button" class="pt-btn {{ $isHtml ? 'pt-btn-primary' : '' }}" id="pt-server"
                                @disabled(!$serverReady) @if (!$serverReady) title="{{ __('pdf-templates::ui.renderer_missing') }}" @endif>
                                {{ __('pdf-templates::ui.generate_server') }}
                            </button>
                            <button type="button" class="pt-btn" id="pt-download" disabled>{{ __('pdf-templates::ui.download') }}</button>
                        </div>
                        <p class="pt-muted" style="margin-top: 10px;">
                            {!! $isHtml
                                ? e(__('pdf-templates::ui.html_server_note'))
                                : __('pdf-templates::ui.wysiwyg_server_note', ['state' => __('pdf-templates::ui.' . ($rendererConfigured ? 'configured' : 'not_configured'))]) !!}
                            {!! __('pdf-templates::ui.multi_note') !!}
                        </p>
                    </div>
                </div>

                <div class="pt-card">
                    <div class="pt-card-head">
                        <h2>{{ __('pdf-templates::ui.api') }}</h2>
                        <span class="pt-badge {{ $apiEnabled ? 'pt-badge-on' : '' }}">{{ __('pdf-templates::ui.' . ($apiEnabled ? 'api_on' : 'api_off')) }}</span>
                    </div>
                    <div class="pt-card-body">
                        <p class="pt-muted"><code>POST {{ $apiUrl }}</code> — {!! __('pdf-templates::ui.api_note') !!}
                            @unless ($isHtml) {{ __('pdf-templates::ui.api_needs_renderer') }} @endunless
                        </p>
                        <pre class="pt-pre">curl -X POST "{{ $apiUrl }}" \
  -H "X-API-KEY: $PDF_TEMPLATES_API_KEY" \
  -H "Content-Type: application/json" \
  --data @data.json -o {{ $template->slug }}.pdf</pre>
                        <pre class="pt-pre">use AnghelutaV\PdfTemplates\Facades\PdfTemplates;

return PdfTemplates::make('{{ $template->slug }}', $data);                 // inline response
PdfTemplates::make('{{ $template->slug }}', $data)->save('docs/1.pdf');    // to storage
$bytes = PdfTemplates::render('{{ $template->slug }}', $data);</pre>
                    </div>
                </div>

                <div class="pt-card">
                    <div class="pt-card-head">
                        <h2>{{ __('pdf-templates::ui.fields_used') }}</h2>
                        @if ($fields)
                            <span class="pt-badge">{{ count($fields) }}</span>
                        @endif
                    </div>
                    <div class="pt-card-body">
                        @if ($fields === null)
                            <p class="pt-muted">{{ __('pdf-templates::ui.fields_unavailable') }}</p>
                        @elseif (!$fields)
                            <p class="pt-muted">{{ __('pdf-templates::ui.fields_none') }}</p>
                        @else
                            <table class="pt-table pt-syntax">
                                @foreach ($fields as $key => $type)
                                    <tr><td><code>{{ $key }}</code></td><td class="pt-muted">{{ $type }}</td></tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                </div>
            </div>

            <div class="pt-card">
                <div class="pt-card-head">
                    <h2>{{ __('pdf-templates::ui.result') }} <span class="pt-muted" id="pt-status"></span></h2>
                </div>
                <div class="pt-card-body pt-flush">
                    <div class="pt-alert pt-alert-danger pt-hidden" id="pt-error" role="alert"></div>
                    <iframe class="pt-frame" id="pt-frame" title="{{ __('pdf-templates::ui.result') }}"></iframe>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { renderOnServer, showPdf, codeTextarea, notify, saveTemplate, sampleFromTemplate } from 'pdf-templates';

        const t = @json(__('pdf-templates::ui'));
        const isHtml = @json($isHtml);
        const renderUrl = @json($ptRoute('render', $template));
        const updateUrl = @json($ptRoute('update', $template));
        const slug = @json($template->slug);
        const textarea = document.getElementById('pt-data');
        const errorBox = document.getElementById('pt-error');
        const status = document.getElementById('pt-status');
        const downloadBtn = document.getElementById('pt-download');
        let lastUrl = null;

        codeTextarea(textarea);

        function parseData() {
            const jsonError = document.getElementById('pt-json-error');
            try {
                const value = JSON.parse(textarea.value || '{}');
                jsonError.textContent = '';
                return value;
            } catch (e) {
                jsonError.textContent = t.invalid_json.replace(':error', e.message);
                throw e;
            }
        }

        async function run(label, producer) {
            errorBox.classList.add('pt-hidden');
            status.textContent = t.generating;
            const started = performance.now();
            try {
                const blob = await producer();
                lastUrl = showPdf(document.getElementById('pt-frame'), blob);
                downloadBtn.disabled = false;
                status.textContent = `${label} · ${Math.round(performance.now() - started)} ms · ${(blob.size / 1024).toFixed(1)} KB`;
            } catch (e) {
                errorBox.textContent = e.message;
                errorBox.classList.remove('pt-hidden');
                status.textContent = '';
            }
        }

        const server = () => run('server', () => {
            parseData();
            return renderOnServer(renderUrl, { data: textarea.value });
        });
        document.getElementById('pt-server').addEventListener('click', server);

        document.getElementById('pt-format').addEventListener('click', () => {
            try {
                textarea.value = JSON.stringify(parseData(), null, 4);
            } catch {
                // error already shown
            }
        });

        document.getElementById('pt-save-sample').addEventListener('click', async () => {
            try {
                parseData();
                await saveTemplate(updateUrl, { sample_data: textarea.value });
                notify(t.sample_saved);
            } catch (e) {
                notify(t.save_error.replace(':error', e.message), 'danger');
            }
        });

        downloadBtn.addEventListener('click', () => {
            if (!lastUrl) return;
            const a = document.createElement('a');
            a.href = lastUrl;
            a.download = slug + '.pdf';
            a.click();
        });

        if (isHtml) {
            server();
        } else {
            // pdfme is only downloaded for WYSIWYG templates
            const { generatePdf } = await import('pdf-templates/pdfme');
            const template = JSON.parse(@json($template->schema ?: \AnghelutaV\PdfTemplates\Models\PdfTemplate::BLANK_SCHEMA));

            const browser = () => run('browser', () => generatePdf(template, parseData()));
            document.getElementById('pt-browser').addEventListener('click', browser);
            document.getElementById('pt-fields').addEventListener('click', () => {
                textarea.value = JSON.stringify(sampleFromTemplate(template), null, 4);
            });
            browser();
        }
    </script>
@endpush
