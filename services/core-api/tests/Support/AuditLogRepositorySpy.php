<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Services\Audit\AuditLogFilter;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use LogicException;
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
     * THE READ SIDE IS NOT SPIED, AND THIS METHOD REFUSES RATHER THAN RETURNING AN EMPTY PAGE.
     *
     * `AuditLogRepositoryInterface` grew `paginate()` in Phase 6a, and it is on the same interface
     * on purpose — that table has no `#[ScopedBy]` backstop, so the explicit `organization_id`
     * argument is its only tenancy and a second query elsewhere would be a second place to forget
     * it. This class exists for tests/Unit/AuditLoggerTest.php, which asserts what the WRITER
     * decides to persist with no database, no container and no facades; there is nothing here to
     * paginate.
     *
     * An empty paginator would be the tempting stub and it is the wrong one: a reader test wired to
     * this spy by accident would go green against a surface that returns nothing, which is the
     * shape `pest-testing` NN2 exists to forbid. Failing loudly names the mistake at the call site.
     */
    public function paginate(
        string $organizationId,
        AuditLogFilter $filter,
        ListQuery $query,
    ): LengthAwarePaginator {
        throw new LogicException(
            'AuditLogRepositorySpy is the WRITE side only. A test that needs to read audit rows '
            .'needs a database, which means it belongs in Feature/ or Security/ against the real '
            .'EloquentAuditLogRepository — an empty page returned from here would make a reader '
            .'assertion pass against a surface that returns nothing.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        return $this->writes[count($this->writes) - 1] ?? [];
    }
}
