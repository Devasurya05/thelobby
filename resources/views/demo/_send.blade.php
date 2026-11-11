{{-- "Send to The Lobby": shared by both pretend sites. Needs $booking (a PartnerBooking) and $sendRoute. --}}
<section class="block" aria-labelledby="send-title">
    <h2 id="send-title">Send to The Lobby</h2>
    <p class="muted measure">On a real site this would be a button like "Add to my group trip". Type the 8-character code of the trip.</p>
    <form method="POST" action="{{ $sendRoute }}" class="measure">
        @csrf
        <label for="trip_code">Trip code</label>
        <input id="trip_code" class="code" type="text" name="trip_code" value="{{ old('trip_code') }}" maxlength="8" autocomplete="off" required>
        @error('trip_code') <p class="field-error">{{ $message }}</p> @enderror
        <div class="actions"><button type="submit">Send to The Lobby</button></div>
    </form>
    @if (! empty($booking->sent_to))
        <p class="hint">Already sent to: <span class="mono">{{ implode(', ', $booking->sent_to) }}</span>. Changes you make here follow to those trips.</p>
    @endif
</section>
