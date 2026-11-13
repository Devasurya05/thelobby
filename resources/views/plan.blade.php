@extends('layouts.app')

@section('title', 'Plan: '.$trip->name)
@section('description', 'Dates, places and tickets for '.$trip->name.', with calendar files and map links.')
@section('inline_errors', '1')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <p class="kicker">{{ $trip->name }}</p>
        <h1>Plan</h1>
        <p class="muted measure">Where to be and when. Add it to your calendar, or open the place in maps.</p>
    </div>

    <section class="block" aria-labelledby="trip-cal-title">
        <h2 id="trip-cal-title">The whole trip</h2>
        @if ($trip->awaiting_booking)
            <p class="measure muted">The trip dates will come from the first booking added to this trip. Until then there is nothing to add to a calendar.</p>
        @else
            <p class="measure">{{ $trip->starts_on->format('D j M') }} to {{ $trip->ends_on->format('D j M Y') }}.</p>
            <div class="actions">
                <a class="btn" href="{{ route('plan.calendar', $trip->join_code) }}">Download calendar file</a>
                <a class="btn secondary" href="{{ $tripGoogleLink }}" target="_blank" rel="noopener noreferrer">Add trip dates to Google Calendar</a>
            </div>
            <p class="hint measure">The calendar file has the trip dates and everything below. Open it on your phone or computer and it goes into Apple, Google or Outlook calendar. Times are the local time at each place.</p>
        @endif
    </section>

    @if ($bookings->isNotEmpty() || $me->isOrganiser())
        <section class="block" aria-labelledby="bookings-title">
            <h2 id="bookings-title">Bookings</h2>
            <p class="muted measure">Bookings made on booking sites. They add the rooms and the plan items for you, and follow later changes.</p>

            @foreach ($bookings as $b)
                @php
                    [$chip, $label] = match ($b->status) {
                        'pending' => ['held', $me->isOrganiser() ? 'Waiting for you' : 'Waiting for the organiser'],
                        'applied' => ['free', 'Added'],
                        'needs_attention' => ['gone', 'Needs attention'],
                        default => ['taken', 'Cancelled'],
                    };
                @endphp
                <div class="row">
                    <div>
                        <p><strong>{{ $b->providerLabel() }}</strong> <span class="mono small">{{ $b->reference }}</span> <span class="chip {{ $chip }}">{{ $label }}</span></p>
                        <p class="small">{{ $b->bookingData()->summary() }}</p>
                        @if ($b->attention)
                            <p class="small attention">{{ $b->attention }}</p>
                        @endif
                        @if ($b->applied_at)
                            <p class="muted small">Last updated {{ $b->applied_at->format('j M, H:i') }}</p>
                        @endif
                    </div>
                    @if ($me->isOrganiser())
                        <div class="row-actions">
                            @if ($b->status === 'pending')
                                <form method="POST" action="{{ route('bookings.accept', [$trip->join_code, $b->id]) }}" class="inline">
                                    @csrf
                                    <button type="submit">Add to trip</button>
                                </form>
                                <form method="POST" action="{{ route('bookings.dismiss', [$trip->join_code, $b->id]) }}" class="inline"
                                      data-confirm="Dismiss this booking? Nothing will be added, and later changes to it will be ignored.">
                                    @csrf
                                    <button type="submit" class="secondary">Dismiss</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('bookings.remove', [$trip->join_code, $b->id]) }}" class="inline"
                                      data-confirm="Remove this booking from the trip? Its plan items go, and so do its rooms if nobody is in them.">
                                    @csrf
                                    <button type="submit" class="secondary">Remove from trip</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($me->isOrganiser() && ! $trip->hasEnded())
                <form method="POST" action="{{ route('bookings.import', $trip->join_code) }}" class="form-grid" style="margin-top: 20px">
                    @csrf
                    <div>
                        <label for="provider">Booking site</label>
                        <select id="provider" name="provider" required>
                            @foreach ($providers as $key => $provider)
                                <option value="{{ $key }}" @selected(old('provider') === $key)>{{ $provider->label() }}</option>
                            @endforeach
                        </select>
                        @error('provider') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="reference">Booking reference</label>
                        <input id="reference" class="code" type="text" name="reference" value="{{ old('reference') }}" maxlength="40" autocomplete="off" placeholder="STAY-7K2P9Q" required>
                        @error('reference') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="span-2 actions"><button type="submit" class="secondary">Import a booking</button></div>
                </form>
                @if (config('lobby.demo_partners'))
                    <p class="hint measure">No booking yet? Make one on the pretend booking sites: <a href="{{ route('demo.stay.create') }}" target="_blank" rel="noopener">DemoStay</a> (hotels) or <a href="{{ route('demo.fly.create') }}" target="_blank" rel="noopener">DemoFly</a> (flights). After booking, press "Send to The Lobby" there with this trip's code <strong class="mono">{{ $trip->join_code }}</strong>.</p>
                @endif
            @endif
        </section>
    @endif

    <section class="block" aria-labelledby="items-title">
        <h2 id="items-title">Day by day</h2>

        @forelse ($days as $date => $items)
            <h3 class="day">{{ \Illuminate\Support\Carbon::parse($date)->format('l j M') }}</h3>
            @foreach ($items as $item)
                @php($mapLink = \App\Support\PlanLinks::map($item->place, $item->address))
                <div class="row">
                    <div>
                        <p><span class="chip outline" style="margin-left: 0">{{ $item->kindLabel() }}</span> <strong>{{ $item->title }}</strong></p>
                        <p class="small">{{ $item->whenText() }}</p>
                        @if ($item->place || $item->address)
                            <p class="muted small">{{ implode(', ', array_filter([$item->place, $item->address])) }}</p>
                        @endif
                        @if ($item->notes)
                            <p class="muted small">{{ $item->notes }}</p>
                        @endif
                        @if ($item->booking)
                            <p class="muted small">From the {{ $item->booking->providerLabel() }} booking {{ $item->booking->reference }}.</p>
                        @endif
                    </div>
                    <div class="row-actions">
                        <a class="btn secondary" href="{{ route('plan.item', [$trip->join_code, $item->id]) }}">Calendar file</a>
                        <a class="btn secondary" href="{{ \App\Support\PlanLinks::googleCalendar($item) }}" target="_blank" rel="noopener noreferrer">Google Calendar</a>
                        @if ($mapLink)
                            <a class="btn secondary" href="{{ $mapLink }}" target="_blank" rel="noopener noreferrer">Open in maps</a>
                        @endif
                        @if ($me->isOrganiser() && ! $item->booking_id)
                            <form method="POST" action="{{ route('plan.delete', [$trip->join_code, $item->id]) }}" class="inline"
                                  data-confirm="Remove {{ $item->title }} from the plan?">
                                @csrf
                                <button type="submit" class="danger">Remove</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        @empty
            <p class="muted measure">
                @if ($me->isOrganiser())
                    Nothing is planned yet. Add the hotel, flights or tickets below and everyone in the trip will see them here.
                @else
                    Nothing is planned yet. The organiser can add the hotel, flights and tickets.
                @endif
            </p>
        @endforelse
    </section>

    @if ($me->isOrganiser() && ! $trip->hasEnded())
        <section class="block" aria-labelledby="add-title">
            <h2 id="add-title">Add to the plan</h2>
            @if ($full)
                <p class="muted">The plan is full. Remove an item before adding another.</p>
            @else
                <form method="POST" action="{{ route('plan.store', $trip->join_code) }}" class="form-grid">
                    @csrf
                    <div>
                        <label for="kind">Type</label>
                        <select id="kind" name="kind" required>
                            @foreach ($kinds as $value => $label)
                                <option value="{{ $value }}" @selected(old('kind', 'stay') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('kind') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="title">Title</label>
                        <input id="title" type="text" name="title" value="{{ old('title') }}" maxlength="120" placeholder="Hotel Sunrise, check in" required>
                        @error('title') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="starts_on">Starts on</label>
                        <input id="starts_on" type="date" name="starts_on" value="{{ old('starts_on', $trip->starts_on->format('Y-m-d')) }}" required>
                        @error('starts_on') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="starts_time">Start time (leave empty for all day)</label>
                        <input id="starts_time" type="time" name="starts_time" value="{{ old('starts_time') }}">
                        @error('starts_time') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="ends_on">Ends on (optional)</label>
                        <input id="ends_on" type="date" name="ends_on" value="{{ old('ends_on') }}">
                        @error('ends_on') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ends_time">End time (optional)</label>
                        <input id="ends_time" type="time" name="ends_time" value="{{ old('ends_time') }}">
                        @error('ends_time') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="place">Place name (optional)</label>
                        <input id="place" type="text" name="place" value="{{ old('place') }}" maxlength="120" placeholder="Hotel Sunrise">
                        @error('place') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="address">Address (optional)</label>
                        <input id="address" type="text" name="address" value="{{ old('address') }}" maxlength="200" placeholder="Candolim, Goa">
                        @error('address') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="span-2">
                        <label for="notes">Notes (optional)</label>
                        <input id="notes" type="text" name="notes" value="{{ old('notes') }}" maxlength="500" placeholder="Booking reference, gate, anything useful">
                        <p class="hint">The place and address are used for the "Open in maps" link. Do not put passwords or card numbers here: everyone in the trip can read it. Use the vault for private files.</p>
                        @error('notes') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="span-2 actions"><button type="submit">Add to the plan</button></div>
                </form>
            @endif
        </section>
    @endif
@endsection

@push('scripts')
    <script src="{{ \App\Support\Assets::url('js/confirm.js') }}"></script>
@endpush
