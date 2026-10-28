@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <!-- Header Principal -->
        <!-- Folosim componenta de Header dedicată -->
        <x-pdf-templates::header :title="__('pdf-templates::ui.title') . ' (' . $templates->total() . ')'">
            <button type="button" class="pt-btn pt-btn-primary" id="pt-new">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                {{ __('pdf-templates::ui.new') }}
            </button>
        </x-pdf-templates::header>

        <!-- Dacă componenta ta include deja afișarea alertelor de sesiune și erori, le poți lăsa acolo sau le poți păstra aici dacă nu le are în interior -->
        <div class="pt-bar">


            <!-- Card Filtre și Acțiuni -->
            <div class="pt-card">
                <div class="pt-card-body">
                    <form method="get" action="{{ $ptRoute('index') }}" class="pt-actions"
                        style="justify-content: space-between;">
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; flex: 1; max-width: 600px;">
                            <input type="search" class="pt-input" name="q" value="{{ request('q') }}"
                                placeholder="{{ __('pdf-templates::ui.search') }}…" style="flex: 1; min-width: 220px;"
                                aria-label="{{ __('pdf-templates::ui.search') }}">
                            <select class="pt-select" name="mode" aria-label="{{ __('pdf-templates::ui.mode') }}">
                                <option value="">{{ __('pdf-templates::ui.all') }}</option>
                                @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                                    <option value="{{ $mode }}" @selected(request('mode') === $mode)>
                                        {{ __('pdf-templates::ui.mode_' . $mode) }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="pt-btn">{{ __('pdf-templates::ui.search') }}</button>
                            @if (request('q') || request('mode'))
                                <a class="pt-btn" href="{{ $ptRoute('index') }}">{{ __('pdf-templates::ui.reset') }}</a>
                            @endif
                        </div>
                        <div class="pt-muted" style="font-size: 12px;">
                            <code>POST
                                {{ url(config('pdf-templates.api.prefix', 'api/pdf-templates') . '/{slug}') }}</code>
                        </div>
                    </form>
                </div>

                <!-- Tabel de date modernizat -->
                <div class="pt-table-wrap">
                    <table class="pt-table">
                        <thead>
                            <tr>
                                <th>{{ __('pdf-templates::ui.name') }}</th>
                                <th>{{ __('pdf-templates::ui.slug') }}</th>
                                <th>{{ __('pdf-templates::ui.mode') }}</th>
                                <th>{{ __('pdf-templates::ui.status') }}</th>
                                <th class="pt-end">{{ __('pdf-templates::ui.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($templates as $template)
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;">
                                            <a href="{{ $ptRoute('edit', $template) }}">{{ $template->name }}</a>
                                        </div>
                                        @if ($template->description)
                                            <div class="pt-muted" style="margin-top: 2px;">{{ $template->description }}
                                            </div>
                                        @endif
                                    </td>
                                    <td><code>{{ $template->slug }}</code></td>
                                    <td><span
                                            class="pt-badge pt-badge-{{ $template->mode }}">{{ __('pdf-templates::ui.mode_' . $template->mode) }}</span>
                                    </td>
                                    <td>
                                        <span class="pt-badge {{ $template->active ? 'pt-badge-on' : '' }}">
                                            {{ $template->active ? __('pdf-templates::ui.active') : __('pdf-templates::ui.inactive') }}
                                        </span>
                                    </td>
                                    <td class="pt-end">
                                        <div style="display: inline-flex; gap: 4px;">
                                            <a class="pt-btn"
                                                href="{{ $ptRoute('test', $template) }}">{{ __('pdf-templates::ui.test') }}</a>
                                            <a class="pt-btn"
                                                href="{{ $ptRoute('edit', $template) }}">{{ __('pdf-templates::ui.edit') }}</a>
                                            <form method="post" action="{{ $ptRoute('duplicate', $template) }}"
                                                style="display: inline;">
                                                @csrf
                                                <button type="submit"
                                                    class="pt-btn">{{ __('pdf-templates::ui.duplicate') }}</button>
                                            </form>
                                            <form method="post" action="{{ $ptRoute('destroy', $template) }}"
                                                style="display: inline;"
                                                onsubmit="return confirm(@js(__('pdf-templates::ui.delete_confirm', ['name' => $template->name])));">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                    class="pt-btn pt-btn-danger">{{ __('pdf-templates::ui.delete') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="pt-muted" style="text-align: center; padding: 32px;">
                                        {{ __('pdf-templates::ui.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Paginare curată -->
                @if ($templates->hasPages())
                    <div class="pt-card-body pt-actions"
                        style="border-top: 1px solid var(--pt-border); justify-content: space-between; padding: 10px 14px;">
                        <span class="pt-muted">{{ __('Pagina') }} {{ $templates->currentPage() }} din
                            {{ $templates->lastPage() }}</span>
                        <div style="display: flex; gap: 4px;">
                            @if (!$templates->onFirstPage())
                                <a class="pt-btn" href="{{ $templates->previousPageUrl() }}">&larr; Anterior</a>
                            @endif
                            @if ($templates->hasMorePages())
                                <a class="pt-btn" href="{{ $templates->nextPageUrl() }}">Următor &rarr;</a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <!-- Dialog Modal Nou -->
            <dialog class="pt-dialog" id="pt-new-dialog">
                <form method="post" action="{{ $ptRoute('store') }}" class="pt-card-body" style="padding: 24px;">
                    @csrf
                    <h2 style="margin-bottom: 16px; font-size: 18px;">{{ __('pdf-templates::ui.new') }}</h2>

                    <label class="pt-label" for="pt-name">{{ __('pdf-templates::ui.name') }}</label>
                    <input type="text" class="pt-input" id="pt-name" name="name" required
                        style="margin-bottom: 16px;" placeholder="Ex: Factură fiscală">

                    <span class="pt-label" style="margin-bottom: 8px;">{{ __('pdf-templates::ui.mode') }}</span>
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                        @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                            <label class="pt-radio"
                                style="padding: 10px; border: 1px solid var(--pt-border); border-radius: var(--pt-radius); background: var(--pt-code-bg);">
                                <input type="radio" name="mode" value="{{ $mode }}"
                                    @checked($loop->first)>
                                <div>
                                    <strong
                                        style="display: block; margin-bottom: 2px;">{{ __('pdf-templates::ui.mode_' . $mode) }}</strong>
                                    <span class="pt-muted"
                                        style="font-size: 12px;">{{ __('pdf-templates::ui.mode_' . $mode . '_hint') }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <label class="pt-label" for="pt-description">{{ __('pdf-templates::ui.description') }}</label>
                    <input type="text" class="pt-input" id="pt-description" name="description"
                        placeholder="O scurtă descriere a șablonului...">

                    <div class="pt-actions" style="justify-content: flex-end; margin-top: 24px; gap: 8px;">
                        <button type="button" class="pt-btn"
                            onclick="this.closest('dialog').close()">{{ __('pdf-templates::ui.cancel') }}</button>
                        <button type="submit"
                            class="pt-btn pt-btn-primary">{{ __('pdf-templates::ui.create') }}</button>
                    </div>
                </form>
            </dialog>
        </div>
    @endsection

    @push('scripts')
        <script>
            document.getElementById('pt-new').addEventListener('click', () => document.getElementById('pt-new-dialog')
                .showModal());
        </script>
    @endpush
