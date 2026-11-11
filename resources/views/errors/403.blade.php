@extends('errors.friendly')
@section('title', 'Not allowed')
{{-- The 403 messages are ones we wrote ourselves (e.g. "You have been removed from this trip."), so showing them is safe. --}}
@section('message', $exception->getMessage() ?: 'You do not have access to this page.')
