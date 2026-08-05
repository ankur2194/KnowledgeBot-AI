# SSRF and crawler egress — reference

Depth for `kb-security-baseline`. Spec: `docs/03-functional-knowledge-sources.md` §8.13, `docs/13-security.md` §18.8.
Crawl4AI configuration (JS rendering, sitemap handling, concurrency tuning) belongs to `crawl4ai-crawler`; this file is only the fetch-safety envelope wrapped around it.

The crawler is the only component that takes a URL from a tenant and connects to it. It runs inside our Docker network, where Qdrant, Valkey, PostgreSQL, SeaweedFS, the Traefik dashboard, and the cloud metadata service are all one HTTP request away. Treat every crawl URL as an attempt to reach them.

## The validation pipeline

Run all seven steps, in this order, for the seed URL **and independently for every redirect hop and every subresource**.

1. **Parse with a real parser, then re-serialize.** `urllib.parse.urlsplit`. Never regex a URL. Reject anything where the re-serialized form differs from what you will actually fetch — that divergence is the whole bug class.
2. **Scheme allow-list: `http`, `https`. Nothing else.** `file:`, `gopher:`, `dict:`, `ftp:`, `data:`, `jar:`, `ldap:`, `netdoc:` are all live SSRF primitives depending on the client library. Deny-by-default, not a block-list of the ones you remembered.
3. **Reject embedded credentials.** `urlsplit(url).username is not None` → reject. `https://metadata.google.internal@evil.com/` and its inverse both parse differently in different libraries; refusing the form entirely removes the ambiguity.
4. **Resolve once, keep every record.** `socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)` returns a list. A hostname with one public A record and one private AAAA record is an attack, not a misconfiguration.
5. **Validate every returned address against a deny-by-default public-IP rule** (below).
6. **Connect to the validated IP literal**, not the hostname — with `Host:` and TLS SNI still set to the original hostname so certificate validation and virtual hosting still work. This is the step that closes DNS rebinding.
7. **Cap the response**: byte ceiling enforced while streaming, wall-clock timeout, content-type allow-list checked on the *response* header.

## The IP check

```python
import ipaddress

def is_fetchable(addr: str) -> bool:
    ip = ipaddress.ip_address(addr)
    if (m := getattr(ip, "ipv4_mapped", None)) is not None:
        ip = m                      # ::ffff:169.254.169.254 must be judged as 169.254.169.254
    return ip.is_global             # deny-by-default: everything not globally routable is refused
```

`is_global` is the whole rule. Do **not** write `if ip.is_private: reject` — `is_private` and `is_global` are not complements. Verified on CPython: `ipaddress.ip_address("100.64.1.1")` (CGNAT, RFC 6598) reports `is_private=False` **and** `is_global=False`. A private-flag check passes it; a `not is_global` check refuses it. Same trap for an enumerated flag list: `ipaddress.ip_address("::ffff:169.254.169.254").is_link_local` is `False`, because link-local is an IPv4 property and the address is an IPv6 object.

**Pin CPython ≥ 3.12.4.** CVE-2024-4032 (disclosed 2024-06-17): `is_private`/`is_global` on `IPv4Address`, `IPv6Address`, `IPv4Network`, and `IPv6Network` disagreed with the IANA Special-Purpose Address Registries in older builds. Fixed in 3.12.4 / 3.13.0a6 and backports. On an unpatched interpreter this entire check silently mis-classifies addresses, and no test in your suite will notice unless it asserts the specific ranges.

Do not filter on the *string* form. `0177.0.0.1`, `2130706433`, `0x7f.1`, and `127.1` are all 127.0.0.1 to some resolvers; `ipaddress.ip_address` rejects most of them outright, which is why parsing beats matching. `nip.io` / `sslip.io` style wildcard DNS (`10.0.0.1.nip.io`) has a perfectly ordinary hostname and resolves to RFC 1918 — proof that hostname-shaped filtering cannot work.

## Why the resolved-IP pin is mandatory

Validate-then-fetch performs two DNS lookups. The attacker's authoritative server answers the first with a public IP and TTL 0, and the second with `169.254.169.254`. Nothing in your code is wrong; the gap between check and use is the vulnerability. This is still shipping in 2025 products — MobSF's `valid_host()` and Craft CMS's GraphQL asset mutation were both bypassed exactly this way.

Implementation surface:

- **httpx** — a custom `httpx.HTTPTransport` / `AsyncHTTPTransport` subclass that resolves + validates in `handle_request`, then rewrites `request.url.host` to the validated IP literal and sets `request.headers["Host"]` plus the TLS `server_hostname` back to the original name. `httpx` has no first-class resolver hook; the transport is the seam. (`httpx-secure` on PyPI implements this shape if you want a reference implementation.)
- **aiohttp** — `aiohttp.TCPConnector(resolver=...)` with a custom `AbstractResolver` whose `resolve()` filters the records. This is the cleanest hook of the three.
- **requests/urllib3** — no clean hook; either drop `requests` for crawl fetches or resolve yourself and pass the IP with a `Host` header override.

