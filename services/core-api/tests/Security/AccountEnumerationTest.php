<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\InviteToOrganization;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmailAddress;
use App\Support\Kb\OpaqueToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| Account enumeration — docs/13 §18.3, and it is NOT the deny oracle
|--------------------------------------------------------------------------
|
| TWO DIFFERENT ORACLES, AND CONFLATING THEM PRODUCES THE WRONG TEST.
|
| tests/Security/DenyOracleTest.php and `expect()->toDenyAsNotFound()` assert the 403-ADMIN /
| 404-PUBLIC SURFACE SPLIT: on `rt/*` and `sdk/*`, a DENIED record must be byte-identical to a record
| that NEVER EXISTED. Their reference is always a LIVE control — a real request to a path on the same
| surface that certainly has no route — so it cannot drift into self-agreement.
|
| THIS FILE ASSERTS A DIFFERENT PROPERTY ON A DIFFERENT SURFACE. The admin surface DELIBERATELY
| admits that a record exists, with a 403 (DenyOracleTest's last case exists to keep it that way, so
| that its public arm cannot go vacuous). So:
|
|   * toDenyAsNotFound() MUST NOT BE USED ON ANY ADMIN AUTH ROUTE. It would pass — an unknown
|     `api/v1/<ulid>` also 404s — while asserting a property this surface does not have.
|   * NO ADMIN AUTH DENIAL MAY BE TURNED INTO A 404 "to be safe". That would make DenyOracleTest's
|     admin arm fail and its public arm vacuous.
|
| The property here is: FOR ONE ENDPOINT, THE N WAYS OF FAILING ARE ONE RESPONSE — compared PAIRWISE
| AGAINST EACH OTHER, never against a literal and never against a missing route. What an attacker
| does is submit two probes and diff the answers, so that is exactly what each test below does.
|
| THE TECHNIQUE IS COPIED FROM DenyOracleTest AND TWO PARTS OF IT ARE LOAD-BEARING:
|
|   1. X-KB-Request-Id IS PINNED. `request_id` echoes it when the caller sends one (RequestId.php's
|      pattern admits any 8-64 chars of [A-Za-z0-9._-]) and mints a ULID otherwise — so without
|      pinning, a byte comparison is GUARANTEED to fail on a field that is supposed to differ. An
|      attacker controls this header too, so pinning it is also the realistic probe.
|   2. ABSENCE IS ASSERTED WITH str_contains(...)->toBeFalse(), NEVER WITH ->not->toContain(...).
|      Pest's toContain() is `toContain(mixed ...$needles)` and takes no message argument, so a
|      second argument becomes a SECOND NEEDLE; and `not` is implemented by running the positive
|      expectation and treating ANY failure as success, and the positive expectation throws on the
|      FIRST absent needle. `->not->toContain($needle, $label)` therefore passes whenever the LABEL
|      is absent, whatever the needle did. It reads like an assertion and is unconditionally green.
|
| WHY THERE IS NO tenantPair() CALL. Same reason DenyOracleTest gives: the property is a property of
| the RESPONSE SHAPE, and it is open or closed identically for one organization or a thousand. Where
| a probe needs an organization at all (the invitation family) TWO are built, because "this
| invitation belongs to somebody else" is a case that only exists with a second tenant.
| TODO(fixtures): move to tenantPair() once its KnowledgeSource half lands in Phase C and lets it be
| implemented.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN, so no rate-limiter bucket is shared with another test or
    // with the previous run of the suite — `password-request` allows three per FIFTEEN MINUTES and the
    // forgot-password case below spends exactly three. See Tests\Support\SpaSession::isolateRateLimits().
    SpaSession::isolateRateLimits(currentTest());

    // Several probes deliberately provoke a logged warning or error; the `stack` channel writes JSON
    // to php://stdout and would interleave with Pest's own output. Silencing the LOGGER never
    // silences the RENDERER — the responses under test are untouched.
    config(['logging.default' => 'null']);
});

/**
 * One request id for every probe in this file, so the envelopes are comparable byte for byte.
 */
