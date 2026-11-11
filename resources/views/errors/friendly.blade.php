{{--
    Friendly error page shown to normal users. It deliberately does NOT extend layouts/app:
    on some errors (an unknown URL, for example) the session never started, and the main layout
    asks for a CSRF token, which would fail and turn one error into two. Standalone = always works.
    Real error details go to the log, never to this page.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | The Lobby</title>
    <meta name="description" content="@yield('title'). Go back to the start of The Lobby.">
    <meta name="robots" content="noindex">
    @include('partials.head')
</head>
<body>
    <header class="site-header">
        <div class="wrap">
            <a class="brand" href="{{ url('/') }}">The Lobby</a>
        </div>
    </header>
    <main class="wrap">
        <div class="page-head">
            <h1>@yield('title')</h1>
            <p class="measure muted">@yield('message')</p>
            <div class="actions"><a class="btn" href="{{ url('/') }}">Back to the start</a></div>
        </div>
    </main>
    <footer class="site-footer">
        <div class="wrap">
            <span>&copy; {{ date('Y') }} The Lobby</span>
            <nav aria-label="Footer">
                <a href="{{ url('/') }}">Home</a>
                <a href="{{ url('/') }}#start">Create a trip</a>
                <a href="{{ url('/join') }}">Join a trip</a>
            </nav>
        </div>
    </footer>
</body>
</html>
