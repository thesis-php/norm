<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresLink;
use Ramsey\Uuid\UuidInterface;
use Thesis\Norm\EntitySet;
use Thesis\Norm\UnitOfWork;

final readonly class IdentityRepository
{
    /**
     * @var EntitySet<Identity>
     */
    private EntitySet $identities;

    /**
     * @param UnitOfWork<PostgresLink> $unitOfWork
     */
    public function __construct(
        private UnitOfWork $unitOfWork,
    ) {
        $this->identities = $unitOfWork->entities(Identity::class, new IdentityTable());
    }

    public function find(UuidInterface $id): ?Identity
    {
        return $this->identities->find(
            id: $id->toString(),
            load: fn(): ?array => $this
                ->unitOfWork
                ->executor
                ->execute(
                    <<<'SQL'
                        select id, password_hash, version
                        from identity
                        where id = ?
                        SQL,
                    [$id->toString()],
                )
                ->fetchRow(),
        );
    }

    public function lock(UuidInterface $id): ?Identity
    {
        return $this->identities->manageOne(
            $this
                ->unitOfWork
                ->executor
                ->execute(
                    <<<'SQL'
                        select id, password_hash, version
                        from identity
                        where id = ?
                        for update
                        SQL,
                    [$id->toString()],
                )
                ->fetchRow(),
        );
    }

    public function add(Identity $identity): void
    {
        $this->identities->add($identity);
    }
}