const ENUM_PROBE_REQUEST_ID = '01JKB000000000000000ENUM01';

/**
 * The fragments that must appear in NO failure body on these endpoints, and what each would tell a
 * prober. Compared case-insensitively, which is strictly stronger than the literal.
 *
 * @param  list<string>  $probedValues  fixture-specific values (addresses, organization names)
 * @return array<string, string>
 */
function enumForbiddenFragments(array $probedValues = []): array
{
    $forbidden = [
        // The four words that distinguish "the thing you named is real but spent" from "the thing you
        // named does not exist". Each of them, on its own, is the oracle.
        'expired' => 'that the token it probed WAS ISSUED and has since lapsed',
        'already' => 'that the state it probed had been reached before — i.e. the record is real',
        'used' => 'that the capability it probed was real and has been consumed',
        'revoked' => 'that the record it probed exists and was withdrawn by an administrator',
        'member' => 'whether the address it probed already belongs to the organization',
        'suspend' => 'the lifecycle state of an organization it has proven no relationship to',
    ];

    foreach ($probedValues as $value) {
        $forbidden[$value] = 'the exact value it submitted, echoed back — which confirms the probe '
            .'addressed something the server recognised';
    }

    return $forbidden;
}

/**
 * THE ASSERTION OF THIS FILE: every response in $responses is the same response.
 *
 * Compared PAIRWISE rather than each-against-the-first, so a failure message names the two probes
 * that can be told apart rather than "probe 4 differs from probe 1".
 *
 * @param  array<string, TestResponse<\Illuminate\Http\JsonResponse>>  $responses
 * @param  array<string, string>  $forbidden
 */
function enumIsOneResponse(array $responses, array $forbidden): void
{
    expect(count($responses))->toBeGreaterThan(
        1,
        'an enumeration test with one probe compares nothing: the property is that N failures are '
        .'indistinguishable, so a single-probe version passes against a fully open oracle',
    );

    $bodies = [];
    $statuses = [];

    foreach ($responses as $label => $response) {
        $bodies[$label] = (string) $response->getContent();
        $statuses[$label] = $response->status();

        // POSITIVE CONTROL ON THE SHAPE ITSELF. Without it, an endpoint that 500s with an empty body
        // for every probe satisfies "all the responses match" perfectly.
        expect($bodies[$label])->toBeJson("[{$label}] did not even render JSON");
        expect($bodies[$label])->not->toBe('', "[{$label}] rendered an empty body");
        expect($statuses[$label])->toBeLessThan(500, "[{$label}] rendered a server error");
    }

    $labels = array_keys($bodies);

    foreach ($labels as $index => $left) {
        foreach (array_slice($labels, $index + 1) as $right) {
            expect($statuses[$right])->toBe(
                $statuses[$left],
                "[{$left}] and [{$right}] are distinguishable BY STATUS, so this endpoint answers "
                .'a question about whether the record exists.',
            );

            expect($bodies[$right])->toBe(
                $bodies[$left],
                "[{$left}] and [{$right}] are distinguishable BY BODY BYTES. An attacker diffs "
                .'responses, not statuses: two failure modes that render differently are an '
                .'account-existence oracle even at the same status code.',
            );
        }
    }

    foreach ($bodies as $label => $body) {
        $haystack = mb_strtolower($body);

        foreach ($forbidden as $fragment => $tells) {
            expect(str_contains($haystack, mb_strtolower($fragment)))->toBeFalse(
                "[{$label}] leaked [{$fragment}] into the failure body, which tells a prober {$tells}.",
            );
        }
    }
}

/**
 * @param  array<string, mixed>  $payload
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function enumProbe(string $uri, array $payload): TestResponse
{
    return currentTest()->postJson(
        $uri,
        $payload,
        spaHeaders(['X-KB-Request-Id' => ENUM_PROBE_REQUEST_ID]),
    );
}

/**
 * Seconds of wall clock a probe took. Used only for the two timeboxed endpoints.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: TestResponse<\Illuminate\Http\JsonResponse>, 1: float}
 */
