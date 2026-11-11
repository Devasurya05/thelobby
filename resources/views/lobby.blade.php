@extends('layouts.app')

@section('title', $trip->name.' - The Lobby')

@section('header_right')
    <span class="badge">{{ $member->role }}</span>
@endsection

@section('content')
    <h1>{{ $trip->name }}</h1>
    <p class="muted">{{ $trip->starts_on->format('j M Y') }} to {{ $trip->ends_on->format('j M Y') }} &middot; you are <strong>{{ $member->name }}</strong></p>

    {{-- Everything the JavaScript needs comes from these data- attributes (no inline script). --}}
    <div id="lobby"
         data-state-url="{{ route('lobby.state', $trip->join_code) }}"
         data-action-base="{{ url('/t/'.$trip->join_code.'/beds') }}/"
         data-join-url="{{ route('join.show', $trip->join_code) }}"
         data-poll-ms="{{ config('lobby.poll_ms') }}">

        <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>
        <div id="me-banner" class="me-banner">Loading the lobby...</div>
        <div id="summary" class="summary muted small"></div>
        <div id="rooms"></div>
    </div>

    <section class="card">
        <h2>Invite people</h2>
        <p class="muted small">Share this link or the code <strong>{{ $trip->join_code }}</strong>:</p>
        <div class="sharebox">{{ route('join.show', $trip->join_code) }}</div>
    </section>
@endsection

@push('scripts')
    {{-- integrity = fingerprint of the exact file; the browser refuses it if the CDN copy ever changes. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
            integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g=="
            crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="{{ asset('js/lobby.js') }}"></script>
@endpush
