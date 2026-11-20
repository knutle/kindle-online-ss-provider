<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardImageTest extends TestCase
{
    public function test_it_renders_the_dashboard_as_a_kindle_resolution_png(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response($this->forecast()),
        ]);

        $response = $this->get('/dashboard.png?latitude=59.91&longitude=10.75&label=Oslo');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="kindle-dashboard.png"');

        $image = new \Imagick;
        $image->readImageBlob($response->getContent());

        $this->assertSame(1236, $image->getImageWidth());
        $this->assertSame(1648, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
        $this->assertGreaterThan(1, $image->getImageColors());

        $image->clear();
    }

    public function test_it_accepts_a_city_and_geocodes_it(): void
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
            'api.open-meteo.com/*' => Http::response($this->forecast()),
        ]);

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

    /**
     * @return array<string, mixed>
     */
    private function forecast(): array
    {
        return [
            'timezone' => 'Europe/Oslo',
            'current' => [
                'temperature_2m' => 8.5,
                'apparent_temperature' => 6.1,
                'relative_humidity_2m' => 67,
                'wind_speed_10m' => 9.2,
                'precipitation' => 0,
                'weather_code' => 2,
                'is_day' => 1,
            ],
            'daily' => [
                'time' => ['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24'],
                'weather_code' => [2, 61, 3, 0, 71],
                'temperature_2m_max' => [10, 9, 8, 7, 5],
                'temperature_2m_min' => [4, 3, 2, 1, -1],
                'precipitation_probability_max' => [0, 80, 10, 0, 30],
            ],
        ];
    }
}