function enumTimedProbe(string $uri, array $payload): array
{
    $started = hrtime(true);
    $response = enumProbe($uri, $payload);

    return [$response, (hrtime(true) - $started) / 1_000_000_000];
}

// -------------------------------------------------------------------------------------------
// POST /api/v1/auth/login
// -------------------------------------------------------------------------------------------

it('cannot tell an unknown address from a wrong password on login', function (): void {
    $known = SpaSession::uniqueEmail('enum-known');
    $absent = SpaSession::uniqueEmail('enum-absent');

    $organization = Organization::factory()->create(['name' => 'Enumeration Org DELTA']);
    User::factory()->recycle($organization)->orgRole(OrgRole::Admin)->create(['email' => $known]);

    [$wrongPassword, $wrongPasswordSeconds] = enumTimedProbe('/api/v1/auth/login', [
        'email' => $known,
        'password' => 'not-the-fixture-password-at-all',
    ]);

    [$unknownAddress, $unknownAddressSeconds] = enumTimedProbe('/api/v1/auth/login', [
        'email' => $absent,
        'password' => 'not-the-fixture-password-at-all',
    ]);

    enumIsOneResponse(
        ['wrong password for a real account' => $wrongPassword, 'no such account' => $unknownAddress],
        enumForbiddenFragments([$known, $absent, 'Enumeration Org DELTA']),
    );

    // THE OTHER CHANNEL, AND IT IS THE ONE A CAREFUL PROBER USES. SessionGuard::attempt() runs both
    // failure modes inside a 200 ms timebox and calls returnEarly() on SUCCESS only, so the timing
    // signal is success-versus-failure and never existence-versus-absence. Asserted on BOTH probes:
    // asserting only that they are within N ms of each other would also pass if the timebox were
    // removed and both happened to be fast.
    expect($wrongPasswordSeconds)->toBeGreaterThan(
        0.19,
        'the wrong-password branch returned faster than the 200 ms timebox, so the two branches can '
        .'be told apart by a stopwatch even though their bodies match',
    );
    expect($unknownAddressSeconds)->toBeGreaterThan(
        0.19,
        'the unknown-address branch returned faster than the 200 ms timebox — this is the classic '
        .'oracle: no user row means no hash to verify, so the request returns early',
    );
});

// -------------------------------------------------------------------------------------------
// POST /api/v1/auth/forgot-password
// -------------------------------------------------------------------------------------------

it('cannot tell unknown from known from broker-throttled on forgot-password', function (): void {
    // UNIQUE PER RUN. `password-request` allows three per FIFTEEN MINUTES keyed on the SUBMITTED
    // address, and this test spends exactly three on the known one — a literal would pass the first
    // run and 429 the second.
    $known = SpaSession::uniqueEmail('enum-reset');
    $absent = SpaSession::uniqueEmail('enum-absent');

    $user = User::factory()->create(['email' => $known]);

    [$unknownResponse, $unknownSeconds] = enumTimedProbe('/api/v1/auth/forgot-password', [
        'email' => $absent,
    ]);

    [$knownResponse, $knownSeconds] = enumTimedProbe('/api/v1/auth/forgot-password', [
        'email' => $known,
    ]);

    // POSITIVE CONTROL FOR THE THIRD PROBE, and without it that probe is decoration. The repeat has
    // to actually reach PasswordBroker's RESET_THROTTLED branch, and the only way to know is that it
    // creates NO new token: `create()` deletes and re-inserts, so a fresh bcrypt payload would differ.
    $issued = Schema::getConnection()
        ->table('password_reset_tokens')
        ->where('email', $user->email)
        ->value('token');

    expect($issued)->toBeString('the known address created no reset token, so there is no throttle '
        .'branch to probe and this test would be comparing two unknown-address responses');

    [$repeatedResponse, $repeatedSeconds] = enumTimedProbe('/api/v1/auth/forgot-password', [
        'email' => $known,
    ]);

    expect(
        Schema::getConnection()->table('password_reset_tokens')->where('email', $user->email)->value('token')
    )->toBe($issued, 'the repeated request minted a NEW token, so it took the send branch rather '
        .'than the throttle branch — the case this test exists for was never exercised');

    expect(Schema::getConnection()->table('password_reset_tokens')->count())->toBe(
        1,
        'the unknown address created a reset row, which is an information leak in the database as '
        .'well as a wasted write',
    );

    // THE BRANCH THAT REGRESSES SILENTLY. Returning 429 on RESET_THROTTLED and 200 on INVALID_USER is
    // an oracle in two requests: probe twice, and a throttle proves the FIRST probe created a token,
    // which proves the account exists.
    enumIsOneResponse(
        [
            'address does not exist' => $unknownResponse,
            'address exists, link sent' => $knownResponse,
            'same address again, broker-throttled' => $repeatedResponse,
        ],
        enumForbiddenFragments([$known, $absent]),
    );

    // PasswordBroker::sendResetLink() wraps the whole branch — lookup, throttle check, token create
    // and the notification callback — in the same 200 ms timebox.
    foreach ([
        'address does not exist' => $unknownSeconds,
        'address exists, link sent' => $knownSeconds,
        'same address again, broker-throttled' => $repeatedSeconds,
    ] as $label => $seconds) {
        expect($seconds)->toBeGreaterThan(
            0.19,
            "[{$label}] returned inside the 200 ms timebox floor, so the three branches are "
            .'distinguishable by a stopwatch',
        );
    }
});

