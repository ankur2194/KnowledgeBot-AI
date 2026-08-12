# Local hostnames

The stack routes entirely by **`Host` header**. Traefik has one entrypoint on :443 and decides
which service gets a request purely from the hostname — so `https://localhost` matches no router
at all and returns a 404 from the edge that looks exactly like a broken deployment.

You need four names to resolve to `127.0.0.1`.

---

## The four names, and why there are four

| Name | Serves | Why it is separate |
|---|---|---|
| `api.<domain>` | `laravel-api`, plus the chat-stream paths to `laravel-api-stream` | the only door inward |
| `app.<domain>` | `web` — the admin console | |
| `chat.<domain>` | `web` — hosted chat | **Same container, different ORIGIN.** Hosted chat renders model-generated Markdown, the highest-risk sink in the product; sharing an origin with the admin console would let one XSS there run with the admin session cookie attached. |
| `<widget-domain>` | `sdk` (the loader and iframe assets), and `/embed/*` from `laravel-api` | **A different REGISTRABLE domain** — not a subdomain. The admin session cookie is scoped to the main domain, so anything of ours on the widget's eTLD+1 makes a widget iframe on a hostile customer page *same-site* with a real admin credential. CHIPS cannot help: the cookie is not the widget's. |

Collapsing any of these locally teaches you a topology that does not exist in production, and the
bugs it hides are the security-relevant ones. Use four names locally too.

`/etc/hosts` cannot express wildcards, so list the exact names.

---

## Option 1 — `/etc/hosts` (simplest)

Set these in `infrastructure/docker/.env`:

```
DOMAIN=knowledgebot.localhost
WIDGET_DOMAIN=kb-widget.localhost
```

Then add:

```
127.0.0.1  api.knowledgebot.localhost
127.0.0.1  app.knowledgebot.localhost
127.0.0.1  chat.knowledgebot.localhost
127.0.0.1  kb-widget.localhost
```

`.localhost` is reserved by RFC 6761 and will never resolve publicly, so a stale entry cannot
accidentally point at someone else's server. Note that `.localhost` is **not** automatically
resolved on every platform — macOS and most Linux distributions do it, Windows does not — hence
the explicit lines.

### On Windows / WSL2

Edit `C:\Windows\System32\drivers\etc\hosts` **on the Windows side**, as Administrator. Editing
`/etc/hosts` inside WSL2 affects only Linux-side resolution, so `curl` inside WSL works and the
browser on Windows gets NXDOMAIN — which reads as "the stack is down".

---

## Option 2 — a wildcard resolver (no file editing)

`*.localhost` on systemd-resolved, or `dnsmasq`:

```
address=/knowledgebot.localhost/127.0.0.1
address=/kb-widget.localhost/127.0.0.1
```

Worth it once you have more than one environment, because there is no `/etc/hosts` line to
forget when a name changes.

---

## Certificates

The dev overlay points ACME at the **Let's Encrypt staging directory**, so your browser will warn
about an untrusted issuer. That is correct and expected.

It is deliberate rather than convenient: production ACME allows only **5 authorization failures
per identifier per hour**, and a Compose restart loop against a name that does not resolve
publicly — which `.localhost` never will — burns that budget in minutes. Every error message after
that describes a rate limit rather than the DNS problem that caused it.

For a local name, staging cannot issue either. Two workable paths:

- **Accept the browser warning** (`curl -k`, "Advanced → Proceed"). Fine for everything except
  testing `Secure` cookie behaviour, which needs a trusted certificate.
- **Use `mkcert`** to generate a locally-trusted certificate and mount it through
  `traefik/dynamic/` as a `tls.certificates` entry. This is the option to take if you are working
  on session, cookie, or widget-embedding behaviour, because those depend on the browser actually
  trusting the origin.

**When you move to real certificates, remove the `caServer` line AND delete the `acme` volume.**
The staging *account* persists inside `acme.json`, and every certificate issued afterwards stays
untrusted with no error anywhere.

---

## What is NOT reachable, and must stay that way

`ai-api`, every `ai-worker-*`, `laravel-worker` (Horizon), `ai-beat`, `otel-collector`, PostgreSQL,
Qdrant, both Valkey instances and the SeaweedFS S3 gateway have **no hostname and no router**, in
dev and in production. Do not add one "just locally": dev overrides get copied into staging, and
`ai-api` trusts its caller by design — an open port there is a complete authorization bypass, not
an information leak.

The dev overlay does bind the data stores to **loopback only**, which is what you use instead:

| | |
|---|---|
| PostgreSQL | `127.0.0.1:5432` |
| Qdrant | `127.0.0.1:6333` |
| valkey-core | `127.0.0.1:6379` |
| valkey-cache | `127.0.0.1:6380` |
| SeaweedFS S3 | `127.0.0.1:8333` |
| SeaweedFS master UI | `127.0.0.1:9333` |

The `127.0.0.1:` prefix is load-bearing. A bare `5432:5432` publishes on `0.0.0.0`, and Docker
DNATs published ports in the iptables `nat` table — *ahead of* the `INPUT` chain `ufw` uses. The
firewall rule is real and is simply never consulted.

For anything without a published port: `docker compose exec <service> …`, or an SSH tunnel.
