@extends('layouts.app')

@section('title', $trip->name)
@section('description', 'The live bed lobby for '.$trip->name.'.')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <p class="kicker">You have joined this trip</p>
        <h1>{{ $trip->name }}</h1>
        <p class="muted">You are {{ $member->name }} <span class="chip outline">{{ $member->role }}</span></p>
    </div>

    {{-- The trip at a glance: when, where, and a way into the full plan. --}}
    <dl class="facts">
        <div>
            <dt>When</dt>
            <dd>
                {{ $trip->starts_on->format('D j M') }} to {{ $trip->ends_on->format('D j M Y') }}
                <span class="fact-links">
                    <a href="{{ route('plan.calendar', $trip->join_code) }}">Add to calendar</a>
                    <a href="{{ $googleLink }}" target="_blank" rel="noopener noreferrer">Google Calendar</a>
                </span>
            </dd>
        </div>
        <div>
            <dt>Where</dt>
            <dd>
                @if ($stay)
                    {{ implode(', ', array_filter([$stay->place, $stay->address])) }}
                    @if ($mapLink)
                        <span class="fact-links"><a href="{{ $mapLink }}" target="_blank" rel="noopener noreferrer">Open in maps</a></span>
                    @endif
                @elseif ($member->isOrganiser())
                    <span class="muted">Not set yet.</span>
                    <span class="fact-links"><a href="{{ route('plan.index', $trip->join_code) }}">Add the place on the plan</a></span>
                @else
                    <span class="muted">The organiser has not added the place yet.</span>
                @endif
            </dd>
        </div>
        <div>
            <dt>Plan</dt>
            <dd>
                @if ($planCount > 0)
                    {{ $planCount }} {{ $planCount === 1 ? 'item' : 'items' }} planned
                @else
                    <span class="muted">Nothing planned yet.</span>
                @endif
                <span class="fact-links"><a href="{{ route('plan.index', $trip->join_code) }}">See the full plan</a></span>
            </dd>
        </div>
    </dl>

    {{-- Everything the JavaScript needs comes from these data- attributes (no inline script). --}}
    <div id="lobby"
         data-state-url="{{ route('lobby.state', $trip->join_code) }}"
         data-action-base="{{ url('/t/'.$trip->join_code.'/beds') }}/"
         data-join-url="{{ route('join.show', $trip->join_code) }}"
         data-trip-code="{{ $trip->join_code }}"
         data-trip-name="{{ $trip->name }}"
         data-my-name="{{ $member->name }}"
         data-poll-ms="{{ config('lobby.poll_ms') }}">

        <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>
        <div id="me-banner" class="me-banner" aria-live="polite">Loading the lobby...</div>
        <p id="summary" class="legend"></p>
        <div id="rooms"></div>
    </div>

    <section class="block" aria-labelledby="vault-title">
        <h2 id="vault-title">Vault</h2>
        <p class="muted measure">Tickets, passports and other private files for this trip. Only people in the trip can open them, and every file is deleted when the trip ends.</p>
        <div class="actions"><a class="btn secondary" href="{{ route('vault.index', $trip->join_code) }}">Open the vault</a></div>
    </section>

    {{-- Kept small and at the bottom on purpose: most people only need it once. --}}
    <section class="invite" aria-labelledby="invite-title">
        <h2 id="invite-title">Invite people</h2>
        <p class="muted small">Send this link, or tell people the code <strong class="mono">{{ $trip->join_code }}</strong>.</p>
        <label class="sr-only" for="invite-link">Invite link</label>
        <input id="invite-link" class="sharebox" type="text" readonly value="{{ route('join.show', $trip->join_code) }}">
        <div class="actions">
            <button type="button" id="copy-invite" class="secondary">Copy link</button>
            {{-- wa.me just opens WhatsApp with this text ready to send; nothing is sent until the person taps Send there. --}}
            <a class="btn secondary" target="_blank" rel="noopener noreferrer"
               href="https://wa.me/?text={{ rawurlencode('Join our trip "'.$trip->name.'" on The Lobby: '.route('join.show', $trip->join_code)) }}">Send on WhatsApp</a>
            {{-- Shown by JavaScript only on phones/browsers that have a share sheet (needs HTTPS or localhost). --}}
            <button type="button" id="share-invite" class="secondary" hidden>Share</button>
        </div>
    </section>

    <nav class="end-links" aria-label="Trip pages">
        <a href="{{ route('plan.index', $trip->join_code) }}">Plan</a>
        <a href="{{ route('vault.index', $trip->join_code) }}">Vault</a>
        @if ($member->isOrganiser())
            <a href="{{ route('members.index', $trip->join_code) }}">People</a>
            <a href="{{ route('audit.index', $trip->join_code) }}">Activity</a>
        @endif
    </nav>
@endsection

@push('scripts')
    {{-- integrity = fingerprint of the exact file; the browser refuses it if the CDN copy ever changes. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
            integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="{{ asset('js/lobby.js') }}"></script>
@endpush
