<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ItineraryItem;
use App\Models\Member;
use App\Models\Trip;
use App\Services\ItineraryService;
use App\Support\MemberToken;
use App\Support\PlanLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Plan page: who may change it, validation, calendar files (.ics) and map links.
 */
class PlanTest extends TestCase
{
    use RefreshDatabase;

    // ---- helpers -------------------------------------------------------------------------------

    private function makeTrip(string $name = 'Goa trip'): Trip
    {
        return Trip::create(['name' => $name, 'join_code' => Trip::newJoinCode(), 'starts_on' => '2026-12-12', 'ends_on' => '2026-12-15']);
    }

    /** @return array{0: Member, 1: string} */
    private function makeMember(Trip $trip, string $name, string $role = Member::ROLE_MEMBER): array
    {
        $token = MemberToken::generate();

        return [
            $trip->members()->create(['name' => $name, 'role' => $role, 'token_hash' => MemberToken::hash($token)]),
            $token,
        ];
    }

    private function as(Trip $trip, string $token)
    {
        return $this->withCookie(MemberToken::cookieName($trip->join_code), $token)->withCredentials();
    }

    private function url(Trip $trip, string $path): string
    {
        return "/t/{$trip->join_code}/{$path}";
    }

    private function addItem(Trip $trip, array $data = []): ItineraryItem
    {
        return $trip->itineraryItems()->create($data + [
            'kind' => 'stay', 'title' => 'Hotel Sunrise', 'starts_on' => '2026-12-12', 'starts_time' => '14:00',
            'ends_on' => '2026-12-15', 'ends_time' => '11:00', 'place' => 'Hotel Sunrise', 'address' => 'Candolim, Goa',
        ]);
    }

    /** A calendar file as plain text with the 75-byte line folds undone, so tests can search it easily. */
    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    // ---- who may change the plan -------------------------------------------------------------------

    public function test_organiser_adds_an_item_and_it_appears_with_an_audit_row(): void
    {
        $trip = $this->makeTrip();
        [$org, $token] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->as($trip, $token)->post($this->url($trip, 'plan'), [
            'kind' => 'stay', 'title' => 'Hotel Sunrise', 'starts_on' => '2026-12-12', 'starts_time' => '14:00',
            'ends_on' => '2026-12-15', 'ends_time' => '11:00', 'place' => 'Hotel Sunrise', 'address' => 'Candolim, Goa',
        ])->assertRedirect($this->url($trip, 'plan'))->assertSessionHas('status');

        $item = $trip->itineraryItems()->firstOrFail();
        $this->assertSame('Hotel Sunrise', $item->title);
        $this->assertSame($org->id, $item->created_by_member_id);

        $log = AuditLog::where('action', 'plan_item_added')->firstOrFail();
        $this->assertSame($org->id, $log->member_id);
        $this->assertSame('stay', $log->details['kind']);

        $this->as($trip, $token)->get($this->url($trip, 'plan'))->assertOk()->assertSee('Hotel Sunrise')->assertSee('14:00 to 11:00 on 15 Dec');
    }

    public function test_members_and_viewers_can_read_the_plan_but_not_change_it(): void
    {
        $trip = $this->makeTrip();
        [, $member] = $this->makeMember($trip, 'Mia');
        [, $viewer] = $this->makeMember($trip, 'Vik', Member::ROLE_VIEWER);
        $item = $this->addItem($trip);

        foreach ([$member, $viewer] as $token) {
            $this->as($trip, $token)->get($this->url($trip, 'plan'))->assertOk()->assertSee('Hotel Sunrise')->assertDontSee('Add to the plan');
            $this->as($trip, $token)->post($this->url($trip, 'plan'), ['kind' => 'stay', 'title' => 'Sneaky', 'starts_on' => '2026-12-12'])->assertForbidden();
            $this->as($trip, $token)->post($this->url($trip, "plan/{$item->id}/delete"))->assertForbidden();
        }

        $this->assertSame(1, $trip->itineraryItems()->count());
        $this->assertSame('Hotel Sunrise', $trip->itineraryItems()->first()->title);
    }

