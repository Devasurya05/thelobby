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
    <title>@yield('title') - The Lobby</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="top"><div class="wrap"><a href="{{ url('/') }}">The Lobby</a></div></header>
    <main class="wrap">
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <p><a class="btn" href="{{ url('/') }}">Back to the start</a></p>
    </main>
</body>
</html>
