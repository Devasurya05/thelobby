@extends('layouts.app')

@section('title', 'Join a trip')
@section('description', 'Join a group trip on The Lobby with the code or invite link a friend sent you.')
@section('inline_errors', '1')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <h1>Join a trip</h1>
        <p class="muted measure">Type the 8-character code your friend sent you, and the name the others should see.</p>
    </div>

    <form method="POST" action="{{ route('join.store') }}" class="measure">
        @csrf
        <label for="join-code">Trip code</label>
        <input id="join-code" class="code" type="text" name="code" value="{{ old('code', $code) }}" maxlength="8" autocomplete="off" required>
        @error('code') <p class="field-error">{{ $message }}</p> @enderror

        <label for="join-name">Your name</label>
        <input id="join-name" type="text" name="name" value="{{ old('name') }}" maxlength="60" autocomplete="name" autofocus required>
        @error('name') <p class="field-error">{{ $message }}</p> @enderror

        <div class="actions"><button type="submit">Join</button></div>
    </form>

    <p class="muted" style="margin-top: 32px">Want to organise your own trip? <a href="{{ url('/') }}#start">Create one</a>.</p>
@endsection
