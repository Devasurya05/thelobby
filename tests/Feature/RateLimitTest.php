<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * Creating trips, joining a trip and the owner login each have their own limit and their own counter.
 * (Limits written without a name share ONE counter per visitor, so using up one would block the others.)
 */
class RateLimitTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private function create(string $name = 'Trip')
    {
        return $this->post('/trips', ['setup' => 'booking', 'trip_name' => $name, 'organiser_name' => 'Asha']);
    }

    public function test_creating_ten_trips_is_allowed_then_it_stops_but_joining_still_works(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->create("Trip $i")->assertRedirect();
        }
        $this->create('Trip 11')->assertStatus(429);

        // The join limit has its own counter, so it is untouched.
        $code = Trip::firstOrFail()->join_code;
        $this->post('/join', ['code' => $code, 'name' => 'Ravi'])->assertRedirect(route('lobby', $code));
    }

    public function test_joining_ten_times_is_allowed_then_it_stops_but_creating_a_trip_still_works(): void
    {
        $trip = $this->makeTrip();

        for ($i = 1; $i <= 10; $i++) {
            $this->post('/join', ['code' => $trip->join_code, 'name' => "Guest $i"])->assertRedirect();
        }
        $this->post('/join', ['code' => $trip->join_code, 'name' => 'Guest 11'])->assertStatus(429);

        $this->create()->assertRedirect();
    }

    public function test_the_owner_login_has_its_own_small_limit(): void
    {
        config(['lobby.admin_key' => str_repeat('k', 20)]);

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/admin/login', ['key' => 'wrong'])->assertSessionHasErrors('key');
        }
        $this->post('/admin/login', ['key' => 'wrong'])->assertStatus(429);

        // Trips and joins are not blocked by someone guessing the owner key.
        $this->create()->assertRedirect();
    }
}
