@extends('demo.layout')

@section('title', 'Book a flight')
@section('brand', 'DemoFly')

@section('content')
    <div class="page-head">
        <p class="kicker">DemoFly, pretend airline</p>
        <h1>Book a flight</h1>
    </div>

    <form method="POST" action="{{ route('demo.fly.store') }}" class="form-grid">
        @csrf
        @include('demo._fly-fields', ['form' => null])
        <div class="span-2 actions"><button type="submit">Book</button></div>
    </form>
@endsection
