<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresLink;
use Ramsey\Uuid\UuidInterface;
use Thesis\Norm\EntitySet;
use Thesis\Norm\UnitOfWork;

/**
 * @phpstan-import-type Row from IdentityTable
 */
final readonly class IdentityRepository
{
    /**
     * @var EntitySet<Identity, Row>
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
            load: fn(): ?array => $this->selectRow(
                <<<'SQL'
                    select id, password_hash, version
                    from identity
                    where id = ?
                    SQL,
                $id,
            ),
        );
    }

    public function lock(UuidInterface $id): ?Identity
    {
        return $this->identities->manageOne($this->selectRow(
            <<<'SQL'
                select id, password_hash, version
                from identity
                where id = ?
                for update
                SQL,
            $id,
        ));
    }

    public function add(Identity $identity): void
    {
        $this->identities->add($identity);
    }

    /**
     * Rows of the driver are untyped: the selected columns are trusted to match the row type of the table.
     *
     * @return ?Row
     */
    private function selectRow(string $sql, UuidInterface $id): ?array
    {
        /** @var ?Row */
        return $this->unitOfWork->executor->execute($sql, [$id->toString()])->fetchRow();
    }
}
