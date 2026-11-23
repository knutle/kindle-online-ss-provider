<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_weather_dashboard_is_public_at_its_new_url(): void
    {
        $this->get('/dashboard')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('weather-dashboard')->where('calendar', null),
        );
    }

    public function test_root_redirects_to_weather_dashboard_preserving_query(): void
    {
        $query = [
            'latitude' => '59.91',
            'calendar' => ['https://example.com/a.ics?x=1&y=2', 'webcal://example.com/b.ics'],
        ];

        $response = $this->get('/?'.http_build_query($query));
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertSame('/dashboard', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $redirectedQuery);
        $this->assertSame($query, $redirectedQuery);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->assertSame('/account/dashboard', route('dashboard', absolute: false));
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }
}
