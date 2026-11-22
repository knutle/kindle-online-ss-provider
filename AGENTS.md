# Agent notes

Living document. Add lessons from each task; fix anything that turns out wrong.

## Project layout

- Laravel + Inertia/React app lives in `src/`. Repo root only has `README.md`, `cloudflare/`, `.github/`.
- Dashboard UI: `src/resources/js/pages/weather-dashboard.tsx`; styles: `src/resources/css/app.css` (`.kindle-*` classes).
- `/dashboard.png` is rendered by `DashboardImageController` via a headless browser at 1236x1601 with `?screenshot=1`, which sets `data-screenshot-mode="true"`. Screenshot-mode CSS overrides (`.kindle-dashboard[data-screenshot-mode='true'] ...`) exist further down `app.css`; check them when changing a class.
- Weather comes from the Open-Meteo API (client-side `fetch`).

## Making layout changes

- Edit the JSX structure and the CSS together. Borders/dividers belong on the container that owns the visual row, not on a child (e.g. the divider under the forecast/quote row is on `.kindle-top-row`, not `.kindle-current`).
- To make a column reach a divider, remove padding from the parent and put it on the children. Padding on the parent leaves a gap above a child's border.
- Pick the section height from the tallest column and let others `align-items: stretch`. Avoid `align-self: flex-start` on columns that should span the row.
- The PNG renderer captures `.kindle-dashboard` as an element. Keep screenshot-mode `height` and `min-height` aligned with the controller viewport; `min-height` alone can let content growth change the generated PNG dimensions. Update `DashboardImageTest` when changing the controller dimensions and verify a rendered PNG.
- After editing JSX, run `npx tsc --noEmit` and filter for the file you touched. Many unrelated errors appear because Wayfinder route files (`@/routes`, `@/actions`) aren't generated in a clean checkout. Ignore those.

## Test fixtures and container development

- `src/tests/Fixtures/oslo-weather.json` is a raw five-day Open-Meteo response. Reuse it for deterministic weather mocks instead of depending on live API responses in tests.
- Wrangler's local container uses `src/` as its image build context. Run `cd cloudflare && npm ci && npx wrangler dev --local --var APP_KEY:01234567890123456789012345678901`; Docker Desktop must be running. The example key is disposable and local-only; never use it for deployment or commit a real `APP_KEY`.
- Put Wrangler's ignore rules in `src/.dockerignore`; it reads the context-root ignore file, so don't rely only on `cloudflare/Dockerfile.dockerignore`. Exclude `bootstrap/cache/*.php` and clear generated Laravel package/service manifests before `composer dump-autoload --no-dev` to avoid stale package discovery referencing dev-only providers.
- The first local container build pulls its base images and installs PHP/Node dependencies, so allow several minutes and wait for Wrangler's ready message before testing the local endpoint.
- Verify the running app with `curl -i http://localhost:8787/up` and render the PNG with `curl -o /tmp/dashboard.png 'http://localhost:8787/dashboard.png?latitude=59.91&longitude=10.75&label=Oslo'`; a successful image response is `image/png` at 1236x1601.

## Calendar events

- `CALENDAR_ICAL_URL` (plus optional `CALENDAR_EVENT_LIMIT`, `CALENDAR_CACHE_SECONDS`) feeds `App\Services\CalendarEvents`, which fetches, caches and parses the iCal with `sabre/vobject` and expands RRULEs. The `/` route passes `events` to the page as an Inertia prop; the URL never reaches the browser, and the PNG renderer gets the events through the same route.
- The container only gets env vars the Worker passes in `cloudflare/worker/index.ts` (`envVars`). A new runtime env var needs: `Env` field + `envVars` entry there, a secret sync step in `.github/workflows/deploy-cloudflare.yml`, and a README mention (setup + Cloudflare sections). `CALENDAR_ICAL_URL` is wired this way.
- Events sit in `.kindle-clock` beside `.kindle-clock-main` (the time) in `.kindle-events`, with no vertical divider.
- sabre `VEvent` has no typed properties, so PHPStan rejects `$vevent->DTSTART`. Use `$vevent->select('DTSTART')[0]` with an `instanceof Property\ICalendar\DateTime` check.
- `composer require` runs a `boost:update` post-update script that fails here. Run `composer update <pkg> --no-scripts` and pin a real constraint (not `*`).
- Run PHPStan with `vendor/bin/phpstan analyse --memory-limit=1G` and fix types properly; no ignore comments.
- Tests need a `.env` with an app key (`cp .env.example .env && php artisan key:generate`); delete it afterwards. Feature tests that render the Inertia page 500 without a built Vite manifest (pre-existing). `tests/Unit/CalendarEventsTest.php` covers parsing.

## Verifying rendering (required for visual changes)

