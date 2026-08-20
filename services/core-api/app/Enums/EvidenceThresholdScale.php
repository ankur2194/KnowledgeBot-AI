<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * WHAT A STORED EVIDENCE THRESHOLD IS MEASURED IN. A threshold without one is not a number, it is
 * three different instructions wearing the same float.
 *
 * ── WHY THIS COLUMN EXISTS AT ALL ─────────────────────────────────────────────────────────────
 *
 * `evidence.min_score = 0.30 on the sigmoid scale` was a property of `bge-reranker-v2-m3` under
 * `normalize=True`. ADR-030 replaced that one local model with a per-organization PROVIDER, and the
 * providers do not agree with it or with each other: NVIDIA's ranking endpoint returns an unbounded
 * signed logit (its own published example ranks 0.226, -1.17, -1.52), while Cohere- and
 * Voyage-shaped responses return a bounded relevance score. Applying 0.30 to a logit passes almost
 * everything; applying a logit threshold to a bounded score refuses almost everything.
 *
 * NEITHER RAISES. Only the refusal rate moves, and only in aggregate, and since ADR-030 it moves
 * for ONE TENANT and not the rest — which is what makes it nearly undetectable. So the scale
 * travels beside every stored threshold and is never assumed, exactly as it travels beside every
 * score on the data-plane side.
 *
 * ── THE VOCABULARY IS THE DATA PLANE'S, MINUS ONE MEMBER, ON PURPOSE ──────────────────────────
 *
 * `RerankScale` in services/ai-service/app/providers/contract.py reads
 * `LOGIT | SIGMOID | UNIT_INTERVAL | UNCALIBRATED`. The first three are here with the same string
 * values, so a threshold configured in the console and a score returned by an adapter are talking
 * about the same thing without a translation table.
 *
 * `UNCALIBRATED` IS DELIBERATELY ABSENT, AND ITS ABSENCE IS THE INTERESTING PART. That member is
 * not a scale — it is the statement that no characterization exists for this `(provider, model)`,
 * which is precisely the state in which `RerankCalibration` REFUSES construction rather than
 * defaulting. A row carrying `evidence_threshold = 0.30, scale = uncalibrated` would be a stored
 * contradiction: a number nobody may compare anything to. `bots_evidence_threshold_scale_check`
 * refuses it in the database for the same reason this enum has no case for it.
 *
 * That makes this a deliberate SUBSET rather than a copy, which is why it does not carry the
 * data-plane class's name: `EvidenceThresholdScale` is "the scales a stored threshold may live on",
 * and the answer is "every one that may be thresholded at all".
 *
 * ── `isBounded()` IS A SEPARATE QUESTION FROM "MAY BE THRESHOLDED" ────────────────────────────
 *
 * Both bounded members live in [0, 1] and `Logit` does not, so the RANGE a threshold must lie in is
 * decided here while WHETHER it may be thresholded was decided by membership of this enum. The data
 * plane records at length why those two must not collapse into one property. `Sigmoid` and
 * `UnitInterval` are listed separately even though both are 0–1, because the coincidence invites
 * treating a threshold as portable between them — only the BOUNDS transfer, and the bounds are not
 * the calibration.
 */
enum EvidenceThresholdScale: string
{
    /** Unbounded, signed, roughly ±10, centred near zero. NVIDIA's ranking models. */
    case Logit = 'logit';
    /** A logistic transform of a logit, bounded 0–1. What the retired local cross-encoder produced. */
    case Sigmoid = 'sigmoid';
    /** Bounded 0–1 but NOT a sigmoid of a logit: a vendor-defined relevance score. */
    case UnitInterval = 'unit_interval';

    /**
     * Whether a threshold on this scale must lie in [0, 1].
     *
     * `bots_evidence_threshold_range` is the same rule in the database, and it is there rather than
     * only here because a repair script and a seeder do not run FormRequests. It is the one part of
     * "0.30 is a valid float on every scale" a constraint can actually catch: 1.7 is refused on a
     * bounded scale and accepted on a logit, which is correct in both directions.
     */
    public function isBounded(): bool
    {
        return $this !== self::Logit;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
