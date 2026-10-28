{{--
    <x-pdf-templates::select name="pdf_template" :value="old('pdf_template', $quote->pdf_template)" mode="html" placeholder="—" class="form-select" />
    Active templates only; any extra attribute (class, id, required, wire:model…) goes to the <select>.
--}}
@props(['name', 'value' => null, 'mode' => null, 'placeholder' => null])
<select name="{{ $name }}" {{ $attributes }}>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach (app(\AnghelutaV\PdfTemplates\PdfTemplates::class)->options($mode) as $slug => $label)
        <option value="{{ $slug }}" @selected((string) $value === (string) $slug)>{{ $label }}</option>
    @endforeach
</select>
