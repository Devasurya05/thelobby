@extends('layouts.app')

@section('title', 'Audit log - '.$trip->name)

@section('header_right')
    <a href="{{ route('lobby', $trip->join_code) }}">Back to lobby</a>
@endsection

@section('content')
    <h1>Audit log</h1>
    <p class="muted">{{ $trip->name }} &middot; newest first &middot; times are {{ config('app.timezone') }}. Entries can never be edited or deleted.</p>

    @forelse ($rows as $row)
        @php($target = \App\Support\AuditText::target($row, $beds, $members))
        @php($details = \App\Support\AuditText::details($row))
        <section class="card audit-row">
            <div class="audit-head">
                <strong>{{ \App\Support\AuditText::label($row->action) }}</strong>
                <span class="muted small">{{ $row->created_at->format('j M, H:i:s') }}</span>
            </div>
            <p class="small">
                {{ $row->member?->name ?? 'System' }}@if ($target) &middot; {{ $target }}@endif
            </p>
            @if ($details)
                <p class="muted small">{{ $details }}</p>
            @endif
            <p class="muted small mono">{{ $row->action }}</p>
        </section>
    @empty
        <p>Nothing has happened yet.</p>
    @endforelse

    <p>
        @unless ($isFirstPage)
            <a class="btn secondary" href="{{ route('audit.index', $trip->join_code) }}">Newest</a>
        @endunless
        @if ($olderBefore)
            <a class="btn secondary" href="{{ route('audit.index', [$trip->join_code, 'before' => $olderBefore]) }}">Older entries</a>
        @endif
    </p>
@endsection
