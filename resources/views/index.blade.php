@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php($filtered = request('q') || request('mode'))
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <x-pdf-templates::header :title="__('pdf-templates::ui.title')" :count="$templates->total()">
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

        <dialog class="pt-dialog pt-dialog-sm" id="pt-new-dialog">
            <form method="post" action="{{ $ptRoute('store') }}">
                @csrf
                <div class="pt-dialog-body">
                    <h2>{{ __('pdf-templates::ui.new') }}</h2>
                    <p class="pt-muted" style="margin-bottom: 20px;">{{ __('pdf-templates::ui.new_hint') }}</p>

                    <div class="pt-field">
                        <label class="pt-label" for="pt-name">{{ __('pdf-templates::ui.name') }}</label>
                        <input type="text" class="pt-input" id="pt-name" name="name" required autofocus
                            placeholder="{{ __('pdf-templates::ui.name_placeholder') }}">
                    </div>

                    <div class="pt-field">
                        <span class="pt-label">{{ __('pdf-templates::ui.mode') }}</span>
                        <div class="pt-choices">
                            @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                                <label class="pt-choice">
                                    <input type="radio" name="mode" value="{{ $mode }}" @checked($loop->first)>
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
                        <input type="text" class="pt-input" id="pt-description" name="description"
                            placeholder="{{ __('pdf-templates::ui.description_placeholder') }}">
                    </div>
                </div>
                <div class="pt-dialog-foot">
                    <button type="button" class="pt-btn" onclick="this.closest('dialog').close()">{{ __('pdf-templates::ui.cancel') }}</button>
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
    </script>
@endpush
