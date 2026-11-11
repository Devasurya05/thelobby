{{-- The DemoStay booking fields, shared by "book" and "change". $values is the current booking's payload or null. --}}
@php
    $hotelName = $values['hotel']['name'] ?? null;
    $currentHotel = collect($hotels)->search(fn ($h) => $h['name'] === $hotelName) ?: 'sunrise';
    $firstRoom = $values['rooms'][0] ?? [];
@endphp
<div>
    <label for="hotel">Hotel</label>
    <select id="hotel" name="hotel" required>
        @foreach ($hotels as $key => $hotel)
            <option value="{{ $key }}" @selected(old('hotel', $currentHotel) === $key)>{{ $hotel['name'] }}, {{ $hotel['address'] }}</option>
        @endforeach
    </select>
    @error('hotel') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="room_type">Room type</label>
    <select id="room_type" name="room_type" required>
        @foreach ($roomTypes as $type)
            <option value="{{ $type }}" @selected(old('room_type', $firstRoom['type'] ?? 'Sea view') === $type)>{{ $type }}</option>
        @endforeach
    </select>
    @error('room_type') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="check_in">Check in (from 14:00)</label>
    <input id="check_in" type="date" name="check_in" value="{{ old('check_in', $values['check_in'] ?? '') }}" required>
    @error('check_in') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="check_out">Check out (by 11:00)</label>
    <input id="check_out" type="date" name="check_out" value="{{ old('check_out', $values['check_out'] ?? '') }}" required>
    @error('check_out') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="rooms">Rooms</label>
    <select id="rooms" name="rooms" required>
        @foreach (range(1, 6) as $n)
            <option value="{{ $n }}" @selected((int) old('rooms', isset($values['rooms']) ? count($values['rooms']) : 3) === $n)>{{ $n }}</option>
        @endforeach
    </select>
    @error('rooms') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="guests">Guests per room (one bed each)</label>
    <select id="guests" name="guests" required>
        @foreach (range(1, 4) as $n)
            <option value="{{ $n }}" @selected((int) old('guests', $firstRoom['guests'] ?? 2) === $n)>{{ $n }}</option>
        @endforeach
    </select>
    @error('guests') <p class="field-error">{{ $message }}</p> @enderror
</div>
