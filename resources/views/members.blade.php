@extends('layouts.app')

@section('title', 'People: '.$trip->name)
@section('description', 'Manage who is in '.$trip->name.'.')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <p class="kicker">{{ $trip->name }}</p>
        <h1>People</h1>
        <p class="muted measure">Only you, the organiser, can see this page. Removing someone ends their access straight away and frees their bed.</p>
    </div>

    @foreach ($members as $m)
        <section class="row member {{ $m->isRemoved() ? 'removed' : '' }}">
            <div>
                <h3>
                    {{ $m->name }}
                    <span class="chip outline">{{ $m->role }}</span>
                    @if ($m->isRemoved())
                        <span class="chip gone">removed</span>
                    @endif
                    @if ($m->id === $me->id)
                        <span class="muted small">(you)</span>
                    @endif
                </h3>
                <p class="muted small">
                    Joined {{ $m->created_at->format('j M, H:i') }}
                    @if ($beds->has($m->id))
                        &middot; bed: {{ $beds[$m->id]->room->name }} &middot; {{ $beds[$m->id]->label }}
                    @endif
                </p>
            </div>

            {{-- Organiser cannot be changed or removed, and removed people stay removed. --}}
            @unless ($m->isOrganiser() || $m->isRemoved())
                <div class="row-actions">
                    <form method="POST" action="{{ route('members.role', [$trip->join_code, $m->id]) }}" class="inline">
                        @csrf
                        <label class="sr-only" for="role-{{ $m->id }}">Role for {{ $m->name }}</label>
                        <select id="role-{{ $m->id }}" name="role">
                            @foreach (\App\Services\MemberService::ASSIGNABLE_ROLES as $role)
                                <option value="{{ $role }}" @selected($m->role === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="secondary">Change role</button>
                    </form>

                    {{-- data-confirm: confirm.js asks "are you sure?" before this form is sent. --}}
                    <form method="POST" action="{{ route('members.remove', [$trip->join_code, $m->id]) }}" class="inline"
                          data-confirm="Remove {{ $m->name }}? They lose access immediately and their bed is freed.">
                        @csrf
                        <button type="submit" class="danger">Remove</button>
                    </form>
                </div>
            @endunless
        </section>
    @endforeach

    <div class="actions"><a class="btn secondary" href="{{ route('audit.index', $trip->join_code) }}">View activity log</a></div>
@endsection

@push('scripts')
    <script src="{{ \App\Support\Assets::url('js/confirm.js') }}"></script>
@endpush