it('queues every credential-bearing mail, which is what keeps the reset timebox honest', function (): void {
    // THE TIMING ASSERTION ABOVE CANNOT PROVE THIS AND MUST NOT BE TRUSTED TO.
    // PasswordBroker::sendResetLink() sends the mail INSIDE its 200 ms timebox, so a SYNCHRONOUS SMTP
    // send blows the floor on the exists-branch and re-opens the oracle the timebox closes. In the
    // suite MAIL_MAILER=array, so a non-queued notification is instant and the timing test stays
    // green while production leaks. The structural property is therefore asserted directly, and it is
    // the reason all three of these implement ShouldQueue.
    foreach ([ResetPassword::class, VerifyEmailAddress::class, InviteToOrganization::class] as $class) {
        // `class_implements()` rather than `is_subclass_of()`: PHPStan folds the latter over a literal
        // class list and reports "will always evaluate to true", which is true TODAY and is the whole
        // point of a regression guard.
        expect(in_array(ShouldQueue::class, (array) class_implements($class), true))->toBeTrue(
            $class.' is not ShouldQueue. A synchronous send inside PasswordBroker::sendResetLink()\'s '
            .'timebox exceeds 200 ms on the branch where the account EXISTS, which is exactly the '
            .'account-enumeration timing oracle the timebox exists to close.',
        );
    }
});

// -------------------------------------------------------------------------------------------
// POST /api/v1/auth/reset-password
// -------------------------------------------------------------------------------------------

it('cannot tell an unknown address from a garbage token on reset-password', function (): void {
    $known = SpaSession::uniqueEmail('enum-reset');
    $absent = SpaSession::uniqueEmail('enum-absent');

    User::factory()->create(['email' => $known]);

    $password = ['password' => 'Enumeration-Probe-1', 'password_confirmation' => 'Enumeration-Probe-1'];

    $unknownAddress = enumProbe('/api/v1/auth/reset-password', [
        'token' => OpaqueToken::mint(),
        'email' => $absent,
    ] + $password);

    $garbageToken = enumProbe('/api/v1/auth/reset-password', [
        'token' => OpaqueToken::mint(),
        'email' => $known,
    ] + $password);

    enumIsOneResponse(
        ['no such account' => $unknownAddress, 'real account, invalid token' => $garbageToken],
        enumForbiddenFragments([$known, $absent]),
    );
});

// -------------------------------------------------------------------------------------------
// POST /api/v1/auth/invitations/preview
// -------------------------------------------------------------------------------------------

/**
 * Two organizations, so "somebody else's invitation" is a case that exists at all, and one plaintext
 * token per invitation state.
 *
 * @return array{organization: Organization, other: Organization, inviter: User, tokens: array<string, string>}
 */
