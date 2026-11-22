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
- After editing JSX, run `npx tsc --noEmit` and filter for the file you touched. Many unrelated errors appear because Wayfinder route files (`@/routes`, `@/actions`) aren't generated in a clean checkout. Ignore those.

## Verifying rendering (required for visual changes)

`npm ci` is needed first (`node_modules` is absent in a fresh checkout). A full `vite build` fails without generated Wayfinder routes, so use a throwaway harness:

1. Create a temp dir inside `src/` (e.g. `.tmph/`) so module resolution works. Do not put it in `/tmp`.
2. Harness pieces: a `vite.config.ts` (react + `@tailwindcss/vite`, alias `@inertiajs/react` to a stub exporting `Head = () => null`, alias `@` to `resources/js`, port 5199); `index.html` with `<div id="r">` and a module script; `main.tsx` that imports `./mock`, the app CSS, and renders the page; `mock.ts` that replaces `window.fetch` with a fixed Open-Meteo response (`current` + `daily` with 5 days).
3. Start `npx vite --config .tmph/vite.config.ts` in the background.
4. Screenshot: `google-chrome --headless --no-sandbox --disable-gpu --window-size=1236,1601 --virtual-time-budget=10000 --screenshot=/tmp/out.png "http://localhost:5199/?latitude=59.9&longitude=10.7&label=Oslo&screenshot=1"`.
5. View the PNG. Check the forecast-present state (and the empty state if you touched it).
6. Clean up: stop vite by PID (`ps aux | grep "[v]ite"`, then `kill <pid>`; `pkill` is not allowed), delete the temp dir, and confirm `git status` shows only intended files.

Pitfalls seen:

- Put the fetch mock in a module imported first, written as plain TypeScript. An inline one-line mock had a paren error and caused a misleading "Failed to fetch" in the UI.
- The tool sometimes reports the screenshot "does not exist" on first view; view it again.
- Vite error overlays show up in the screenshot, so read it if the page looks wrong.

## Workflow conventions

- Keep changes surgical. Don't commit screenshots or harness files.
- Branch from `main`, push, and open the PR with `gh pr create --base main --head <branch>` (push first, or `gh` aborts). No PR template exists. Include the Co-authored-by trailer on commits.
- Keep replies short; the user typically asks for a visual check after each tweak, so render and look at the image every time.

## Change log of user-requested tweaks

1. Quote of the day spans the full row height (divider to divider) next to the forecast heading; rows from humidity/wind/precipitation down are 100% width.
2. No gap between the quote's vertical divider and the horizontal divider above it.