    public function test_the_service_refuses_a_non_organiser_and_logs_it(): void
    {
        $trip = $this->makeTrip();
        [$mia] = $this->makeMember($trip, 'Mia');

        $result = app(ItineraryService::class)->add($trip, $mia, ['kind' => 'stay', 'title' => 'X', 'starts_on' => '2026-12-12']);

        $this->assertFalse($result->ok);
        $this->assertSame(403, $result->status);
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame('plan_add', AuditLog::where('action', 'action_refused')->firstOrFail()->details['attempted']);
    }

    public function test_a_removed_organiser_cannot_change_the_plan(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $org->forceFill(['removed_at' => now()])->save();

        $result = app(ItineraryService::class)->add($trip, $org->fresh(), ['kind' => 'stay', 'title' => 'X', 'starts_on' => '2026-12-12']);

        $this->assertFalse($result->ok);
        $this->assertSame(0, $trip->itineraryItems()->count());
    }

    public function test_nothing_can_be_added_after_the_trip_has_ended(): void
    {
        $trip = $this->makeTrip();
        [$org, $token] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $trip->forceFill(['ended_at' => now()])->save();

        $result = app(ItineraryService::class)->add($trip->fresh(), $org, ['kind' => 'stay', 'title' => 'Late', 'starts_on' => '2026-12-12']);

        $this->assertFalse($result->ok);
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->as($trip, $token)->get($this->url($trip, 'plan'))->assertOk()->assertDontSee('Add to the plan');
    }

    public function test_the_plan_has_a_size_limit(): void
    {
        config(['lobby.plan.max_items' => 2]);
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $service = app(ItineraryService::class);
        $row = ['kind' => 'other', 'title' => 'Thing', 'starts_on' => '2026-12-12'];

        $this->assertTrue($service->add($trip, $org, $row)->ok);
        $this->assertTrue($service->add($trip, $org, $row)->ok);
        $third = $service->add($trip, $org, $row);

        $this->assertFalse($third->ok);
        $this->assertStringContainsString('full', $third->message);
        $this->assertSame(2, $trip->itineraryItems()->count());
    }

    // ---- delete ------------------------------------------------------------------------------------

    public function test_organiser_removes_an_item_and_it_is_logged(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $item = $this->addItem($trip);

        $this->as($trip, $token)->post($this->url($trip, "plan/{$item->id}/delete"))->assertRedirect($this->url($trip, 'plan'));

        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame('Hotel Sunrise', AuditLog::where('action', 'plan_item_deleted')->firstOrFail()->details['title']);
    }

    public function test_an_item_from_another_trip_cannot_be_removed_or_downloaded(): void
    {
        $trip = $this->makeTrip();
        $other = $this->makeTrip('Other trip');
        [, $token] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $foreign = $this->addItem($other, ['title' => 'Secret plan']);

        $this->as($trip, $token)->post($this->url($trip, "plan/{$foreign->id}/delete"))->assertRedirect()->assertSessionHas('problem');
        $this->assertSame(1, $other->itineraryItems()->count());

        $this->as($trip, $token)->get($this->url($trip, "plan/{$foreign->id}.ics"))->assertNotFound();
    }

    // ---- validation --------------------------------------------------------------------------------

