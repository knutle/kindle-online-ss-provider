<?php

namespace App\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DashboardImageController extends Controller
{
    private const WIDTH = 1236;

    private const HEIGHT = 1648;

    public function __invoke(Request $request): Response
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

        $weather = $this->fetchWeather($location);
        $timezone = $input['timezone'] ?? $weather['timezone'] ?? 'UTC';
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return response($this->renderPng($location, $weather, $now, $timezone), 200, [
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

    /**
     * @param  array{latitude: float, longitude: float, name: string}  $location
     * @return array<string, mixed>
     */
    private function fetchWeather(array $location): array
    {
        $cacheKey = 'dashboard-png-weather:'.hash(
            'sha256',
            sprintf('%.4F:%.4F', $location['latitude'], $location['longitude']),
        );

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($location): array {
            try {
                $response = Http::timeout(10)->get(
                    'https://api.open-meteo.com/v1/forecast',
                    [
                        'latitude' => $location['latitude'],
                        'longitude' => $location['longitude'],
                        'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,is_day,wind_speed_10m',
                        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                        'temperature_unit' => 'celsius',
                        'wind_speed_unit' => 'kmh',
                        'timezone' => 'auto',
                        'forecast_days' => 5,
                    ],
                );
            } catch (ConnectionException) {
                abort(502, 'The weather service is unavailable.');
            }

            if (! $response->successful()) {
                abort(502, 'The weather service is unavailable.');
            }

            $weather = $response->json();

            if (! is_array($weather) || ! isset($weather['current'], $weather['daily']['time'][0])) {
                abort(502, 'The weather service returned an invalid forecast.');
            }

            return $weather;
        });
    }

    /**
     * @param  array{latitude: float, longitude: float, name: string}  $location
     * @param  array<string, mixed>  $weather
     */
    private function renderPng(array $location, array $weather, DateTimeImmutable $now, string $timezone): string
    {
        if (! extension_loaded('imagick')) {
            abort(503, 'PNG rendering requires the PHP Imagick extension.');
        }

        $current = $weather['current'];
        $daily = $weather['daily'];
        $image = new \Imagick;
        $image->newImage(self::WIDTH, self::HEIGHT, new \ImagickPixel('#fff'), 'png');
        $image->setImageColorspace(\Imagick::COLORSPACE_GRAY);
        $image->setImageResolution(300, 300);
        $this->line($image, 64, 112, 1172, 112);
        $this->text($image, 64, 78, 'K  DAILY BRIEF', 28, false, 700, 0.13);
        $this->text($image, 1172, 78, $now->format('l, F j, Y'), 24, true, 400, 0, 'right');
        $this->text($image, 618, 190, 'LOCAL TIME', 19, false, 700, 0.16, 'center');
        $this->text($image, 618, 390, $now->format('H:i'), 174, true, 400, -0.04, 'center');
        $this->line($image, 64, 455, 1172, 455);
        $this->text($image, 64, 518, 'YOUR LOCAL FORECAST', 18, false, 700, 0.16);
        $this->text($image, 64, 566, $location['name'], 38);
        $this->weatherIcon(
            $image,
            (int) $current['weather_code'],
            64,
            618,
            128,
            (int) $current['is_day'] === 1,
        );
        $this->text($image, 222, 744, round((float) $current['temperature_2m']).'°', 142, true, 400, -0.05);
        $this->text($image, 226, 790, $this->weatherDescription((int) $current['weather_code']), 26);
        $this->text($image, 1172, 718, 'H: '.round((float) $daily['temperature_2m_max'][0]).'°  /  L: '.round((float) $daily['temperature_2m_min'][0]).'°', 24, true, 400, 0, 'right');
        $this->text($image, 1172, 758, 'Feels like '.round((float) $current['apparent_temperature']).'°', 22, true, 400, 0, 'right');
        $this->line($image, 64, 840, 1172, 840);
        $this->text($image, 64, 888, 'HUMIDITY', 17, false, 400, 0.04);
        $this->text($image, 64, 930, (int) $current['relative_humidity_2m'].'%', 26, true, 700);
        $this->text($image, 470, 888, 'WIND', 17, false, 400, 0.04);
        $this->text($image, 470, 930, round((float) $current['wind_speed_10m']).' km/h', 26, true, 700);
        $this->text($image, 1172, 888, 'PRECIPITATION', 17, false, 400, 0.04, 'right');
        $this->text($image, 1172, 930, (float) $current['precipitation'].' mm', 26, true, 700, 0, 'right');
        $this->line($image, 64, 970, 1172, 970);
        $this->text($image, 64, 1030, '5-DAY FORECAST', 18, false, 700, 0.16);

        $columnWidth = 1108 / 5;

        foreach (array_slice($daily['time'], 0, 5) as $index => $date) {
            $x = 64 + ($columnWidth * $index);
            $center = $x + ($columnWidth / 2);

            if ($index > 0) {
                $this->line($image, (int) $x, 1060, (int) $x, 1382, '#aaa');
            }

            $day = match ($index) {
                0 => 'Today',
                1 => 'Tomorrow',
                default => (new DateTimeImmutable($date.'T12:00:00', new DateTimeZone($timezone)))->format('D'),
            };
            $this->text($image, (int) $center, 1092, $day, 22, true, 400, 0, 'center');
            $this->weatherIcon($image, (int) $daily['weather_code'][$index], (int) ($center - 30), 1120, 60);
            $this->text($image, (int) $center, 1225, $this->weatherDescription((int) $daily['weather_code'][$index]), 18, true, 400, 0, 'center');
            $this->text($image, (int) $center, 1276, round((float) $daily['temperature_2m_max'][$index]).'°   '.round((float) $daily['temperature_2m_min'][$index]).'°', 22, true, 700, 0, 'center');
            $this->text($image, (int) $center, 1316, (int) ($daily['precipitation_probability_max'][$index] ?? 0).'% rain', 17, false, 400, 0, 'center');
        }

        $this->text($image, 64, 1575, 'MADE FOR A MOMENT OF CALM', 15, false, 700, 0.1);
        $this->text($image, 1172, 1575, 'WEATHER BY OPEN-METEO', 15, false, 700, 0.1, 'right');
        $image->setImageFormat('png');

        try {
            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
    }

    private function text(
        \Imagick $image,
        int $x,
        int $y,
        string $text,
        int $size,
        bool $serif = true,
        int $weight = 400,
        float $letterSpacing = 0,
        string $alignment = 'left',
    ): void {
        $draw = new \ImagickDraw;
        $draw->setFillColor('#171717');
        $draw->setFont($this->fontPath($serif, $weight));
        $draw->setFontSize($size);
        $draw->setTextAntialias(true);
        $draw->setTextAlignment(match ($alignment) {
            'center' => \Imagick::ALIGN_CENTER,
            'right' => \Imagick::ALIGN_RIGHT,
            default => \Imagick::ALIGN_LEFT,
        });
        $draw->setTextKerning($size * $letterSpacing);
        $image->annotateImage($draw, $x, $y, 0, $text);
    }

    private function fontPath(bool $serif, int $weight): string
    {
        static $resolved = [];
        $key = ($serif ? 'serif' : 'sans').($weight >= 600 ? '-bold' : '-regular');

        if (isset($resolved[$key])) {
            return $resolved[$key];
        }

        $candidates = $serif
            ? ($weight >= 600
                ? [
                    '/System/Library/Fonts/Supplemental/Georgia Bold.ttf',
                    '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
                    '/usr/share/fonts/truetype/liberation2/LiberationSerif-Bold.ttf',
                    '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf',
                ]
                : [
                    '/System/Library/Fonts/Supplemental/Georgia.ttf',
                    '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
                    '/usr/share/fonts/truetype/liberation2/LiberationSerif-Regular.ttf',
                    '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
                ])
            : ($weight >= 600
                ? [
                    '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
                    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                    '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                ]
                : [
                    '/System/Library/Fonts/Supplemental/Arial.ttf',
                    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                    '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
                    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $resolved[$key] = $candidate;
            }
        }

        abort(503, 'PNG rendering requires system serif and sans-serif TrueType fonts.');
    }

    private function line(\Imagick $image, int $x1, int $y1, int $x2, int $y2, string $color = '#171717'): void
    {
        $draw = new \ImagickDraw;
        $draw->setStrokeColor($color);
        $draw->setStrokeWidth(1.5);
        $draw->line($x1, $y1, $x2, $y2);
        $image->drawImage($draw);
    }

    private function weatherIcon(
        \Imagick $image,
        int $code,
        int $x,
        int $y,
        int $size,
        bool $isDay = true,
    ): void {
        $scale = $size / 100;
        $point = static fn (int $value, int $offset): float => $offset + ($value * $scale);
        $draw = new \ImagickDraw;
        $draw->setStrokeColor('#171717');
        $draw->setStrokeWidth(max(2, 5 * $scale));
        $draw->setStrokeLineCap(\Imagick::LINECAP_ROUND);
        $draw->setFillColor('#fff');

        if ($code === 0 && $isDay || $code > 0 && $code <= 3) {
            $draw->circle($point(50, $x), $point(42, $y), $point(70, $x), $point(42, $y));
            foreach ([0, 45, 90, 135, 180, 225, 270, 315] as $angle) {
                $radians = deg2rad($angle);
                $draw->line(
                    $point((int) (50 + (31 * cos($radians))), $x),
                    $point((int) (42 + (31 * sin($radians))), $y),
                    $point((int) (50 + (42 * cos($radians))), $x),
                    $point((int) (42 + (42 * sin($radians))), $y),
                );
            }
        }

        if ($code !== 0 || ! $isDay) {
            $draw->pathStart();
            $draw->pathMoveToAbsolute($point(19, $x), $point(74, $y));
            $draw->pathLineToAbsolute($point(73, $x), $point(74, $y));
            $draw->pathCurveToAbsolute($point(91, $x), $point(74, $y), $point(93, $x), $point(48, $y), $point(75, $x), $point(44, $y));
            $draw->pathCurveToAbsolute($point(69, $x), $point(20, $y), $point(36, $x), $point(19, $y), $point(28, $x), $point(43, $y));
            $draw->pathCurveToAbsolute($point(8, $x), $point(39, $y), $point(6, $x), $point(70, $y), $point(19, $x), $point(74, $y));
            $draw->pathClose();
            $draw->pathFinish();
        }

        if ($code >= 95) {
            $draw->polyline([
                ['x' => $point(56, $x), 'y' => $point(71, $y)],
                ['x' => $point(47, $x), 'y' => $point(84, $y)],
                ['x' => $point(56, $x), 'y' => $point(84, $y)],
                ['x' => $point(45, $x), 'y' => $point(99, $y)],
            ]);
        } elseif ($code >= 51 && $code <= 67 || $code >= 80 && $code <= 82) {
            foreach ([35, 57, 79] as $offset) {
                $draw->line($point($offset, $x), $point(81, $y), $point($offset - 7, $x), $point(94, $y));
            }
        } elseif ($code >= 71 && $code <= 86) {
            foreach ([35, 57, 79] as $offset) {
                $draw->line($point($offset, $x), $point(82, $y), $point($offset, $x), $point(92, $y));
                $draw->line($point($offset - 5, $x), $point(87, $y), $point($offset + 5, $x), $point(87, $y));
            }
        }

        $image->drawImage($draw);
    }

    private function weatherDescription(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear sky',
            $code <= 3 => 'Partly cloudy',
            $code === 45 || $code === 48 => 'Foggy',
            $code >= 51 && $code <= 57 => 'Drizzle',
            $code >= 61 && $code <= 67 => 'Rain',
            $code >= 71 && $code <= 77 => 'Snow',
            $code >= 80 && $code <= 82 => 'Rain showers',
            $code === 85 || $code === 86 => 'Snow showers',
            $code >= 95 => 'Thunderstorm',
            default => 'Cloudy',
        };
    }
}
