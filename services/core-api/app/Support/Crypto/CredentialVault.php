<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use App\Exceptions\SecretUnavailableException;
use RuntimeException;
use SensitiveParameter;

/**
 * Envelope encryption for provider credentials (kb-security-baseline §18.2).
 *
 * A per-credential data key (DEK) encrypts the secret; the DEK is wrapped by the KEK and the
 * wrapped form is what the row stores, beside `key_version`. Storing the version is what makes a
 * KEK rotation a DATA change — re-wrap every DEK — rather than a schema migration.
 *
 * NOT APP_KEY. config/kb.php states the reason: APP_KEY covers sessions and cookies and rotates
 * for entirely different reasons, so sharing one key would make "log everyone out" and "re-wrap
 * every tenant's provider credential" the same operation.
 *
 * FAILS CLOSED ON A MISSING KEK, and that is the one behaviour worth writing a test for. A missing
 * KEK is never a reason to store a credential unwrapped and never a reason to skip encryption; the
 * only correct response is to refuse the write.
 *
 * `#[SensitiveParameter]` on every plaintext argument: PHP then renders the argument as
 * `Object(SensitiveParameterValue)` in stack traces, so an exception thrown anywhere below these
 * calls cannot carry the key into a log, an error tracker, or an envelope.
 */
final class CredentialVault
{
    private const CIPHER = 'aes-256-gcm';

    private const TAG_BYTES = 16;

    private const IV_BYTES = 12;

    public function __construct(
        private readonly ?string $kek,
        private readonly int $kekVersion,
    ) {}

    /**
     * Encrypt a provider secret under a fresh data key.
     *
     * @return array{credential_ciphertext: string, data_key_ciphertext: string, key_version: int, last_four: string}
     */
    public function seal(#[SensitiveParameter] string $plaintext): array
    {
        if ($plaintext === '') {
            throw new RuntimeException('Refusing to seal an empty provider credential.');
        }

        $dataKey = random_bytes(32);

        return [
            'credential_ciphertext' => $this->encrypt($plaintext, $dataKey),
            'data_key_ciphertext' => $this->encrypt($dataKey, $this->kek()),
            'key_version' => $this->kekVersion,
            'last_four' => $this->lastFour($plaintext),
        ];
    }

    /**
     * Recover a provider secret. The return value must not outlive the call that asked for it.
     */
    public function open(string $credentialCiphertext, string $dataKeyCiphertext): string
    {
        return $this->decrypt($credentialCiphertext, $this->decrypt($dataKeyCiphertext, $this->kek()));
    }

    /**
     * The ONLY derived form of a key that may be rendered. Four characters, and never a prefix:
     * a provider key prefix identifies the vendor and, on several providers, the account.
     */
    public function lastFour(#[SensitiveParameter] string $plaintext): string
    {
        return substr(str_pad($plaintext, 4, '*', STR_PAD_LEFT), -4);
    }

    /**
     * Identifies WHICH key was involved without being derivable back to it — the value an audit
     * row may carry (kb-security-baseline §18.11). Keyed on the KEK rather than on nothing, so two
     * organizations that happen to paste the same key do not produce the same public fingerprint.
     */
    public function fingerprint(#[SensitiveParameter] string $plaintext): string
    {
        return substr(hash_hmac('sha256', $plaintext, $this->kek()), 0, 16);
    }

    private function kek(): string
    {
        if ($this->kek === null || trim($this->kek) === '') {
            throw new SecretUnavailableException(
                'KB_KEK is not available, so no provider credential can be sealed or opened. A '
                .'missing key-encrypting key is never a reason to store a credential unwrapped.',
            );
        }

        // Accepts whatever the secret file holds — raw, base64, hex — and derives a fixed 32-byte
        // key from it. The KEK is a wrapping key, so its own encoding is an operational detail;
        // pinning the derivation here is what stops it becoming one.
        return hash('sha256', trim($this->kek), true);
    }

    private function encrypt(#[SensitiveParameter] string $plaintext, string $key): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Provider credential encryption failed.');
        }

        return $iv.$tag.$ciphertext;
    }

    private function decrypt(string $envelope, string $key): string
    {
        if (strlen($envelope) <= self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Provider credential ciphertext is truncated.');
        }

        $plaintext = openssl_decrypt(
            substr($envelope, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($envelope, 0, self::IV_BYTES),
            substr($envelope, self::IV_BYTES, self::TAG_BYTES),
        );

        if ($plaintext === false) {
            // Deliberately says nothing about which of the two layers failed and carries no
            // fragment of either input: an oracle that distinguishes "wrong KEK" from "corrupt
            // ciphertext" is a probe an attacker can run.
            throw new RuntimeException('Provider credential could not be decrypted.');
        }

        return $plaintext;
    }
}
