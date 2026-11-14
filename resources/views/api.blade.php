@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php
        $t = fn (string $key, array $replace = []) => __('pdf-templates::ui.' . $key, $replace);
        $keyForDocs = $newKey['key'] ?? 'YOUR_API_KEY';
    @endphp
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <x-pdf-templates::header :title="$t('api_title')" :back="$ptRoute('index')" :subtitle="$t('api_subtitle')">
            <a class="pt-btn" href="{{ $ptRoute('api.postman', $newKey ? ['key' => $newKey['key']] : []) }}" data-pt-tip="{{ $t('api_postman_tip') }}">
                <x-pdf-templates::icon name="download" :size="15" /> {{ $t('api_postman') }}
            </a>
        </x-pdf-templates::header>

        @if ($newKey)
            {{-- Shown once: only a hash is stored --}}
            <div class="pt-card pt-api-newkey">
                <div class="pt-card-body">
                    <strong>{{ $t('api_key_once', ['name' => $newKey['name']]) }}</strong>
                    <div class="pt-api-key-row">
                        <code id="pt-new-key">{{ $newKey['key'] }}</code>
                        <button type="button" class="pt-btn pt-btn-sm" data-pt-copy="pt-new-key"><x-pdf-templates::icon name="copy" :size="14" /> {{ $t('api_copy') }}</button>
                    </div>
                    <p class="pt-muted pt-hint">{{ $t('api_key_postman') }}</p>
                </div>
            </div>
        @endif

        <div class="pt-card pt-api-shell">
            <div class="pt-tabs" role="tablist" id="pt-api-tabs">
                <button type="button" class="pt-tab" role="tab" data-tab="pt-api-server" @if ($tab === 'server') aria-selected="true" @endif>
                    <x-pdf-templates::icon name="server" :size="14" /> {{ $t('api_tab_server') }}
                    <span class="pt-api-dot {{ $renderer['reachable'] ? 'is-on' : '' }}" id="pt-renderer-dot" aria-hidden="true"></span>
                </button>
                <button type="button" class="pt-tab" role="tab" data-tab="pt-api-docs" @if ($tab === 'docs') aria-selected="true" @endif>
                    <x-pdf-templates::icon name="book" :size="14" /> {{ $t('api_tab_docs') }}
                </button>
                <button type="button" class="pt-tab" role="tab" data-tab="pt-api-log" @if ($tab === 'log') aria-selected="true" @endif>
                    <x-pdf-templates::icon name="layers" :size="14" /> {{ $t('api_tab_log') }}
                    @if ($stats['failed'])
                        <span class="pt-badge pt-badge-danger">{{ $stats['failed'] }}</span>
                    @endif
                </button>
            </div>

            {{-- ─────────── Server & keys ─────────── --}}
            <div class="pt-card-body pt-api-panel" id="pt-api-server">
                <div class="pt-api-grid">
                    <section class="pt-api-box" id="pt-renderer" data-status="{{ $ptRoute('api.renderer') }}" data-start="{{ $ptRoute('api.renderer.start') }}" data-stop="{{ $ptRoute('api.renderer.stop') }}">
                        <div class="pt-api-box-head">
                            <h2>{{ $t('renderer_title') }}</h2>
                            <span class="pt-badge pt-dot {{ $renderer['reachable'] ? 'pt-badge-on' : '' }}" data-renderer-state>
                                {{ $renderer['reachable'] ? $t('renderer_running') : $t('renderer_stopped_state') }}
                            </span>
                        </div>
                        <p class="pt-muted pt-hint">{{ $t('renderer_what') }}</p>
                        <dl class="pt-api-facts">
                            <dt>URL</dt><dd><code data-renderer-url>{{ $renderer['url'] }}</code></dd>
                            <dt>PID</dt><dd data-renderer-pid>{{ $renderer['pid'] ?? '—' }}</dd>
                            <dt>Node.js</dt><dd data-renderer-node>{{ $renderer['node'] ?? $t('renderer_node_missing') }}</dd>
                            <dt>npm</dt><dd data-renderer-installed>{{ $renderer['installed'] ? $t('renderer_installed') : $t('renderer_not_installed') }}</dd>
                        </dl>
                        @unless ($renderer['configured'])
                            <div class="pt-alert pt-alert-warning">{!! $t('renderer_not_configured', ['url' => e($renderer['url'])]) !!}</div>
                        @endunless
                        @if ($renderer['manageable'])
                            <div class="pt-actions">
                                <button type="button" class="pt-btn pt-btn-primary" data-renderer-action="start" @disabled($renderer['reachable'])>
                                    <x-pdf-templates::icon name="play" :size="14" /> {{ $t('renderer_start') }}
                                </button>
                                <button type="button" class="pt-btn" data-renderer-action="restart" @disabled(! $renderer['pid'])>
                                    <x-pdf-templates::icon name="rotate-ccw" :size="14" /> {{ $t('renderer_restart') }}
                                </button>
                                <button type="button" class="pt-btn pt-btn-danger" data-renderer-action="stop" @disabled(! $renderer['pid'])>
                                    <x-pdf-templates::icon name="square" :size="14" /> {{ $t('renderer_stop') }}
                                </button>
                                <button type="button" class="pt-btn pt-btn-ghost" data-renderer-action="refresh" data-pt-tip="{{ $t('renderer_refresh') }}" aria-label="{{ $t('renderer_refresh') }}">
                                    <x-pdf-templates::icon name="rotate-ccw" :size="14" />
                                </button>
                            </div>
                            <p class="pt-muted pt-hint">{!! $t('renderer_production') !!}</p>
                        @else
                            <p class="pt-muted pt-hint">{!! $renderer['local'] ? $t('renderer_manage_off') : $t('renderer_remote') !!}</p>
                        @endif
                        <details class="pt-api-logbox" @if ($renderer['log'] && ! $renderer['reachable']) open @endif>
                            <summary>{{ $t('renderer_log') }}</summary>
                            <pre class="pt-code-block" data-renderer-log>{{ $renderer['log'] ?: '—' }}</pre>
                        </details>
                    </section>

                    <section class="pt-api-box">
                        <div class="pt-api-box-head"><h2>{{ $t('api_keys') }}</h2></div>
                        <form method="post" action="{{ $ptRoute('api.keys.store') }}" class="pt-api-keyform">
                            @csrf
                            <input type="text" class="pt-input" name="name" required maxlength="120" placeholder="{{ $t('api_key_name') }}" aria-label="{{ $t('api_key_name') }}">
                            <button type="submit" class="pt-btn pt-btn-primary"><x-pdf-templates::icon name="plus" :size="15" /> {{ $t('api_key_create') }}</button>
                        </form>
                        @if ($masterKey)
                            <p class="pt-muted pt-hint">{!! $t('api_master_on') !!}</p>
                        @endif
                        @if ($keys->isNotEmpty())
                            <div class="pt-table-wrap">
                                <table class="pt-table">
                                    <thead>
                                        <tr>
                                            <th>{{ $t('name') }}</th>
                                            <th>{{ $t('api_key') }}</th>
                                            <th>{{ $t('api_last_used') }}</th>
                                            <th>{{ $t('api_usage') }}</th>
                                            <th>{{ $t('api_limits') }}</th>
                                            <th class="pt-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($keys as $key)
                                            <tr @if ($key->revoked_at) class="pt-muted" @endif>
                                                <td>
                                                    <a href="{{ $ptRoute('api.index', ['key' => $key->id]) }}">{{ $key->name }}</a>
                                                    @if ($key->revoked_at)
                                                        <span class="pt-badge">{{ $t('api_revoked') }}</span>
                                                    @endif
                                                </td>
                                                <td><code>{{ $key->prefix }}…</code></td>
                                                <td>{{ $key->last_used_at?->diffForHumans() ?? '—' }}</td>
                                                <td class="pt-api-usage">
                                                    @php($month = (int) $key->month_documents)
                                                    <div>
                                                        <strong>{{ $month }}</strong>@if ($key->per_month) <span class="pt-muted">/ {{ $key->per_month }}</span>@endif
                                                        <span class="pt-muted">{{ $t('api_docs_this_month') }}</span>
                                                    </div>
                                                    @if ($key->per_month)
                                                        @php($share = min(100, round($month / $key->per_month * 100)))
                                                        <div class="pt-api-meter {{ $share >= 90 ? 'is-full' : '' }}"><span style="width: {{ $share }}%"></span></div>
                                                    @endif
                                                    <div class="pt-muted">{{ $t('api_usage_30d', ['renders' => $key->renders_30d, 'failed' => $key->failed_30d]) }}</div>
                                                </td>
                                                <td>
                                                    @unless ($key->revoked_at)
                                                        <form method="post" action="{{ $ptRoute('api.keys.update', $key) }}" class="pt-api-limits">
                                                            @csrf
                                                            @method('PUT')
                                                            <label>
                                                                <input type="number" name="per_minute" min="1" class="pt-input" value="{{ $key->per_minute }}" placeholder="∞">
                                                                <span class="pt-muted">{{ $t('api_per_minute') }}</span>
                                                            </label>
                                                            <label>
                                                                <input type="number" name="per_month" min="1" class="pt-input" value="{{ $key->per_month }}" placeholder="∞">
                                                                <span class="pt-muted">{{ $t('api_per_month') }}</span>
                                                            </label>
                                                            <button type="submit" class="pt-btn pt-btn-sm">{{ $t('save') }}</button>
                                                        </form>
                                                    @else
                                                        —
                                                    @endunless
                                                </td>
                                                <td class="pt-end">
                                                    @unless ($key->revoked_at)
                                                        <form method="post" action="{{ $ptRoute('api.keys.revoke', $key) }}"
                                                            onsubmit="return confirm(@js($t('api_revoke_confirm', ['name' => $key->name])));">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="pt-btn pt-btn-sm pt-btn-danger">{{ $t('api_revoke') }}</button>
                                                        </form>
                                                    @endunless
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif (! $masterKey)
                            <p class="pt-muted">{{ $t('api_no_keys') }}</p>
                        @endif
                    </section>
                </div>
            </div>

            {{-- ─────────── Documentation ─────────── --}}
            <div class="pt-card-body pt-api-panel pt-api-docs" id="pt-api-docs">
                <nav class="pt-api-toc">
                    <a href="#doc-auth">{{ $t('doc_auth') }}</a>
                    <a href="#doc-render">{{ $t('doc_render') }}</a>
                    <a href="#doc-params">{{ $t('doc_params') }}</a>
                    <a href="#doc-responses">{{ $t('doc_responses') }}</a>
                    <a href="#doc-async">{{ $t('doc_async') }}</a>
                    <a href="#doc-batch">{{ $t('doc_batch') }}</a>
                    <a href="#doc-endpoints">{{ $t('doc_endpoints') }}</a>
                    <a href="#doc-errors">{{ $t('doc_errors') }}</a>
                </nav>

                <section id="doc-auth">
                    <h2>{{ $t('doc_auth') }}</h2>
                    <p>{!! $t('doc_auth_text') !!}</p>
                    <pre class="pt-code-block"><code>X-API-KEY: {{ $keyForDocs }}
