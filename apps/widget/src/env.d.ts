/// <reference types="vite/client" />

/**
 * The THREE build-time constants, and there are exactly three.
 *
 * Declaring one here is only half the job: `define` in vite.config.ts is a TEXT SUBSTITUTION, not
 * a binding, so a constant declared here and missing from `define` type-checks cleanly, satisfies
 * the editor, and then ships as a bare identifier that throws `ReferenceError` on a customer's
 * live page before the launcher paints.
 *
 * The assertion that catches it:
 *   rg -o '__KB_[A-Z_]+__' apps/widget/src | sort -u   ==   the `define` keys   ==   this file
 * and neither built bundle may contain the substring `__KB_` (apps/widget/Dockerfile greps for it).
 *
 * There is no fourth constant, and in particular none naming the hosted-chat origin: the loader is
 * given exactly two origins on purpose, and the hosted-chat fallback is derived from
 * `__KB_API_ORIGIN__` in src/loader/degrade.ts. (A constant name written out in a COMMENT would
 * fail that same grep, which is why this paragraph describes it instead of spelling it.)
 */

/** The loader's own version, from `KB_VERSION` or the package manifest. Used only in the
 *  double-injection console warning, so a customer can see which copy won. */
declare const __KB_VERSION__: string;

/** `https://<widget-domain>` — a SEPARATE registrable domain. The iframe's origin, the only value
 *  ever passed as a `targetOrigin` by the loader, and the only value `event.origin` is compared
 *  against with `===` on the host side. */
declare const __KB_WIDGET_ORIGIN__: string;

/** `https://api.<domain>` — the Laravel public API, on the MAIN registrable domain. Used for
 *  exactly one request from the host document: the session mint, which is the only request in the
 *  whole system carrying an unforgeable `Origin: https://customer.example`.
 *
 *  It is a different eTLD+1 from `__KB_WIDGET_ORIGIN__` by rule, not by accident: the admin
 *  session cookie is scoped to the main domain, so an API on the widget's registrable domain would
 *  put a widget iframe on a hostile customer page same-site with a real admin credential. */
declare const __KB_API_ORIGIN__: string;
