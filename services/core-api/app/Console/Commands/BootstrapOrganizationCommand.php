<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Support\Kb\FrontendUrl;
use App\Support\Observability\LogContext;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Create the FIRST organization and its owner. Run once, on an empty database.
 *
 * NOT A SEEDER. `DatabaseSeeder` stays empty per its own docblock, and nothing is registered in it: a
 * seeder is something `migrate --seed` runs by accident, and this command must never be something that
 * happens as a side effect of a migration.
 *
 * ── HOW IT AVOIDS BEING A PRODUCTION BACKDOOR — FOUR PROPERTIES, EACH INDEPENDENTLY SUFFICIENT ───
 *
 * 1. IT REFUSES TO RUN WHEN ANY ORGANIZATION EXISTS, and there is no `--force`. It is idempotent BY
 *    REFUSAL rather than by upsert, and the difference is the whole property: an upsert would let this
 *    command silently CHANGE an existing organization's owner — which is precisely the backdoor. A
 *    second organization is a product feature reached through the API, not a command flag.
 *
 * 2. IT NEVER ACCEPTS A PASSWORD, IN ANY FORM — no option, no prompt, no environment variable. It
 *    creates the owner with `bin2hex(random_bytes(32))` as an unusable placeholder that it never
 *    prints, then mints a reset token through the NORMAL broker and sends the standard
 *    App\Notifications\ResetPassword. The operator sets their own password through the same flow every
 *    other user uses. Consequence: the secret never reaches shell history, an env file, a Compose file,
 *    or `docker compose config` output — which `make prod-config` runs before every deploy and which
 *    renders every interpolated value in full. A `KB_BOOTSTRAP_OWNER_PASSWORD` variable would also
 *    contain the string PASSWORD and would need allow-listing in tests/Arch/SecretsResolverTest.php,
 *    which is that suite telling you not to.
 *
 * 3. `--print-link` IS THE ONLY WAY TO GET THE URL ON STDOUT, it is OFF BY DEFAULT, and its help text
 *    says it writes a credential there. It exists because bootstrapping an environment with no working
 *    mailbox is a real situation, and gating it would produce a lockout with no way out.
 *
 * 4. IT IS INTERACTIVE BY DEFAULT and fully non-interactive when every option is supplied, so
 *    `--no-interaction` works in a CI smoke test — and an operator running it by hand is asked, out
 *    loud, for every value rather than getting a plausible default.
 *
 * ── WHY THE TRANSACTION IS NOT IN THIS FILE ─────────────────────────────────────────────────────
 *
 * `tests/Arch/DoctrineTest.php:40-42` pins `Illuminate\Support\Facades\DB` to
 * `App\Repositories\Eloquent`, so a `DB::transaction()` here fails the Arch suite. The three rows —
 * organization, owner, membership — go in one transaction inside
 * OrganizationRepositoryInterface::createWithOwner(), which is also where they belong: an organization
 * with no owner cannot be administered and an owner with no membership authorizes nothing.
 *
 * ── THE OWNER IS CREATED VERIFIED, UNLIKE AN INVITEE ────────────────────────────────────────────
 *
 * `email_verified_at = now()`. There is no invitation to prove the address, the operator is asserting
 * it out of band, and an unverified owner cannot pass the `verified` gate on any org-scoped write route
 * — i.e. cannot do the thing they were created for. The address is nonetheless validated through the
 * SAME rules RegisterRequest uses and lower-cased first: `users_email_unique` is on `lower(email)`
 * while `EloquentUserProvider::retrieveByCredentials()` does an exact match, so a mixed-case bootstrap
 * owner could never log in and could never reset their password either — on the very first user.
 *
 * ── IT WRITES NO AUDIT ROW ──────────────────────────────────────────────────────────────────────
 *
 * App\Services\Audit\AuditLogger::OPERATIONS has no `organization.bootstrapped`, and an unknown
 * operation name throws by design. This command therefore writes one STRUCTURED LOG LINE instead,
 * which is telemetry and not audit — kb-observability-conventions separates the two explicitly — so the
 * §18.11 requirement that organization creation be audited is NOT met by this path. Reported, not
 * silently resolved: closing it is one entry in that constant plus a call inside the repository's
 * transaction, in files this change set does not own.
 *
 * The class carries the `Command` suffix because `arch()->preset()->laravel()` asserts it for
 * everything in App\Console\Commands. The artisan signature — the part that is a contract — is
 * `kb:bootstrap-organization`.
 */
