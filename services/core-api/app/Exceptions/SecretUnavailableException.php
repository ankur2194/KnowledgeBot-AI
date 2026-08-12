<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a secret was pointed at a FILE and that file could not produce material.
 *
 * IT LIVES HERE AND NOT BESIDE App\Support\Kb\KbSecrets, WHICH IS THE ONLY THING THAT THROWS IT.
 * Pest's `laravel` arch preset asserts `expect('App')->not->toImplement(Throwable)->ignoring
 * ('App\Exceptions')` — every throwable in the application lives in one namespace, so "where is
 * that exception defined" has one answer. Bending the preset instead was the alternative, and it
 * is worse in both available spellings: `->ignoring('App\Support')` exempts a whole namespace from
 * a rule that should stay live for code nobody has written yet, and a per-class exemption is a
 * list that only ever grows. Cohesion with the resolver is a real argument, but it is a comment's
 * worth of value and this is a rule's worth of cost. Nothing about the split is load-bearing at
 * runtime: a namespace does not decide when a throw can happen, and this one is thrown while
 * config is still being BUILT, before the container or any exception handler exists.
 *
 * THE MESSAGE CARRIES THE NAME AND THE PATH AND NOTHING ELSE. It never carries the file's
 * contents, a prefix of them, a length, or a hash: an exception message is the single most
 * widely-copied string in an incident — it reaches the log pipeline, the error envelope in
 * debug mode, the span status, the ticket, and the chat window someone pasted it into.
 *
 * This is deliberately NOT recoverable. A missing or empty KEK, HMAC key, database password or
 * S3 secret must stop the process at configuration-build time, because every alternative is
 * worse and silent: an empty HMAC key still produces a valid-looking signature, and it verifies
 * against any peer that also resolved the empty string. A `null` credential surfaces minutes
 * later as a 401 from a provider, or hours later as a decryption failure on a stored credential.
 */
final class SecretUnavailableException extends RuntimeException
{
    public static function unreadable(string $name, string $pointer, string $path): self
    {
        return new self(
            "Secret [{$name}] is delivered as a file via [{$pointer}], but [{$path}] does not "
            .'exist or is not readable by this process. The file is the source of truth; refusing '
            .'to fall back to an environment value.'
        );
    }

    public static function blank(string $name, string $pointer, string $path): self
    {
        return new self(
            "Secret [{$name}] is delivered as a file via [{$pointer}], but [{$path}] is empty "
            .'after trimming. An empty secret is never a valid secret: an empty signing key '
            .'verifies against any peer that also resolved to empty.'
        );
    }
}
