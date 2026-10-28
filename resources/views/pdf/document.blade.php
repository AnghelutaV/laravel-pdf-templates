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
        {!! $template->css !!}
    </style>
</head>
<body>
    {!! $body !!}
</body>
</html>
