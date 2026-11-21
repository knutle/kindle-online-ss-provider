# Kindle Online SS Provider

This repository contains a Laravel weather dashboard that can also serve a
Kindle-sized PNG image of the dashboard. The interactive dashboard lets you
search for a city or use your device location, then shows current conditions
and a five-day forecast. Weather data is provided by Open-Meteo.

The Laravel application is in [`example-app/`](example-app/). Its
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
- Node.js 22 and npm
- Internet access for Open-Meteo weather/geocoding requests

From the repository root, set up and start the application:

```sh
cd example-app
composer setup
composer dev
```

The setup command installs PHP and JavaScript dependencies, creates the local
environment file and application key, runs database migrations, installs the
headless Chrome browser used for PNG rendering, and builds frontend assets.
SQLite is configured by default. Once the development server is running, visit
<http://localhost:8000>.

## Dependencies

The application is built with Laravel 13, PHP, Inertia.js, React, TypeScript,
and Vite Plus. Important runtime dependencies include:

- **PHP:** Laravel Framework, Inertia Laravel, Laravel Fortify, Laravel
  Wayfinder, and Spatie Browsershot.
- **JavaScript:** React, Inertia React, Vite Plus, Tailwind CSS, and Puppeteer.
  Puppeteer supplies the headless Chrome browser used by Browsershot.
- **External service:** Open-Meteo Forecast and Geocoding APIs; no API key is
  configured by this project.

The Composer and npm dependency manifests and lock files are in `example-app/`.
