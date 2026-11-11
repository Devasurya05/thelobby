@extends('layouts.app')

@section('title', 'Owner login')
@section('description', 'Owner login for The Lobby.')
@section('inline_errors', '1')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <h1>Owner login</h1>
        <p class="muted">For the person who runs this site. It opens the list of recent errors.</p>
    </div>

    <form method="POST" action="{{ url('/admin/login') }}" class="measure">
        @csrf
        <label for="admin-key">Admin key</label>
        <input id="admin-key" type="password" name="key" autocomplete="off" autofocus required>
        @error('key') <p class="field-error">{{ $message }}</p> @enderror

        <div class="actions"><button type="submit">Log in</button></div>
    </form>
@endsection
