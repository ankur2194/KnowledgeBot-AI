<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\Exceptions\KbException;
use App\Services\Chat\ChatContext;
use App\Services\Chat\ConfigSnapshot;
use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingDesignation;
use App\Services\Embedding\EmbeddingReadiness;
use App\Services\Sources\IngestionSubmission;
use App\Support\Crypto\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * The ONLY class in this application permitted to open a connection to `ai-api`.
 *
 * A controller, job or service that builds its own `Http::` call bypasses signing, deadline
 * propagation and the retry ban; an arch test pins the Http facade to this namespace, and a second
 * test fails on a literal `services.ai.url` or `ai-api` anywhere in `app/` outside it.
 *
 * THAT SECOND HALF USED TO READ "AND CI GREPS FOR `services.ai.url` OUTSIDE IT", AND THERE IS NO
 * CI: `.github/` was deleted on 2026-08-17 and nothing replaced it. It is now
 * tests/Arch/StringLevelDoctrineTest.php, which reads STRING LITERALS out of the token stream — so
 * it does not match the two mentions in this docblock, which the published grep did. The human
 * spelling is `rg 'ai-api|services\.ai\.url' services/core-api/app | grep -v Services/Internal`.
 *
 * NO ->retry() ANYWHERE. Retry ownership belongs to the tier that owns the adapter, and attempts
 * multiply across tiers (kb-error-taxonomy, "Retry ownership").
 */
final class InternalAiClient
{
    /** A relayed per-field message is bounded before it is put in our own response body. */
    private const MAX_RELAYED_MESSAGE_LENGTH = 512;

    /** A relayed field PATH is bounded for the same reason, and paths are short by construction. */
    private const MAX_RELAYED_FIELD_LENGTH = 128;

    public function __construct(
        private readonly InternalRequestSigner $signer,
        private readonly CredentialVault $vault,
    ) {}

