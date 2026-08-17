<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Throwable;

/**
 * The audit write side, captured instead of executed — so tests/Unit/AuditLoggerTest.php can assert
 * on exactly what AuditLogger DECIDED to persist, with no database in the way.
 *
 * IT LIVES HERE RATHER THAN INSIDE THE TEST FILE, for the reason KbSecretFixtures spells out at
 * length: `Tests\` is mapped PSR-4 to ./tests in composer.json's autoload-dev, so a class declared
 * inside tests/Unit/AuditLoggerTest.php makes `composer install` and every `dump-autoload -o` print a
 * PSR-4 non-compliance warning — permanently, and therefore invisibly. That warning was cleaned up
 * once already; re-adding it costs the next person the one after it.
 *
 * `new AuditLog` in write() is safe with no container: Model::__construct only boots traits and fires
 * model events through a static dispatcher that Unit/ never sets, so nothing here reaches the
 * framework.
 */
final class AuditLogRepositorySpy implements AuditLogRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $writes = [];

    /**
     * @param  Throwable|null  $failWith  thrown instead of writing, so the per-operation
     *                                    write-failure policy can be exercised without a broken
     *                                    database
     */
    public function __construct(private readonly ?Throwable $failWith = null) {}

    /**
     * @param  array<string, bool|float|int|string>  $details
     */
    public function write(
        string $operation,
        string $outcome,
        ?string $organizationId,
        ?string $actorId,
        ?string $subjectType,
        ?string $subjectId,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
        array $details,
    ): AuditLog {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->writes[] = [
            'operation' => $operation,
            'outcome' => $outcome,
            'organization_id' => $organizationId,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'details' => $details,
        ];

        return new AuditLog;
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        return $this->writes[count($this->writes) - 1] ?? [];
    }
}
