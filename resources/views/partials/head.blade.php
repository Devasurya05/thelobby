{{-- Shared <head> parts: icons, fonts, stylesheet. Used by the main layout and the standalone error pages. --}}
<meta name="theme-color" content="#f3efe6">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
{{-- Fonts come from Google Fonts. If they cannot load (venue Wi-Fi), the page falls back to Georgia and Segoe UI. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,400..600&family=Source+Sans+3:wght@400..700&display=swap">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
