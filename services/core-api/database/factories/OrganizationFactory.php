<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * The root of every tenant-owned graph. tenantPair() creates TWO of these and nothing in the
 * isolation suite works until it can.
 *
 * THE ONE RULE THAT IS NOT OBVIOUS: the two organizations must be DISTINGUISHABLE in every column a
 * test or an assertion can read. A factory that produces `name: 'Test'` for both makes a
 * cross-tenant leak invisible — the response body contains Org B's row, the assertion compares it
 * against Org A's name, and they are the same string. faker's unique company()/slug() is what makes
 * a leak show up as a value from the wrong org rather than as a value that happens to match.
 *
 * `slug` is unique across the WHOLE table, not per organization — an organization has no parent to
 * be unique within. Stated because the Valkey key rule (`{family}:{org_id}:…`) exists precisely
 * because most user-chosen strings are NOT globally unique, and a reader needs to know which case
 * this column is.
 *
 * STATES: none, deliberately. Resist adding a `withBot()` convenience — recycle($org) is what pins
 * the organization across a nested graph, and a state that creates a child for you is a state that
 * can create it for a THIRD organization.
 *
 * The embedding designation is NOT set here and there is no `->designating()` state. It is written
 * only through EmbeddingDesignationService, which resolves the pair against the data plane first;
 * a factory that could write it directly would let a test construct a designation the production
 * path would have refused, and every assertion built on that fixture would be about a state the
 * application cannot reach.
 *
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => OrganizationStatus::Active,
            'settings' => [],
        ];
    }

    public function suspended(): static
    {
        // A suspended organization still OWNS its rows. Used to assert check 5 (entity status),
        // which is one of the two checks reviewers forget.
        return $this->state(fn (): array => ['status' => OrganizationStatus::Suspended]);
    }
}
