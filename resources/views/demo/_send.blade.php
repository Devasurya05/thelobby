{{--
    "Send to The Lobby": shared by both pretend sites.
    Needs: $booking (a PartnerBooking), $sendRoute (send to an existing trip), $newTripRoute (make a new trip),
    $existingTrip (the trip this booking is already in, or null), $cancelled (true when the booking is cancelled).
--}}
<section class="block" id="send" aria-labelledby="send-title">
    <h2 id="send-title">Send to The Lobby</h2>

    @if ($existingTrip)
        <p class="measure">This booking is already in the trip <strong>{{ $existingTrip->name }}</strong>
            (code <span class="mono">{{ $existingTrip->join_code }}</span>). Changes you make here follow to it by themselves.</p>
        <div class="actions"><a class="btn" href="{{ route('lobby', $existingTrip->join_code) }}">Open the trip</a></div>
    @elseif (! $cancelled)
        <h3>Start a new trip with this booking</h3>
        <p class="muted measure">The Lobby makes a new trip with its own code. The rooms and dates come from this booking, and you become the organiser. Nothing to fill in.</p>
        <form method="POST" action="{{ $newTripRoute }}" class="measure">
            @csrf
            <label for="organiser_name">Your name</label>
            <input id="organiser_name" type="text" name="organiser_name" value="{{ old('organiser_name') }}" maxlength="60" autocomplete="name" required>
            @error('organiser_name') <p class="field-error">{{ $message }}</p> @enderror
            <div class="actions"><button type="submit">Create a new trip</button></div>
        </form>
    @endif

    <h3 style="margin-top: 32px">{{ $existingTrip ? 'Send it to another trip too' : 'Already made a trip? Put its code here' }}</h3>
    <p class="muted measure">Type the 8-character code of the trip. If you made that trip with "they will come from a booking", it fills in straight away. Otherwise the organiser sees the booking on the Plan page and presses "Add to trip".</p>
    <form method="POST" action="{{ $sendRoute }}" class="measure">
        @csrf
        <label for="trip_code">Trip code</label>
        <input id="trip_code" class="code" type="text" name="trip_code" value="{{ old('trip_code') }}" maxlength="8" autocomplete="off" required>
        @error('trip_code') <p class="field-error">{{ $message }}</p> @enderror
        <div class="actions"><button type="submit" class="secondary">Send to this trip</button></div>
    </form>

    @if (! empty($booking->sent_to))
        <p class="hint">Sent to: <span class="mono">{{ implode(', ', $booking->sent_to) }}</span>. Changes you make here follow to those trips.</p>
    @endif
</section>
