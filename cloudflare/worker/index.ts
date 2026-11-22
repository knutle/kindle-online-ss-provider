import { Container, getContainer } from '@cloudflare/containers';

interface Env {
    LARAVEL: DurableObjectNamespace<LaravelContainer>;
    APP_KEY: string;
    CALENDAR_ICAL_URL?: string;
}

export class LaravelContainer extends Container<Env> {
    defaultPort = 8080;
    sleepAfter = '10m';

    constructor(ctx: DurableObjectState, env: Env) {
        super(ctx, env);
        this.envVars = {
            APP_KEY: env.APP_KEY,
            ...(env.CALENDAR_ICAL_URL ? { CALENDAR_ICAL_URL: env.CALENDAR_ICAL_URL } : {}),
        };
    }

    // Cold starts after a deploy (image pull, cache warm-up) exceed the library's 8s/20s defaults.
    override async fetch(request: Request): Promise<Response> {
        if (!this.ctx.container?.running) {
            await this.startAndWaitForPorts({
                ports: this.defaultPort,
                cancellationOptions: { instanceGetTimeoutMS: 60_000, portReadyTimeoutMS: 120_000 },
            });
        }

        return super.fetch(request);
    }

    override onStart() {
        console.log(JSON.stringify({ message: 'laravel container started' }));
    }

    override onStop(params: { exitCode: number; reason: string }) {
        console.log(JSON.stringify({ message: 'laravel container stopped', ...params }));
    }

    override onError(error: unknown) {
        console.error(JSON.stringify({ message: 'laravel container error', error: String(error) }));
        throw error;
    }
}

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        const response = await getContainer(env.LARAVEL).fetch(request);

        if (new URL(request.url).pathname === '/dashboard.png' && response.ok) {
            const cached = new Response(response.body, response);
            cached.headers.set('Cache-Control', 'public, max-age=600');
            return cached;
        }

        return response;
    },
};
