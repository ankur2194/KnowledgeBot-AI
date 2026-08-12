<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\Exceptions\KbException;
use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingDesignation;
use App\Services\Embedding\EmbeddingReadiness;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The ONLY class in this application permitted to open a connection to `ai-api`.
 *
 * A controller, job or service that builds its own `Http::` call bypasses signing, deadline
 * propagation and the retry ban; an arch test pins the Http facade to this namespace and CI greps
 * for `services.ai.url` outside it.
 *
 * NO ->retry() ANYWHERE. Retry ownership belongs to the tier that owns the adapter, and attempts
 * multiply across tiers (kb-error-taxonomy, "Retry ownership").
 */
final class InternalAiClient
{
    public function __construct(private readonly InternalRequestSigner $signer) {}

    /**
     * Ask the data plane which of this organization's connections supplies the embedding
     * credential, and why not when the answer is none.
     *
     * WHY THIS IS A ROUND TRIP AND NOT A LOCAL COMPUTATION. The resolution rule is one function —
     * `embedding_readiness()` — and every caller asks it: the indexer that writes vectors, the
     * query path that embeds the question, and this screen. A second implementation would not be
     * an inconsistency, it would be a correctness bug: `EmbeddingSpace` derives the Qdrant
     * collection name from (provider, model, ...), so an index written under one model and queried
     * under another finds plausible neighbours that are simply wrong, with nothing raised and no
     * metric moved. Laravel also cannot compute the vendor axis at all — `PROVIDER_TASKS` is
     * repository-level data with a source per cell and lives on the other side of the seam.
     *
     * NO `provider_credential` ON THIS BODY, AND THAT IS DELIBERATE RATHER THAN AN OMISSION.
     * Selection answers WHICH connection, never WITH WHAT KEY; the data-plane model refuses at
     * import any field whose name looks like a secret. Sending the key here would put a plaintext
     * credential on a request whose whole purpose is to render an admin screen, and the screen
     * persists nothing.
     *
     * @param  list<EmbeddingCandidate>  $candidates
     */
    public function embeddingReadiness(
        string $organizationId,
        array $candidates,
        ?EmbeddingDesignation $designation,
        ?string $actorId = null,
    ): EmbeddingReadiness {
        // Serialize ONCE and sign those exact bytes. Re-encoding JSON to hash it is not
        // byte-stable and produces intermittent 401s.
        $body = json_encode([
            'connections' => array_map(
                static fn (EmbeddingCandidate $c): array => $c->toArray(),
                $candidates,
            ),
            'designated' => $designation?->toArray(),
        ], JSON_THROW_ON_ERROR);

        $path = '/internal/'.config('kb.contract_version').'/embedding/readiness';

        // Build the X-KB-* set ONCE and derive both the signature and the request from it. Never
        // hand-write the canonical string from a second literal list: the signed set and the sent
        // set drift the moment someone adds a header, and the failure is a 401 on a request that
        // looks correct in the log.
        $headers = [
            // Never defaulted, never read from a body. It is the tenant scope for the entire data
            // plane, which is why it is inside the signature.
            'X-KB-Org-Id' => $organizationId,
            // No X-KB-Bot-Id: a source belongs to the organization and is not bot-assigned when it
            // is embedded. Absent on `provider.test` for the same reason.
            'X-KB-Actor-Type' => $actorId === null ? 'system' : 'user',
            'X-KB-Operation' => 'embedding.readiness',
            'X-KB-Request-Id' => (string) Str::ulid(),
            // The snapshot in the BODY, named. NOT config('kb.contract_version') — see
            // snapshotVersion() for why the two are different axes and why a constant is wrong.
            'X-KB-Config-Version' => (string) $this->snapshotVersion($body),
            'X-KB-Contract-Version' => (string) config('kb.contract_version'),
            'X-KB-Deadline' => (string) $this->deadlineMs(),
            'X-KB-Timestamp' => (string) time(),
        ];

        if ($actorId !== null) {
            $headers['X-KB-Actor-Id'] = $actorId;
        }

        // NO X-KB-Idempotency-Key. This is a read: it creates nothing, stores nothing, and bills
        // nothing, so a replay record would be a Valkey key per admin page view with no operation
        // to deduplicate. The header is required on MUTATIONS.

        $signature = $this->signer->sign('POST', $path, $body, $headers);

        try {
            $response = Http::baseUrl((string) config('services.ai.url'))
                ->withBody($body, 'application/json')
                ->withHeaders($headers + [
                    'Accept' => 'application/json',
                    'X-KB-Signature' => $signature,   // redacted from every log line
                ])
                ->connectTimeout((int) config('kb.timeouts.connect'))
                ->timeout((int) config('kb.timeouts.readiness'))
                ->post($path);
        } catch (ConnectionException) {
            // The exception is deliberately NOT chained. A connection exception's message carries
            // the resolved internal host and port — topology a tenant must never be told, and
            // `previous` is rendered by several log formatters and by debug-mode responses.
            throw KbException::aiServiceUnavailable(
                'The AI service could not be reached to resolve this organization\'s embedding '
                .'configuration.',
            );
        }

        if ($response->failed()) {
            throw $this->relay($response->status(), $response->json());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw KbException::aiServiceUnavailable(
                'The AI service returned an unreadable embedding-readiness response.',
            );
        }

        return EmbeddingReadiness::fromResponse($payload);
    }