<span class="pt-muted"># {{ $t('doc_or') }}</span>
Authorization: Bearer {{ $keyForDocs }}</code></pre>
                </section>

                <section id="doc-render">
                    <h2>{{ $t('doc_render') }}</h2>
                    <p><span class="pt-badge pt-api-method">POST</span> <code>{{ $apiUrl }}/<span data-doc-slug>{slug}</span></code></p>
                    <p>{{ $t('doc_render_text') }}</p>
                    @if ($docs->isNotEmpty())
                        <label class="pt-api-pick">
                            <span class="pt-label">{{ $t('doc_template') }}</span>
                            <select class="pt-select" id="pt-doc-template">
                                @foreach ($docs as $doc)
                                    <option value="{{ $loop->index }}">{{ $doc['name'] }} ({{ $doc['slug'] }})</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="pt-api-two">
                            <div>
                                <h3>{{ $t('doc_body') }}</h3>
                                <pre class="pt-code-block pt-api-json"><code data-doc-body></code></pre>
                            </div>
                            <div>
                                <h3>{{ $t('doc_fields') }}</h3>
                                <table class="pt-table pt-api-fields"><tbody data-doc-fields></tbody></table>
                                <p class="pt-muted pt-hint">{{ $t('doc_fields_hint') }}</p>
                            </div>
                        </div>
                        <h3>{{ $t('doc_code') }}</h3>
                        <div class="pt-api-lang" role="tablist">
                            @foreach (['curl' => 'cURL', 'php' => 'PHP (Laravel)', 'js' => 'JavaScript', 'python' => 'Python'] as $lang => $label)
                                <button type="button" class="pt-btn pt-btn-sm" data-doc-lang="{{ $lang }}" @if ($loop->first) aria-pressed="true" @endif>{{ $label }}</button>
                            @endforeach
                            <button type="button" class="pt-btn pt-btn-sm pt-btn-ghost" data-pt-copy="pt-doc-code"><x-pdf-templates::icon name="copy" :size="13" /> {{ $t('api_copy') }}</button>
                        </div>
                        <pre class="pt-code-block"><code id="pt-doc-code"></code></pre>
                    @else
                        <p class="pt-muted">{{ $t('doc_no_templates') }}</p>
                    @endif
                </section>

                <section id="doc-params">
                    <h2>{{ $t('doc_params') }}</h2>
                    <table class="pt-table pt-api-options">
                        <thead><tr><th>{{ $t('doc_param') }}</th><th>{{ $t('doc_values') }}</th><th>{{ $t('doc_meaning') }}</th></tr></thead>
                        <tbody>
                            <tr><td><code>output</code></td><td><code>pdf</code> · <code>base64</code> · <code>url</code></td><td>{{ $t('doc_p_output') }}</td></tr>
                            <tr><td><code>format</code></td><td><code>pdf</code> · <code>png</code> · <code>jpg</code></td><td>{{ $t('doc_p_format') }}</td></tr>
                            <tr><td><code>page</code></td><td>1, 2…</td><td>{{ $t('doc_p_page') }}</td></tr>
                            <tr><td><code>filename</code></td><td>invoice-42</td><td>{{ $t('doc_p_filename') }}</td></tr>
                            <tr><td><code>download</code></td><td><code>1</code></td><td>{{ $t('doc_p_download') }}</td></tr>
                            <tr><td><code>async</code></td><td><code>1</code></td><td>{{ $t('doc_p_async') }}</td></tr>
                            <tr><td><code>webhook</code></td><td>https://…</td><td>{{ $t('doc_p_webhook') }}</td></tr>
                            <tr><td><code>watermark</code></td><td>DRAFT</td><td>{{ $t('doc_p_watermark') }}</td></tr>
                            <tr><td><code>watermark_color</code> · <code>watermark_opacity</code></td><td>%23dc2626 · 0.2</td><td>{{ $t('doc_p_watermark_style') }}</td></tr>
                        </tbody>
                    </table>
                </section>

                <section id="doc-responses">
                    <h2>{{ $t('doc_responses') }}</h2>
                    <div class="pt-api-two">
                        <div>
                            <h3><code>output=pdf</code> <span class="pt-muted">({{ $t('doc_default') }})</span></h3>
                            <pre class="pt-code-block"><code>HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: inline; filename="<span data-doc-slug>invoice</span>.pdf"
