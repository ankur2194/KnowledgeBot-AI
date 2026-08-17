import Link from 'next/link';

import { ForgotPasswordForm } from '@/features/auth/forgot-password-form';

/**
 * Request a password-reset link.
 *
 * THE CONFIRMATION IS BYTE-IDENTICAL whether the address exists or not, and that is the whole
 * security property of this screen: any difference in copy, in status, or in response TIME turns it
 * into an account-enumeration oracle. Laravel answers 200 unconditionally; the client must not
 * "helpfully" distinguish. A component spec asserts the two renders are identical, because this is
 * the one layer where that regression is catchable before it ships.
 *
 * A SERVER COMPONENT THAT PASSES ONE PRIMITIVE DOWN. `searchParams` is a Promise in Next 16 and is
 * awaited here; what crosses into `<ForgotPasswordForm/>` is a single string, because anything a
 * server component hands a client component is serialized into the RSC payload embedded in the HTML —
 * the whole object, not the fields the UI reads.
 *
 * `?email=` is a PREFILL AND NOTHING ELSE — the address a failed sign-in already had typed. It is
 * attacker-controlled (anybody can link here with any address), which is exactly why it is only ever
 * a `defaultValues` seed for a field the user can see and edit, is re-validated by the schema and
 * again by `ForgotPasswordRequest`, and is never used to look anything up on this side.
 *
 * NOTHING IS CACHED AND NOTHING IS CACHEABLE: the layout pins `force-dynamic` because a `force-static`
 * page would read `searchParams` as EMPTY and the Full Route Cache would then serve one shell to
 * everybody. Nothing here is org-scoped — a visitor at this route has no organization — so the
 * org-switch question does not arise: there is no state to reset, because the Next server produced no
 * tenant byte.
 */
export default async function ForgotPasswordPage({
  searchParams,
}: {
  readonly searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  // A repeated `?email=a&email=b` arrives as an array; Next gives no guarantee about which one a
  // consumer "meant", so neither does this — an array seeds nothing and the user types the address.
  const seed = typeof params.email === 'string' ? params.email : '';

  return (
    <section aria-labelledby="forgot-heading" className="space-y-6">
      <h1 id="forgot-heading" className="text-2xl font-semibold">
        Reset your password
      </h1>
      <p className="text-muted-foreground text-sm">
        Enter the email address on your account and we will send a link to choose a new password.
      </p>
      <ForgotPasswordForm email={seed} />
      <p className="text-muted-foreground text-sm">
        <Link href="/login" className="underline">
          Back to sign in
        </Link>
      </p>
    </section>
  );
}
