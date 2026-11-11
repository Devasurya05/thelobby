<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- CSRF token for AJAX calls (jQuery will send it in a header). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') | The Lobby @else The Lobby: pick your bed, no clashes @endif</title>
    <meta name="description" content="@yield('description', 'Pick beds for a group trip without double bookings, and keep tickets and passports in a private vault that deletes itself when the trip ends.')">
    @stack('head')
    @include('partials.head')
</head>
<body>
    @php
        // Set by the trip.member middleware on every /t/{code} page. Outside a trip these are null.
        $navTrip = request()->attributes->get('trip');
        $navMember = request()->attributes->get('member');
    @endphp
    <a class="skip" href="#main">Skip to the content</a>

    <header class="site-header">
        <div class="wrap">
            <a class="brand" href="{{ route('home') }}">The Lobby</a>
            <button type="button" class="menu-toggle secondary" id="menu-toggle" aria-expanded="false" aria-controls="site-nav">Menu</button>
            <nav class="nav" id="site-nav" aria-label="Main">
                @if ($navTrip && $navMember)
                    <a href="{{ route('lobby', $navTrip->join_code) }}" @if (request()->routeIs('lobby')) aria-current="page" @endif>Rooms</a>
                    <a href="{{ route('plan.index', $navTrip->join_code) }}" @if (request()->routeIs('plan.*')) aria-current="page" @endif>Plan</a>
                    <a href="{{ route('vault.index', $navTrip->join_code) }}" @if (request()->routeIs('vault.*')) aria-current="page" @endif>Vault</a>
                    @if ($navMember->isOrganiser())
                        <a href="{{ route('members.index', $navTrip->join_code) }}" @if (request()->routeIs('members.*')) aria-current="page" @endif>People</a>
                        <a href="{{ route('audit.index', $navTrip->join_code) }}" @if (request()->routeIs('audit.*')) aria-current="page" @endif>Activity</a>
                    @endif
                @else
                    <a href="{{ url('/') }}#how">How it works</a>
                    <a href="{{ url('/') }}#start">Create a trip</a>
                    <a href="{{ route('join.show') }}" @if (request()->routeIs('join.show')) aria-current="page" @endif>Join a trip</a>
                @endif
            </nav>
        </div>
    </header>

    <main id="main" class="wrap">
        {{-- Messages after an action. {{ }} escapes HTML automatically, so user text can never inject script (XSS). --}}
        @if (session('status'))
            <div class="flash" role="status">{{ session('status') }}</div>
        @endif
        @if (session('problem'))
            <div class="flash error" role="alert">{{ session('problem') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error" role="alert">
                @hasSection('inline_errors')
                    Some details need fixing. The problems are marked below.
                @else
                    {{ $errors->first() }}
                @endif
            </div>
        @endif

        @yield('content')
    </main>

    @include('partials.footer')

    <script src="{{ asset('js/menu.js') }}"></script>
    @stack('scripts')
</body>
</html>
