@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    <div class="pt" data-csrf="{{ csrf_token() }}">
        <x-pdf-templates::header :title="__('pdf-templates::ui.title') . ' (' . $templates->total() . ')'">
            <button type="button" class="pt-btn pt-btn-primary" id="pt-new">+ {{ __('pdf-templates::ui.new') }}</button>
        </x-pdf-templates::header>

        <div class="pt-card">
            <div class="pt-card-body">
                <p class="pt-muted">{{ __('pdf-templates::ui.intro') }}
                    <code>POST {{ url(config('pdf-templates.api.prefix', 'api/pdf-templates') . '/{slug}') }}</code></p>
                <form method="get" action="{{ $ptRoute('index') }}" class="pt-actions">
                    <input type="search" class="pt-input" name="q" value="{{ request('q') }}" placeholder="{{ __('pdf-templates::ui.search') }}…" style="max-width: 320px;" aria-label="{{ __('pdf-templates::ui.search') }}">
                    <select class="pt-select" name="mode" aria-label="{{ __('pdf-templates::ui.mode') }}">
                        <option value="">{{ __('pdf-templates::ui.all') }}</option>
                        @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                            <option value="{{ $mode }}" @selected(request('mode') === $mode)>{{ __('pdf-templates::ui.mode_' . $mode) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="pt-btn">{{ __('pdf-templates::ui.search') }}</button>
                    @if (request('q') || request('mode'))
                        <a class="pt-btn" href="{{ $ptRoute('index') }}">{{ __('pdf-templates::ui.reset') }}</a>
                    @endif
                </form>
            </div>
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
                                    <a href="{{ $ptRoute('edit', $template) }}"><strong>{{ $template->name }}</strong></a>
                                    @if ($template->description)
                                        <div class="pt-muted">{{ $template->description }}</div>
                                    @endif
                                </td>
                                <td><code>{{ $template->slug }}</code></td>
                                <td><span class="pt-badge pt-badge-{{ $template->mode }}">{{ __('pdf-templates::ui.mode_' . $template->mode) }}</span></td>
                                <td>
                                    <span class="pt-badge {{ $template->active ? 'pt-badge-on' : '' }}">
                                        {{ $template->active ? __('pdf-templates::ui.active') : __('pdf-templates::ui.inactive') }}
                                    </span>
                                </td>
                                <td class="pt-end">
                                    <a class="pt-btn" href="{{ $ptRoute('test', $template) }}">{{ __('pdf-templates::ui.test') }}</a>
                                    <a class="pt-btn" href="{{ $ptRoute('edit', $template) }}">{{ __('pdf-templates::ui.edit') }}</a>
                                    <form method="post" action="{{ $ptRoute('duplicate', $template) }}" style="display: inline;">
                                        @csrf
                                        <button type="submit" class="pt-btn">{{ __('pdf-templates::ui.duplicate') }}</button>
                                    </form>
                                    <form method="post" action="{{ $ptRoute('destroy', $template) }}" style="display: inline;"
                                        onsubmit="return confirm(@js(__('pdf-templates::ui.delete_confirm', ['name' => $template->name])));">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="pt-btn pt-btn-danger">{{ __('pdf-templates::ui.delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="pt-muted">{{ __('pdf-templates::ui.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($templates->hasPages())
                <div class="pt-card-body pt-actions">
                    @if (!$templates->onFirstPage())
                        <a class="pt-btn" href="{{ $templates->previousPageUrl() }}">&larr;</a>
                    @endif
                    <span class="pt-muted">{{ $templates->currentPage() }} / {{ $templates->lastPage() }}</span>
                    @if ($templates->hasMorePages())
                        <a class="pt-btn" href="{{ $templates->nextPageUrl() }}">&rarr;</a>
                    @endif
                </div>
            @endif
        </div>

        <dialog class="pt-dialog" id="pt-new-dialog" style="width: min(520px, 96vw);">
            <form method="post" action="{{ $ptRoute('store') }}" class="pt-card-body">
                @csrf
                <h2 style="margin-bottom: 12px;">{{ __('pdf-templates::ui.new') }}</h2>
                <label class="pt-label" for="pt-name">{{ __('pdf-templates::ui.name') }}</label>
                <input type="text" class="pt-input" id="pt-name" name="name" required style="margin-bottom: 12px;">

                <span class="pt-label">{{ __('pdf-templates::ui.mode') }}</span>
                @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::MODES as $mode)
                    <label class="pt-radio">
                        <input type="radio" name="mode" value="{{ $mode }}" @checked($loop->first)>
                        <span><strong>{{ __('pdf-templates::ui.mode_' . $mode) }}</strong> — {{ __('pdf-templates::ui.mode_' . $mode . '_hint') }}</span>
                    </label>
                @endforeach

                <label class="pt-label" for="pt-description" style="margin-top: 12px;">{{ __('pdf-templates::ui.description') }}</label>
                <input type="text" class="pt-input" id="pt-description" name="description">

                <div class="pt-actions" style="justify-content: flex-end; margin-top: 16px;">
                    <button type="button" class="pt-btn" onclick="this.closest('dialog').close()">{{ __('pdf-templates::ui.cancel') }}</button>
                    <button type="submit" class="pt-btn pt-btn-primary">{{ __('pdf-templates::ui.create') }}</button>
                </div>
            </form>
        </dialog>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('pt-new').addEventListener('click', () => document.getElementById('pt-new-dialog').showModal());
    </script>
@endpush
