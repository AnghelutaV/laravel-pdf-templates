@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php($filtered = request('q') || request('mode'))
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <x-pdf-templates::header :title="__('pdf-templates::ui.title')" :count="$templates->total()">
            {{-- Default here too: a config published before this key existed has no ui.repository (explicit null hides it) --}}
            @if ($repo = config('pdf-templates.ui.repository', 'https://github.com/AnghelutaV/laravel-pdf-templates'))
                <div class="pt-btn-group">
                    <a class="pt-btn pt-btn-icon-text" href="{{ $repo }}" target="_blank" rel="noopener"
                        data-pt-tip="{{ __('pdf-templates::ui.star_on_github') }}" aria-label="GitHub">
                        <x-pdf-templates::icon name="github" :size="16" />
                        <span class="pt-stars pt-hidden" data-pt-stars="{{ $repo }}"><x-pdf-templates::icon name="star" :size="12" /> <span></span></span>
                    </a>
                    <a class="pt-btn pt-btn-icon-text" href="{{ $repo }}#readme" target="_blank" rel="noopener"
                        data-pt-tip="{{ __('pdf-templates::ui.docs') }}" aria-label="{{ __('pdf-templates::ui.docs') }}">
                        <x-pdf-templates::icon name="book" :size="15" />
                    </a>
                </div>
            @endif
            <button type="button" class="pt-btn pt-btn-primary" id="pt-new">
                <x-pdf-templates::icon name="plus" />
                {{ __('pdf-templates::ui.new') }}
            </button>
        </x-pdf-templates::header>

        <div class="pt-card">
            <form method="get" action="{{ $ptRoute('index') }}" class="pt-card-head">
                <div class="pt-actions" style="flex: 1; max-width: 640px;">
                    <label class="pt-input-icon">
                        <x-pdf-templates::icon name="search" />
                        <input type="search" class="pt-input" name="q" value="{{ request('q') }}"
                            placeholder="{{ __('pdf-templates::ui.search_placeholder') }}"
                            aria-label="{{ __('pdf-templates::ui.search') }}">
                    </label>
                    <select class="pt-select" name="mode" aria-label="{{ __('pdf-templates::ui.mode') }}"
                        onchange="this.form.requestSubmit()">
                        <option value="">{{ __('pdf-templates::ui.all') }}</option>
                        @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                            <option value="{{ $mode }}" @selected(request('mode') === $mode)>{{ __('pdf-templates::ui.mode_' . $mode) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="pt-btn">{{ __('pdf-templates::ui.search') }}</button>
                    @if ($filtered)
                        <a class="pt-btn pt-btn-ghost" href="{{ $ptRoute('index') }}">
                            <x-pdf-templates::icon name="x" :size="14" /> {{ __('pdf-templates::ui.reset') }}
                        </a>
                    @endif
                </div>
                <span class="pt-endpoint" data-pt-tip="API">
                    <span class="pt-method">POST</span>
                    <code>{{ url(config('pdf-templates.api.prefix', 'api/pdf-templates') . '/{slug}') }}</code>
                </span>
            </form>

            @if ($templates->isEmpty())
                <div class="pt-empty">
                    <div class="pt-empty-icon"><x-pdf-templates::icon name="file" :size="22" /></div>
                    <h2>{{ $filtered ? __('pdf-templates::ui.no_results') : __('pdf-templates::ui.empty') }}</h2>
                    <p class="pt-muted">{{ $filtered ? __('pdf-templates::ui.no_results_hint') : __('pdf-templates::ui.empty_hint') }}</p>
                    @if ($filtered)
                        <a class="pt-btn" href="{{ $ptRoute('index') }}">{{ __('pdf-templates::ui.reset') }}</a>
                    @else
                        <button type="button" class="pt-btn pt-btn-primary" data-pt-new>
                            <x-pdf-templates::icon name="plus" /> {{ __('pdf-templates::ui.new') }}
                        </button>
                    @endif
                </div>
            @else
                <div class="pt-table-wrap">
                    <table class="pt-table">
                        <thead>
                            <tr>
                                <th class="pt-col-id">ID</th>
                                <th>{{ __('pdf-templates::ui.name') }}</th>
                                <th>{{ __('pdf-templates::ui.slug') }}</th>
                                <th>{{ __('pdf-templates::ui.mode') }}</th>
                                <th>{{ __('pdf-templates::ui.status') }}</th>
                                <th class="pt-end"><span class="pt-hidden">{{ __('pdf-templates::ui.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($templates as $template)
                                <tr>
                                    <td class="pt-col-id"><span class="pt-id">#{{ $template->id }}</span></td>
                                    <td>
                                        <div class="pt-name">
                                            <span class="pt-name-icon pt-mode-{{ $template->mode }}">
                                                <x-pdf-templates::icon :name="$template->mode === 'html' ? 'code' : 'layout'" :size="18" />
                                            </span>
                                            <div style="min-width: 0;">
                                                <a href="{{ $ptRoute('edit', $template) }}">{{ $template->name }}</a>
                                                @if ($template->description)
                                                    <div class="pt-muted">{{ $template->description }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td><code>{{ $template->slug }}</code></td>
                                    <td>
                                        <span class="pt-badge pt-badge-{{ $template->mode }}">{{ __('pdf-templates::ui.mode_' . $template->mode) }}</span>
                                    </td>
                                    <td>
                                        <span class="pt-badge pt-dot {{ $template->active ? 'pt-badge-on' : '' }}">
                                            {{ $template->active ? __('pdf-templates::ui.active') : __('pdf-templates::ui.inactive') }}
                                        </span>
                                    </td>
                                    <td class="pt-end">
                                        <div class="pt-row-actions">
                                            <a class="pt-btn pt-btn-sm" href="{{ $ptRoute('test', $template) }}">
                                                <x-pdf-templates::icon name="flask" :size="14" /> {{ __('pdf-templates::ui.test') }}
                                            </a>
                                            <a class="pt-btn pt-btn-sm pt-btn-icon" href="{{ $ptRoute('edit', $template) }}"
                                                data-pt-tip="{{ __('pdf-templates::ui.edit') }}" aria-label="{{ __('pdf-templates::ui.edit') }}">
                                                <x-pdf-templates::icon name="pencil" :size="14" />
                                            </a>
                                            <form method="post" action="{{ $ptRoute('duplicate', $template) }}">
                                                @csrf
                                                <button type="submit" class="pt-btn pt-btn-sm pt-btn-icon"
                                                    data-pt-tip="{{ __('pdf-templates::ui.duplicate') }}" aria-label="{{ __('pdf-templates::ui.duplicate') }}">
                                                    <x-pdf-templates::icon name="copy" :size="14" />
                                                </button>
                                            </form>
                                            <form method="post" action="{{ $ptRoute('destroy', $template) }}"
                                                onsubmit="return confirm(@js(__('pdf-templates::ui.delete_confirm', ['name' => $template->name])));">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="pt-btn pt-btn-sm pt-btn-icon pt-btn-danger"
                                                    data-pt-tip="{{ __('pdf-templates::ui.delete') }}" aria-label="{{ __('pdf-templates::ui.delete') }}">
                                                    <x-pdf-templates::icon name="trash" :size="14" />
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pt-card-foot">
                    <span class="pt-muted">
                        {{ __('pdf-templates::ui.showing', ['from' => $templates->firstItem(), 'to' => $templates->lastItem(), 'total' => $templates->total()]) }}
                    </span>
                    @if ($templates->hasPages())
                        <div class="pt-actions">
                            <span class="pt-muted">{{ __('pdf-templates::ui.page_of', ['current' => $templates->currentPage(), 'last' => $templates->lastPage()]) }}</span>
                            <a class="pt-btn pt-btn-sm pt-btn-icon" href="{{ $templates->previousPageUrl() ?? '#' }}"
                                @if ($templates->onFirstPage()) aria-disabled="true" style="pointer-events: none; opacity: .5;" @endif
                                data-pt-tip="{{ __('pdf-templates::ui.previous') }}" aria-label="{{ __('pdf-templates::ui.previous') }}">
                                <x-pdf-templates::icon name="chevron-left" :size="14" />
                            </a>
                            <a class="pt-btn pt-btn-sm pt-btn-icon" href="{{ $templates->nextPageUrl() ?? '#' }}"
                                @unless ($templates->hasMorePages()) aria-disabled="true" style="pointer-events: none; opacity: .5;" @endunless
                                data-pt-tip="{{ __('pdf-templates::ui.next') }}" aria-label="{{ __('pdf-templates::ui.next') }}">
                                <x-pdf-templates::icon name="chevron-right" :size="14" />
                            </a>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <footer class="pt-footer">
            <span>laravel-pdf-templates</span>
            @if ($version)
                @if ($version['url'])
                    <a href="{{ $version['url'] }}" target="_blank" rel="noopener"
                        data-pt-tip="{{ __('pdf-templates::ui.installed_version') }}"><code>{{ $version['label'] }}</code></a>
                    · <a href="{{ $version['changelog'] }}" target="_blank" rel="noopener">{{ __('pdf-templates::ui.changelog') }}</a>
                @else
                    <code data-pt-tip="{{ __('pdf-templates::ui.installed_version') }}">{{ $version['label'] }}</code>
                @endif
            @endif
        </footer>

        <dialog class="pt-dialog pt-dialog-sm" id="pt-new-dialog" @if ($aiEnabled && $errors->ai->has('message')) data-open @endif>
            <form method="post" action="{{ $ptRoute('store') }}" enctype="multipart/form-data" id="pt-new-form">
                @csrf
                <div class="pt-dialog-body">
                    <h2>{{ __('pdf-templates::ui.new') }}</h2>
                    <p class="pt-muted" style="margin-bottom: 20px;">{{ __('pdf-templates::ui.new_hint') }}</p>

                    <div class="pt-field">
                        <label class="pt-label" for="pt-name">{{ __('pdf-templates::ui.name') }}</label>
                        <input type="text" class="pt-input" id="pt-name" name="name" required autofocus value="{{ old('name') }}"
                            placeholder="{{ __('pdf-templates::ui.name_placeholder') }}">
                    </div>

                    <div class="pt-field">
                        <span class="pt-label">{{ __('pdf-templates::ui.mode') }}</span>
                        <div class="pt-choices">
                            @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                                <label class="pt-choice">
                                    <input type="radio" name="mode" value="{{ $mode }}" @checked(old('mode') ? old('mode') === $mode : $loop->first)>
                                    <span class="pt-choice-icon">
                                        <x-pdf-templates::icon :name="$mode === 'html' ? 'code' : 'layout'" :size="18" />
                                    </span>
                                    <span>
                                        <strong>{{ __('pdf-templates::ui.mode_' . $mode) }}</strong>
                                        <span class="pt-muted">{{ __('pdf-templates::ui.mode_' . $mode . '_hint') }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="pt-label" for="pt-description">
                            {{ __('pdf-templates::ui.description') }} <span class="pt-muted">({{ __('pdf-templates::ui.optional') }})</span>
                        </label>
                        <input type="text" class="pt-input" id="pt-description" name="description" value="{{ old('description') }}"
                            placeholder="{{ __('pdf-templates::ui.description_placeholder') }}">
                    </div>

                    @if ($aiEnabled)
                        {{-- Optional: describe it (and/or attach an example) → the AI writes the first version --}}
                        <div class="pt-ai-new">
                            <label class="pt-label" for="pt-ai-message">
                                <x-pdf-templates::icon name="sparkles" :size="14" /> {{ __('pdf-templates::ui.ai_describe') }}
                                <span class="pt-muted">({{ __('pdf-templates::ui.optional') }})</span>
                            </label>
                            @error('message', 'ai')
                                <div class="pt-alert pt-alert-danger" role="alert">{{ $message }}</div>
                            @enderror
                            <textarea class="pt-textarea" id="pt-ai-message" name="message" rows="3"
                                placeholder="{{ __('pdf-templates::ui.ai_describe_placeholder') }}">{{ old('message') }}</textarea>
                            <label class="pt-ai-new-file pt-muted">
                                <x-pdf-templates::icon name="paperclip" :size="13" /> {{ __('pdf-templates::ui.ai_attach') }}
                                <input type="file" name="file" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf">
                            </label>
                        </div>
                    @endif
                </div>
                <div class="pt-dialog-foot">
                    <button type="button" class="pt-btn" onclick="this.closest('dialog').close()">{{ __('pdf-templates::ui.cancel') }}</button>
                    @if ($aiEnabled)
                        <button type="submit" class="pt-btn" id="pt-ai-generate" formaction="{{ $ptRoute('assistant.create') }}">
                            <x-pdf-templates::icon name="sparkles" :size="14" /> <span>{{ __('pdf-templates::ui.ai_generate') }}</span>
                        </button>
                    @endif
                    <button type="submit" class="pt-btn pt-btn-primary">{{ __('pdf-templates::ui.create') }}</button>
                </div>
            </form>
        </dialog>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('#pt-new, [data-pt-new]').forEach((button) => button.addEventListener('click',
            () => document.getElementById('pt-new-dialog').showModal()));
        if (document.getElementById('pt-new-dialog').hasAttribute('data-open')) document.getElementById('pt-new-dialog').showModal();

        // "Generate with AI" needs a description; then it takes a while (the model writes the whole template)
        document.getElementById('pt-new-form').addEventListener('submit', (e) => {
            const generate = document.getElementById('pt-ai-generate');
            if (!generate || e.submitter !== generate) return;
            const message = document.getElementById('pt-ai-message');
            if (!message.value.trim()) {
                e.preventDefault();
                message.required = true;
                message.reportValidity();
                message.required = false;
                return;
            }
            setTimeout(() => {
                e.target.querySelectorAll('button').forEach((b) => b.disabled = true);
                generate.classList.add('pt-ai-busy');
                generate.querySelector('span').textContent = @json(__('pdf-templates::ui.ai_generating'));
            });
        });

        // GitHub star count: public API from the browser, cached 6 h in localStorage; silent when offline / rate-limited
        (() => {
            const badge = document.querySelector('[data-pt-stars]');
            const repo = badge && new URL(badge.dataset.ptStars).pathname.replace(/^\/|\/$/g, '');
            if (!repo || !/^[\w.-]+\/[\w.-]+$/.test(repo)) return;
            const key = 'pt-stars:' + repo;
            const show = (count) => {
                badge.lastElementChild.textContent = Intl.NumberFormat(undefined, { notation: 'compact' }).format(count);
                badge.classList.remove('pt-hidden');
            };
            let cached = null;
            try { cached = JSON.parse(localStorage.getItem(key)); } catch {}
            if (cached && Date.now() - cached.at < 6 * 3600e3) return show(cached.count);

            fetch('https://api.github.com/repos/' + repo, { headers: { Accept: 'application/vnd.github+json' } })
                .then((response) => response.ok ? response.json() : null)
                .then((json) => {
                    if (!json || typeof json.stargazers_count !== 'number') return cached && show(cached.count);
                    try { localStorage.setItem(key, JSON.stringify({ count: json.stargazers_count, at: Date.now() })); } catch {}
                    show(json.stargazers_count);
                })
                .catch(() => cached && show(cached.count));
        })();
    </script>
@endpush