    public function test_bad_input_is_rejected_with_messages_and_nothing_is_saved(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $url = $this->url($trip, 'plan');
        $ok = ['kind' => 'stay', 'title' => 'Fine', 'starts_on' => '2026-12-12'];

        $this->as($trip, $token)->post($url, ['kind' => 'rocket'] + $ok)->assertSessionHasErrors('kind');
        $this->as($trip, $token)->post($url, ['title' => ''] + $ok)->assertSessionHasErrors('title');
        $this->as($trip, $token)->post($url, ['title' => str_repeat('a', 121)] + $ok)->assertSessionHasErrors('title');
        $this->as($trip, $token)->post($url, ['starts_on' => 'tomorrow'] + $ok)->assertSessionHasErrors('starts_on');
        $this->as($trip, $token)->post($url, ['ends_on' => '2026-12-01'] + $ok)->assertSessionHasErrors('ends_on');
        $this->as($trip, $token)->post($url, ['starts_time' => '25:99'] + $ok)->assertSessionHasErrors('starts_time');
        $this->as($trip, $token)->post($url, ['notes' => str_repeat('n', 501)] + $ok)->assertSessionHasErrors('notes');
        // Same day, end clock time before the start clock time.
        $this->as($trip, $token)->post($url, ['starts_time' => '14:00', 'ends_time' => '09:00'] + $ok)->assertSessionHasErrors('ends_time');

        $this->assertSame(0, $trip->itineraryItems()->count());
    }

    public function test_an_all_day_item_needs_no_times(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->as($trip, $token)->post($this->url($trip, 'plan'), ['kind' => 'other', 'title' => 'Rest day', 'starts_on' => '2026-12-13'])
            ->assertSessionHasNoErrors();

        $item = $trip->itineraryItems()->firstOrFail();
        $this->assertTrue($item->isAllDay());
        $this->assertSame('All day', $item->whenText());
    }

    // ---- calendar files ----------------------------------------------------------------------------

    public function test_a_timed_item_is_written_as_local_times_with_a_default_one_hour_end(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $stay = $this->addItem($trip);
        $dinner = $this->addItem($trip, ['kind' => 'meal', 'title' => 'Dinner', 'starts_on' => '2026-12-13', 'starts_time' => '20:00', 'ends_on' => null, 'ends_time' => null]);

        $stayFile = $this->as($trip, $token)->get($this->url($trip, "plan/{$stay->id}.ics"))->assertOk()->getContent();
        $dinnerFile = $this->as($trip, $token)->get($this->url($trip, "plan/{$dinner->id}.ics"))->assertOk()->getContent();

        $this->assertStringContainsString("DTSTART:20261212T140000\r\n", $stayFile);
        $this->assertStringContainsString("DTEND:20261215T110000\r\n", $stayFile);
        $this->assertStringContainsString('LOCATION:Hotel Sunrise\\, Candolim\\, Goa', $this->unfold($stayFile));
        $this->assertStringContainsString("DTSTART:20261213T200000\r\n", $dinnerFile);
        $this->assertStringContainsString("DTEND:20261213T210000\r\n", $dinnerFile);
    }

