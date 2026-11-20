import { Head } from '@inertiajs/react';
import {
    Cloud,
    CloudDrizzle,
    CloudFog,
    CloudLightning,
    CloudRain,
    CloudSnow,
    CloudSun,
    LocateFixed,
    RefreshCw,
    Search,
    Sun,
} from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';

type WeatherLocation = {
    latitude: number;
    longitude: number;
    name: string;
    country: string;
    region?: string;
};

type ForecastDay = {
    date: string;
    code: number;
    high: number;
    low: number;
    precipitation: number;
};

type Forecast = {
    temperature: number;
    feelsLike: number;
    humidity: number;
    wind: number;
    precipitation: number;
    code: number;
    isDay: boolean;
    days: ForecastDay[];
    updatedAt: Date;
};

type WeatherResponse = {
    current: {
        temperature_2m: number;
        apparent_temperature: number;
        relative_humidity_2m: number;
        wind_speed_10m: number;
        precipitation: number;
        weather_code: number;
        is_day: number;
    };
    daily: {
        time: string[];
        weather_code: number[];
        temperature_2m_max: number[];
        temperature_2m_min: number[];
        precipitation_probability_max: number[];
    };
};

const savedLocationKey = 'kindle-weather-location';

function weatherDescription(code: number): string {
    if (code === 0) return 'Clear sky';
    if (code <= 3) return 'Partly cloudy';
    if (code === 45 || code === 48) return 'Foggy';
    if (code >= 51 && code <= 57) return 'Drizzle';
    if (code >= 61 && code <= 67) return 'Rain';
    if (code >= 71 && code <= 77) return 'Snow';
    if (code >= 80 && code <= 82) return 'Rain showers';
    if (code === 85 || code === 86) return 'Snow showers';
    if (code >= 95) return 'Thunderstorm';
    return 'Cloudy';
}

function WeatherIcon({
    code,
    isDay = true,
    className,
}: {
    code: number;
    isDay?: boolean;
    className?: string;
}) {
    const Icon =
        code === 0
            ? isDay
                ? Sun
                : Cloud
            : code <= 3
              ? CloudSun
              : code === 45 || code === 48
                ? CloudFog
                : code >= 51 && code <= 57
                  ? CloudDrizzle
                  : code >= 61 && code <= 67
                    ? CloudRain
                    : code >= 71 && code <= 77
                      ? CloudSnow
                      : code >= 80 && code <= 82
                        ? CloudRain
                        : code === 85 || code === 86
                          ? CloudSnow
                          : code >= 95
                            ? CloudLightning
                            : Cloud;

    return <Icon aria-hidden="true" className={className} strokeWidth={1.5} />;
}

function formatDay(date: string, index: number): string {
    if (index === 0) return 'Today';
    if (index === 1) return 'Tomorrow';

    return new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(
        new Date(`${date}T12:00:00`),
    );
}

async function fetchForecast(location: WeatherLocation): Promise<Forecast> {
    const params = new URLSearchParams({
        latitude: String(location.latitude),
        longitude: String(location.longitude),
        current:
            'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,is_day,wind_speed_10m',
        daily: 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
        temperature_unit: 'celsius',
        wind_speed_unit: 'kmh',
        timezone: 'auto',
        forecast_days: '5',
    });
    const response = await fetch(
        `https://api.open-meteo.com/v1/forecast?${params}`,
    );

    if (!response.ok) {
        throw new Error('Weather service is unavailable. Try again shortly.');
    }

    const data = (await response.json()) as WeatherResponse;

    return {
        temperature: Math.round(data.current.temperature_2m),
        feelsLike: Math.round(data.current.apparent_temperature),
        humidity: data.current.relative_humidity_2m,
        wind: Math.round(data.current.wind_speed_10m),
        precipitation: data.current.precipitation,
        code: data.current.weather_code,
        isDay: data.current.is_day === 1,
        days: data.daily.time.map((date, index) => ({
            date,
            code: data.daily.weather_code[index],
            high: Math.round(data.daily.temperature_2m_max[index]),
            low: Math.round(data.daily.temperature_2m_min[index]),
            precipitation: data.daily.precipitation_probability_max[index] ?? 0,
        })),
        updatedAt: new Date(),
    };
}

