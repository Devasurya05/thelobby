@extends('layouts.app')

@section('title', 'Join a trip - The Lobby')

@section('content')
    <h1>Join a trip</h1>

    <section class="card">
        <form method="POST" action="{{ route('join.store') }}">
            @csrf
            <label for="join-code">Trip code</label>
            <input id="join-code" class="code" type="text" name="code" value="{{ old('code', $code) }}" maxlength="8" autocomplete="off" required>
            @error('code') <p class="field-error">{{ $message }}</p> @enderror

            <label for="join-name">Your name</label>
            <input id="join-name" type="text" name="name" value="{{ old('name') }}" maxlength="60" autofocus required>
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <button class="block" type="submit">Join</button>
        </form>
    </section>
@endsection
