<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the trip plan. Times are "wall clock" times at the place (no time zone), so a calendar
 * shows exactly what the organiser typed. No time = an all-day event.
 */
class ItineraryItem extends Model
{
    // Stored word => label on the screen.
    public const KINDS = [
        'stay' => 'Stay',
        'flight' => 'Flight',
        'event' => 'Event or tickets',
        'meal' => 'Meal',
        'other' => 'Other',
    ];

    protected $fillable = [
        'trip_id', 'created_by_member_id', 'kind', 'title', 'starts_on', 'starts_time',
        'ends_on', 'ends_time', 'place', 'address', 'notes',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Other';
    }

    public function isAllDay(): bool
    {
        return $this->starts_time === null;
    }

    /** The first moment, as a plain local date and time. */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->starts_on->format('Y-m-d').' '.($this->clock($this->starts_time) ?? '00:00'));
    }

    /**
     * The end. For an all-day item this is the day AFTER the last day (calendar files count the end as
     * "up to, but not including"). For a timed item: the given end, else the same clock time on the end
     * date, else one hour after the start.
     */
    public function endsAt(): Carbon
    {
        if ($this->isAllDay()) {
            return Carbon::parse(($this->ends_on ?? $this->starts_on)->format('Y-m-d'))->addDay();
        }

        if ($this->ends_on === null && $this->ends_time === null) {
            return $this->startsAt()->copy()->addHour();
        }

        $date = ($this->ends_on ?? $this->starts_on)->format('Y-m-d');

        return Carbon::parse($date.' '.($this->clock($this->ends_time) ?? $this->clock($this->starts_time)));
    }

    /** Short sentence for the Plan page: "14:00", "14:00 to 11:00 on 15 Dec", "All day", "All day until 15 Dec". */
    public function whenText(): string
    {
        $endDay = $this->ends_on !== null && ! $this->ends_on->isSameDay($this->starts_on) ? $this->ends_on : null;

        if ($this->isAllDay()) {
            return $endDay ? 'All day until '.$endDay->format('j M') : 'All day';
        }

        $start = $this->clock($this->starts_time);
        $endTime = $this->clock($this->ends_time);

        if ($endDay) {
            return $start.' to '.($endTime ?? $start).' on '.$endDay->format('j M');
        }

        return $endTime ? $start.' to '.$endTime : $start;
    }

    // MySQL gives "14:00:00"; we only ever show and compare hours and minutes.
    private function clock(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