    /**
     * Relay the class the data plane assigned, verbatim, and never re-derive one from the status.
     */
    private function relay(int $status, mixed $payload): KbException
    {
        $errorClass = is_array($payload) ? ($payload['error_class'] ?? null) : null;
        $message = is_array($payload) ? ($payload['message'] ?? null) : null;

        if (! is_string($errorClass) || $errorClass === '') {
            // No envelope means the failure did not come from our own handler — a proxy page, a
            // truncated body, a 502 from something in between. That is a dependency being
            // unavailable, and it is the ONE case where deriving from the status is correct,
            // because there is no assigned class to relay.
            return KbException::aiServiceUnavailable(
                'The AI service returned an unclassified error while resolving this '
                ."organization's embedding configuration (HTTP {$status}).",
            );
        }

        return KbException::relayed(
            $errorClass,
            is_string($message) && $message !== ''
                ? $message
                : 'The AI service rejected the embedding-readiness request.',
            $status,
        );
    }

    /**
     * The version of the configuration snapshot THIS request carries — X-KB-Config-Version.
     *
     * WHY IT IS NOT `config('kb.contract_version')`, WHICH IS ALREADY IN SCOPE TWO LINES UP. Those
     * are two different axes and the resemblance is a trap. `contract_version` versions the SEAM:
     * it mirrors the /internal/v1 path prefix, changes on a deploy, and is identical for every
     * organization. `config_version` versions the DATA in the body — which connections this
     * organization has, and which pair it designated. A caller that sent the contract version here
     * would send `v1`, which is not a decimal and is refused at the boundary anyway; a caller that
     * sent any other CONSTANT would be worse, because the value would then be identical for every
     * organization and every configuration, and docs/22 (ADR-011, property 1) makes this value part
     * of cache and replay identity. A cache-key component that never distinguishes anything is not
     * a harmless placeholder.
     *
     * SO IT COMES FROM THE SNAPSHOT ITSELF. `laravel-control-plane` writes the chat call as
     * `(string) $snap->version` and says, of the credential, that putting it inside the snapshot
     * "would be hashed into $snap->version, so every rotation would move configuration_version" —
     * i.e. the version IS a hash of the snapshot with the credential excluded. There is no
     * ConfigSnapshot object on this path: for `embedding.readiness` the snapshot is `$body`, and
     * `$body` carries no credential at all (see the docblock above — the data-plane model refuses
     * any field whose name looks like a secret), so "excluding the credential" holds by
     * construction rather than by discipline. Rotating a key cannot move this number because the
     * key is not one of the bytes being hashed.
     *
     * The bytes are stable by construction, which is what makes this worth hashing at all:
     * EloquentEmbeddingCandidateRepository orders by (connection_id, model) precisely so "the
     * REQUEST BODY [is] stable too", and it is the same single serialization the signature covers.
     *
     * 56 BITS, NOT 64 AND NOT 256. `app/api/deps.py` accepts `\A(?:0|[1-9][0-9]{0,17})\Z` and
     * parses with `int()`, so the decimal must be at most 18 digits; 7 bytes of SHA-256 is at most
     * 17 and always fits a 64-bit signed PHP int, so `(string)` of this value matches that regex
     * for every possible body. Truncation is not a security property here — nothing authenticates
     * on this number; the signature over the whole body does that.
     *
     * A CONTRADICTION THIS DOES NOT RESOLVE, recorded rather than papered over:
     * `kb-internal-api-contracts` calls the header a "Monotonic integer", while
     * `laravel-control-plane` and docs/22 describe a hash of the snapshot. A hash satisfies the
     * second and cannot satisfy the first. Nothing on either plane reads the value today (it is
     * parsed, range-checked and stored by `request_context`, and compared by nothing), so the
     * disagreement is inert until something does — at which point it is a contract decision, not
     * an edit here.
     */
    private function snapshotVersion(string $snapshot): int
    {
        return (int) hexdec(substr(hash('sha256', $snapshot), 0, 14));
    }

    /**
     * ABSOLUTE epoch milliseconds, computed from this request's own remaining budget — never a
     * duration, and never re-derived downstream.
     */
    private function deadlineMs(): int
    {
        $startedAt = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);

        return (int) round(($startedAt + (float) config('kb.timeouts.readiness')) * 1000);
    }
}
