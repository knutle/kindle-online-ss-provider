import { Container, getContainer } from '@cloudflare/containers';

interface Env {
    LARAVEL: DurableObjectNamespace<LaravelContainer>;
    APP_KEY: string;
}

export class LaravelContainer extends Container<Env> {
    defaultPort = 8080;
    sleepAfter = '10m';

    constructor(ctx: DurableObjectState, env: Env) {
        super(ctx, env);
        this.envVars = { APP_KEY: env.APP_KEY };
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
