@extends('demo.layout')

@php
    $p = $booking->payload;
    $cancelled = $p['status'] === 'VOID';
@endphp

@section('title', ($cancelled ? 'Cancelled ' : 'Booking ').$booking->reference)
@section('brand', 'DemoFly')

@section('content')
    <div class="page-head">
        <p class="kicker">DemoFly, pretend airline</p>
        <h1>{{ $cancelled ? 'Booking cancelled' : 'Booking confirmed' }}</h1>
        <p class="mono" style="font-size: 1.6rem; font-weight: 600">{{ $booking->reference }}</p>
        <p class="muted">Revision {{ $p['rev'] }}. Keep the reference: you can import this booking into a trip with it.</p>
    </div>

    <dl class="facts">
        @foreach ($p['segments'] as $segment)
            <div>
                <dt>{{ $loop->first ? 'Outbound' : 'Return' }}</dt>
                <dd>
                    {{ $segment['flight'] }}: {{ $airports[$segment['from']] ?? $segment['from'] }} to {{ $airports[$segment['to']] ?? $segment['to'] }},
                    {{ \Illuminate\Support\Carbon::parse(substr($segment['departs_at'], 0, 10))->format('D j M Y') }}, departs {{ substr($segment['departs_at'], 11, 5) }}, arrives {{ substr($segment['arrives_at'], 11, 5) }}
                </dd>
            </div>
        @endforeach
        <div><dt>Passengers</dt><dd>{{ $p['pax'] }}</dd></div>
        <div><dt>Status</dt><dd>{{ $cancelled ? 'Cancelled' : 'Ticketed' }}</dd></div>
    </dl>

    @include('demo._send', ['sendRoute' => route('demo.fly.send', $booking->reference)])

    @unless ($cancelled)
        <section class="block" aria-labelledby="change-title">
            <h2 id="change-title">Change this booking</h2>
            <p class="muted measure">Changes are sent to every trip this booking was sent to.</p>
            <form method="POST" action="{{ route('demo.fly.change', $booking->reference) }}" class="form-grid">
                @csrf
                @include('demo._fly-fields')
                <div class="span-2 actions"><button type="submit" class="secondary">Save changes</button></div>
            </form>
        </section>

        <section class="danger-zone" aria-labelledby="cancel-title">
            <h2 id="cancel-title">Cancel this booking</h2>
            <p class="muted measure">The cancellation is sent to every trip this booking was sent to.</p>
            <form method="POST" action="{{ route('demo.fly.cancel', $booking->reference) }}" data-confirm="Cancel this booking?">
                @csrf
                <button type="submit" class="danger">Cancel booking</button>
            </form>
        </section>
    @endunless
@endsection
