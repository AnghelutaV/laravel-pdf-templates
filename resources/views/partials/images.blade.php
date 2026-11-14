{{-- Image library section of the editor menu; wired by imageLibrary() from images.js --}}
<section class="pt-drawer-section" id="pt-images" data-store="{{ $ptRoute('images.store') }}">
    <h3>{{ __('pdf-templates::ui.images') }}</h3>
    <label class="pt-btn pt-btn-block pt-images-drop" id="pt-images-drop">
        <x-pdf-templates::icon name="upload" :size="15" /> {{ __('pdf-templates::ui.images_upload') }}
        <input type="file" id="pt-images-input" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml" multiple hidden>
    </label>
    <div class="pt-images" id="pt-images-grid" aria-live="polite"></div>
    @if ($importExternal ?? false)
        {{-- HTML mode: images loaded from the internet (dompdf does not fetch them) → library images --}}
        <button type="button" class="pt-btn pt-btn-block" id="pt-images-import" data-url="{{ $ptRoute('images.import') }}" style="margin-top: 8px;">
            <x-pdf-templates::icon name="download" :size="15" /> {{ __('pdf-templates::ui.images_import') }}
        </button>
    @endif
    <p class="pt-muted pt-hint">{!! __('pdf-templates::ui.images_hint') !!}</p>
</section>