function enumInvitationStates(): array
{
    $organization = Organization::factory()->create(['name' => 'Enumeration Org DELTA']);
    $other = Organization::factory()->create(['name' => 'Enumeration Org ECHO']);

    $inviter = User::factory()->recycle($organization)->orgRole(OrgRole::Owner)
        ->create(['email' => 'enum-inviter@example.test']);
    $accepter = User::factory()->recycle($other)->orgRole(OrgRole::Admin)
        ->create(['email' => 'enum-accepter@example.test']);

    $tokens = [
        'unknown' => OpaqueToken::mint(),
        'expired' => OpaqueToken::mint(),
        'accepted' => OpaqueToken::mint(),
        'revoked' => OpaqueToken::mint(),
        'suspended organization' => OpaqueToken::mint(),
    ];

    OrganizationInvitation::factory()->recycle($organization)->invitedBy($inviter)
        ->token($tokens['expired'])->expired()->create(['email' => 'enum-expired@example.test']);

    OrganizationInvitation::factory()->recycle($organization)->invitedBy($inviter)
        ->token($tokens['accepted'])->accepted($accepter)->create(['email' => 'enum-accepted@example.test']);

    OrganizationInvitation::factory()->recycle($organization)->invitedBy($inviter)
        ->token($tokens['revoked'])->revoked()->create(['email' => 'enum-revoked@example.test']);

    // A live, pending invitation whose ORGANIZATION is suspended. RegistrationService::resolve()
    // refuses it, and it must be refused with the same body as a token that never existed —
    // otherwise the token holder learns the organization's lifecycle state.
    $suspended = Organization::factory()->suspended()->create(['name' => 'Enumeration Org FOXTROT']);
    $suspendedInviter = User::factory()->recycle($suspended)->orgRole(OrgRole::Owner)->create();

    OrganizationInvitation::factory()->recycle($suspended)->invitedBy($suspendedInviter)
        ->token($tokens['suspended organization'])->create(['email' => 'enum-suspended@example.test']);

    return ['organization' => $organization, 'other' => $other, 'inviter' => $inviter, 'tokens' => $tokens];
}

it('cannot tell four dead invitation states apart on preview, nor from a token that never existed', function (): void {
    $fixture = enumInvitationStates();

    // POSITIVE CONTROL FIRST: a pending invitation really does preview, so the four 404s below are
    // not passing because the endpoint is broken for everything.
    $live = OpaqueToken::mint();
    OrganizationInvitation::factory()->recycle($fixture['organization'])->invitedBy($fixture['inviter'])
        ->token($live)->role(OrgRole::Analyst)->create(['email' => 'enum-live@example.test']);

    enumProbe('/api/v1/auth/invitations/preview', ['token' => $live])
        ->assertOk()
        ->assertJsonPath('data.organization_name', 'Enumeration Org DELTA')
        ->assertJsonPath('data.email', 'enum-live@example.test')
        ->assertJsonPath('data.role', OrgRole::Analyst->value);

    $responses = [];

    foreach ($fixture['tokens'] as $state => $token) {
        $responses[$state] = enumProbe('/api/v1/auth/invitations/preview', ['token' => $token]);
    }

    enumIsOneResponse($responses, enumForbiddenFragments([
        'enum-expired@example.test',
        'enum-accepted@example.test',
        'enum-revoked@example.test',
        'enum-suspended@example.test',
        'enum-accepter@example.test',
        'Enumeration Org DELTA',
        'Enumeration Org FOXTROT',
    ]));

    // And the shared body is the surface's 404, not a bespoke one.
    expect($responses['unknown']->status())->toBe(404);
    expect($responses['unknown']->json('error_class'))->toBe('authorization');
});

// -------------------------------------------------------------------------------------------
// POST /api/v1/auth/register
// -------------------------------------------------------------------------------------------

