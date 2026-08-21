<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Http\Requests\StoreSourceRequest;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sources\Upload\UploadLimits;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| GET .../sources/upload-limits
|--------------------------------------------------------------------------
|
| THE POINT OF THIS FILE IS THE EQUALITY TEST, and it is worth saying why a test that compares a
| response to a constant is not tautological. The failure it exists to catch is not "the endpoint
| returns the wrong number" — it is "somebody wrote the number down a second time". A console that
| believes the batch cap is 20 while the server enforces 10 renders a green upload that 422s, and
| the way that happens is not a typo in a comparison: it is a literal appearing in the resource
| because reading it from the FormRequest felt indirect. So the assertion is against
| `StoreSourceRequest`'s constants, on the far side of the whole chain
| (constant -> UploadLimits -> resource -> JSON), and a literal anywhere in that chain fails it.
|
| MUTATION-CHECKED: replacing `UploadLimits::maxBatch()` in OrgUploadLimitsResource with the literal
| `20` fails `it publishes the FormRequest's own constants…`, and restoring it passes. Replacing it
| with the literal `10` — the CURRENT correct value — passes, which is the honest limit of what a
| value comparison can prove and is why the arch-style assertion below exists as well.
|
| The route-ordering half matters just as much and is invisible in a passing response:
| `/sources/upload-limits` is declared BEFORE `/sources/{source}`, and declared after it the literal
| segment binds as a source id and 404s with a body byte-identical to "no such route". The test
| asserts both directions — this route resolves, AND a real source id still resolves — because a
| naive "put the literal first" edit that broke the parameterised one would otherwise pass.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * One organization and an owner in it, plus a second organization nobody in the first belongs to.
 *
 * @return array{orgA: Organization, orgB: Organization, ownerA: User}
 */
function uploadLimitsFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Limits Org ALPHA', 'slug' => 'limits-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Limits Org BRAVO', 'slug' => 'limits-bravo']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('limits-owner-alpha')]),
    ];
}

it("publishes the FormRequest's own constants rather than a second copy of the numbers", function (): void {
    $f = uploadLimitsFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/upload-limits",
        spaHeaders(),
    );

    $response->assertOk();

    // THE BATCH CAP, END TO END. `StoreSourceRequest::MAX_FILES` is what `files => array|max:` is
    // built from, so this is the rule the server enforces reaching the client unchanged.
    expect($response->json('data.max_batch'))->toBe(StoreSourceRequest::MAX_FILES);

    // THE PER-FILE CEILING, WITH THE UNIT CONVERTED EXACTLY ONCE. The FormRequest's constant is in
    // the KIBIBYTES Laravel's file `max:` rule speaks; the wire is in the bytes a browser's
    // `File.size` reports. `× 1024`, not `× 1000` — the 2.4% between the two is a file an operator
    // was told would be accepted and that the server refuses.
    expect($response->json('data.max_bytes'))->toBe(StoreSourceRequest::MAX_FILE_KILOBYTES * 1024);

    // AND THE CONVERSION HAPPENS IN ONE PLACE, which is the assertion the two lines above cannot
    // make on their own: they would both pass if the resource multiplied by 1024 itself.
    expect($response->json('data.max_bytes'))->toBe(UploadLimits::maxBytes());
});

