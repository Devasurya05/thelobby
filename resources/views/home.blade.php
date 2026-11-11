@extends('layouts.app')

@section('inline_errors', '1')

@section('content')
    <section class="hero">
        <h1>Pick your bed.<br>No clashes.</h1>
        <p class="lead">Plan a group trip together. Everyone picks a bed in a live lobby, and tickets and passports go in a private vault that deletes itself when the trip ends.</p>
        <div class="actions">
            <a class="btn" href="#create">Create a trip</a>
            <a class="btn secondary" href="#join">Join with a code</a>
        </div>
    </section>

    {{-- Filled in by public/js/home.js from this browser's own list; stays hidden if there is nothing. --}}
    <section id="my-trips" class="block" hidden>
        <h2>Your trips on this device</h2>
        <ul id="my-trips-list" class="trip-list"></ul>
    </section>

    <section class="block" id="try">
        <h2>Try it</h2>
        <p class="measure muted">This is a practice lobby. Nothing here is saved. Grab a free bed, then confirm it before the timer runs out. Ravi is holding a bed too, and his time is running out.</p>
        <div id="demo" class="demo" aria-live="polite">
            <noscript><p class="muted">The practice lobby needs JavaScript. The real lobby works the same way.</p></noscript>
        </div>
    </section>

    <section class="block" id="how">
        <h2>How it works</h2>
        <ol class="steps">
            <li>
                <div>
                    <h3>Create a trip</h3>
                    <p>Add the dates and list your rooms and beds. You get an invite link and an 8-character code to share.</p>
                </div>
            </li>
            <li>
                <div>
                    <h3>Friends join</h3>
                    <p>They open the link and type a name. No accounts and no passwords.</p>
                </div>
            </li>
            <li>
                <div>
                    <h3>Everyone picks a bed</h3>
                    <p>Grabbing a bed holds it for a short time. Confirming makes it yours. Two people can never end up on the same bed, even if they tap at the same moment.</p>
                </div>
            </li>
        </ol>
        <div class="extras">
            <div>
                <h3>A private vault</h3>
                <p>Upload tickets and passports. Files open through links that stop working after 60 seconds, and every file is deleted when the trip ends.</p>
            </div>
            <div>
                <h3>A record of everything</h3>
                <p>The organiser sees who did what and when, including attempts that were refused.</p>
            </div>
        </div>
    </section>

    {{-- Two separate sections, one under the other, each with its own anchor, so "Create" and "Join" each land on one thing. --}}
    <section class="block" id="create" aria-labelledby="create-title">
        <h2 id="create-title">Create a trip</h2>
        <p class="muted">You will be the organiser.</p>
        <form method="POST" action="{{ route('trips.store') }}" class="measure">
                @csrf
                <label for="trip-name">Trip name</label>
                <input id="trip-name" type="text" name="trip_name" value="{{ old('trip_name') }}" maxlength="100" placeholder="Goa villa weekend" required>
                @error('trip_name') <p class="field-error">{{ $message }}</p> @enderror

                <label for="organiser-name">Your name</label>
                <input id="organiser-name" type="text" name="organiser_name" value="{{ old('organiser_name') }}" maxlength="60" autocomplete="name" required>
                @error('organiser_name') <p class="field-error">{{ $message }}</p> @enderror

                <label for="starts-on">Starts on</label>
                <input id="starts-on" type="date" name="starts_on" value="{{ old('starts_on') }}" required>
                @error('starts_on') <p class="field-error">{{ $message }}</p> @enderror

                <label for="ends-on">Ends on</label>
                <input id="ends-on" type="date" name="ends_on" value="{{ old('ends_on') }}" required>
                <p class="hint">The vault deletes every file the day after this date.</p>
                @error('ends_on') <p class="field-error">{{ $message }}</p> @enderror

                <label for="rooms">Rooms and beds</label>
                <textarea id="rooms" name="rooms" required placeholder="Room 1: Window, Door&#10;Room 2: Bed 1, Bed 2, Bed 3">{{ old('rooms') }}</textarea>
                <p class="hint">One room per line, like <code>Room name: bed, bed, bed</code>. A line with no colon is a room with one bed. <button type="button" class="link-button" id="fill-example">Fill in an example</button></p>
                @error('rooms') <p class="field-error">{{ $message }}</p> @enderror

                <div class="actions"><button type="submit">Create trip</button></div>
        </form>
    </section>

    <section class="block" id="join" aria-labelledby="join-title">
        <h2 id="join-title">Join a trip</h2>
        <p class="muted">Got a code or an invite link from a friend?</p>
        <form method="POST" action="{{ route('join.store') }}" class="measure">
            @csrf
            <label for="join-code">Trip code</label>
            <input id="join-code" class="code" type="text" name="code" value="{{ old('code') }}" maxlength="8" autocomplete="off" required>
            @error('code') <p class="field-error">{{ $message }}</p> @enderror

            <label for="join-name">Your name</label>
            <input id="join-name" type="text" name="name" value="{{ old('name') }}" maxlength="60" autocomplete="name" required>
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <div class="actions"><button type="submit">Join</button></div>
        </form>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/home.js') }}"></script>
@endpush
