@extends('demo.layout')

@section('title', 'Demo booking sites')

@section('content')
    <div class="page-head">
        <h1>Demo booking sites</h1>
        <p class="lead muted measure">Two pretend websites. They are not real and charge nothing. Book something here, send it to a trip on The Lobby, and watch the trip fill itself in.</p>
    </div>

    <section class="block">
        <h2>DemoStay</h2>
        <p class="muted measure">A pretend hotel site. Pick a hotel, dates, rooms and guests. Each room becomes a room with beds in the lobby.</p>
        <div class="actions"><a class="btn" href="{{ route('demo.stay.create') }}">Book a hotel</a></div>
    </section>

    <section class="block">
        <h2>DemoFly</h2>
        <p class="muted measure">A pretend airline site. Pick a route, a date and passengers, with an optional return flight. Each flight becomes an item on the trip's plan.</p>
        <div class="actions"><a class="btn" href="{{ route('demo.fly.create') }}">Book a flight</a></div>
    </section>

    <section class="block">
        <h2>How the connection works</h2>
        <ol class="measure">
            <li>You book here and get a reference, for example <span class="mono">STAY-7K2P9Q</span>.</li>
            <li>Press "Create a new trip" and type your name. A trip is made for you with its own code, and the booking is already in it.</li>
            <li>Or, if you already have a trip, type its code. If you made it with "they will come from a booking", it fills in at once. Otherwise the organiser presses "Add to trip" on the Plan page.</li>
            <li>The site sends The Lobby a signed message each time, so nobody can pretend to be the booking site.</li>
            <li>If you change or cancel the booking here, the trip follows by itself.</li>
        </ol>
    </section>
@endsection
