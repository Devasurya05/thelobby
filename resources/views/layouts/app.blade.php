<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- CSRF token for AJAX calls (jQuery will send it in a header). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'The Lobby')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a href="{{ route('home') }}">The Lobby</a>
            @yield('header_right')
        </div>
    </header>

    <main class="wrap">
        {{-- {{ }} escapes HTML automatically, so user text can never inject script (XSS). --}}
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