`npm ci` is needed first (`node_modules` is absent in a fresh checkout). A full `vite build` fails without generated Wayfinder routes, so use a throwaway harness:

1. Create a temp dir inside `src/` (e.g. `.tmph/`) so module resolution works. Do not put it in `/tmp`.
2. Harness pieces: a `vite.config.ts` (react + `@tailwindcss/vite`, alias `@inertiajs/react` to a stub exporting `Head = () => null`, alias `@` to `resources/js`, port 5199); `index.html` with `<div id="r">` and a module script; `main.tsx` that imports `./mock`, the app CSS, and renders the page; `mock.ts` that replaces `window.fetch` with a fixed Open-Meteo response (`current` + `daily` with 5 days).
3. Start `npx vite --config .tmph/vite.config.ts` in the background.
4. Screenshot: `google-chrome --headless --no-sandbox --disable-gpu --window-size=1236,1601 --virtual-time-budget=10000 --screenshot=/tmp/out.png "http://localhost:5199/?latitude=59.9&longitude=10.7&label=Oslo&screenshot=1"`.
5. View the PNG. Check the forecast-present state (and the empty state if you touched it).
6. Clean up: stop vite by PID (`ps aux | grep "[v]ite"`, then `kill <pid>`; `pkill` is not allowed), delete the temp dir, and confirm `git status` shows only intended files.

Pitfalls seen:

- The mock must match the fields the page reads: `current` (`temperature_2m`, `apparent_temperature`, `relative_humidity_2m`, `wind_speed_10m`, `precipitation`, `weather_code`, `is_day`) and `daily` (`time`, `weather_code`, `temperature_2m_max/min`, `precipitation_probability_max`). A wrong field gives "Cannot read properties of undefined".
- Pass sample `events` as a prop to the page in `main.tsx` when checking the calendar.
- To stop vite, list PIDs with `ps aux | grep "[v]ite --config" | awk '{print $2}'` in one call, then `kill <pid> <pid>` in another; `kill $(...)` is rejected.
- Put the fetch mock in a module imported first, written as plain TypeScript. An inline one-line mock had a paren error and caused a misleading "Failed to fetch" in the UI.
- The tool sometimes reports the screenshot "does not exist" on first view; view it again.
- Vite error overlays show up in the screenshot, so read it if the page looks wrong.
- Outside screenshot mode (no `&screenshot=1`) the page is taller than 1000px. Capture the full height (e.g. `--window-size=1236,2000 --hide-scrollbars`) so the footer and search/location tools are visible; a short window crops the page.
- `kill` needs numeric PIDs; list them with `ps aux | grep "[v]ite" | awk '{print $2}'` first (npm, sh and node processes all need stopping).
- Verify both modes after a layout change: screenshot mode and normal mode.

## Tests

- Run `cd src && XDEBUG_MODE=off php artisan test --filter=Dashboard` (needs `composer install`, `.env` and `php artisan key:generate`). Redirect output through `grep -v Xdebug` or it floods.
- `DashboardTest::test_authenticated_users_can_visit_the_dashboard` fails with "Vite manifest not found" in a clean checkout (no `public/build`); this is pre-existing, not caused by CSS changes.

## Workflow conventions

- Before opening a PR, walk the template checklist: README/docs, rendered image for layout changes, and Cloudflare/deploy impact (secrets, worker, workflow). Tick items only when actually handled.

- Keep changes surgical. Don't commit screenshots or harness files.
- Branch from `main`, push, and open the PR with `gh pr create --base main --head <branch>` (push first, or `gh` aborts).
- Follow `.github/PULL_REQUEST_TEMPLATE.md` (Summary, Changes, Verification, Not verified / notes, Checklist) when writing PR descriptions.
- Be explicit about what was not verified (untested layouts, undeployed changes, required secrets).
- End commit messages with the trailer `Co-authored-by: copilot-swe-agent[bot] <198982749+Copilot@users.noreply.github.com>`.
- Keep replies short; the user typically asks for a visual check after each tweak, so render and look at the image every time.

## Change log of user-requested tweaks

1. Quote of the day spans the full row height (divider to divider) next to the forecast heading; rows from humidity/wind/precipitation down are 100% width.
2. No gap between the quote's vertical divider and the horizontal divider above it.
3. Upcoming calendar events (from the iCal URL) display to the right of the local time.
4. No vertical divider between the clock and the calendar events.
5. Screenshot-mode rows have 35px horizontal padding (was 20px). The forecast/quote row has that padding only at its outer edges, with a 15px gap on each side of the divider and no flex gap.
6. Quote of the day is centred horizontally (`align-items: center; text-align: center`), in both screenshot and normal mode. The row's divider/gap rules in `.kindle-top-row`, `.kindle-weather-summary` and `.kindle-quote` apply in both modes; screenshot mode only overrides padding.