final class BootstrapOrganizationCommand extends Command
{
    /**
     * Bytes of the unusable placeholder password.
     *
     * It is never printed, never returned and never reachable: `bin2hex(random_bytes(32))` is 64 hex
     * characters, so it satisfies every password rule while being a value nobody holds. It exists only
     * so `users.password` is NOT NULL and so a stolen row yields a hash of a 256-bit random rather than
     * of a guessable placeholder.
     */
    private const PLACEHOLDER_BYTES = 32;

    /** Random suffix length on a derived slug, so two organizations named alike do not collide. */
    private const SLUG_SUFFIX = 6;

    protected $signature = 'kb:bootstrap-organization
                            {--name= : Organization display name}
                            {--slug= : URL slug; defaults to a slug of --name plus a short random suffix}
                            {--email= : Owner email address; the password-setup link is sent here}
                            {--owner-name= : Owner display name}
                            {--platform-owner : Also set users.is_platform_owner (spec §6.1)}
                            {--print-link : WRITES A CREDENTIAL TO STDOUT — print the password-setup URL instead of relying on mail}';

    protected $description = 'Create the first organization and its owner. Refuses to run once any organization exists.';

    public function handle(OrganizationRepositoryInterface $organizations): int
    {
        // PROPERTY 1, and it is checked FIRST — before any prompt, so an operator on a live database is
        // refused immediately rather than after typing an address.
        if ($organizations->anyExists()) {
            $this->components->error(
                'An organization already exists, so this command will not run. It is deliberately '
                .'idempotent by REFUSAL and has no --force: an upsert here could silently change an '
                .'existing organization\'s owner. Create further organizations through the API.',
            );

            return self::FAILURE;
        }

        $name = $this->requiredValue('name', 'Organization display name');
        $ownerName = $this->requiredValue('owner-name', 'Owner display name');
        $email = $this->requiredValue('email', 'Owner email address');

        if ($name === null || $ownerName === null || $email === null) {
            return self::FAILURE;
        }

        // Lower-cased BEFORE validation, exactly as StoreInvitationRequest and LoginRequest do it in
        // prepareForValidation(). See the class docblock for why this is the one normalisation that
        // cannot be skipped.
        $email = Str::lower(trim($email));

        if (! $this->emailIsValid($email)) {
            return self::FAILURE;
        }

        $slug = $this->slugFor($name);

        if ($slug === null) {
            return self::FAILURE;
        }

        try {
            $created = $organizations->createWithOwner(
                name: $name,
                slug: $slug,
                email: $email,
                ownerName: $ownerName,
                platformOwner: (bool) $this->option('platform-owner'),
                // PROPERTY 2. Minted here, handed straight to the repository, never printed, never
                // logged, never returned. The local variable is not even named.
                placeholderPassword: bin2hex(random_bytes(self::PLACEHOLDER_BYTES)),
            );
        } catch (Throwable $failure) {
            $this->components->error('Could not create the organization: '.$failure->getMessage());

            return self::FAILURE;
        }

        $owner = $created['owner'];

        // AFTER the transaction. The broker writes its own row and the notification queues a job, and
        // neither belongs inside a transaction whose rollback cannot reach them.
        $link = $this->issuePasswordSetupLink($owner);

        if ($link === null) {
            // PARTIAL FAILURE IS A FAILURE. The organization and the owner exist and the operator has no
            // way in, so this must exit non-zero: AppServiceProvider's `->onFailure()` hook keys on a
            // non-zero exit, and a command that swallows this and returns SUCCESS is indistinguishable
            // from one that worked. `kb:bootstrap-organization` cannot be re-run (property 1), so the
            // recovery is the normal forgot-password flow — which the message below says.
            $this->components->error(
                'The organization and its owner were created, but the password-setup link could not be '
                .'issued. Nothing here can be re-run: use POST /api/v1/auth/forgot-password for '
                .$email.' once mail is working.',
            );

            $this->logBootstrap($created['organization']->id, linkIssued: false);

            return self::FAILURE;
        }

        $this->logBootstrap($created['organization']->id, linkIssued: true);

        $this->components->info(sprintf(
            'Created organization "%s" (%s) with owner %s.',
            $name,
            $slug,
            $email,
        ));

        if ((bool) $this->option('print-link')) {
            // PROPERTY 3. Opt-in, and announced as what it is.
            $this->components->warn('The URL below is a LIVE CREDENTIAL. It is on your terminal and in your scrollback.');
            $this->line($link);
        } else {
            $this->components->info(
                'A password-setup link has been mailed to '.$email
                .'. Re-run with --print-link if mail is not working in this environment.',
            );
        }

        return self::SUCCESS;
    }

