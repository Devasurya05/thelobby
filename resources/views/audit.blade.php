@extends('layouts.app')

@section('title', 'Activity: '.$trip->name)
@section('description', 'Everything that happened in '.$trip->name.'.')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <p class="kicker">{{ $trip->name }}</p>
        <h1>Activity</h1>
        <p class="muted measure">Newest first. Times are {{ config('app.timezone') }}. Entries can never be edited or deleted.</p>
    </div>

    @forelse ($rows as $row)
        @php($target = \App\Support\AuditText::target($row, $beds, $members))
        @php($details = \App\Support\AuditText::details($row))
        <section class="row audit-row">
            <div>
                <strong>{{ \App\Support\AuditText::label($row->action) }}</strong>
                <p class="small">{{ $row->member?->name ?? 'System' }}@if ($target) &middot; {{ $target }}@endif</p>
                @if ($details)
                    <p class="muted small">{{ $details }}</p>
                @endif
                <p class="muted small mono">{{ $row->action }}</p>
            </div>
            <span class="muted small">{{ $row->created_at->format('j M, H:i:s') }}</span>
        </section>
    @empty
        <p>Nothing has happened yet.</p>
    @endforelse

    <div class="actions">
        @unless ($isFirstPage)
            <a class="btn secondary" href="{{ route('audit.index', $trip->join_code) }}">Newest</a>
        @endunless
        @if ($olderBefore)
            <a class="btn secondary" href="{{ route('audit.index', [$trip->join_code, 'before' => $olderBefore]) }}">Older entries</a>
        @endif
    </div>
@endsection
