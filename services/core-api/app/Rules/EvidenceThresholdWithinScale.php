<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\EvidenceThresholdScale;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An evidence threshold must lie inside the range its own SCALE defines.
 *
 * ── THE ONE HALF OF THE PORTABILITY PROBLEM A RANGE CHECK CAN CATCH ───────────────────────────
 *
 * `EvidenceThresholdScale` records the whole of it: `0.30` was a property of `bge-reranker-v2-m3`
 * under `normalize=True`, ADR-030 replaced that one local model with a per-organization provider,
 * and the providers disagree — NVIDIA's ranking endpoint returns an unbounded signed logit while
 * Cohere- and Voyage-shaped responses return a bounded relevance score. Applying `0.30` to a logit
 * passes almost everything; applying a logit threshold to a bounded score refuses almost
 * everything. NEITHER RAISES, which is what makes the mistake nearly undetectable: only the refusal
 * rate moves, only in aggregate, and since ADR-030 only for one tenant.
 *
 * `1.7` is the part that IS catchable — refused on a bounded scale, accepted on a logit, correctly
 * in both directions. `bots_evidence_threshold_range` is the same rule in the database, and it is
 * there rather than only here because a repair script and a seeder do not run FormRequests. This
 * rule exists so the HTTP caller gets a 422 keyed on the field they typed into rather than a 23514
 * rendered as a 500 — a bug report about the server for what is plainly a bad request.
 *
 * ── WHY IT IS A DataAwareRule AND NOT A `required_if` / `between` PAIR ────────────────────────
 *
 * The bound depends on the VALUE of a sibling field, and Laravel's declarative rules can express
 * "required when a sibling equals X" but not "between 0 and 1 when a sibling equals X". Written as
 * an `after()` closure it would be invisible to `kb:dump-form-rules` — the manifest in
 * packages/contracts/rules/ is dumped from executing `rules()` and nothing else, so a constraint
 * expressed in a hook is a constraint the generated client is never told about (docs/22 finding
 * 19). A rule object is recorded by class name, which is a name both a generator and
 * `form-drift.test.ts` can key on.
 *
 * ── IT DOES NOT VALIDATE THE PAIRING, AND THAT IS A DIFFERENT RULE ────────────────────────────
 *
 * "A number with no scale, or a scale with no number" is `bots_evidence_threshold_paired` in the
 * database and a mutual `required_with` in the FormRequest — declarative, dumpable, and already
 * correct. This rule assumes the pair and checks only the range, so a request that failed the
 * pairing does not also collect a confusing range error about a scale it never sent.
 */
final class EvidenceThresholdWithinScale implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = $this->data['evidence_threshold_scale'] ?? null;

        // NO SCALE IN THE PAYLOAD IS NOT THIS RULE'S FAILURE. The mutual `required_with` pair has
        // already refused that body, and adding a second error here would report a range violation
        // against a scale the caller never named.
        $scale = is_string($raw) ? EvidenceThresholdScale::tryFrom($raw) : null;

        if ($scale === null || ! $scale->isBounded()) {
            return;
        }

        if (! is_numeric($value)) {
            // `numeric` in the rule list has already produced its own error; bailing here keeps the
            // response to one message per field.
            return;
        }

        $threshold = (float) $value;

        if ($threshold < 0.0 || $threshold > 1.0) {
            $fail(
                'The :attribute must be between 0 and 1 on the `'.$scale->value.'` scale, which is '
                .'bounded. A threshold is only meaningful in the units the reranker returns — an '
                .'unbounded logit and a bounded relevance score are different measurements wearing '
                .'the same float — and a value outside the scale\'s own range would refuse every '
                .'answer without raising anything anywhere. Use the `logit` scale for a provider '
                .'that returns unbounded signed scores.',
            );
        }
    }
}
