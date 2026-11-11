<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | @yield('brand', 'Demo booking sites')</title>
    <meta name="description" content="@yield('description', 'A pretend booking site used to show how The Lobby connects to booking sites. Not a real booking.')">
    <meta name="robots" content="noindex">
    @include('partials.head')
</head>
<body>
    <div class="demo-strip">
        <div class="wrap">Demo site, not a real booking site. It stands in for a hotel or airline website so you can see how bookings connect to <a href="{{ url('/') }}">The Lobby</a>.</div>
    </div>

    <header class="site-header">
        <div class="wrap">
            <a class="brand" href="{{ route('demo.index') }}">@yield('brand', 'Demo booking sites')</a>
            <nav class="nav" aria-label="Demo sites">
                <a href="{{ route('demo.stay.create') }}">DemoStay</a>
                <a href="{{ route('demo.fly.create') }}">DemoFly</a>
                <a href="{{ url('/') }}">The Lobby</a>
            </nav>
        </div>
    </header>

    <main class="wrap">
        @if (session('status'))
            <div class="flash" role="status">{{ session('status') }}</div>
        @endif
        @if (session('problem'))
            <div class="flash error" role="alert">{{ session('problem') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error" role="alert">Some details need fixing. The problems are marked below.</div>
        @endif

        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap">
            <span>&copy; {{ date('Y') }} Demo booking sites (pretend)</span>
            <nav aria-label="Footer">
                <a href="{{ route('demo.index') }}">Demo home</a>
                <a href="{{ url('/') }}">The Lobby</a>
            </nav>
        </div>
    </footer>
    <script src="{{ asset('js/confirm.js') }}"></script>
</body>
</html>
