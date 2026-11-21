<?php

namespace App\Http\Controllers;

use App\Services\DashboardImageRenderer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\Browsershot\Exceptions\ElementNotFound;
use Spatie\Browsershot\Exceptions\RemoteConnectionException;
use Spatie\Browsershot\Exceptions\UnsuccessfulResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DashboardImageController extends Controller
{
    private const WIDTH = 1236;

    private const HEIGHT = 1601;

    public function __invoke(Request $request, DashboardImageRenderer $renderer): Response
    {
        $validator = Validator::make($request->query(), [
            'city' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        if ($validator->fails()) {
            return response($validator->errors()->first(), 422, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $input = $validator->validated();

        if (filled($input['city'] ?? null)) {
            $location = $this->findCity($input['city']);
        } elseif (isset($input['latitude'], $input['longitude'])) {
            $location = [
                'latitude' => (float) $input['latitude'],
                'longitude' => (float) $input['longitude'],
                'name' => $input['label'] ?? sprintf(
                    '%.4f°, %.4f°',
                    $input['latitude'],
                    $input['longitude'],
                ),
            ];
        } else {
            return response('Use ?city=London or provide both latitude and longitude.', 422, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $dashboardUrl = rtrim(config('app.url'), '/').'/?'.http_build_query([
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'label' => $location['name'],
            'timezone' => $input['timezone'] ?? null,
            'screenshot' => '1',
        ], '', '&', PHP_QUERY_RFC3986);

        try {
            $png = $renderer->render($dashboardUrl, self::WIDTH, self::HEIGHT);
        } catch (ProcessFailedException|ElementNotFound|RemoteConnectionException|UnsuccessfulResponse $exception) {
            report($exception);

            abort(503, 'The dashboard could not be rendered by the browser.');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="kindle-dashboard.png"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @return array{latitude: float, longitude: float, name: string}
     */
    private function findCity(string $city): array
    {
        try {
            $response = Http::timeout(8)->get(
                'https://geocoding-api.open-meteo.com/v1/search',
                [
                    'name' => $city,
                    'count' => 1,
                    'language' => 'en',
                    'format' => 'json',
                ],
            );
        } catch (ConnectionException) {
            abort(502, 'The location service is unavailable.');
        }

        if (! $response->successful()) {
            abort(502, 'The location service is unavailable.');
        }

        $match = $response->json('results.0');

        if (! is_array($match)) {
            abort(422, 'No matching city was found.');
        }

        return [
            'latitude' => (float) $match['latitude'],
            'longitude' => (float) $match['longitude'],
            'name' => implode(', ', array_filter([
                $match['name'] ?? $city,
                $match['admin1'] ?? null,
                $match['country'] ?? null,
            ])),
        ];
    }
}
