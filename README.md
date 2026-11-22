# Kindle Online SS Provider

This repository contains a Laravel weather dashboard that can also serve a
Kindle-sized PNG image of the dashboard. The interactive dashboard lets you
search for a city or use your device location, then shows current conditions
and a five-day forecast. Weather data is provided by Open-Meteo. If an iCal
feed is configured, upcoming calendar events are shown beside the local time.

The Laravel application is in [`src/`](src/). Its
`/dashboard.png` endpoint renders the dashboard in a headless browser at
1236 × 1601 pixels, suitable for displaying on a Kindle.

## Example usage

With the application running locally, open the interactive dashboard at
<http://localhost:8000>. To request an image for a city, use:

```text
http://localhost:8000/dashboard.png?city=London
```

Or provide coordinates and an optional label:

```text
http://localhost:8000/dashboard.png?latitude=59.91&longitude=10.75&label=Oslo
```

An optional IANA timezone can also be supplied, for example
`&timezone=Europe%2FOslo`. The image endpoint returns a PNG. A city name is
looked up using Open-Meteo's geocoding service; coordinates skip that lookup.

## Installation and development

Prerequisites:

- PHP 8.3 or later and Composer
- Node.js 22.18.0 or later within 22.x and npm
- Internet access for Open-Meteo weather/geocoding requests

From the repository root, set up and start the application:

```sh
cd src
composer setup
composer dev
```

The setup command installs PHP and JavaScript dependencies, creates the local
environment file and application key, runs database migrations, installs the
headless Chrome browser used for PNG rendering, and builds frontend assets.
SQLite is configured by default. Once the development server is running, visit
<http://localhost:8000>.

## Calendar events

Set `CALENDAR_ICAL_URL` in `src/.env` to an iCal (`.ics`) feed URL to list
upcoming events (including recurring ones, up to 60 days ahead) to the right of
the local time. Without it, no events are shown. Optional settings:
`CALENDAR_EVENT_LIMIT` (default `5`) and `CALENDAR_CACHE_SECONDS` (default
`300`). The feed is fetched server-side, so the URL is never sent to the browser.

## Dependencies

The application is built with Laravel 13, PHP, Inertia.js, React, TypeScript,
and Vite Plus. Important runtime dependencies include:

- **PHP:** Laravel Framework, Inertia Laravel, Laravel Fortify, Laravel
  Wayfinder, Spatie Browsershot, and sabre/vobject (iCal parsing).
- **JavaScript:** React, Inertia React, Vite Plus, Tailwind CSS, and Puppeteer.
  Puppeteer supplies the headless Chrome browser used by Browsershot.
- **External service:** Open-Meteo Forecast and Geocoding APIs; no API key is
  configured by this project. An optional iCal feed supplies calendar events.

The Composer and npm dependency manifests and lock files are in `src/`.

## Deploying to Cloudflare

The app needs PHP and headless Chrome, so it runs as a
[Cloudflare Container](https://developers.cloudflare.com/containers/) behind a
small Worker (`cloudflare/worker/index.ts`, configured in `cloudflare/wrangler.jsonc`).

```sh
cd cloudflare
npm install
npx wrangler secret put APP_KEY   # value from `php artisan key:generate --show`
npx wrangler secret put CALENDAR_ICAL_URL   # optional, enables calendar events
npx wrangler deploy               # builds cloudflare/Dockerfile and deploys
```

### Automated deploys

Tests run on every push to `main` via `.github/workflows/tests.yml`. To deploy,
fast-forward `release` to `main` (`git push origin main:release`).

`.github/workflows/deploy-cloudflare.yml` deploys on pushes to the `release` branch (or
manually via *Run workflow*). Add these repository secrets: `CLOUDFLARE_API_TOKEN`
(with Workers and Containers edit permissions), `CLOUDFLARE_ACCOUNT_ID`, and
optionally `APP_KEY` and `CALENDAR_ICAL_URL`, which are uploaded as Worker
secrets in the same deploy. Optionally set the repository variable `WORKER_URL`
(e.g. `https://kindle-weather-dashboard.<subdomain>.workers.dev`) so the workflow
warms the container via `/up` after deploying; each deploy replaces the container,
so the first request otherwise pays the cold start. Use the `workers.dev` URL
rather than a custom domain: Cloudflare security rules on a custom domain can
return 403 to GitHub runner IPs. The warm-up step is non-fatal, so a failure
there does not fail the deploy.

Notes:

- The container uses cookie sessions, file cache, and a throwaway SQLite
  database, so state is lost when the container sleeps. Use an external
  database if you need persistent Fortify accounts.
- `APP_URL` is `http://127.0.0.1:8080` inside the container because Browsershot
  renders the dashboard by requesting the app's own URL.
- `/dashboard.png` responses are served with `Cache-Control: max-age=600`.
- To test the image locally: `docker build -t kindle -f cloudflare/Dockerfile src && docker run -p 8080:8080 -e APP_KEY=... kindle`.