TLS: after pinning, the certificate must still be validated against the **hostname**, not the IP. Getting this backwards trades SSRF for a MITM.

## Redirects

`follow_redirects=True` (httpx) / `allow_redirects=True` (requests, aiohttp) **defeats every check above**, because the redirect is resolved and fetched inside the client, after your validator ran. Symptom: your SSRF test suite passes and production still reaches the metadata service.

Fix: `follow_redirects=False`, then loop yourself — read `Location`, resolve it against the current URL, run the full seven-step pipeline again, cap at 5 hops, and refuse a hop that changes scheme to anything outside `{http, https}` or that introduces credentials. Also refuse a redirect that leaves the tenant's approved domain scope if the crawl source declares one.

## Cloud metadata

All of these are already covered by the `is_global` rule — list them anyway, as the fixture set for the SSRF test in `docs/17-testing-performance.md` §22.5:

| Target | Address |
|---|---|
| AWS / GCP / Azure / DigitalOcean / Oracle IMDS | `169.254.169.254` |
| AWS ECS task metadata | `169.254.170.2` |
| AWS IMDS over IPv6 | `fd00:ec2::254` |
| Alibaba Cloud | `100.100.100.200` |
| GCP by name | `metadata.google.internal` |

**IMDSv2 is not a substitute for the validator.** It requires a `PUT` to obtain a token and a hop limit of 1, which stops *proxy*-shaped SSRF. It does not stop redirect-shaped SSRF: if the crawler follows a redirect to the metadata endpoint, the request still originates from the instance itself — same hop, same result. Set `HttpTokens=required` and `HttpPutResponseHopLimit=1` anyway (AWS made IMDSv2 the default for newly released instance types from mid-2024, and account-level defaults have been settable since March 2024), and treat it as the third layer, never the first.

## Network egress is the only real backstop

Application-layer validation covers code you wrote. It does not cover:

- **Headless-browser subresource fetches.** Crawl4AI drives Playwright. Once a page is loaded, its JavaScript, `<img>`, `<iframe>`, `<link>`, and `fetch()` calls are issued by the *browser process*, which never consults your Python validator. A crawled page can therefore probe the internal network from inside your container. Mitigate in-browser with `page.route("**/*", handler)` and run the same validator inside the handler, aborting non-conforming requests — but treat that as best-effort, not a boundary. <!-- UNVERIFIED: the route-interception mitigation is standard Playwright API usage, but I found no authoritative writeup confirming it intercepts 100% of browser-initiated subresource types. -->
- Bugs in the fetch library itself, and anything else in the container that opens a socket.

So: the crawler gets its **own Docker network** with no route to the `internal` network carrying PostgreSQL, Qdrant, Valkey, and SeaweedFS. Egress goes through a forward proxy that re-runs the IP validation, or through firewall rules denying `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`, `169.254.0.0/16`, `fc00::/7`, and `fe80::/10`. If the crawler needs to write results, it does so through the AI service's API, not by holding a database credential.

## Response handling

- **Byte cap while streaming.** `Content-Length` is attacker-controlled and often absent. Count bytes as you read (`response.aiter_bytes()`) and abort at the cap. A `Content-Encoding: gzip` response also decompresses — cap the *decompressed* size too, or a 1 MB body becomes 10 GB of RAM.
- **Wall-clock timeout on the whole fetch**, not just connect. A server that dribbles one byte per second holds a worker forever; `httpx.Timeout(connect=..., read=..., write=..., pool=...)` plus an outer `asyncio.wait_for`.
- **Content-type allow-list on the response**, matched on the parsed media type only (strip parameters, lowercase). Not on the URL extension — `https://x/report.pdf` can return `text/html`.
- **Concurrency cap per organization**, so one tenant's 50 000-URL sitemap cannot starve every other tenant's crawl.

## Sources

- OWASP SSRF Prevention Cheat Sheet — allow-list over deny-list, "disable the support for the following of the redirection in your web client", network segregation as the second line.
- CVE-2024-4032 (CPython `ipaddress`), fixed 3.12.4 / 3.13.0a6.
- AWS, *Amazon EC2 Instance Metadata Service IMDSv2 by default* (2024) and *Defense in depth against open firewalls, reverse proxies, and SSRF*.
- MobSF `valid_host()` DNS-rebinding bypass and Craft CMS GraphQL asset-mutation TOCTOU (both 2025) as current proof the two-lookup pattern still ships.
