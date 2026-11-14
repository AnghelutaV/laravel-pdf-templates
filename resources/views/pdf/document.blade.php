{{-- HTML mode document rendered by dompdf. $fonts: family => [[file, weight, style], ...] from config --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $template->name }}</title>
    <style>
        @foreach ($fonts as $family => $files)
            @foreach ($files as $font)
                @font-face { font-family: "{{ $family }}"; font-weight: {{ $font[1] ?? 'normal' }}; font-style: {{ $font[2] ?? 'normal' }}; src: url("{{ str_replace('\\', '/', $font[0]) }}") format("truetype"); }
            @endforeach
        @endforeach

        @page { margin: 15mm; }
        body { font-family: "{{ $defaultFont }}", sans-serif; font-size: 10pt; }
        {{-- A header cell is centred, unless its table / section / row sets an alignment (Table properties → Text align) --}}
        table[style*="text-align"] th, thead[style*="text-align"] th, tbody[style*="text-align"] th, tr[style*="text-align"] th,
        table[align] th, tr[align] th { text-align: inherit; }
        {{-- Images from the visual editor (CKEditor): a block image is centred, a resized one fills its width --}}
        figure.image { margin: 0.9em auto; text-align: center; }
        figure.image img { display: block; margin: 0 auto; max-width: 100%; }
        figure.image_resized img { width: 100%; height: auto; }
        {!! $template->css !!}
        {{-- Editor menu: margins (win over the template's @page), header / footer, word break --}}
        {!! $setupCss ?? '' !!}
    </style>
</head>
<body>
    {!! $setupHtml ?? '' !!}
    {!! $body !!}
</body>
</html>