X-Render-Id: 9b2f…</code></pre>
                            <h3><code>output=base64</code></h3>
                            <pre class="pt-code-block"><code>{
  "id": "9b2f6c1e-…",
  "filename": "<span data-doc-slug>invoice</span>.pdf",
  "mime": "application/pdf",
  "bytes": 48213,
  "data": "JVBERi0xLjcK…"
}</code></pre>
                        </div>
                        <div>
                            <h3><code>output=url</code></h3>
                            <pre class="pt-code-block"><code>{
  "id": "9b2f6c1e-…",
  "status": "done",
  "template": "<span data-doc-slug>invoice</span>",
  "format": "pdf",
  "documents": 1,
  "url": "{{ $apiUrl }}/files/9b2f…?expires=…&signature=…",
  "expires_at": "{{ now()->addMinutes((int) config('pdf-templates.api.files_ttl', 1440))->toIso8601String() }}",
  "bytes": 48213,
  "duration_ms": 184
}</code></pre>
                            <p class="pt-muted pt-hint">{{ $t('doc_url_hint', ['minutes' => (int) config('pdf-templates.api.files_ttl', 1440)]) }}</p>
                        </div>
                    </div>
                </section>

                <section id="doc-async">
                    <h2>{{ $t('doc_async') }}</h2>
                    <p>{!! $t('doc_async_text') !!}</p>
                    <div class="pt-api-two">
                        <div>
                            <h3>{{ $t('doc_async_answer') }}</h3>
                            <pre class="pt-code-block"><code>HTTP/1.1 202 Accepted
{
  "id": "9b2f6c1e-…",
  "status": "queued",
  "status_url": "{{ $apiUrl }}/renders/9b2f6c1e-…"
}</code></pre>
                        </div>
                        <div>
                            <h3>{{ $t('doc_webhook_verify') }}</h3>
                            <pre class="pt-code-block"><code>// {{ $t('doc_webhook_secret') }}
