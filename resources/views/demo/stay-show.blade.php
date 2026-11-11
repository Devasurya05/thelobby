@extends('demo.layout')

@php
    $p = $booking->payload;
    $cancelled = $p['state'] === 'CANCELLED';
    $nights = (new DateTimeImmutable($p['check_in']))->diff(new DateTimeImmutable($p['check_out']))->days;
@endphp

@section('title', ($cancelled ? 'Cancelled ' : 'Booking ').$booking->reference)
@section('brand', 'DemoStay')

@section('content')
    <div class="page-head">
        <p class="kicker">DemoStay, pretend hotels</p>
        <h1>{{ $cancelled ? 'Booking cancelled' : 'Booking confirmed' }}</h1>
        <p class="mono" style="font-size: 1.6rem; font-weight: 600">{{ $booking->reference }}</p>
        <p class="muted">Version {{ $p['version'] }}. Keep the reference: you can import this booking into a trip with it.</p>
    </div>

    <dl class="facts">
        <div><dt>Hotel</dt><dd>{{ $p['hotel']['name'] }}, {{ $p['hotel']['address'] }}</dd></div>
        <div><dt>Stay</dt><dd>{{ \Illuminate\Support\Carbon::parse($p['check_in'])->format('D j M Y') }} to {{ \Illuminate\Support\Carbon::parse($p['check_out'])->format('D j M Y') }} ({{ $nights }} {{ $nights === 1 ? 'night' : 'nights' }})</dd></div>
        <div><dt>Rooms</dt><dd>{{ count($p['rooms']) }} x {{ $p['rooms'][0]['type'] }}, {{ $p['rooms'][0]['guests'] }} {{ $p['rooms'][0]['guests'] === 1 ? 'guest' : 'guests' }} each</dd></div>
        <div><dt>Status</dt><dd>{{ $cancelled ? 'Cancelled' : 'Confirmed' }}</dd></div>
    </dl>

    @include('demo._send', [
        'sendRoute' => route('demo.stay.send', $booking->reference),
        'newTripRoute' => route('demo.stay.newtrip', $booking->reference),
        'cancelled' => $cancelled,
    ])

    @unless ($cancelled)
        <section class="block" aria-labelledby="change-title">
            <h2 id="change-title">Change this booking</h2>
            <p class="muted measure">Changes are sent to every trip this booking was sent to.</p>
            <form method="POST" action="{{ route('demo.stay.change', $booking->reference) }}" class="form-grid">
                @csrf
                @include('demo._stay-fields', ['values' => $p])
                <div class="span-2 actions"><button type="submit" class="secondary">Save changes</button></div>
            </form>
        </section>

        <section class="danger-zone" aria-labelledby="cancel-title">
            <h2 id="cancel-title">Cancel this booking</h2>
            <p class="muted measure">The cancellation is sent to every trip this booking was sent to.</p>
            <form method="POST" action="{{ route('demo.stay.cancel', $booking->reference) }}" data-confirm="Cancel this booking?">
                @csrf
                <button type="submit" class="danger">Cancel booking</button>
            </form>
        </section>
    @endunless
@endsection