    /**
     * Mint a reset token through the NORMAL broker and send the STANDARD notification.
     *
     * `createToken()` + `sendPasswordResetNotification()` rather than `sendResetLink()`, and the
     * decomposition buys exactly one thing: the token, so `--print-link` can build the URL. It is the
     * same two calls `PasswordBroker::sendResetLink()` makes, in the same order, against the same token
     * repository — so the link this prints and the link the mail carries are the same link, and the
     * expiry is `config('auth.passwords.users.expire')` with no second opinion.
     *
     * `broker()` below is what makes `createToken()` reachable — see that method for why the call
     * cannot be made straight off the facade. Its throw happens inside this try, so a broken
     * container surfaces as "Password-setup link failed: …" and a non-zero exit with the
     * organization already created, which is this method's documented failure mode rather than a
     * new one.
     *
     * Returns null on failure, so the caller can exit non-zero with the organization already created.
     */
    private function issuePasswordSetupLink(User $owner): ?string
    {
        try {
            $token = $this->broker()->createToken($owner);

            // The standard notification, via App\Models\User::sendPasswordResetNotification(), which is
            // queued. Nothing bespoke: an operator's first email from this system should be the exact
            // mail every other user gets, or the bootstrap path is the one flow nobody ever tests.
            $owner->sendPasswordResetNotification($token);

            // Built the same way App\Notifications\ResetPassword builds it — same helper, same two query
            // parameters — because a printed URL that differs from the mailed one by a parameter is a
            // link that 404s in the SPA for reasons nobody can see.
            return FrontendUrl::for('/reset-password', [
                'token' => $token,
                'email' => $owner->getEmailForPasswordReset(),
            ]);
        } catch (Throwable $failure) {
            $this->components->error('Password-setup link failed: '.$failure->getMessage());

            return null;
        }
    }

    /**
     * The default password broker, narrowed to the CONCRETE class that actually has `createToken()`.
     *
     * ── WHY THIS METHOD EXISTS AT ALL ─────────────────────────────────────────────────────────────
     *
     * `Password::broker()` is DECLARED as `Illuminate\Contracts\Auth\PasswordBroker`, and that
     * interface declares exactly two methods: `sendResetLink()` and `reset()`. `createToken()` lives
     * only on the concrete `Illuminate\Auth\Passwords\PasswordBroker`, which is what
     * `PasswordBrokerManager` has always returned. So PHPStan is RIGHT — the declared type has no
     * such method — and the code is also right, which is precisely the situation that must not be
     * settled with a baseline entry or a `phpstan-ignore` line (spelled without its leading @ here
     * precisely because the analyser parses the mention as the annotation): the analyser cannot see a fact the
     * container guarantees, so the fact is asserted where it is cheap and the analyser is left able
     * to fail on everything else.
     *
     * A THROW AND NOT `assert()`. Assertions compile out under `zend.assertions=-1`, so on a
     * production box a substituted broker would reach `createToken()` on an object that has no such
     * method and take the process down with a fatal error instead of a catchable Throwable. The
     * caller's `catch (Throwable)` turns this into a readable message and a non-zero exit.
     *
     * NOT `Password::createToken($owner)`, which the facade publishes as an `@method` and which
     * would also analyse cleanly. That routes through `PasswordBrokerManager::__call`, whose return
     * type PHPStan can only take from a docblock on a magic method — a weaker guarantee than a real
     * instance of a real class — and it would leave nothing in the tree explaining why the obvious
     * call does not type-check.
     *
     * THE `->broker()` IN THE CALL SITE IS LOAD-BEARING FOR MORE THAN READABILITY.
     * tests/Security/SingleCredentialMechanismTest.php scans every line of app/ for a `createToken`
     * call and narrows its one exclusion BY RECEIVER — `Password::`, `->broker()` or the class name
     * on the same line — so that a genuine mint on a User in this same file still fails. Reading the
     * broker into a local first would leave a bare `$variable` as the receiver on that line, which
     * trips a Sanctum-credential check over a password-reset token that is not one. Keeping the
     * receiver as `$this->broker()` keeps that narrowing meaningful without widening its regex, and
     * that is why the local lives behind this method instead of in the caller.
     */
    private function broker(): PasswordBroker
    {
        $broker = Password::broker();

        if (! $broker instanceof PasswordBroker) {
            throw new RuntimeException(
                'The default password broker is '.$broker::class.', which does not implement '
                .'createToken(). This command mints a reset token through the NORMAL broker on '
                .'purpose, so the first operator sets their own password through the same flow '
                .'every other user uses; a substituted broker has to provide the same token '
                .'repository, or the printed link and the mailed link stop being the same link.',
            );
        }

        return $broker;
    }

