<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use Illuminate\Database\QueryException;

/**
 * Keeps a vault column out of the log store when the statement carrying it fails.
 *
 * ── THE LEAK, EXACTLY ──────────────────────────────────────────────────────────────────────────
 *
 * `BinaryCast::set()` renders raw bytes as PostgreSQL's hex input format — `'\x'.bin2hex($value)`
 * — because PDO has no way to bind a parameter as `bytea`. That string becomes an ordinary PDO
 * BINDING, and `QueryException::formatMessage()` builds its message by interpolating every binding
 * into the SQL (`Str::replaceArray('?', $bindings, $sql)`, vendor QueryException:83). So the
 * exception's `getMessage()` contains the SEALED CREDENTIAL and the WRAPPED DATA KEY in full, hex
 * encoded, for any failure of the INSERT or UPDATE that writes them — a deadlock, a CHECK
 * violation, a mid-statement connection reset, a disk-full.
 *
 * Nothing then stopped it: Laravel's handler reports an unhandled throwable as
 * `$logger->error($e->getMessage(), ['exception' => $e])`, `KbJsonFormatter::renderException()`
 * puts the same message in the `exception` field, and `redact()` cannot help — every pattern in it
 * recognises a credential by its own SHAPE (`sk-…`, `Bearer …`, `KB1 …`), and AES-GCM ciphertext
 * rendered as hex has no shape. Two long-lived copies of the tenant's key material land in Loki,
 * which is a store nobody classifies as sensitive because nothing was supposed to put a secret in
 * it. The RESPONSE was never at risk — a QueryException is not an HttpExceptionInterface, so the
 * render closure's >=500 arm emits the fixed constant — which is exactly why this went unnoticed.
 *
 * ── WHY A REPORT-TIME SCRUB AND NOT A TRY/CATCH AT THE TWO WRITE SITES ─────────────────────────
 *
 * Wrapping `EloquentProviderConnectionRepository::create()` and `::rotateCredential()` would fix
 * the two writers that exist today and would silently fail to cover the third. A vault column is a
 * `bytea` cast on a model, and any future writer — a KEK-rewrap command, a backfill migration
 * running through Eloquent, a repository somebody adds for a second credential-bearing table —
 * reaches the same PDO path with none of the guard. The property that must hold is about the
 * COLUMN, not about a call site, so the check lives where every path converges: the single
 * reporting funnel in bootstrap/app.php.
 *
 * It is also the only placement that covers a rethrow. `AuditLogger` rethrows an ON_FAILURE_ABORT
 * write failure UNWRAPPED so the render closure can classify it, which means a failing audit INSERT
 * inside the connection transaction arrives at the handler as somebody else's QueryException.
 *
 * ── WHAT COUNTS AS A VAULT QUERY ───────────────────────────────────────────────────────────────
 *
 * Two independent tests, ORed, because each catches what the other cannot:
 *
 *   1. THE TABLE NAME in the SQL. Catches a statement whose vault binding is absent from THIS
 *      failure — a `SELECT ... FOR UPDATE` that deadlocks, an UPDATE that touches only `status` —
 *      where there is nothing key-shaped to recognise but the statement is still on the credential
 *      path. Cheap, and deliberately over-broad: over-scrubbing a `provider_connections` error
 *      costs a reader the interpolated values of `label` and `status`, which the row itself holds.
 *   2. A BINDING THAT LOOKS LIKE ENCODED BYTES. Catches a future `bytea` column on a table nobody
 *      added to the list below — which is the failure mode a name list has by construction. The
 *      pattern is `BinaryCast::set()`'s own output and nothing else: a backslash, an `x`, and an
 *      even number of at least 32 hex digits. A sealed credential is a 12-byte IV plus ciphertext
 *      plus a 16-byte tag, so it can never be shorter than that; a ULID, a label, a status and an
 *      email cannot match it at all (`\` is the first character).
 *
 * The scrubbed line keeps everything an operator can act on and nothing a binding can carry: the
 * SQLSTATE, the connection, and `getSql()` — which is the PARAMETERIZED statement, `?` placeholders
 * and all, because `formatMessage()` interpolates into a COPY and leaves `$this->sql` alone.
 */
