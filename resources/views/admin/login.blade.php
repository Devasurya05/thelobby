@extends('layouts.app')

@section('title', 'Owner login - The Lobby')

@section('content')
    <h1>Owner login</h1>

    <section class="card">
        <form method="POST" action="{{ url('/admin/login') }}">
            @csrf
            <label for="admin-key">Admin key</label>
            <input id="admin-key" type="password" name="key" autocomplete="off" autofocus required>
            @error('key') <p class="field-error">{{ $message }}</p> @enderror

            <button class="block" type="submit">Log in</button>
        </form>
    </section>
@endsection