$body = $request->getContent();
$valid = hash_equals(
    hash_hmac('sha256', $body, env('PDF_WEBHOOK_SECRET')),
    $request->header('X-Signature', '')
);
$render = json_decode($body, true); // id, status, url, error…</code></pre>
                        </div>
                    </div>
                </section>

                <section id="doc-batch">
                    <h2>{{ $t('doc_batch') }}</h2>
                    <p>{!! $t('doc_batch_text', ['max' => (int) config('pdf-templates.api.batch_max', 100)]) !!}</p>
                    <pre class="pt-code-block"><code>POST {{ $apiUrl }}/batch
{
  "template": "{{ $example->slug ?? 'invoice' }}",
  "filename": "invoice-@{{ number }}",
  "items": [ { "number": 41, … }, { "number": 42, … } ]
}

{ "items": [
  { "template": "invoice", "data": { … }, "filename": "invoice-41" },
  { "template": "receipt", "data": { … } }
] }</code></pre>
                </section>

                <section id="doc-endpoints">
                    <h2>{{ $t('doc_endpoints') }}</h2>
                    <table class="pt-table pt-api-options">
                        <tbody>
                            <tr><td><span class="pt-badge pt-api-method">POST</span></td><td><code>/{slug}</code></td><td>{{ $t('doc_e_render') }}</td></tr>
                            <tr><td><span class="pt-badge pt-api-method">POST</span></td><td><code>/batch</code></td><td>{{ $t('doc_e_batch') }}</td></tr>
                            <tr><td><span class="pt-badge pt-api-method is-get">GET</span></td><td><code>/templates</code></td><td>{{ $t('api_opt_templates') }}</td></tr>
                            <tr><td><span class="pt-badge pt-api-method is-get">GET</span></td><td><code>/templates/{slug}</code></td><td>{{ $t('doc_e_template') }}</td></tr>
                            <tr><td><span class="pt-badge pt-api-method is-get">GET</span></td><td><code>/renders/{id}</code></td><td>{{ $t('api_opt_status') }}</td></tr>
                            <tr><td><span class="pt-badge pt-api-method is-get">GET</span></td><td><code>/files/{id}</code></td><td>{{ $t('doc_e_file') }}</td></tr>
                        </tbody>
                    </table>
                    <p class="pt-muted pt-hint">{{ $t('doc_base_url') }} <code>{{ $apiUrl }}</code></p>
                </section>

                <section id="doc-errors">
                    <h2>{{ $t('doc_errors') }}</h2>
                    <table class="pt-table pt-api-options">
                        <tbody>
                            <tr><td><code>401</code></td><td>{{ $t('doc_err_401') }}</td></tr>
                            <tr><td><code>403</code></td><td>{{ $t('doc_err_403') }}</td></tr>
                            <tr><td><code>404</code></td><td>{{ $t('doc_err_404') }}</td></tr>
                            <tr><td><code>422</code></td><td>{{ $t('doc_err_422') }}</td></tr>
                            <tr><td><code>429</code></td><td>{{ $t('doc_err_429') }}</td></tr>
                        </tbody>
                    </table>
                    <pre class="pt-code-block"><code>{ "error": "{#if} without {/if}", "id": "9b2f6c1e-…" }</code></pre>
                </section>
            </div>

            {{-- ─────────── Log ─────────── --}}
            <div class="pt-api-panel" id="pt-api-log">
                <div class="pt-card-head">
                    <div class="pt-actions pt-api-stats">
                        <span class="pt-badge">{{ $t('api_stat_day', ['count' => $stats['day']]) }}</span>
                        <span class="pt-badge {{ $stats['failed'] ? 'pt-badge-danger' : '' }}">{{ $t('api_stat_failed', ['count' => $stats['failed']]) }}</span>
                        <span class="pt-badge">{{ $t('api_stat_avg', ['ms' => $stats['avg_ms']]) }}</span>
                    </div>
                    <form method="get" action="{{ $ptRoute('api.index') }}">
                        <input type="hidden" name="tab" value="log">
                        @if (request('key'))
                            <input type="hidden" name="key" value="{{ request('key') }}">
                        @endif
                        <select class="pt-select" name="status" onchange="this.form.requestSubmit()" aria-label="{{ $t('status') }}">
                            <option value="">{{ $t('all') }}</option>
                            @foreach (['done', 'failed', 'queued', 'processing'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $t('api_status_' . $status) }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
                @if ($renders->isEmpty())
                    <div class="pt-card-body"><p class="pt-muted">{{ $t('api_log_empty') }}</p></div>
                @else
                    <div class="pt-table-wrap">
                        <table class="pt-table">
                            <thead>
                                <tr>
                                    <th>{{ $t('api_when') }}</th>
                                    <th>{{ $t('api_template') }}</th>
                                    <th>{{ $t('api_key') }}</th>
                                    <th>{{ $t('status') }}</th>
                                    <th>{{ $t('api_output') }}</th>
                                    <th>{{ $t('api_time') }}</th>
                                    <th class="pt-end">{{ $t('api_size') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($renders as $render)
                                    <tr class="pt-api-row" data-details="pt-render-{{ $render->id }}" tabindex="0" aria-expanded="false">
                                        <td class="pt-col-date"><span class="pt-api-chevron" aria-hidden="true"></span>{{ $render->created_at?->format('d.m.Y H:i:s') }}</td>
                                        <td><code>{{ $render->template_slug }}</code>@if ($render->documents > 1) <span class="pt-muted">× {{ $render->documents }}</span>@endif</td>
                                        <td>{{ $render->apiKey?->name ?? $t('api_master') }}</td>
                                        <td>
                                            <span class="pt-badge pt-dot pt-api-{{ $render->status }}">{{ $t('api_status_' . $render->status) }}</span>
                                            @if ($render->error)
                                                <div class="pt-muted pt-api-error" title="{{ $render->error }}">{{ \Illuminate\Support\Str::limit($render->error, 90) }}</div>
                                            @endif
                                            @if ($render->webhook)
                                                <div class="pt-muted" title="{{ $render->webhook }}">webhook: {{ $render->webhook_status ?? '…' }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <code>{{ $render->format }}</code> · {{ $render->output }}
                                            @if ($render->hasFile())
                                                · <a href="{{ $render->fileUrl() }}" target="_blank" rel="noopener">{{ $t('api_open') }}</a>
                                            @endif
                                        </td>
                                        <td>{{ $render->duration_ms !== null ? $render->duration_ms . ' ms' : '—' }}</td>
                                        <td class="pt-end">{{ $render->bytes ? number_format($render->bytes / 1024, 1) . ' KB' : '—' }}</td>
                                    </tr>
                                    {{-- Details: the data sent, the options, the client, the full error --}}
                                    <tr class="pt-api-details pt-hidden" id="pt-render-{{ $render->id }}">
                                        <td colspan="7">
                                            <div class="pt-api-detail-grid">
                                                <div class="pt-api-detail-data">
                                                    <div class="pt-api-detail-head">
                                                        <strong>{{ $t('api_input') }}</strong>
                                                        @if ($render->data !== null)
                                                            <span class="pt-muted">{{ number_format(strlen($render->data) / 1024, 1) }} KB</span>
                                                            <button type="button" class="pt-btn pt-btn-sm pt-btn-ghost" data-pt-copy="pt-render-data-{{ $render->id }}"><x-pdf-templates::icon name="copy" :size="13" /> {{ $t('api_copy') }}</button>
                                                        @endif
                                                    </div>
                                                    @if ($render->data !== null)
                                                        <pre class="pt-code-block pt-api-json"><code id="pt-render-data-{{ $render->id }}" class="pt-json">{!! $render->highlightedData() !!}</code></pre>
                                                    @else
                                                        <p class="pt-muted">{{ $t('api_no_data') }}</p>
                                                    @endif
                                                </div>
                                                <div>
                                                    <dl class="pt-api-facts">
                                                        <dt>ID</dt><dd><code>{{ $render->uuid }}</code></dd>
                                                        <dt>{{ $t('api_request') }}</dt>
                                                        <dd><code>POST /{{ $render->template_slug }}{{ $render->options ? '?' . urldecode(http_build_query($render->options)) : '' }}</code></dd>
                                                        <dt>{{ $t('api_key') }}</dt><dd>{{ $render->apiKey?->name ?? $t('api_master') }}</dd>
                                                        <dt>{{ $t('api_documents') }}</dt><dd>{{ $render->documents }}</dd>
                                                        <dt>{{ $t('api_file') }}</dt><dd>{{ $render->filename ?? '—' }}</dd>
                                                        <dt>IP</dt><dd>{{ $render->ip ?? '—' }}</dd>
                                                        <dt>{{ $t('api_client') }}</dt><dd class="pt-muted">{{ $render->user_agent ?? '—' }}</dd>
                                                        <dt>{{ $t('api_finished') }}</dt><dd>{{ $render->finished_at?->format('d.m.Y H:i:s') ?? '—' }}</dd>
                                                        @if ($render->webhook)
                                                            <dt>Webhook</dt><dd><code>{{ $render->webhook }}</code> → {{ $render->webhook_status ?? '…' }}</dd>
                                                        @endif
                                                    </dl>
                                                    @if ($render->error)
                                                        <pre class="pt-code-block pt-api-error-full">{{ $render->error }}</pre>
                                                    @endif
                                                    <div class="pt-actions">
                                                        @if ($render->data !== null)
                                                            <a class="pt-btn pt-btn-sm" href="{{ $ptRoute('api.renders.again', $render->uuid) }}" target="_blank" rel="noopener">
                                                                <x-pdf-templates::icon name="play" :size="13" /> {{ $t('api_render_again') }}
                                                            </a>
                                                        @endif
                                                        @if ($render->hasFile())
                                                            <a class="pt-btn pt-btn-sm" href="{{ $render->fileUrl() }}" target="_blank" rel="noopener">
                                                                <x-pdf-templates::icon name="download" :size="13" /> {{ $t('api_stored_file') }}
                                                            </a>
                                                        @endif
                                                        @if ($render->template_id)
                                                            <a class="pt-btn pt-btn-sm pt-btn-ghost" href="{{ $ptRoute('edit', $render->template_id) }}">
                                                                <x-pdf-templates::icon name="pencil" :size="13" /> {{ $t('api_open_template') }}
                                                            </a>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($renders->hasPages())
                        <div class="pt-card-foot">
                            <span class="pt-muted">{{ $t('page_of', ['current' => $renders->currentPage(), 'last' => $renders->lastPage()]) }}</span>
                            <div class="pt-actions">
                                <a class="pt-btn pt-btn-sm pt-btn-icon" href="{{ $renders->previousPageUrl() ?? '#' }}" @if ($renders->onFirstPage()) aria-disabled="true" style="pointer-events: none; opacity: .5;" @endif aria-label="{{ $t('previous') }}"><x-pdf-templates::icon name="chevron-left" :size="14" /></a>
                                <a class="pt-btn pt-btn-sm pt-btn-icon" href="{{ $renders->nextPageUrl() ?? '#' }}" @unless ($renders->hasMorePages()) aria-disabled="true" style="pointer-events: none; opacity: .5;" @endunless aria-label="{{ $t('next') }}"><x-pdf-templates::icon name="chevron-right" :size="14" /></a>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script type="module">
        import { notify } from 'pdf-templates';

        (() => {
            const t = {{ Illuminate\Support\Js::from([
                'running' => __('pdf-templates::ui.renderer_running'),
                'stopped' => __('pdf-templates::ui.renderer_stopped_state'),
                'working' => __('pdf-templates::ui.renderer_working'),
                'installed' => __('pdf-templates::ui.renderer_installed'),
                'notInstalled' => __('pdf-templates::ui.renderer_not_installed'),
                'nodeMissing' => __('pdf-templates::ui.renderer_node_missing'),
                'copied' => __('pdf-templates::ui.api_copied'),
            ]) }};
            const csrf = document.querySelector('[data-csrf]').dataset.csrf;

            // Tabs; the one in ?tab= (or the log, when filtering it) opens first
            const tabs = [...document.querySelectorAll('#pt-api-tabs .pt-tab')];
            const show = (tab) => {
                tabs.forEach((b) => {
                    b.setAttribute('aria-selected', b === tab);
                    document.getElementById(b.dataset.tab).classList.toggle('pt-hidden', b !== tab);
                });
                const url = new URL(location.href);
                url.searchParams.set('tab', tab.dataset.tab.replace('pt-api-', ''));
                history.replaceState(null, '', url);
            };
            tabs.forEach((b) => b.addEventListener('click', () => show(b)));
            show(tabs.find((b) => b.getAttribute('aria-selected') === 'true') ?? tabs[0]);

            // Log: a click on a row (not on its links) opens its details
            const toggleRow = (row) => {
                const details = document.getElementById(row.dataset.details);
                const open = details.classList.toggle('pt-hidden') === false;
                row.setAttribute('aria-expanded', open);
            };
            document.querySelectorAll('.pt-api-row').forEach((row) => {
                row.addEventListener('click', (e) => e.target.closest('a, button') || toggleRow(row));
                row.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), toggleRow(row)));
            });

            document.addEventListener('click', (e) => {
                const button = e.target.closest('[data-pt-copy]');
                if (!button) return;
                navigator.clipboard?.writeText(document.getElementById(button.dataset.ptCopy).innerText.trim());
                const label = button.lastChild;
                const before = label.textContent;
                label.textContent = ' ' + t.copied;
                setTimeout(() => label.textContent = before, 1500);
            });

            // Renderer: start / stop / restart and the status, without reloading the page
            const box = document.getElementById('pt-renderer');
            const paint = (s) => {
                const state = box.querySelector('[data-renderer-state]');
                state.textContent = s.reachable ? t.running : t.stopped;
                state.classList.toggle('pt-badge-on', s.reachable);
                document.getElementById('pt-renderer-dot').classList.toggle('is-on', s.reachable);
                box.querySelector('[data-renderer-pid]').textContent = s.pid ?? '—';
                box.querySelector('[data-renderer-node]').textContent = s.node ?? t.nodeMissing;
                box.querySelector('[data-renderer-installed]').textContent = s.installed ? t.installed : t.notInstalled;
                box.querySelector('[data-renderer-log]').textContent = s.log || '—';
                const action = (name) => box.querySelector(`[data-renderer-action="${name}"]`);
                if (action('start')) {
                    action('start').disabled = s.reachable;
                    action('stop').disabled = action('restart').disabled = !s.pid;
                }
            };
            const call = async (url, method = 'POST') => {
                const response = await fetch(url, { method, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
                const json = await response.json().catch(() => ({ error: `HTTP ${response.status}` }));
                if (json.url) paint(json);
                if (!response.ok) throw new Error(json.error || json.message || `HTTP ${response.status}`);

                return json;
            };
            box.querySelectorAll('[data-renderer-action]').forEach((button) => button.addEventListener('click', async () => {
                const buttons = box.querySelectorAll('[data-renderer-action]');
                buttons.forEach((b) => b.disabled = true);
                box.querySelector('[data-renderer-state]').textContent = t.working;
                try {
                    const action = button.dataset.rendererAction;
                    if (action === 'refresh') {
                        await call(box.dataset.status, 'GET');
                    } else {
                        if (action !== 'start') await call(box.dataset.stop);
                        const json = action === 'stop' ? await call(box.dataset.status, 'GET') : await call(box.dataset.start);
                        if (json.message) notify(json.message);
                    }
                } catch (error) {
                    box.querySelector('details').open = true;
                    notify(error.message, 'danger');
                } finally {
                    const s = await call(box.dataset.status, 'GET').catch(() => null);
                    if (!s) buttons.forEach((b) => b.disabled = false);
                }
            }));

            // Documentation: examples for the chosen template
            const docs = {{ Illuminate\Support\Js::from($docs) }};
            const apiUrl = @js($apiUrl);
            const apiKey = @js($keyForDocs);
            const select = document.getElementById('pt-doc-template');
            if (!select) return;
            let lang = 'curl';
            const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
            const code = (doc) => {
                const url = `${apiUrl}/${doc.slug}`;
                const body = JSON.stringify(doc.example, null, 2);
                const compact = JSON.stringify(doc.example);
                switch (lang) {
                    case 'php':
                        return `use Illuminate\\Support\\Facades\\Http;\n\n$response = Http::withHeaders(['X-API-KEY' => '${apiKey}'])\n    ->post('${url}', ${phpArray(doc.example, 1)});\n\nfile_put_contents('${doc.slug}.pdf', $response->body());\n\n// JSON with a download link instead of the file:\n// Http::withHeaders([...])->post('${url}?output=url', [...])->json('url');`;
                    case 'js':
                        return `const response = await fetch('${url}', {\n  method: 'POST',\n  headers: { 'X-API-KEY': '${apiKey}', 'Content-Type': 'application/json' },\n  body: JSON.stringify(${body.replace(/\n/g, '\n  ')}),\n});\nconst pdf = await response.blob(); // or ?output=url → (await response.json()).url`;
                    case 'python':
                        return `import requests\n\nresponse = requests.post(\n    "${url}",\n    headers={"X-API-KEY": "${apiKey}"},\n    json=${body.replace(/\n/g, '\n    ').replace(/\btrue\b/g, 'True').replace(/\bfalse\b/g, 'False').replace(/\bnull\b/g, 'None')},\n)\nopen("${doc.slug}.pdf", "wb").write(response.content)`;
                    default:
                        return `curl -X POST "${url}" \\\n  -H "X-API-KEY: ${apiKey}" \\\n  -H "Content-Type: application/json" \\\n  --data '${compact.replace(/'/g, "'\\''")}' \\\n  -o ${doc.slug}.pdf`;
                }
            };
            // A PHP array literal of the example data, for the Laravel snippet
            const phpArray = (value, depth) => {
                const pad = '    '.repeat(depth);
                if (Array.isArray(value)) return value.length ? `[\n${value.map((v) => pad + '    ' + phpArray(v, depth + 1)).join(',\n')},\n${pad}]` : '[]';
                if (value && typeof value === 'object') {
                    const entries = Object.entries(value);
                    return entries.length ? `[\n${entries.map(([k, v]) => `${pad}    '${k.replace(/'/g, "\\'")}' => ${phpArray(v, depth + 1)}`).join(',\n')},\n${pad}]` : '[]';
                }
                if (typeof value === 'string') return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

                return value === null ? 'null' : String(value);
            };
            const render = () => {
                const doc = docs[select.value];
                document.querySelectorAll('[data-doc-slug]').forEach((el) => el.textContent = doc.slug);
                document.querySelector('[data-doc-body]').textContent = JSON.stringify(doc.example, null, 2);
                document.querySelector('[data-doc-fields]').innerHTML = Object.entries(doc.fields).map(([key, type]) => `<tr><td><code>${esc(key)}</code></td><td class="pt-muted">${esc(type)}</td></tr>`).join('') || '<tr><td class="pt-muted">—</td></tr>';
                document.getElementById('pt-doc-code').textContent = code(doc);
            };
            document.querySelectorAll('[data-doc-lang]').forEach((button) => button.addEventListener('click', () => {
                lang = button.dataset.docLang;
                document.querySelectorAll('[data-doc-lang]').forEach((b) => b.setAttribute('aria-pressed', b === button));
                render();
            }));
            select.addEventListener('change', render);
            render();
        })();
    </script>
@endpush
