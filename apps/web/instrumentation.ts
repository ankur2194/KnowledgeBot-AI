import { registerOTel } from '@vercel/otel';

/**
 * Server-side OTel. The Next server does little beyond RSC and route handlers — it holds no tenant
 * data by design (nextjs-app-router NN1) — so a hand-rolled Node SDK buys nothing over
 * `registerOTel()`.
 *
 * `OTEL_SERVICE_NAME` must be one of the four values the `service` metric label is bounded to, and
 * this app's is `web` (kb-observability-conventions). The other three name services this app has no
 * route to, so their names do not appear anywhere in this package — CI greps for them.
 */
export function register(): void {
  // The hook also runs in the edge runtime, where the Node exporter is unavailable. No route in
  // this app exports `runtime = 'edge'`, so this guard should never fire — it is here so that the
  // day someone adds one, they get a no-op instead of a boot crash.
  if (process.env.NEXT_RUNTIME !== 'nodejs') return;

  registerOTel({ serviceName: process.env.OTEL_SERVICE_NAME ?? 'web' });
}
