<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>{{ $tr->title }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.6;
        }

        h1 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        h2 {
            font-size: 14px;
            margin-top: 24px;
        }

        .muted {
            color: #6b655d;
            font-size: 11px;
        }

        .signature {
            margin-bottom: 10px;
        }
    </style>
</head>

<body>

    <h1>{{ $tr->title }}</h1>
    <div class="muted">
        {{ __('pet.url') }} {{ url("/{$locale}/petition/{$tr->slug}/{$petition->id}") }}<br>
        {{ __('pet.total_signatures') }} {{ $petition->signature_count }}
    </div>

    <h2>{{ __('pet.pdf_description') }}</h2>
    {!! $tr->description !!}

    <h2>{{ __('pet.pdf_latest') }}</h2>

    @if ($isCapped ?? false)
        <p class="muted">{{ __('pet.pdf_capped') }}</p>
    @endif

    @forelse($signatures as $sig)
        <div class="signature">
            <strong>{{ $sig->display_name ?? __('pet.anonymous') }}</strong>
            <span class="muted">
                ({{ optional($sig->created_at)->format('Y-m-d') }})
            </span><br>
            {{ $sig->text ?? __('pet.default_comment') }}
        </div>
    @empty
        <p>{{ __('pet.no_signatures') }}</p>
    @endforelse

</body>

</html>