    /**
     * One structured log line. Telemetry, NOT audit — see the class docblock.
     *
     * ── THE DESIGN'S TWO CONTEXT KEYS DO NOT EXIST, AND THAT IS REPORTED RATHER THAN WORKED AROUND ──
     *
     * §11 asks for `operation: 'organization.bootstrapped'` and `actor_type: 'operator'` in the
     * context. `App\Logging\KbJsonFormatter::ALLOWED_EXTRA_FIELDS` is CLOSED and contains neither, so
     * both would be dropped by name — silently as far as the operator is concerned, since the line
     * would still be written.
     *
     *   * `operation` is satisfiable and is satisfied: it is a FORMATTER-OWNED field, and
     *     `LogContext::setOperation()` exists precisely so a command or a queued job — neither of which
     *     has a route to derive it from — can name it. So the field lands on the line, through the
     *     mechanism that owns it.
     *   * `actor_type` has no home in the vocabulary at all. Adding one is a change to the closed
     *     catalog in both runtimes, which is observability-engineer's call, not this change set's. It is
     *     dropped, and the operation name already says an operator did this: there is no authenticated
     *     user on a console command for it to be distinguished from.
     *
     * `outcome` and `org_id` are both on the allow-list. Nothing here carries a credential or a token
     * fingerprint: there is no audit operation to key a fingerprint against, and an unshaped 16-
     * character hex string in a log line is exactly the value KbJsonFormatter's redaction cannot
     * distinguish from a request id.
     */
    private function logBootstrap(string $organizationId, bool $linkIssued): void
    {
        // A console command has no route, so the formatter cannot derive `operation` — this is the
        // documented way to name it. Set immediately before the write rather than at the top of
        // handle(), so the refusal path does not label itself as a bootstrap that happened.
        LogContext::setOperation('organization.bootstrapped');

        Log::info('Bootstrapped the first organization and its owner.', [
            'org_id' => $organizationId,
            // `success` / `failure` are the two values AuditLogger uses for the same question, so a
            // dashboard does not need a third vocabulary. A link that could not be issued is a partial
            // creation and the command exits non-zero, so the line must not read as a success.
            'outcome' => $linkIssued ? 'success' : 'failure',
        ]);
    }

    /**
     * An option, or a prompt for it, or null with an error already printed.
     *
     * `--no-interaction` makes `ask()` return the default (null), which is what turns a missing option
     * into a clean refusal in CI rather than a hang.
     */
    private function requiredValue(string $option, string $question): ?string
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            $value = $this->components->ask($question);
        }

        if (! is_string($value) || trim($value) === '') {
            $this->components->error("--{$option} is required.");

            return null;
        }

        return trim($value);
    }

    /**
     * The SAME rules RegisterRequest applies to an address, run through the validator rather than
     * restated — a second spelling of `email:rfc,strict` is a second thing to get wrong on the one user
     * who cannot be created any other way.
     */
    private function emailIsValid(string $email): bool
    {
        $validator = Validator::make(
            ['email' => $email],
            ['email' => ['bail', 'required', 'string', 'email:rfc,strict', 'max:254']],
        );

        if ($validator->fails()) {
            $this->components->error('--email is not a valid address: '.$validator->errors()->first('email'));

            return false;
        }

        return true;
    }

    /**
     * `--slug`, or one derived from the name plus a short random suffix.
     *
     * The suffix is not decoration: `organizations_slug_unique` is global, and a derived slug with no
     * suffix makes "Acme" unusable for the second tenant. On the FIRST organization that cannot yet
     * happen, which is exactly why the rule is established here rather than discovered later.
     */
    private function slugFor(string $name): ?string
    {
        $supplied = $this->option('slug');

        if (is_string($supplied) && trim($supplied) !== '') {
            $slug = Str::slug(trim($supplied));

            if ($slug === '') {
                $this->components->error('--slug contains no sluggable characters.');

                return null;
            }

            return $slug;
        }

        $base = Str::slug($name);

        if ($base === '') {
            // `Str::slug()` transliterates and then strips, so a name written entirely in a script it
            // cannot transliterate — or in punctuation — yields the empty string. Refusing is right: the
            // alternative is an organization whose slug is a bare random suffix, which nobody can read
            // and which the operator did not choose.
            $this->components->error(
                'Could not derive a slug from --name. Pass --slug explicitly: a name that slugs to '
                .'nothing would leave this organization addressed by a bare random suffix.',
            );

            return null;
        }

        // The suffix is not decoration: `organizations_slug_unique` is GLOBAL, so a derived slug with no
        // suffix makes "Acme" unusable for the second tenant. On the first organization that cannot yet
        // happen, which is exactly why the rule is established here rather than discovered later.
        return $base.'-'.Str::lower(Str::random(self::SLUG_SUFFIX));
    }
}
