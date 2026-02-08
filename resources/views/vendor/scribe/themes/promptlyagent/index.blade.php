<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{{ $metadata['title'] ?? 'API Documentation' }}</title>

    <!-- Inter Font (matching main application) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset("/scribe-theme/css/theme-promptlyagent.style.css") }}" media="screen">
    <link rel="stylesheet" href="{{ asset("/scribe-theme/css/theme-promptlyagent-custom.css") }}" media="screen">
    <link rel="stylesheet" href="{{ asset("/scribe-theme/css/theme-promptlyagent.print.css") }}" media="print">

    <script src="https://cdn.jsdelivr.net/npm/lodash@4.17.10/lodash.min.js"></script>

    <link rel="stylesheet"
          href="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/styles/obsidian.min.css">
    <script src="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/highlight.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jets/0.14.1/jets.min.js"></script>

@if(isset($metadata['example_languages']))
    <style id="language-style">
        /* starts out as display none and is replaced with js later  */
        @foreach($metadata['example_languages'] as $lang)
            body .content .{{ $lang }}-example code { display: none; }
        @endforeach
    </style>
@endif

    <script>
        var tryItOutBaseUrl = "{{ rtrim($metadata['try_it_out']['base_url'] ?? $metadata['base_url'] ?? '', '/') }}";
        var useCsrf = Boolean({{ $metadata['try_it_out']['use_csrf'] ?? 'false' }});
        var csrfUrl = "{{ $metadata['try_it_out']['csrf_url'] ?? '/sanctum/csrf-cookie' }}";
    </script>
    <script src="{{ asset("/vendor/scribe/js/tryitout-5.6.0.js") }}"></script>

    <script src="{{ asset("/scribe-theme/js/theme-default-5.6.0.js") }}"></script>

    <!-- PromptlyAgent custom styling loaded from external CSS -->
</head>

<body data-languages="{{ json_encode($metadata['example_languages'] ?? []) }}" class="antialiased">

<a href="#" id="nav-button">
    <span>
        MENU
        <img src="{{ asset("/vendor/scribe/images/navbar.png") }}" alt="navbar-image"/>
    </span>
</a>
<div class="tocify-wrapper">
    @include("scribe::themes.promptlyagent.sidebar")

    <ul class="toc-footer" id="last-updated">
        <li>{{ $metadata['last_updated'] ?? '' }}</li>
    </ul>
</div>

<div class="page-wrapper">
    <div class="dark-box"></div>
    <div class="content">
        @if(isset($intro) && $intro)
            {!! $intro !!}
        @endif

        @if(isset($auth) && $auth)
            {!! $auth !!}
        @endif

        @include("scribe::themes.promptlyagent.groups")

        <div class="lang-selector">
            @foreach(($metadata['example_languages'] ?? []) as $lang)
                <button type="button" class="lang-button" data-language-name="{{ $lang }}">{{ $lang }}</button>
            @endforeach
        </div>
    </div>
</div>
</body>
</html>
