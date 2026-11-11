@extends('layouts.app')

@section('title', $trip->name.' - The Lobby')

@section('header_right')
    <span class="badge">{{ $member->role }}</span>
@endsection

@section('content')
    <h1>{{ $trip->name }}</h1>
    <p class="muted">{{ $trip->starts_on->format('j M Y') }} to {{ $trip->ends_on->format('j M Y') }} &middot; you are <strong>{{ $member->name }}</strong></p>

    <section class="card">
        <h2>Invite people</h2>
        <p class="muted small">Share this link or the code <strong>{{ $trip->join_code }}</strong>:</p>
        <div class="sharebox">{{ route('join.show', $trip->join_code) }}</div>
    </section>

    <section class="card">
        <h2>Beds</h2>
        <p class="muted">The live bed grid arrives in the next step.</p>
    </section>
@endsection
