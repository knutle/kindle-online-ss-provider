<?php

namespace Tests\Feature;

use App\Services\DashboardImageRenderer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardImageTest extends TestCase
{
    public function test_it_renders_the_dashboard_at_kindle_resolution_in_a_browser(): void
    {
        $this->mock(DashboardImageRenderer::class)
            ->shouldReceive('render')
            ->once()
            ->withArgs(function (string $url, int $width, int $height): bool {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return $width === 1236
                    && $height === 1601
                    && ($query['latitude'] ?? null) === '59.91'
                    && ($query['longitude'] ?? null) === '10.75'
                    && ($query['label'] ?? null) === 'Oslo'
                    && ($query['screenshot'] ?? null) === '1';
            })
            ->andReturn('browser-rendered-png');

        $this->get('/dashboard.png?latitude=59.91&longitude=10.75&label=Oslo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="kindle-dashboard.png"')
            ->assertSee('browser-rendered-png', false);
    }

    public function test_it_geocodes_a_city_before_rendering_the_dashboard(): void
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => Http::response([
                'results' => [[
                    'latitude' => 51.5072,
                    'longitude' => -0.1276,
                    'name' => 'London',
                    'admin1' => 'England',
                    'country' => 'United Kingdom',
                ]],
            ]),
        ]);

        $this->mock(DashboardImageRenderer::class)
            ->shouldReceive('render')
            ->once()
            ->withArgs(function (string $url): bool {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return ($query['latitude'] ?? null) === '51.5072'
                    && ($query['longitude'] ?? null) === '-0.1276'
                    && ($query['label'] ?? null) === 'London, England, United Kingdom';
            })
            ->andReturn('browser-rendered-png');

        $this->get('/dashboard.png?city=London')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'geocoding-api.open-meteo.com')
            && $request['name'] === 'London');
    }

    public function test_it_requires_a_city_or_coordinate_pair(): void
    {
        $this->get('/dashboard.png')
            ->assertUnprocessable()
            ->assertSeeText('Use ?city=London or provide both latitude and longitude.');
    }

    public function test_it_forwards_the_calendar_url_to_the_rendered_dashboard(): void
    {
        $calendar = 'https://example.com/cal.ics?token=a&b=c';

        $this->mock(DashboardImageRenderer::class)
            ->shouldReceive('render')
            ->once()
            ->withArgs(function (string $url) use ($calendar): bool {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return ($query['calendar'] ?? null) === $calendar;
            })
            ->andReturn('png');

        $this->get('/dashboard.png?latitude=59.91&longitude=10.75&calendar='.rawurlencode($calendar))
            ->assertOk();
    }
}
