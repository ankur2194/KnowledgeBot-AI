# Security Architecture

> Part of the **KnowledgeBot AI** specification — §18, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 18. Security Architecture

## 18.1 Security Principles

- Least privilege.
- Tenant isolation.
- Deny by default.
- No provider credentials in clients.
- Validate at boundaries.
- Treat uploaded and crawled content as untrusted.
- Keep secrets out of logs.
- Make destructive actions auditable.
- Separate public and internal networks.

## 18.2 Credential Security

Provider API keys must be:

- Encrypted at rest using application-level envelope encryption or Laravel-supported encrypted storage with a protected master key.
- Decrypted only in the trusted backend or AI service for the shortest practical time.
- Redacted in APIs, logs, and admin views.
- Replaceable without database migrations.
- Testable through a controlled connection-check action.

The encryption key must be delivered through a secret-management mechanism or protected environment configuration, never committed to Git.

## 18.3 Authentication Security

- Secure, HTTP-only cookies for web sessions.
- CSRF protection for session-authenticated requests.
- Short-lived access tokens where applicable.
- Refresh or reauthentication policies for sensitive actions.
- Password rate limiting.
- Email verification.
- Optional two-factor authentication.

## 18.4 Authorization

Every protected action must check:

- Authenticated identity.
- Organization membership.
- Role or permission.
- Entity ownership.
- Entity status.
- Destructive-action policy.

UI hiding is not authorization.

## 18.5 Embedded Widget Security

- Allowed-origin validation.
- Short-lived session token.
- Signed authenticated-user metadata.
- Strict CORS.
- Content Security Policy.
- Sandboxed iframe.
- Rate limiting.
- Abuse detection hooks.
- Optional CAPTCHA challenge after suspicious behavior.

## 18.6 Prompt Injection Defense

Crawled and uploaded content may contain malicious instructions.

Controls:

- Clearly delimit source content.
- State that source content is untrusted data.
- Never allow source text to change system instructions.
- Do not provide sensitive tools to the initial RAG bot.
- Filter or flag common prompt-injection patterns for diagnostics.
- Preserve source provenance.
- Require explicit future permission design before adding actions or tools.

Prompt-injection detection is a defense layer, not a guarantee.

## 18.7 File Security

- MIME detection.
- Size limits.
- Parser sandboxing where practical.
- CPU and memory limits on worker containers.
- Processing timeouts.
- Malware-scanning integration.
- Zip-bomb protection.
- Password-protected file policy.
- No direct execution of macros.
- Strip active content from converted artifacts.

## 18.8 Crawler Security

- SSRF protections.
- DNS and redirect validation.
- Network egress restrictions.
- Response-size limits.
- Request timeout.
- Concurrency limits.
- Content-type allow-list.
- No arbitrary local-file access.

## 18.9 Output Security

- Render model output through a safe Markdown renderer.
- Sanitize HTML.
- Disable arbitrary scripts.
- Validate URLs before creating links.
- Add `rel` protections to external links.
- Avoid rendering unsafe embedded content.

## 18.10 Data Privacy

Organizations should control:

- Whether conversations are stored.
- Retention duration.
- Whether administrators may review conversations.
- Whether anonymous metadata is collected.
- Whether user feedback comments are stored.
- Whether crawl snapshots are retained.

The project should provide deletion workflows for users, conversations, sources, and organizations.

## 18.11 Audit Logging

Audit the following:

- Login security events.
- User and role changes.
- Provider credential changes.
- Bot publish and configuration changes.
- Source upload, disable, and deletion.
- Crawl schedule changes.
- Data exports.
- Retention-policy changes.
- Destructive operations.

Sensitive values must not be written into audit details.

