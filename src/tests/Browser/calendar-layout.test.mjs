import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { after, before, test } from 'node:test';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import puppeteer from 'puppeteer';
import { createServer } from 'vite';

const weather = await readFile(
    new URL('../Fixtures/oslo-weather.json', import.meta.url),
    'utf8',
);

let server;
let browser;
let baseUrl;

before(async () => {
    server = await createServer({
        configFile: false,
        appType: 'custom',
        server: { host: '127.0.0.1', port: 0 },
        optimizeDeps: {
            exclude: ['@inertiajs/react'],
            include: ['react', 'react-dom/client', 'lucide-react'],
        },
        plugins: [
            {
                name: 'calendar-layout-test',
                enforce: 'pre',
                resolveId(id) {
                    if (
                        id === 'virtual:calendar-test' ||
                        id === '@inertiajs/react'
                    ) {
                        return `\0${id}`;
                    }
                },
                load(id) {
                    if (id === '\0@inertiajs/react') {
                        return 'export const Head = () => null;';
                    }
                    if (id === '\0virtual:calendar-test') {
                        return `
                            import '@vitejs/plugin-react/preamble';
                            import React from 'react';
                            import { createRoot } from 'react-dom/client';
                            import '/resources/css/app.css';
                            import Dashboard from '/resources/js/pages/weather-dashboard.tsx';
                            const count = Number(new URLSearchParams(location.search).get('events'));
                            const events = Array.from({ length: count }, (_, i) => ({
                                title: i < 2 ? 'Calendar event with a very long title that should be truncated '.repeat(3).trim() : 'Calendar event ' + (i + 1),
                                start: '2026-11-25T' + String(10 + i).padStart(2, '0') + ':00:00Z',
                                end: null,
                                allDay: false,
                            }));
                            createRoot(document.getElementById('root')).render(React.createElement(Dashboard, { events }));
                        `;
                    }
                },
                configureServer(vite) {
                    vite.middlewares.use((request, response, next) => {
                        if (request.url?.startsWith('/?')) {
                            response.setHeader('Content-Type', 'text/html');
                            response.end(
                                '<div id="root"></div><script type="module" src="/@id/virtual:calendar-test"></script>',
                            );
                        } else {
                            next();
                        }
                    });
                },
            },
            react(),
            tailwindcss(),
        ],
    });
    await server.listen();
    baseUrl = `http://127.0.0.1:${server.httpServer.address().port}`;
    browser = await puppeteer.launch({
        executablePath: process.env.CHROME_PATH || undefined,
        headless: 'shell',
        args: process.env.CI === 'true' ? ['--no-sandbox'] : [],
    });
});

after(async () => {
    await browser?.close();
    await server?.close();
});

async function checkLayout({ width, height, screenshot, count, name }) {
    const page = await browser.newPage();
    try {
        await page.setViewport({ width, height });
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            if (request.url().startsWith('https://api.open-meteo.com/')) {
                void request.respond({
                    status: 200,
                    contentType: 'application/json',
                    headers: { 'Access-Control-Allow-Origin': '*' },
                    body: weather,
                });
            } else {
                void request.continue();
            }
        });
        const query = new URLSearchParams({
            latitude: '59.91',
            longitude: '10.75',
            label: 'Oslo',
            events: String(count),
            ...(screenshot ? { screenshot: '1' } : {}),
        });
        await page.goto(`${baseUrl}/?${query}`, { waitUntil: 'networkidle0' });
        await page.waitForSelector(
            screenshot
                ? 'main[data-screenshot-ready="true"]'
                : '.kindle-forecast-grid',
        );

        const layout = await page.evaluate(() => {
            const dashboard = document
                .querySelector('.kindle-dashboard')
                .getBoundingClientRect();
            const footer = document
                .querySelector('.kindle-footer')
                .getBoundingClientRect();
            const list = document
                .querySelector('.kindle-events ul')
                ?.getBoundingClientRect();
            const events = [
                ...document.querySelectorAll('.kindle-events li'),
            ].map((item) => {
                const rect = item.getBoundingClientRect();
                return { left: rect.left, right: rect.right, top: rect.top };
            });
            const title = document.querySelector('.kindle-next-event-title');
            const listTitle = document.querySelector('.kindle-event-title');
            return {
                dashboard: {
                    right: dashboard.right,
                    bottom: dashboard.bottom,
                    height: dashboard.height,
                },
                footerBottom: footer.bottom,
                list: list && { left: list.left, right: list.right },
                events,
                nextEvent: title?.textContent,
                clockLabel: document.querySelector('.kindle-clock-label')
                    ?.textContent,
                titleTruncated:
                    title &&
                    title.scrollWidth > title.clientWidth &&
                    getComputedStyle(title).textOverflow === 'ellipsis',
                listTitleTruncated:
                    listTitle &&
                    listTitle.scrollHeight > listTitle.clientHeight &&
                    getComputedStyle(listTitle).webkitLineClamp === '2',
                pageWidth: document.documentElement.scrollWidth,
            };
        });
        if (process.env.CALENDAR_LAYOUT_SCREENSHOT_DIR) {
            await page.screenshot({
                path: `${process.env.CALENDAR_LAYOUT_SCREENSHOT_DIR}/${name}.png`,
                fullPage: !screenshot,
            });
        }
        assert.equal(layout.events.length, Math.max(0, count - 1));
        assert.equal(layout.clockLabel, count ? 'NEXT EVENT' : 'LOCAL TIME');
        if (count) {
            assert.equal(
                layout.nextEvent,
                'Calendar event with a very long title that should be truncated '
                    .repeat(3)
                    .trim(),
            );
            assert.ok(
                layout.titleTruncated,
                'long event titles must be truncated',
            );
        }
        assert.ok(
            layout.pageWidth <= width,
            'page must not overflow horizontally',
        );
        if (count > 2) {
            assert.equal(layout.events[0].top, layout.events[1].top);
            assert.ok(layout.events[1].left > layout.events[0].right);
        }
        if (count > 1) {
            assert.ok(
                layout.listTitleTruncated,
                'long upcoming event titles must wrap and clamp to two lines',
            );
        }
        for (const event of layout.events) {
            assert.ok(
                event.left >= layout.list.left &&
                    event.right <= layout.list.right,
            );
        }
        if (screenshot) {
            assert.equal(layout.dashboard.height, height);
            assert.ok(
                layout.footerBottom <= layout.dashboard.bottom,
                'footer must fit in PNG',
            );
        }
    } finally {
        await page.close();
    }
}

void test('five events appear side by side in screenshot mode', async () => {
    await checkLayout({
        width: 1236,
        height: 1601,
        screenshot: true,
        count: 5,
        name: 'calendar-png',
    });
});

void test('five events wrap without overflowing on the interactive dashboard', async () => {
    await checkLayout({
        width: 1236,
        height: 2000,
        screenshot: false,
        count: 5,
        name: 'calendar-desktop',
    });
    await checkLayout({
        width: 390,
        height: 1200,
        screenshot: false,
        count: 5,
        name: 'calendar-mobile',
    });
});

void test('dashboards with one or no events remain usable', async () => {
    await checkLayout({
        width: 1236,
        height: 1601,
        screenshot: true,
        count: 1,
        name: 'calendar-one',
    });
    await checkLayout({
        width: 1236,
        height: 1601,
        screenshot: true,
        count: 0,
        name: 'calendar-none',
    });
});
