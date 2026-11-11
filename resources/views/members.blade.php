@extends('layouts.app')

@section('title', 'Members - '.$trip->name)

@section('header_right')
    <a href="{{ route('lobby', $trip->join_code) }}">Back to lobby</a>
@endsection

@section('content')
    <h1>Members</h1>
    <p class="muted">{{ $trip->name }} &middot; only you (the organiser) can see this page.</p>

    @if (session('status'))
        <div class="flash ok" role="status">{{ session('status') }}</div>
    @endif
    @if (session('problem'))
        <div class="flash error" role="alert">{{ session('problem') }}</div>
    @endif

    @foreach ($members as $m)
        <section class="card member {{ $m->isRemoved() ? 'removed' : '' }}">
            <div class="member-head">
                <strong>{{ $m->name }}</strong>
                <span class="badge">{{ $m->role }}</span>
                @if ($m->isRemoved())
                    <span class="badge gone">removed</span>
                @endif
                @if ($m->id === $me->id)
                    <span class="muted small">(you)</span>
                @endif
            </div>
            <p class="muted small">
                Joined {{ $m->created_at->format('j M, H:i') }}
                @if ($beds->has($m->id))
                    &middot; bed: {{ $beds[$m->id]->room->name }} &middot; {{ $beds[$m->id]->label }}
                @endif
            </p>

            {{-- Organiser cannot be changed or removed, and removed people stay removed. --}}
            @unless ($m->isOrganiser() || $m->isRemoved())
                <div class="member-actions">
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

    <p><a class="btn secondary" href="{{ route('audit.index', $trip->join_code) }}">View audit log</a></p>
@endsection

@push('scripts')
    <script src="{{ asset('js/confirm.js') }}"></script>
@endpush
