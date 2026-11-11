@extends('layouts.app')

@section('title', 'Errors - The Lobby')

@section('header_right')
    <form method="POST" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit" class="secondary">Log out</button>
    </form>
@endsection

@section('content')
    <h1>Recent errors</h1>
    <p class="muted small">Newest 100. Only unexpected errors are listed (not 404s or validation mistakes). The full detail is in <code>storage/logs</code>.</p>

    {{-- Not called $errors: Laravel already uses that name for form validation messages. --}}
    @forelse ($logs as $log)
        <section class="card">
            <strong>{{ $log->exception_class }}</strong>
            <span class="muted small">{{ $log->created_at }} &middot; {{ $log->method }} {{ $log->url }}</span>
            <p>{{ $log->message }}</p>
            <p class="muted small">{{ $log->file }}:{{ $log->line }} @if ($log->trip_id) &middot; trip #{{ $log->trip_id }} @endif @if ($log->member_id) &middot; member #{{ $log->member_id }} @endif</p>
        </section>
    @empty
        <p>No errors recorded.</p>
    @endforelse
@endsection
