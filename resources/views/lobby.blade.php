@extends('layouts.app')

@section('title', $trip->name)
@section('description', 'The live bed lobby for '.$trip->name.'.')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <p class="kicker">{{ $trip->starts_on->format('j M') }} to {{ $trip->ends_on->format('j M Y') }}</p>
        <h1>{{ $trip->name }}</h1>
        <p class="muted">You are {{ $member->name }} <span class="chip outline">{{ $member->role }}</span></p>
    </div>

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

    <section class="block" aria-labelledby="invite-title">
        <h2 id="invite-title">Invite people</h2>
        <p class="muted">Send this link, or tell people the code <strong class="mono">{{ $trip->join_code }}</strong>.</p>
        <label class="sr-only" for="invite-link">Invite link</label>
        <input id="invite-link" class="sharebox" type="text" readonly value="{{ route('join.show', $trip->join_code) }}">
        <div class="actions">
            <button type="button" id="copy-invite">Copy link</button>
            {{-- wa.me just opens WhatsApp with this text ready to send; nothing is sent until the person taps Send there. --}}
            <a class="btn secondary" target="_blank" rel="noopener noreferrer"
               href="https://wa.me/?text={{ rawurlencode('Join our trip "'.$trip->name.'" on The Lobby: '.route('join.show', $trip->join_code)) }}">Send on WhatsApp</a>
            {{-- Shown by JavaScript only on phones/browsers that have a share sheet (needs HTTPS or localhost). --}}
            <button type="button" id="share-invite" class="secondary" hidden>Share</button>
        </div>
    </section>
@endsection

@push('scripts')
    {{-- integrity = fingerprint of the exact file; the browser refuses it if the CDN copy ever changes. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
            integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="{{ asset('js/lobby.js') }}"></script>
@endpush