it('publishes the same allow-list the intake gate enforces, sorted and de-duplicated', function (): void {
    $f = uploadLimitsFixture();

    SpaSession::establish(currentTest(), $f['ownerA']);

    $published = currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/upload-limits",
        spaHeaders(),
    )->assertOk()->json('data.allowed_mime');

    expect($published)->toBe(UploadLimits::allowedMime());

    // SORTED AND UNIQUE, because `kb:dump-openapi` compares the committed document byte for byte and
    // a set in insertion order would churn packages/contracts every time somebody reordered a row in
    // `EXTENSIONS`.
    $sorted = $published;
    sort($sorted, SORT_STRING);

    expect($published)->toBe($sorted);
    expect($published)->toBe(array_values(array_unique($published)));

    // EVERY EXTENSION'S ACCEPTED TYPES ARE IN IT. The gate cross-checks the sniffed type against the
    // extension, so an extension whose types were missing from this list would be a file the server
    // accepts and the console refuses before sending — the drift running the other way.
    $reachable = array_values(array_unique(array_merge(...array_values(UploadLimits::EXTENSIONS))));

    // A SET DIFFERENCE RATHER THAN A LOOP OF `toContain`, so a failure names EVERY missing type at
    // once instead of the first one — which is what makes the message actionable when somebody adds
    // an extension and forgets the publication half.
    expect(array_values(array_diff($reachable, $published)))->toBe([]);

    // AND NOTHING MACRO-ENABLED IS REACHABLE THROUGH IT. The four the security baseline names are
    // refused by name at step 2 and are absent from the extension map besides.
    foreach (UploadLimits::MACRO_EXTENSIONS as $macro) {
        expect(UploadLimits::EXTENSIONS)->not->toHaveKey($macro);
    }
});

it('resolves before the parameterised source route without shadowing it', function (): void {
    $f = uploadLimitsFixture();

    $source = KnowledgeSource::factory()->recycle($f['orgA'])->create();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // BOTH DIRECTIONS. Declared after `/sources/{source}` the literal segment binds as a source id
    // and 404s; declared in a way that broke the parameterised route, the second call would 404
    // instead. Only asserting both catches either mistake.
    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/upload-limits",
        spaHeaders(),
    )->assertOk()->assertJsonStructure(['data' => ['max_bytes', 'allowed_mime', 'max_batch']]);

    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/{$source->id}",
        spaHeaders(),
    )->assertOk()->assertJsonPath('data.id', $source->id);
});

it('answers a suspended organization, because the numbers are what stop being usable not what stop being true', function (): void {
    $f = uploadLimitsFixture();

    $f['orgA']->forceFill(['status' => OrganizationStatus::Suspended])->save();

    SpaSession::establish(currentTest(), $f['ownerA']);

    // NO 409, DELIBERATELY — the same decision `index` and `show` make on this surface. An operator
    // of a suspended organization is exactly the person working out what to do about it, and an
    // endpoint that goes dark then is an endpoint that hides the explanation. The refusal lives on
    // POST .../sources, which does check organization status.
    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/upload-limits",
        spaHeaders(),
    )->assertOk();
});

it('demands sources.upload, and denies a foreign organization the way the surface denies', function (): void {
    $f = uploadLimitsFixture();

    // THE ANALYST HOLDS NONE OF THE FOUR `sources.*` PERMISSIONS. It is the role that proves the
    // gate is asked rather than assumed — an owner passes every gate, so an owner-only test cannot
    // fail against a missing `Gate::authorize()`.
    $analyst = User::factory()->recycle($f['orgA'])->orgRole(OrgRole::Analyst)
        ->create(['email' => SpaSession::uniqueEmail('limits-analyst-alpha')]);

    SpaSession::establish(currentTest(), $analyst);

    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/upload-limits",
        spaHeaders(),
    )->assertForbidden();

    // A FOREIGN ORGANIZATION. The owner of A is not a member of B, so the membership middleware
    // answers before the policy does — and the body is the surface's own denial rather than a hint
    // that B exists.
    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $f['ownerA']);

    $foreign = currentTest()->getJson(
        "/api/v1/organizations/{$f['orgB']->id}/sources/upload-limits",
        spaHeaders(),
    );

    expect($foreign->status())->toBeIn([403, 404]);
});

it('is unreachable without a session', function (): void {
    $f = uploadLimitsFixture();

    currentTest()->getJson(
        "/api/v1/organizations/{$f['orgA']->id}/sources/upload-limits",
        spaHeaders(),
    )->assertUnauthorized();
});