    public function test_an_all_day_item_ends_the_day_after_its_last_day(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $item = $this->addItem($trip, ['title' => 'Villa', 'starts_time' => null, 'ends_time' => null]);

        $file = $this->as($trip, $token)->get($this->url($trip, "plan/{$item->id}.ics"))->getContent();

        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261212\r\n", $file);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261216\r\n", $file); // end is "up to but not including"
    }

    public function test_the_trip_calendar_has_the_trip_dates_and_every_item_and_is_not_cached(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $this->addItem($trip);
        $this->addItem($trip, ['title' => 'Flight home', 'kind' => 'flight', 'starts_on' => '2026-12-15', 'starts_time' => '18:30', 'ends_on' => null, 'ends_time' => null]);

        $response = $this->as($trip, $token)->get($this->url($trip, 'calendar.ics'))->assertOk();
        $file = $response->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $file);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $file);
        $this->assertSame(3, substr_count($file, 'BEGIN:VEVENT')); // trip dates + two items
        $this->assertStringContainsString('SUMMARY:Goa trip', $file);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261212\r\n", $file);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261216\r\n", $file);
        $this->assertStringContainsString('text/calendar', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="goa-trip.ics"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_text_is_escaped_so_a_title_cannot_inject_a_second_event(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $item = $this->addItem($trip, ['title' => "Lunch, then\nEND:VEVENT\nBEGIN:VEVENT; evil\\", 'notes' => "line1\r\nline2"]);

        $file = $this->as($trip, $token)->get($this->url($trip, "plan/{$item->id}.ics"))->getContent();

        // The words appear inside the escaped SUMMARY text, but only ONE line of the file is a real event marker.
        $lines = explode("\r\n", $file);
        $this->assertSame(1, count(array_keys($lines, 'BEGIN:VEVENT', true)));
        $this->assertSame(1, count(array_keys($lines, 'END:VEVENT', true)));
        $this->assertStringContainsString('SUMMARY:Lunch\\, then\\nEND:VEVENT\\nBEGIN:VEVENT\\; evil\\\\', $this->unfold($file));
        $this->assertStringContainsString('DESCRIPTION:line1\\nline2', $this->unfold($file));
    }

    public function test_long_lines_are_folded_at_75_bytes_without_splitting_characters(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $note = str_repeat('Zürich Bahnhofstraße ', 20); // multi-byte characters, well over 75 bytes
        $item = $this->addItem($trip, ['notes' => mb_substr($note, 0, 400)]);

        $file = $this->as($trip, $token)->get($this->url($trip, "plan/{$item->id}.ics"))->getContent();

        foreach (explode("\r\n", $file) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'line too long: '.$line);
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'a character was cut in half');
        }
        $this->assertStringContainsString(mb_substr(trim($note), 0, 100), $this->unfold($file)); // nothing lost
    }

    public function test_strangers_cannot_download_calendar_files(): void
    {
        $trip = $this->makeTrip();
        $item = $this->addItem($trip);

        $this->get($this->url($trip, 'calendar.ics'))->assertRedirect(route('join.show', $trip->join_code));
        $this->get($this->url($trip, "plan/{$item->id}.ics"))->assertRedirect(route('join.show', $trip->join_code));
        $this->get($this->url($trip, 'plan'))->assertRedirect(route('join.show', $trip->join_code));
    }

    // ---- map and Google Calendar links ---------------------------------------------------------------

    public function test_map_link_uses_place_and_address_and_is_missing_when_both_are_empty(): void
    {
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=Hotel%20Sunrise%2C%20Candolim%2C%20Goa',
            PlanLinks::map('Hotel Sunrise', 'Candolim, Goa'),
        );
        $this->assertSame('https://www.google.com/maps/search/?api=1&query=Goa%20airport', PlanLinks::map(null, 'Goa airport'));
        $this->assertNull(PlanLinks::map(null, null));
        $this->assertNull(PlanLinks::map('  ', ''));
    }

    public function test_google_calendar_links_carry_the_right_dates(): void
    {
        $trip = $this->makeTrip();
        $timed = $this->addItem($trip);
        $allDay = $this->addItem($trip, ['title' => 'Villa', 'starts_time' => null, 'ends_time' => null]);

        $this->assertStringContainsString('dates=20261212T140000%2F20261215T110000', PlanLinks::googleCalendar($timed));
        $this->assertStringContainsString('dates=20261212%2F20261216', PlanLinks::googleCalendar($allDay));
        $this->assertStringContainsString('dates=20261212%2F20261216', PlanLinks::googleCalendarForTrip($trip));
        $this->assertStringContainsString('location=Hotel%20Sunrise%2C%20Candolim%2C%20Goa', PlanLinks::googleCalendar($timed));
    }

    public function test_the_plan_page_shows_map_links_and_escapes_user_text(): void
    {
        $trip = $this->makeTrip();
        [, $token] = $this->makeMember($trip, 'Mia');
        $this->addItem($trip, ['title' => '<script>alert(1)</script>', 'place' => '<b>Place</b>']);
        $this->addItem($trip, ['title' => 'No location', 'place' => null, 'address' => null]);

        $page = $this->as($trip, $token)->get($this->url($trip, 'plan'))->assertOk();

        $page->assertSee('Open in maps')
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>Place</b>', false);
        $this->assertSame(1, substr_count($page->getContent(), 'Open in maps')); // the item with no place has no map link
    }
}