    /**
     * Open the chat stream: `POST /internal/v1/chat/stream`, SSE back, relayed to the client.
     *
     * ═══ THE ONE PLACE `provider_credentials` IS CONSTRUCTED ════════════════════════════════
     *
     * ADR-011, restated as a property of THIS METHOD: the map is a TOP-LEVEL sibling of `config`,
     * never a member of it, and it is built from sealed rows decrypted into a local that dies with
     * this method. `rg -n 'provider_credentials' services/core-api/app` must show exactly one
     * construction site, and this is it.
     *
     * IT IS A MAP AND NOT ONE FIELD (finding F12, ruled 2026-08-12 as `docs/22` § G7). One turn can
     * need three keys — chat, embedding (ADR-031 explicitly permits a different connection), rerank —
     * plus one per fallback link. `ConfigSnapshot::connectionIdsInPlay()` decides which; this method
     * decrypts each at the point it enters the map and never builds an intermediate array of
     * plaintext keys, because a plain `array<string,string>` of secrets is one `var_export` in an
     * exception message away from printing all three.
     *
     * A CONNECTION WITH NO SEALED ROW IS A REFUSAL, NOT A FALLBACK TO THE CHAT KEY. Falling back
     * would send one tenant's key to a vendor they did not choose for that surface and would embed
     * the question in a vector space the corpus was not indexed in — neither of which raises
     * anywhere downstream. The far side's `credential_for()` fails closed for the same reason; this
     * fails closed one hop earlier, so the refusal names the surface rather than arriving as a
     * relayed 422 mid-stream.
     *
     * ═══ `requestEpoch()`, NOT `callEpoch()` — AND THIS IS THE OPPOSITE CHOICE FROM THE JOBS ══
     *
     * This method is reached from an HTTP request handler under PHP-FPM, where one request is one
     * process and `LARAVEL_START` genuinely is this request's start. The far side's remaining budget
     * then shrinks as ours does, which is the whole reason the header is an absolute instant.
     * `callEpoch()` here would silently EXTEND the deadline past the client's own 60 s, so Laravel
     * would abandon a turn the data plane was still happily billing for. Finding B1/Q2 is the
     * mirror-image mistake on the queued paths; the two names read differently enough that picking
     * the wrong one is a visible choice.
     *
     * ═══ NO `->retry()`, AND `read_timeout` IS NOT OPTIONAL ═════════════════════════════════
     *
     * Laravel never retries its chat call: the provider adapter owns provider retries, and attempts
     * multiply across tiers (3×3 is nine provider calls from one click). And with `'stream' => true`
     * Guzzle returns as soon as headers land, so `->timeout()` does not bound the BODY read at all —
     * `read_timeout` is documented to default to `default_socket_timeout` and is in fact never set
     * (guzzle#2783). Unset, a dead upstream holds this FPM child forever. It is set BELOW the
     * heartbeat interval doubled, so a healthy-but-quiet upstream produces a `null` from
     * `UpstreamStream::frames()` rather than a stall.
     *
     * ═══ `X-KB-Bot-Id` IS PRESENT HERE AND ABSENT ON EVERY OTHER CALL IN THIS CLASS ═════════
     *
     * Not an inconsistency: a knowledge source is ORGANIZATION-owned and is assigned to zero or many
     * bots (ADR-067), so ingestion and the two maintenance operations have no single bot to name. A
     * chat turn has exactly one, and the far side REQUIRES it — `chat_stream` raises `validation`
     * without it, because the mandatory Qdrant filter has a bot-access term and there would be no
     * value to put in it.
     *
     * @param  array<string, array{credential_ciphertext: string, data_key_ciphertext: string}>  $sealed
     *                                                                                                    connection_id => the sealed material, read org-scoped by the caller. Keys that are not
     *                                                                                                    in `connectionIdsInPlay()` are ignored; an id in play with no entry here is a refusal.
     *
     * @throws KbException `validation` when a surface has no credential; `internal_dependency` when
     *                     the data plane is unreachable or refuses before the first byte
     */
    public function openChatStream(
        ConfigSnapshot $snapshot,
        ChatContext $context,
        int $deadlineMs,
        #[SensitiveParameter] array $sealed,
    ): UpstreamStream {
        $credentials = [];

        foreach ($snapshot->connectionIdsInPlay() as $connectionId) {
            $row = $sealed[$connectionId] ?? null;

            if ($row === null) {
                // The MESSAGE NAMES NO CONNECTION ID AND NO VENDOR. This refusal is rendered to
                // whoever is chatting, which on the widget surface is a stranger on a customer's
                // marketing site; the id belongs in the operator's log line, not in the envelope.
                throw KbException::validation(
                    'This bot is configured against a provider connection whose credential is not '
                    .'available, so the turn cannot run. An administrator needs to re-enter the key '
                    .'for that connection.',
                );
            }

            // DECRYPTED ONE AT A TIME, STRAIGHT INTO THE MAP. Never into a named local and never
            // through a comprehension over every row: the resulting plain array of plaintexts has
            // no masking anywhere in it, and one `str()` of it in an exception or a debug line
            // prints every key the tenant owns.
            $credentials[$connectionId] = $this->vault->open(
                $row['credential_ciphertext'],
                $row['data_key_ciphertext'],
            );
        }

        // Serialize the CONFIG once to derive its version, then serialize the whole body once and
        // sign those exact bytes. Re-encoding JSON to hash it is not byte-stable and produces
        // intermittent 401s.
        $versioned = $snapshot->withVersion(
            $this->snapshotVersion(json_encode($snapshot->hashableArray(), JSON_THROW_ON_ERROR)),
        );

        $body = json_encode([
            // Present, checked against the verified headers on the far side, and then unused. Scope
            // comes from the signed headers; `assert_scope_agrees()` refuses a disagreement, which
            // catches a control plane that resolved a snapshot for one tenant and signed for another.
            'org_id' => $context->organizationId,
            'bot_id' => $context->botId,
            'conversation_id' => $context->conversationId,
            'message_id' => $context->messageId,
            'client_message_id' => $context->clientMessageId,
            'actor_type' => $context->actorType->value,
            // THE INTERNAL FIELD NAME IS `query` AND THE PUBLIC ONE IS `content`. Laravel maps one
            // onto the other here and nowhere else; a client that posts `query` to the public API
            // gets a 422, and `text` is the `token` frame's field.
            'query' => $context->query,
            // A LIST, and `array_values` is load-bearing: an associative array with gaps encodes as
            // a JSON OBJECT, and the far side types this `tuple[str, ...]` — so a history that lost
            // one turn to a filter would 422 the whole request rather than send a short window.
            'history' => array_values($context->history),
            'deadline_epoch_ms' => $deadlineMs,
            'config' => $versioned->toArray(),
            // TOP-LEVEL, BESIDE `config` AND NEVER INSIDE IT. See the docblock; ADR-011 property 1
            // is that rotating a key must not move `configuration_version`, and the version above
            // was derived from `hashableArray()`, which cannot reach this map.
            'provider_credentials' => $credentials,
        ], JSON_THROW_ON_ERROR);

        $path = '/internal/'.config('kb.contract_version').'/chat/stream';
        $timestamp = (string) time();

        // Build the X-KB-* set ONCE and derive both the signature and the request from it.
        $headers = [
            'X-KB-Org-Id' => $context->organizationId,
            // PRESENT HERE, ABSENT EVERYWHERE ELSE IN THIS CLASS — see the docblock.
            'X-KB-Bot-Id' => $context->botId,
            'X-KB-Actor-Type' => $context->actorType->value,
            'X-KB-Operation' => 'chat.execute',
            // Also the replay nonce, so it is per-REQUEST and never derived from anything stable.
            'X-KB-Request-Id' => $context->requestId,
            'X-KB-Config-Version' => (string) $versioned->version,
            'X-KB-Contract-Version' => (string) config('kb.contract_version'),
            // ABSOLUTE epoch milliseconds, computed by the CALLER from its own remaining budget and
            // passed in — this method never re-derives one, because a fresh duration downstream is
            // exactly what the header exists to prevent.
            'X-KB-Deadline' => (string) $deadlineMs,
            // A MUTATION: it bills tokens and writes a transcript. The key is derived from the
            // conversation and the client-minted message id, so a double submit collapses instead of
            // billing two generations.
            'X-KB-Idempotency-Key' => $context->idempotencyKey(),
            'X-KB-Timestamp' => $timestamp,
        ];

        if ($context->actorId !== null) {
            $headers['X-KB-Actor-Id'] = $context->actorId;
        }

        $signature = $this->signer->sign('POST', $path, $body, $headers);

        try {
            $response = Http::baseUrl((string) config('services.ai.url'))
                ->withBody($body, 'application/json')
                ->withHeaders($headers + [
                    'Accept' => 'text/event-stream',
                    'X-KB-Signature' => $signature,   // redacted from every log line
                ])
                ->connectTimeout((int) config('kb.timeouts.connect'))
                ->timeout((int) config('kb.timeouts.internal'))
                // `stream => true` routes this through Guzzle's StreamHandler and returns as soon as
                // headers land, which is what makes the relay a relay rather than a buffer.
                // `read_timeout` bounds the BODY read and has no working default — see the docblock.
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => (int) config('kb.timeouts.stream_read'),
                ])
                // NO ->retry(). Retry ownership is the provider adapter's.
                ->post($path);
        } catch (ConnectionException) {
            // NOT CHAINED: a connection exception's message carries the resolved internal host and
            // port — topology a tenant must never be told.
            throw KbException::aiServiceUnavailable(
                'The AI service could not be reached to answer this message.',
            );
        }

        if ($response->failed()) {
            // A pre-stream refusal — a 422 on the body, a 401 on the signature. It arrives as JSON
            // rather than as SSE, and the relay has not written a byte yet, so it can still be a
            // status. `->json()` reads the buffered error body; a streamed 4xx is small by
            // construction because the far side's handlers run before the generator starts.
            throw $this->relay($response->status(), $response->json(), 'answering this message');
        }

        // ── `detach()` AND NOT `resource()`, AND THE DIFFERENCE IS A LAYER ──────────────────
        //
        // `Response::resource()` wraps the PSR body in `GuzzleHttp\Psr7\StreamWrapper`, a userland
        // stream wrapper that implements no `stream_set_option` — so any attempt to set a timeout or
        // a blocking mode on it raises a warning, which Laravel converts to an exception and the
        // relay reports as a failure on a turn that was working. `detach()` returns the SOCKET the
        // handler is actually reading, so `feof()` means "the far side hung up" and `fclose()` means
        // "the far side is told" rather than "a wrapper was discarded".
        //
        // Detaching leaves the Guzzle response holding an empty body. Nothing reads it afterwards:
        // this method returns the stream and the response object goes out of scope.
        $resource = $response->toPsrResponse()->getBody()->detach();

        if (! is_resource($resource)) {
            throw KbException::aiServiceUnavailable(
                'The AI service accepted the message but returned no readable stream.',
            );
        }

        return new UpstreamStream($resource);
    }

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
            // AN HTTP CALLER MEASURES FROM REQUEST START. This method is reached from an admin
            // screen, so `LARAVEL_START` is this request's own beginning under PHP-FPM and the far
            // side's remaining time shrinks as ours does. A queued caller must NOT use this epoch —
            // see `requestEpoch()`.
            'X-KB-Deadline' => (string) $this->deadlineMs(
                (float) config('kb.timeouts.readiness'),
                self::requestEpoch(),
            ),
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
     * Hand one source to the ingestion pipeline: `POST /internal/v1/ingestion/jobs`, `202 {job_id}`.
     *
     * ── THE BODY IS ASSEMBLED SERVER-SIDE AND CARRIES NO REQUEST INPUT ────────────────────────
     *
     * `IngestionSubmission` is built from `knowledge_sources` and `source_items` rows this
     * organization owns, after the FormRequest, the policy and the state machine have passed. The
     * three fields that matter most are precisely the ones a client may never supply:
     * `storage_key` is a path, `mime` is the SNIFFED type rather than the caller's `Content-Type`,
     * and `content_hash` is what the published version becomes checkable against.
     *
     * NO CREDENTIAL ON THIS BODY. `app/ingestion/tasks.py` resolves and decrypts the embedding
     * credential at execution time through the provider layer's own accessor, and nothing under
     * `app/ingestion/` accepts a credential parameter. A key attached here would sit in a Celery
     * task argument, which is serialized to the broker and read by anything that instruments task
     * args.
     *
     * ── THE IDEMPOTENCY KEY IS DERIVED, NEVER MINTED ──────────────────────────────────────────
     *
     * `sha256(org_id | operation | fingerprint)`, with the fingerprint coming from the submission
     * itself. A random key would make every retry of this job a SECOND ingestion of the same
     * document — same bytes, same parse, same embedding spend, and two versions racing the pointer.
     * The fingerprint's own contents and the one component it cannot cover are stated in full on
     * `IngestionSubmission`.
     *
     * ── NO `->retry()`, AND NO X-KB-BOT-ID ────────────────────────────────────────────────────
     *
     * Retry ownership is the JOB's on this path — `kb-error-taxonomy` permits job SUBMISSION to
     * retry and permits nothing else on this side of the seam — so a `->retry()` here would
     * multiply attempts against a ladder `SubmitIngestionJob` already runs.
     *
     * `X-KB-Bot-Id` is absent, and the header table lists ingestion among the bot-scoped
     * operations. THE CONTRADICTION IS REPORTED RATHER THAN SPLIT: a knowledge source belongs to
     * the ORGANIZATION and is assigned to zero or many bots, so there is no single bot id to send
     * and inventing one would put a bot in a signature that scopes nothing. `embeddingReadiness()`
     * above omits it for the same reason and says so.
     *
     * @throws KbException
     */
    public function submitIngestion(
        string $organizationId,
        IngestionSubmission $submission,
        ?string $actorId = null,
    ): string {
        // Serialize ONCE and sign those exact bytes. Re-encoding JSON to hash it is not
        // byte-stable and produces intermittent 401s.
        $body = json_encode($submission->toArray(), JSON_THROW_ON_ERROR);

        $path = '/internal/'.config('kb.contract_version').'/ingestion/jobs';

        $headers = [
            'X-KB-Org-Id' => $organizationId,
            // `system` when the scheduler or a recrawl dispatcher submitted it; `user` when an
            // administrator pressed a button. It drives what the diagnostics contracts may return
            // on the far side, so it is never defaulted to the flattering value.
            'X-KB-Actor-Type' => $actorId === null ? 'system' : 'user',
            'X-KB-Operation' => 'ingestion.submit',
            'X-KB-Request-Id' => (string) Str::ulid(),
            'X-KB-Config-Version' => (string) $this->snapshotVersion($body),
            'X-KB-Contract-Version' => (string) config('kb.contract_version'),
            // A QUEUED CALLER MEASURES FROM NOW, AND THIS LINE IS THE WHOLE OF FINDING B1.
            // `SubmitIngestionJob` runs in a worker that is up for hours, so `LARAVEL_START` there
            // is the worker's BOOT — `boot + 20 s` is already in the past by the time a warm worker
            // picks up its second job, and the far side clamps remaining budget at zero and refuses
            // every submission before a byte is parsed.
            'X-KB-Deadline' => (string) $this->deadlineMs(
                (float) config('kb.timeouts.ingestion'),
                self::callEpoch(),
            ),
            // REQUIRED ON A MUTATION. Absent, the far side answers `validation` -> 422, which is
            // correct and is not a case worth reaching: this is a write, and a write whose retry
            // cannot be recognised as a replay is a duplicate job.
            'X-KB-Idempotency-Key' => hash(
                'sha256',
                $organizationId."\x1fingestion.submit\x1f".$submission->fingerprint(),
            ),
            'X-KB-Timestamp' => (string) time(),
        ];

        if ($actorId !== null) {
            $headers['X-KB-Actor-Id'] = $actorId;
        }

        $signature = $this->signer->sign('POST', $path, $body, $headers);

        try {
            $response = Http::baseUrl((string) config('services.ai.url'))
                ->withBody($body, 'application/json')
                ->withHeaders($headers + [
                    'Accept' => 'application/json',
                    'X-KB-Signature' => $signature,   // redacted from every log line
                ])
                ->connectTimeout((int) config('kb.timeouts.connect'))
                ->timeout((int) config('kb.timeouts.ingestion'))
                ->post($path);
        } catch (ConnectionException) {
            // NOT CHAINED. A connection exception's message carries the resolved internal host and
            // port — topology a tenant must never be told, and `previous` is rendered by several
            // log formatters and by debug-mode responses.
            throw KbException::aiServiceUnavailable(
                'The AI service could not be reached to submit this source for processing.',
            );
        }

        if ($response->failed()) {
            throw $this->relay($response->status(), $response->json(), 'submitting this source version for ingestion');
        }

        $payload = $response->json();
        $jobId = is_array($payload) ? ($payload['job_id'] ?? null) : null;

        if (! is_string($jobId) || $jobId === '') {
            // A 202 with no job id is a contract violation, not a dependency outage — but it is
            // reported as `internal_dependency` for the same reason an unclassified error body is:
            // there is no assigned class to relay, and the taxonomy has no row for "the far side
            // answered in a shape we cannot read" other than this one.
            throw KbException::aiServiceUnavailable(
                'The AI service accepted the ingestion submission without returning a job id.',
            );
        }

        return $jobId;
    }

    /**
     * Carry a source's disable or enable into the retrieval index, and read back the proof.
     *
     * ── WHAT THE DATA PLANE ACTUALLY DOES WITH THIS ───────────────────────────────────────────
     *
     * One filtered `set_payload` per collection, rewriting `source_status` on every point of this
     * source, followed by a filtered count of the points now carrying the new value. That count is
     * the RESPONSE, and it is the reason this call is synchronous while `submitIngestion()` is a
     * 202: `set_payload` returns the same acknowledgement whether it rewrote ten thousand points
     * or none, so an accepted-and-report-later shape would hand this caller the one thing that
     * proves nothing (`kb-deletion-and-verification`).
     *
     * ── THE IDENTITY LIST IS OURS TO RESOLVE AND MUST NOT BE ABBREVIATED ─────────────────────
     *
     * `$embeddingIdentities` is every DISTINCT `source_versions.embedding_model_version` of this
     * source, which is the set of Qdrant collections its points live in. We own that table, so we
     * resolve it; the data plane opens no database connection for this call and could not. Sending
     * only the active version's identity is the silent half-rewrite: the collection that was
     * addressed verifies, and the one that was not keeps answering.
     *
     * ── NO RETRY HERE. `SyncSourceStatusJob` OWNS IT ────────────────────────────────────────
     *
     * Same rule as `submitIngestion()`: one retrying tier per hop, and for a queued call it is the
     * job. A `->retry()` here would multiply with `#[Tries]` and turn one admin click into fifteen
     * rewrites of the same payload.
     *
     * @param  list<string>  $embeddingIdentities
     * @return array{passed: bool, rewritten: int, verified: int}
     *
     * @throws KbException when the far side refuses, is unreachable, or does not verify
     */
    public function syncSourceStatus(
        string $organizationId,
        string $sourceId,
        string $sourceStatus,
        array $embeddingIdentities,
        ?string $actorId = null,
    ): array {
        return $this->syncPayload(
            $organizationId,
            'source-status',
            'source.status.sync',
            [
                'source_id' => $sourceId,
                'source_status' => $sourceStatus,
                'embedding_model_versions' => array_values($embeddingIdentities),
            ],
            $sourceId."\x1f".$sourceStatus,
            $actorId,
        );
    }

    /**
     * Add or remove one bot id in the `bot_ids` LIST on the points in scope, and read back the proof.
     *
     * ── `bot_ids` IS A LIST ON EACH POINT AND THAT IS THE WHOLE HAZARD ───────────────────────
     *
     * Every bot the source is assigned to is in it, so the far side does a scroll and a
     * read-modify-write rather than a delete-by-filter. A delete-by-filter on `bot_ids` destroys
     * the chunks the OTHER bots still answer from, at HTTP 200, and `BotService::delete()` names
     * that trap at its call site. Nothing on this side can prevent it; what this side can do is
     * never ask for a delete, which is why there is no shape of this request that expresses one.
     *
     * ── `$sourceIds === null` MEANS EVERY POINT IN THE ORGANIZATION, AND ONLY FOR A REVOKE ───
     *
     * It is the deleted-bot case: the `bot_source_assignments` rows are gone, so we cannot
     * enumerate what the bot could see. The far side refuses the same shape for a grant with a 422
     * — granting a bot access to every source a tenant owns is not an operation the contract
     * offers — and this method refuses it here as well, so the mistake is caught before a signed
     * request is built rather than as a relayed validation error.
     *
     * @param  list<string>|null  $sourceIds
     * @param  list<string>  $embeddingIdentities
     * @return array{passed: bool, rewritten: int, verified: int}
     *
     * @throws KbException
     */
    public function syncBotAccess(
        string $organizationId,
        string $botId,
        bool $grant,
        ?array $sourceIds,
        array $embeddingIdentities,
        ?string $actorId = null,
    ): array {
        if ($grant && $sourceIds === null) {
            throw KbException::aiServiceUnavailable(
                'An organization-wide grant is not an operation this contract offers.',
            );
        }

        return $this->syncPayload(
            $organizationId,
            'bot-access',
            'bot.access.sync',
            [
                'bot_id' => $botId,
                'grant' => $grant,
                'source_ids' => $sourceIds === null ? null : array_values($sourceIds),
                'embedding_model_versions' => array_values($embeddingIdentities),
            ],
            $botId."\x1f".($grant ? 'grant' : 'revoke')."\x1f"
                .($sourceIds === null ? 'all' : implode(',', $sourceIds)),
            $actorId,
        );
    }

    /**
     * The shared half of the two maintenance operations: sign, send, verify the shape, return.
     *
     * ONE METHOD BECAUSE THE TWO REQUESTS DIFFER ONLY IN THEIR PATH AND THEIR BODY. Everything
     * that is easy to get subtly wrong — serializing once and signing those exact bytes, the
     * queued-caller epoch, the required idempotency key, the connection-exception blackout — is
     * identical, and two copies of it would drift on the half that is exercised less.
     *
     * `X-KB-Idempotency-Key` IS REQUIRED AND IS DERIVED FROM THE INSTRUCTION, not from a random
     * value: two deliveries of the same instruction must carry the same key. The far side stores
     * no replay record for it (both operations are convergent — it selects the points still
     * NEEDING the change), so the key is a contract obligation and a correlation handle rather
     * than a deduplication mechanism, and its `_idempotency_key` dependency says why at length.
     *
     * `X-KB-Actor-Type` is `system` for a queued sweep and `user` when an administrator pressed a
     * button, exactly as on `submitIngestion()` — never defaulted to the flattering value.
     *
     * @param  array<string, mixed>  $body
     * @return array{passed: bool, rewritten: int, verified: int}
     *
     * @throws KbException
     */
    private function syncPayload(
        string $organizationId,
        string $route,
        string $operation,
        array $body,
        string $instruction,
        ?string $actorId,
    ): array {
        // Serialize ONCE and sign those exact bytes. Re-encoding to hash is not byte-stable.
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $path = '/internal/'.config('kb.contract_version').'/maintenance/'.$route;

        $headers = [
            'X-KB-Org-Id' => $organizationId,
            'X-KB-Actor-Type' => $actorId === null ? 'system' : 'user',
            'X-KB-Operation' => $operation,
            'X-KB-Request-Id' => (string) Str::ulid(),
            'X-KB-Config-Version' => (string) $this->snapshotVersion($payload),
            'X-KB-Contract-Version' => (string) config('kb.contract_version'),
            // `callEpoch()` AND NOT `LARAVEL_START` — finding B1/Q2. Both callers are queued jobs
            // in a worker that is up for hours, so the constant is that worker's BOOT and the
            // deadline it produces is already in the past.
            'X-KB-Deadline' => (string) $this->deadlineMs(
                (float) config('kb.timeouts.maintenance'),
                self::callEpoch(),
            ),
            'X-KB-Idempotency-Key' => hash(
                'sha256',
                $organizationId."\x1f".$operation."\x1f".$instruction,
            ),
            'X-KB-Timestamp' => (string) time(),
        ];

        if ($actorId !== null) {
            $headers['X-KB-Actor-Id'] = $actorId;
        }

        $signature = $this->signer->sign('POST', $path, $payload, $headers);

        try {
            $response = Http::baseUrl((string) config('services.ai.url'))
                ->withBody($payload, 'application/json')
                ->withHeaders($headers + [
                    'Accept' => 'application/json',
                    'X-KB-Signature' => $signature,   // redacted from every log line
                ])
                ->connectTimeout((int) config('kb.timeouts.connect'))
                ->timeout((int) config('kb.timeouts.maintenance'))
                ->post($path);
        } catch (ConnectionException) {
            // NOT CHAINED: the message carries the resolved internal host and port.
            throw KbException::aiServiceUnavailable(
                'The AI service could not be reached to update the retrieval index.',
            );
        }

        if ($response->failed()) {
            throw $this->relay($response->status(), $response->json(), 'synchronizing this source\'s retrieval scope');
        }

        $report = $response->json();

        // A 200 WHOSE BODY CANNOT BE READ IS NOT A SUCCESS. The counts ARE the proof, so a
        // response without them is indistinguishable from a rewrite that did not happen — and
        // treating it as a pass is exactly the acknowledged-but-not-applied failure the far side
        // spends a filtered count to rule out.
        if (! is_array($report) || ! isset($report['passed'], $report['rewritten'], $report['verified'])) {
            throw KbException::aiServiceUnavailable(
                'The AI service answered the index update in a shape this service cannot read.',
            );
        }

        if ($report['passed'] !== true) {
            throw KbException::aiServiceUnavailable(
                'The AI service could not confirm that the retrieval index was updated.',
            );
        }

        return [
            'passed' => true,
            'rewritten' => (int) $report['rewritten'],
            'verified' => (int) $report['verified'],
        ];
    }

    /**
     * Relay the class the data plane assigned, verbatim, and never re-derive one from the status.
     *
     * FOUR FIELDS CROSS, NOT TWO. The envelope is
     * `{error_class, message, retryable, request_id, actionable}` plus an `errors` superset on
     * `validation`, and reading only the first two lost the others in ways nobody could see from
     * this side:
     *
     *   * `retryable` IS THE DATA PLANE'S ORIGIN VERDICT. `_handle_unexpected()` raises with
     *     `origin=Origin.SELF` and sends `retryable: false` — "our bug, do not retry". Dropping it
     *     let KbException::relayed() default to ORIGIN_DOWNSTREAM and bootstrap/app.php recompute
     *     `true`, so a defect reached the browser as a brownout and apps/web ran a full backoff
     *     ladder against a guaranteed failure. That is ADR-029 / finding O1 re-opened at the relay
     *     boundary. See KbException::relayed() for why the STATUS is still not consulted.
     *   * `errors` IS THE ONLY THING THAT MAKES A 422 RENDERABLE. main.py's
     *     `_handle_validation_error` builds a real `dict[str, list[str]]` from Pydantic's `loc`
     *     paths on EVERY validation envelope; dropping it turned a per-field refusal into an opaque
     *     sentence. It also broke an invariant a client tests structurally: apps/web discriminates
     *     the ADR-031 resolver refusal on `validation` WITH NO MAP, which is only sound while every
     *     other `validation` keeps its map.
     *   * `actionable` SAYS WHETHER THE RELAYED MESSAGE IS ADDRESSED TO A PERSON (finding J2). The
     *     data plane's `_handle_unexpected` sends the fixed 5xx placeholder with `false`; every
     *     other KbError sends a sentence with `true`. Recomputing it on this side would be ADR-052
     *     recurring on a fourth field — the generalizable form of which is that a decision about
     *     how two planes agree on a field survives only where every hop that copies the field
     *     preserves it, and this method is such a hop. It fails closed, below.
     */
    private function relay(int $status, mixed $payload, ?string $subject = null): KbException
    {
        // DEFAULTED RATHER THAN REQUIRED, so the three existing call sites keep the sentence they
        // shipped with byte for byte. It is a parameter at all because FOUR operations now share
        // this method and the two fallback sentences below name only one of them — an unclassified
        // failure on a chat turn told the operator to look at their embedding configuration, which
        // is a wrong lead rather than a vague one.
        $subject ??= "resolving this organization's embedding configuration";

        $errorClass = is_array($payload) ? ($payload['error_class'] ?? null) : null;
        $message = is_array($payload) ? ($payload['message'] ?? null) : null;
        $retryable = is_array($payload) ? ($payload['retryable'] ?? null) : null;
        $actionable = is_array($payload) ? ($payload['actionable'] ?? null) : null;

        if (! is_string($errorClass) || $errorClass === '') {
            // No envelope means the failure did not come from our own handler — a proxy page, a
            // truncated body, a 502 from something in between. That is a dependency being
            // unavailable, and it is the ONE case where deriving from the status is correct,
            // because there is no assigned class to relay.
            return KbException::aiServiceUnavailable(
                "The AI service returned an unclassified error while {$subject} (HTTP {$status}).",
            );
        }

        return KbException::relayed(
            $errorClass,
            is_string($message) && $message !== ''
                ? $message
                // DERIVED FROM $subject FOR THE SAME REASON THE SENTENCE ABOVE IS. This read
                // 'The AI service rejected the embedding-readiness request.' until 2026-08-27 —
                // a second hard-coded noun, three call sites away from being right, and missed
                // when the first one was fixed because fixing one instance of a claim reads as
                // fixing the claim (`docs/22` § Q16).
                : "The AI service rejected this request while {$subject}.",
            $status,
            // A NON-BOOLEAN IS `null`, NOT `false`. `null` means "the envelope did not say", which
            // relayed() maps to DOWNSTREAM — the same reading a missing field has always had. A
            // truthy cast would let `"false"`, `0` or an absent key silently assert SELF origin and
            // suppress a retry that was legitimate.
            is_bool($retryable) ? $retryable : null,
            $this->fieldErrors($errorClass, $payload),
            // FAILS CLOSED, and in the OPPOSITE direction to `retryable` above — deliberately, so
            // the asymmetry is not read as an oversight. There, a missing field has a defensible
            // default (`downstream`) that the taxonomy has always assumed. Here the two outcomes are
            // not symmetric: a wrong `false` costs an operator a blander sentence, while a wrong
            // `true` renders a downstream placeholder — or worse, whatever text an unclassified
            // failure carried — at that operator as though it were advice.
            $actionable === true,
        );
    }

    /**
     * The `validation` superset, normalized — or null on every other class and every other shape.
     *
     * FAIL CLOSED ON THE SHAPE, because `errors` is contractually `Record<string, string[]>` and a
     * client keys a form on it. FastAPI's handler already says why the alternative is worse: "A
     * list still satisfies `typeof value === 'object'`, so the envelope type-guard passes, the form
     * then keys on `0` and `1`, no field matches, and every message collapses into one
     * opaque root error." A malformed map is therefore dropped entirely rather than forwarded —
     * which renders as the deliberate-refusal shape (`validation`, no map), the one thing a client
     * already knows how to display.
     *
     * NEVER `{}`, NEVER `null` ON THE WIRE. The envelope contract is that `errors` is present only
     * on `validation` and only when it carries something, so an empty map returns null here and the
     * render closure omits the key.
     *
     * @return array<string, list<string>>|null
     */
    private function fieldErrors(string $errorClass, mixed $payload): ?array
    {
        if ($errorClass !== 'validation' || ! is_array($payload)) {
            return null;
        }

        $errors = $payload['errors'] ?? null;

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            // A field PATH is a string. Pydantic joins its `loc` segments dotted and falls back to
            // `_`, so an integer key here means the map was really a LIST and the whole envelope is
            // the shape the type-guard cannot distinguish. Refuse the lot.
            if (! is_string($field) || $field === '' || ! is_array($messages)) {
                return null;
            }

            $texts = [];

            foreach ($messages as $text) {
                if (! is_string($text)) {
                    return null;
                }

                // Bounded on the way in. The messages are the data plane's, not a tenant's, but a
                // relayed field name and a relayed sentence are both strings this service is about
                // to put in its own response body.
                $texts[] = mb_substr($text, 0, self::MAX_RELAYED_MESSAGE_LENGTH);
            }

            if ($texts === []) {
                return null;
            }

            $normalized[mb_substr($field, 0, self::MAX_RELAYED_FIELD_LENGTH)] = $texts;
        }

        // No emptiness check here: `$errors === []` returned null above, so reaching this
        // line means the loop ran at least once, and every iteration either returned null or
        // assigned. PHPStan proves the branch dead; keeping it would be a guard that reads as
        // defence and is actually unreachable.
        return $normalized;
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
     * ABSOLUTE epoch milliseconds — an instant, never a duration, and never re-derived downstream.
     *
     * ── BOTH ARGUMENTS ARE REQUIRED, AND THE EPOCH IS THE HALF THAT USED TO BE IMPLICIT ───────
     *
     * The rule is: A CALLER THAT SUPPLIES ITS OWN BUDGET SUPPLIES ITS OWN EPOCH. There is no
     * default for either, because the defaults are what hid the defect this signature exists to
     * make impossible — see `requestEpoch()` below for exactly what went wrong.
     *
     * What must never happen is a caller re-deriving a fresh duration downstream: the header is an
     * absolute instant, so the far side's remaining time shrinks as ours does rather than
     * restarting. That property is the reason for the whole header and it is unaffected by which
     * epoch is chosen — an epoch chosen wrongly does not restart the budget, it EXHAUSTS it.
     */
    private function deadlineMs(float $budgetSeconds, float $startedAt): int
    {
        return (int) round(($startedAt + $budgetSeconds) * 1000);
    }

    /**
     * The instant the CURRENT HTTP REQUEST began. Correct ONLY in a request-scoped process.
     *
     * ── WHY THIS IS A NAMED METHOD AND NOT A LINE INSIDE `deadlineMs()` ───────────────────────
     *
     * `LARAVEL_START` IS PROCESS-SCOPED, NOT REQUEST-SCOPED, AND THE TWO COINCIDE ONLY UNDER
     * PHP-FPM. It is defined in exactly two places — `public/index.php` and `artisan` — and under
     * FPM one request is one process, so it is the request's start and the arithmetic is right.
     * In a long-lived process it is the moment that process BOOTED. A `queue:work` or Horizon
     * worker is such a process, so a job that measured from here sent `boot + budget`: a deadline
     * ALREADY IN THE PAST for any worker up longer than its budget, and further into the past for
     * the rest of the process's life. The far side clamps remaining budget at zero and refuses,
     * which means every submission from a warm worker would be refused before a byte was read,
     * with this service correct in every log.
     *
     * That is not hypothetical and it is not a near miss: it shipped, and the docblock here named
     * the hazard and then mis-resolved it — it claimed the job "sets its own budget through this
     * argument", which is true and irrelevant, because the budget is not the epoch. The fix is the
     * signature: the epoch is chosen at the call site, by name, and the two names read differently
     * enough that picking the wrong one is a visible choice rather than an omission.
     *
     * THE FALLBACK IS A SAFETY NET FOR A REQUEST-SCOPED PROCESS THAT SOMEHOW LACKS THE CONSTANT
     * (a differently-bootstrapped SAPI), and it is NOT the queued path's answer — a worker HAS the
     * constant, so the fallback never fires there. A queued caller must call `callEpoch()`.
     */
    private static function requestEpoch(): float
    {
        return defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
    }

    /**
     * Now. The correct epoch in ANY process, and the only correct one in a long-lived worker.
     *
     * A queued job has no request, so there is no earlier instant its budget could honestly be
     * measured from: the work begins when the job runs. Reading `LARAVEL_START` here would measure
     * from the worker's boot — see `requestEpoch()`.
     */
    private static function callEpoch(): float
    {
        return microtime(true);
    }
}
