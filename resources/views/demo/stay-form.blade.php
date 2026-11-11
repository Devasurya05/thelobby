@extends('demo.layout')

@section('title', 'Book a hotel')
@section('brand', 'DemoStay')

@section('content')
    <div class="page-head">
        <p class="kicker">DemoStay, pretend hotels</p>
        <h1>Book a hotel</h1>
    </div>

    <form method="POST" action="{{ route('demo.stay.store') }}" class="form-grid">
        @csrf
        @include('demo._stay-fields', ['values' => null])
        <div class="span-2 actions"><button type="submit">Book</button></div>
    </form>
@endsection
