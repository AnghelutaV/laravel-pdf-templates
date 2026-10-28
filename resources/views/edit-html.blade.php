@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php
        $fonts = collect([config('pdf-templates.html.default_font', 'DejaVu Sans'), ...array_keys(config('pdf-templates.html.fonts', []))])
            ->unique()->map(fn($font) => '<code>' . e($font) . '</code>')->implode(', ');
    @endphp
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <form method="post" action="{{ $ptRoute('update', $template) }}" id="pt-form">
            @csrf
            @method('PUT')
            <x-pdf-templates::header :title="$template->name" :back="$ptRoute('index')" badge="html">
                <a class="pt-btn" href="{{ $ptRoute('export', $template) }}">{{ __('pdf-templates::ui.export') }}</a>
                <a class="pt-btn" href="{{ $ptRoute('test', $template) }}">{{ __('pdf-templates::ui.test') }}</a>
                <button type="submit" class="pt-btn pt-btn-primary">{{ __('pdf-templates::ui.save') }} <span style="opacity: .7;">(Ctrl+S)</span></button>
            </x-pdf-templates::header>

            <div class="pt-card">
                <div class="pt-card-body">
                    @include('pdf-templates::partials.settings')
                    <div class="pt-grid" style="margin-top: 12px;">
                        <div class="pt-col-2">
                            <label class="pt-label" for="pt-paper">{{ __('pdf-templates::ui.paper') }}</label>
                            <select class="pt-select" id="pt-paper" name="paper" style="width: 100%;">
                                @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::PAPERS as $paper)
                                    <option value="{{ $paper }}" @selected($template->paper === $paper)>{{ strtoupper($paper) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="pt-col-2">
                            <label class="pt-label" for="pt-orientation">{{ __('pdf-templates::ui.orientation') }}</label>
                            <select class="pt-select" id="pt-orientation" name="orientation" style="width: 100%;">
                                @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::ORIENTATIONS as $orientation)
                                    <option value="{{ $orientation }}" @selected($template->orientation === $orientation)>{{ __('pdf-templates::ui.' . $orientation) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-split pt-even">
                <div class="pt-card">
                    <div class="pt-tabs" id="pt-tabs" role="tablist">
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-html">HTML</button>
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-css">CSS</button>
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-data">{{ __('pdf-templates::ui.tab_data') }}</button>
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-syntax">{{ __('pdf-templates::ui.tab_syntax') }}</button>
                    </div>
                    <div class="pt-card-body">
                        <div id="pt-tab-html">
                            <textarea class="pt-textarea pt-code" id="pt-html" name="html" spellcheck="false" aria-label="HTML">{{ old('html', $template->html) }}</textarea>
                        </div>
                        <div id="pt-tab-css">
                            <textarea class="pt-textarea pt-code" id="pt-css" name="css" spellcheck="false" aria-label="CSS">{{ old('css', $template->css) }}</textarea>
                            <p class="pt-muted" style="margin-top: 8px;">{!! __('pdf-templates::ui.fonts_hint', ['fonts' => $fonts]) !!}</p>
                        </div>
                        <div id="pt-tab-data">
                            <textarea class="pt-textarea pt-code" id="pt-sample" name="sample_data" spellcheck="false" aria-label="JSON">{{ old('sample_data', $template->sample_data) }}</textarea>
                        </div>
                        <div id="pt-tab-syntax">
                            <table class="pt-table pt-syntax">
                                @foreach (__('pdf-templates::ui.syntax') as [$code, $description])
                                    <tr><td><code>{{ $code }}</code></td><td>{{ $description }}</td></tr>
                                @endforeach
                            </table>
                            <p class="pt-muted" style="margin-top: 8px;">{!! __('pdf-templates::ui.multi_note') !!}</p>
                        </div>
                    </div>
                </div>

                <div class="pt-card">
                    <div class="pt-card-head">
                        <h2>{{ __('pdf-templates::ui.preview') }} <span class="pt-muted" id="pt-status"></span></h2>
                        <div class="pt-actions">
                            <label class="pt-check pt-muted"><input type="checkbox" id="pt-auto" checked> {{ __('pdf-templates::ui.auto') }}</label>
                            <button type="button" class="pt-btn" id="pt-preview">{{ __('pdf-templates::ui.generate') }}</button>
                        </div>
                    </div>
                    <div class="pt-card-body pt-flush">
                        <div class="pt-alert pt-alert-danger pt-hidden" id="pt-error" role="alert"></div>
                        <iframe class="pt-frame" id="pt-frame" title="{{ __('pdf-templates::ui.preview') }}"></iframe>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { renderOnServer, showPdf, codeTextarea, tabs, onSaveShortcut } from 'pdf-templates';

        const t = @json(__('pdf-templates::ui'));
        const renderUrl = @json($ptRoute('render', $template));
        const [html, css, sample, paper, orientation] = ['pt-html', 'pt-css', 'pt-sample', 'pt-paper', 'pt-orientation']
            .map((id) => document.getElementById(id));
        const status = document.getElementById('pt-status');
        const errorBox = document.getElementById('pt-error');
        let timer;
        let seq = 0;

        tabs(document.getElementById('pt-tabs'));
        [html, css, sample].forEach(codeTextarea);

        async function preview() {
            const current = ++seq;
            status.textContent = t.generating;
            try {
                const blob = await renderOnServer(renderUrl, {
                    html: html.value, css: css.value, data: sample.value, paper: paper.value, orientation: orientation.value,
                });
                if (current !== seq) return;
                showPdf(document.getElementById('pt-frame'), blob);
                errorBox.classList.add('pt-hidden');
                status.textContent = new Date().toLocaleTimeString();
            } catch (e) {
                if (current !== seq) return;
                errorBox.textContent = e.message;
                errorBox.classList.remove('pt-hidden');
                status.textContent = '';
            }
        }

        document.getElementById('pt-preview').addEventListener('click', preview);
        [html, css, sample, paper, orientation].forEach((el) => el.addEventListener('input', () => {
            if (!document.getElementById('pt-auto').checked) return;
            clearTimeout(timer);
            timer = setTimeout(preview, 900);
        }));
        onSaveShortcut(() => document.getElementById('pt-form').requestSubmit());
        preview();
    </script>
@endpush
