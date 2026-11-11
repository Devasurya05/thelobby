@extends('layouts.app')

@section('content')
    <h1>Pick your bed. No clashes.</h1>
    <p class="muted">Group trips made simple: choose your room in a live lobby, then share tickets in a private vault that deletes itself when the trip ends.</p>

    {{-- Filled in by public/js/home.js from this browser's own list; stays hidden if there is nothing. --}}
    <section id="my-trips" class="card my-trips" hidden>
        <h2>Your trips on this device</h2>
        <ul id="my-trips-list" class="trip-list"></ul>
    </section>

    <section class="card join-card">
        <h2>Join a trip</h2>
        <p class="muted small">Got a code or invite link from a friend?</p>
        <form method="POST" action="{{ route('join.store') }}">
            @csrf
            <label for="join-code">Trip code</label>
            <input id="join-code" class="code" type="text" name="code" value="{{ old('code') }}" maxlength="8" autocomplete="off" required>
            @error('code') <p class="field-error">{{ $message }}</p> @enderror

            <label for="join-name">Your name</label>
            <input id="join-name" type="text" name="name" value="{{ old('name') }}" maxlength="60" required>
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <button class="block" type="submit">Join</button>
        </form>
    </section>

    <p class="divider"><span>or organise your own</span></p>

    <section class="card create-card">
        <h2>Create a trip</h2>
        <form method="POST" action="{{ route('trips.store') }}">
            @csrf
            <label for="trip-name">Trip name</label>
            <input id="trip-name" type="text" name="trip_name" value="{{ old('trip_name') }}" maxlength="100" placeholder="Goa villa weekend" required>
            @error('trip_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="organiser-name">Your name (you will be the organiser)</label>
            <input id="organiser-name" type="text" name="organiser_name" value="{{ old('organiser_name') }}" maxlength="60" required>
            @error('organiser_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="starts-on">Starts on</label>
            <input id="starts-on" type="date" name="starts_on" value="{{ old('starts_on') }}" required>
            @error('starts_on') <p class="field-error">{{ $message }}</p> @enderror

            <label for="ends-on">Ends on</label>
            <input id="ends-on" type="date" name="ends_on" value="{{ old('ends_on') }}" required>
            @error('ends_on') <p class="field-error">{{ $message }}</p> @enderror

            <label for="rooms">Rooms and beds (one room per line)</label>
            <textarea id="rooms" name="rooms" required placeholder="Room 1: Window, Door&#10;Room 2: Bed 1, Bed 2, Bed 3">{{ old('rooms') }}</textarea>
            <p class="muted small">Format: <code>Room name: bed, bed, bed</code>. A line with no colon is a room with one bed.</p>
            @error('rooms') <p class="field-error">{{ $message }}</p> @enderror

            <button class="block" type="submit">Create trip</button>
        </form>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/home.js') }}"></script>
@endpush
