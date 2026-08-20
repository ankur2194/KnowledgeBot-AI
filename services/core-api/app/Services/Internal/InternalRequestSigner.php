<?php

declare(strict_types=1);

namespace App\Services\Internal;

use RuntimeException;
use SensitiveParameter;

/**
 * The canonical string and the HMAC over it (kb-internal-api-contracts, "Transport and service
 * authentication").
 *
 *     canonical = PREFIX \n METHOD \n PATH \n X-KB-Timestamp \n sha256_hex(raw_body) \n
 *                 + "\n".join(sorted("name:value" for every X-KB-* header except the signature))
 *
 * A SEPARATE CLASS FROM THE CLIENT, WITH NO FACADES AND NO HTTP, so the canonical string is
 * testable without booting the framework or opening a socket. The rule it encodes has one shape of
 * bug — the signed set and the sent set drifting apart — and that bug is invisible in an
 * integration test, which only ever reports a 401 on a request that looks correct in the log.
 *
 * THE HEADER BLOCK IS NOT OPTIONAL. Method + path + timestamp + body alone leaves the request
 * forgeable: `X-KB-Org-Id` is the tenant scope for the entire data plane, so an unsigned org
 * header means a legitimately signed request can be replayed against another organization by
 * flipping one value. The set is derived FROM the headers actually being sent, never hand-written
 * from a second literal list.
 */
final class InternalRequestSigner
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $keyId,
        #[SensitiveParameter] private readonly ?string $secret,
    ) {}

    /**
     * The canonical string for a request. Public so a contract test can assert it byte for byte
     * against the verifier's own reconstruction.
     *
     * @param  array<string, string>  $kbHeaders  every X-KB-* header being sent, signature excluded
     */
    public function canonicalString(string $method, string $path, string $body, array $kbHeaders): string
    {
        // CASE-INSENSITIVE, AND THAT IS NOT DEFENSIVENESS. This method serves BOTH directions:
        // the outbound signer hands it the canonical `X-KB-Timestamp` spelling it is about to put
        // on the wire, and the inbound VERIFIER hands it the set it recomputed from
        // `$request->headers->all()`, which Symfony normalises to lower case. A literal key lookup
        // silently yields the EMPTY STRING on the verifier's side, so the fourth line of the
        // canonical string differs while every other line matches — and the only symptom is a 401
        // on a request that is correct in every log. Measured, not imagined: it was this method's
        // first bug.
        $timestamp = '';

        foreach ($kbHeaders as $name => $value) {
            if (strcasecmp($name, 'X-KB-Timestamp') === 0) {
                $timestamp = $value;

                break;
            }
        }

        $canonical = $this->prefix."\n".strtoupper($method)."\n".$path."\n"
            .$timestamp."\n".hash('sha256', $body)."\n";

        $lines = [];

        foreach ($kbHeaders as $name => $value) {
            if (strcasecmp($name, 'X-KB-Signature') === 0) {
                continue;
            }

            $lines[] = strtolower($name).':'.trim($value);
        }

        // Byte order, because the verifier sorts the same way. SORT_STRING is explicit: PHP's
        // default sort flag compares numeric-looking strings numerically, and a header value that
        // happens to be all digits would then order differently on the two sides.
        sort($lines, SORT_STRING);

        return $canonical.implode("\n", $lines);
    }

    /**
     * `key_id:hex`. The id travels on the wire because two ids are live during a rotation — signer
     * and verifier deploy at different times, so a single-id scheme 401s every internal call
     * through a rolling deploy.
     *
     * @param  array<string, string>  $kbHeaders
     */
    public function sign(string $method, string $path, string $body, array $kbHeaders): string
    {
        if ($this->secret === null || $this->secret === '') {
            // An empty signing key still produces a valid-looking signature, and it verifies
            // against any peer that also resolved the empty string. Refuse instead.
            throw new RuntimeException(
                "No HMAC secret is configured for key id [{$this->keyId}]; refusing to sign an "
                .'internal request with an empty key.',
            );
        }

        return $this->keyId.':'.hash_hmac(
            'sha256',
            $this->canonicalString($method, $path, $body, $kbHeaders),
            $this->secret,
        );
    }
}
