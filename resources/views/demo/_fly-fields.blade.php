{{-- The DemoFly booking fields, shared by "book" and "change". $form is the current booking's values or null. --}}
<div>
    <label for="from">From</label>
    <select id="from" name="from" required>
        @foreach ($airports as $code => $name)
            <option value="{{ $code }}" @selected(old('from', $form['from'] ?? 'GOI') === $code)>{{ $name }} ({{ $code }})</option>
        @endforeach
    </select>
    @error('from') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="to">To</label>
    <select id="to" name="to" required>
        @foreach ($airports as $code => $name)
            <option value="{{ $code }}" @selected(old('to', $form['to'] ?? 'BLR') === $code)>{{ $name }} ({{ $code }})</option>
        @endforeach
    </select>
    @error('to') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="date">Departure date</label>
    <input id="date" type="date" name="date" value="{{ old('date', $form['date'] ?? '') }}" required>
    @error('date') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="time">Departure time</label>
    <select id="time" name="time" required>
        @foreach ($times as $t)
            <option value="{{ $t }}" @selected(old('time', $form['time'] ?? '18:30') === $t)>{{ $t }}</option>
        @endforeach
    </select>
    @error('time') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div class="span-2">
    <label for="has_return" style="display: flex; gap: 10px; align-items: center">
        <input id="has_return" type="checkbox" name="has_return" value="1" @checked(old('has_return', $form['has_return'] ?? false))>
        Add a return flight
    </label>
</div>
<div>
    <label for="return_date">Return date</label>
    <input id="return_date" type="date" name="return_date" value="{{ old('return_date', $form['return_date'] ?? '') }}">
    @error('return_date') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="return_time">Return time</label>
    <select id="return_time" name="return_time">
        @foreach ($times as $t)
            <option value="{{ $t }}" @selected(old('return_time', $form['return_time'] ?? '18:30') === $t)>{{ $t }}</option>
        @endforeach
    </select>
    @error('return_time') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="pax">Passengers</label>
    <select id="pax" name="pax" required>
        @foreach (range(1, 9) as $n)
            <option value="{{ $n }}" @selected((int) old('pax', $form['pax'] ?? 3) === $n)>{{ $n }}</option>
        @endforeach
    </select>
    @error('pax') <p class="field-error">{{ $message }}</p> @enderror
</div>