final class VaultQueryScrubber
{
    /**
     * Tables holding a vault column today.
     *
     * A CONVENIENCE, NOT THE MECHANISM — :self::looksLikeEncodedBytes() is what makes a table
     * nobody listed here still safe. Adding a name is therefore an improvement to the message a
     * reader gets, never the thing that stops a leak.
     *
     * @var list<string>
     */
    public const VAULT_TABLES = ['provider_connections'];

    /**
     * `BinaryCast::set()`'s output shape, and nothing else.
     *
     * At least 32 hex digits — 16 bytes — so no short `\x…` literal a human typed can trip it, and
     * an EVEN count because `bin2hex()` cannot produce an odd one. Anchored at both ends: a
     * binding that merely CONTAINS such a run is prose, and prose is not what this is about.
     */
    private const ENCODED_BYTES = '/\A\\\\x(?:[0-9a-f]{2}){16,}\z/i';

    /** Enough of the parameterized statement to identify it; it carries no values. */
    private const MAX_SQL_LENGTH = 400;

    /**
     * Would reporting this exception put vault bytes in the log store?
     */
    public static function isVaultQuery(QueryException $e): bool
    {
        $sql = (string) $e->getSql();

        foreach (self::VAULT_TABLES as $table) {
            if (str_contains($sql, $table)) {
                return true;
            }
        }

        foreach ($e->getBindings() as $binding) {
            if (self::looksLikeEncodedBytes($binding)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The line that gets logged INSTEAD of `$e->getMessage()`.
     *
     * THE PREVIOUS EXCEPTION'S MESSAGE IS THE ONE THAT IS SAFE, and that inversion is the whole
     * trick: PDO raises "SQLSTATE[40P01]: Deadlock detected: 7 ERROR: …", a server-side diagnostic
     * with no bindings in it, and `QueryException` then wraps it and appends the interpolated SQL.
     * So the useful half of the message is the half that never carried a value.
     */
    public static function summarize(QueryException $e): string
    {
        $previous = $e->getPrevious();

        return sprintf(
            'A database error on a statement carrying vault columns was scrubbed before logging: '
            .'%s (connection: %s, sqlstate: %s, sql: %s). The full driver message is NOT logged: it '
            .'interpolates every binding, and on this path the bindings are the sealed credential '
            .'and the wrapped data key (kb-security-baseline §18.2). Correlate on request_id.',
            $previous === null ? 'no driver detail' : self::firstLine($previous->getMessage()),
            self::firstLine((string) $e->getConnectionName()),
            self::sqlState($e),
            mb_substr((string) $e->getSql(), 0, self::MAX_SQL_LENGTH),
        );
    }

    /**
     * SQLSTATE as PDO reports it — `errorInfo[0]`, falling back to the exception code.
     */
    public static function sqlState(QueryException $e): string
    {
        $info = $e->errorInfo;

        if (is_array($info) && isset($info[0]) && is_scalar($info[0]) && (string) $info[0] !== '') {
            return (string) $info[0];
        }

        // `QueryException` copies the PDO exception's code, which for the PDO drivers is the
        // SQLSTATE STRING rather than an integer — but `Throwable::getCode()` is typed `int`, so
        // the cast is what makes this total for both spellings.
        $code = (string) $e->getCode();

        return $code !== '' && $code !== '0' ? $code : 'unknown';
    }

    private static function looksLikeEncodedBytes(mixed $binding): bool
    {
        return is_string($binding) && preg_match(self::ENCODED_BYTES, $binding) === 1;
    }

    /**
     * One line, bounded.
     *
     * PDO's message can carry a multi-line server NOTICE/DETAIL block, and a driver DETAIL line
     * DOES echo values — "Key (organization_id)=(01J…) is not present in table …". Keeping only
     * the first line drops that whole class without having to enumerate which diagnostics are safe.
     */
    private static function firstLine(string $text): string
    {
        $line = strtok($text, "\r\n");

        return mb_substr($line === false ? '' : $line, 0, 200);
    }
}