it('cannot tell five dead invitation states apart on register', function (): void {
    $fixture = enumInvitationStates();

    $responses = [];

    foreach ($fixture['tokens'] as $state => $token) {
        $responses[$state] = enumProbe('/api/v1/auth/register', [
            'token' => $token,
            'name' => 'Probe Registrant',
            'password' => 'Enumeration-Probe-1',
            'password_confirmation' => 'Enumeration-Probe-1',
        ]);
    }

    // FIVE, NOT FOUR: the suspended-organization case is a LIVE, PENDING, UNEXPIRED invitation whose
    // organization cannot grow, and it is the one a reader is most likely to forget — its body comes
    // from a different branch of RegistrationService::resolve() than the other four.
    //
    // Note what is NOT in this list: an email mismatch. RegisterRequest deliberately has no `email`
    // field (D3) — the token is the sole authority for the address — so the mismatch case does not
    // exist on this endpoint. It exists on /auth/invitations/accept and is asserted there.
    enumIsOneResponse($responses, enumForbiddenFragments([
        'enum-expired@example.test',
        'enum-accepted@example.test',
        'enum-revoked@example.test',
        'enum-suspended@example.test',
        'Enumeration Org DELTA',
        'Enumeration Org FOXTROT',
    ]));

    expect($responses['unknown']->status())->toBe(422);
    expect($responses['unknown']->json('errors.token'))->toBeArray();

    // The refusal is on `token` and NEVER on `email`: an error on `email` would say "this token is
    // real but was issued to somebody else".
    foreach ($responses as $state => $response) {
        expect($response->json('errors.email'))->toBeNull("[{$state}] answered on the email key");
    }

    // Nothing was created by any of the five.
    expect(User::query()->where('name', 'Probe Registrant')->count())->toBe(0);
});

// -------------------------------------------------------------------------------------------
// POST /api/v1/auth/invitations/accept
// -------------------------------------------------------------------------------------------

it('cannot tell an invalid token from someone else\'s invitation from an existing membership', function (): void {
    $organization = Organization::factory()->create(['name' => 'Enumeration Org DELTA']);
    $inviter = User::factory()->recycle($organization)->orgRole(OrgRole::Owner)->create();

    $home = Organization::factory()->create(['name' => 'Enumeration Org ECHO']);
    $actor = User::factory()->recycle($home)->orgRole(OrgRole::Analyst)
        ->create(['email' => SpaSession::uniqueEmail('enum-actor')]);

    SpaSession::establish(currentTest(), $actor);

    // Issued to SOMEBODY ELSE. hash_equals against the AUTHENTICATED user's address is the only
    // thing between this and a signed-in user joining an organization by intercepting a link.
    $foreign = OpaqueToken::mint();
    OrganizationInvitation::factory()->recycle($organization)->invitedBy($inviter)
        ->token($foreign)->create(['email' => 'enum-somebody-else@example.test']);

    // Issued to the actor, for an organization they are ALREADY in.
    $duplicate = OpaqueToken::mint();
    OrganizationInvitation::factory()->recycle($home)->invitedBy($actor)
        ->token($duplicate)->create(['email' => $actor->email]);

    $responses = [
        'token that never existed' => enumProbe('/api/v1/auth/invitations/accept', ['token' => OpaqueToken::mint()]),
        'invitation issued to another address' => enumProbe('/api/v1/auth/invitations/accept', ['token' => $foreign]),
        'already a member of that organization' => enumProbe('/api/v1/auth/invitations/accept', ['token' => $duplicate]),
    ];

    enumIsOneResponse($responses, enumForbiddenFragments([
        'enum-somebody-else@example.test',
        'Enumeration Org DELTA',
    ]));

    expect($responses['token that never existed']->status())->toBe(422);

    // The foreign invitation is still pending — a refused accept must not consume it.
    expect(
        OrganizationInvitation::query()->where('email', 'enum-somebody-else@example.test')
            ->whereNotNull('accepted_at')->count()
    )->toBe(0);

    // And no membership was created in the organization the actor was never invited to.
    expect($actor->membershipFor($organization->id))->toBeNull(
        'a signed-in user joined an organization whose invitation was issued to a different address',
    );
});