export default function WeatherDashboard() {
    const [now, setNow] = useState<Date | null>(null);
    const [location, setLocation] = useState<WeatherLocation | null>(null);
    const [forecast, setForecast] = useState<Forecast | null>(null);
    const [city, setCity] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setNow(new Date());
        const timer = window.setInterval(() => setNow(new Date()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    const updateWeather = useCallback(async (nextLocation: WeatherLocation) => {
        setLocation(nextLocation);
        setLoading(true);
        setError(null);

        try {
            const nextForecast = await fetchForecast(nextLocation);
            setForecast(nextForecast);

            try {
                window.localStorage.setItem(
                    savedLocationKey,
                    JSON.stringify(nextLocation),
                );
            } catch {
                // Weather remains available for this visit if storage is disabled.
            }
        } catch (reason) {
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Could not load the weather forecast.',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (!location) return;

        const timer = window.setInterval(
            () => void updateWeather(location),
            30 * 60 * 1000,
        );

        return () => window.clearInterval(timer);
    }, [location, updateWeather]);

    useEffect(() => {
        try {
            const saved = window.localStorage.getItem(savedLocationKey);

            if (saved) {
                const parsed = JSON.parse(saved) as WeatherLocation;

                if (
                    typeof parsed.latitude === 'number' &&
                    typeof parsed.longitude === 'number' &&
                    typeof parsed.name === 'string' &&
                    typeof parsed.country === 'string'
                ) {
                    void updateWeather(parsed);
                }
            }
        } catch {
            setError('Could not read the saved location. Search for a city.');
        }
    }, [updateWeather]);

    async function searchCity(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const query = city.trim();

        if (!query) return;

        setLoading(true);
        setError(null);

        try {
            const params = new URLSearchParams({
                name: query,
                count: '1',
                language: 'en',
                format: 'json',
            });
            const response = await fetch(
                `https://geocoding-api.open-meteo.com/v1/search?${params}`,
            );

            if (!response.ok) {
                throw new Error('Location search is unavailable. Try again.');
            }

            const result = (await response.json()) as {
                results?: Array<{
                    latitude: number;
                    longitude: number;
                    name: string;
                    country: string;
                    admin1?: string;
                }>;
            };
            const match = result.results?.[0];

            if (!match) {
                throw new Error('No matching city found. Try another search.');
            }

            const nextLocation = {
                latitude: match.latitude,
                longitude: match.longitude,
                name: match.name,
                country: match.country,
                region: match.admin1,
            };
            setCity('');
            await updateWeather(nextLocation);
        } catch (reason) {
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Could not find that location.',
            );
            setLoading(false);
        }
    }

    function useDeviceLocation() {
        if (!navigator.geolocation) {
            setError('Device location is not available in this browser.');
            return;
        }

        setLoading(true);
        setError(null);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                void updateWeather({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    name: 'Current location',
                    country: '',
                });
            },
            () => {
                setLoading(false);
                setError(
                    'Could not access device location. Search for a city instead.',
                );
            },
            { enableHighAccuracy: false, timeout: 10_000 },
        );
    }

    const locationLabel = location
        ? [location.name, location.region, location.country]
              .filter(Boolean)
              .join(', ')
        : 'Choose a location';

    return (
        <>
            <Head title="Weather &amp; Time" />
            <main className="kindle-dashboard">
                <header className="kindle-header">
                    <div className="kindle-brand">
                        <span className="kindle-mark" aria-hidden="true">
                            K
                        </span>
                        <span>DAILY BRIEF</span>
                    </div>
                    <p className="kindle-date">
                        {now
                            ? new Intl.DateTimeFormat(undefined, {
                                  weekday: 'long',
                                  month: 'long',
                                  day: 'numeric',
                                  year: 'numeric',
                              }).format(now)
                            : ''}
                    </p>
                </header>

                <section
                    className="kindle-clock"
                    aria-label="Current date and time"
                >
                    <p className="kindle-clock-label">LOCAL TIME</p>
                    <time dateTime={now?.toISOString()}>
                        {now
                            ? new Intl.DateTimeFormat(undefined, {
                                  hourCycle: 'h23',
                                  hour: '2-digit',
                                  minute: '2-digit',
                              }).format(now)
                            : '--:--'}
                    </time>
                </section>

                <section
                    className="kindle-weather"
                    aria-labelledby="weather-heading"
                >
                    <div className="kindle-section-heading">
                        <div>
                            <p className="kindle-eyebrow">
                                YOUR LOCAL FORECAST
                            </p>
                            <h1 id="weather-heading">{locationLabel}</h1>
                        </div>
                        {forecast && (
                            <button
                                aria-label="Refresh weather"
                                className="kindle-icon-button"
                                disabled={loading}
                                onClick={() => {
                                    if (location) void updateWeather(location);
                                }}
                                type="button"
                            >
                                <RefreshCw aria-hidden="true" size={20} />
                                <span>Refresh</span>
                            </button>
                        )}
                    </div>

                    {forecast ? (
                        <>
                            <div className="kindle-current">
                                <WeatherIcon
                                    className="kindle-current-icon"
                                    code={forecast.code}
                                    isDay={forecast.isDay}
                                />
                                <div className="kindle-current-reading">
                                    <p className="kindle-temperature">
                                        {forecast.temperature}
                                        <span>°</span>
                                    </p>
                                    <p className="kindle-condition">
                                        {weatherDescription(forecast.code)}
                                    </p>
                                </div>
                                <div className="kindle-current-details">
                                    <p>
                                        H: {forecast.days[0]?.high}°{' '}
                                        <span aria-hidden="true">/</span> L:{' '}
                                        {forecast.days[0]?.low}°
                                    </p>
                                    <p>Feels like {forecast.feelsLike}°</p>
                                </div>
                            </div>

                            <div className="kindle-weather-facts">
                                <p>
                                    <span>Humidity</span>
                                    <strong>{forecast.humidity}%</strong>
                                </p>
                                <p>
                                    <span>Wind</span>
                                    <strong>{forecast.wind} km/h</strong>
                                </p>
                                <p>
                                    <span>Precipitation</span>
                                    <strong>{forecast.precipitation} mm</strong>
                                </p>
                            </div>

                            <div className="kindle-forecast">
                                <p className="kindle-eyebrow">5-DAY FORECAST</p>
                                <div className="kindle-forecast-grid">
                                    {forecast.days.map((day, index) => (
                                        <article
                                            className="kindle-forecast-day"
                                            key={day.date}
                                        >
                                            <h2>
                                                {formatDay(day.date, index)}
                                            </h2>
                                            <WeatherIcon
                                                className="kindle-forecast-icon"
                                                code={day.code}
                                            />
                                            <p className="kindle-day-condition">
                                                {weatherDescription(day.code)}
                                            </p>
                                            <p className="kindle-day-temperatures">
                                                <strong>{day.high}°</strong>
                                                <span>{day.low}°</span>
                                            </p>
                                            <p className="kindle-rain-chance">
                                                {day.precipitation}% rain
                                            </p>
                                        </article>
                                    ))}
                                </div>
                            </div>

                            <p className="kindle-updated">
                                {loading
                                    ? 'Updating forecast…'
                                    : `Updated ${new Intl.DateTimeFormat(
                                          undefined,
                                          {
                                              hourCycle: 'h23',
                                              hour: '2-digit',
                                              minute: '2-digit',
                                          },
                                      ).format(forecast.updatedAt)}`}
                            </p>
                        </>
                    ) : (
                        <div className="kindle-empty">
                            <CloudSun
                                aria-hidden="true"
                                className="kindle-empty-icon"
                                strokeWidth={1.5}
                            />
                            <p>
                                {loading
                                    ? 'Finding your forecast…'
                                    : 'Choose a city or use device location to see the forecast.'}
                            </p>
                        </div>
                    )}

                    {error && (
                        <p
                            aria-live="polite"
                            className="kindle-error"
                            role="status"
                        >
                            {error}
                        </p>
                    )}

                    <div className="kindle-location-tools">
                        <form className="kindle-search" onSubmit={searchCity}>
                            <label className="sr-only" htmlFor="city-search">
                                Search for a city
                            </label>
                            <Search aria-hidden="true" size={18} />
                            <input
                                autoComplete="off"
                                id="city-search"
                                onChange={(event) =>
                                    setCity(event.target.value)
                                }
                                placeholder="Search city"
                                value={city}
                            />
                            <button
                                disabled={loading || !city.trim()}
                                type="submit"
                            >
                                Search
                            </button>
                        </form>
                        <button
                            className="kindle-location-button"
                            disabled={loading}
                            onClick={useDeviceLocation}
                            type="button"
                        >
                            <LocateFixed aria-hidden="true" size={18} />
                            Use device location
                        </button>
                    </div>
                </section>

                <footer className="kindle-footer">
                    <span>MADE FOR A MOMENT OF CALM</span>
                    <span>WEATHER BY OPEN-METEO</span>
                </footer>
            </main>
        </>
    );
}
