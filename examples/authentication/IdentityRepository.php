<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresQueryError;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Repository;

/**
 * @phpstan-type Row = array{id: non-empty-string, password_hash: non-empty-string, version: positive-int}
 *
 * @extends Repository<PostgresLink, Identity, Row>
 */
final class IdentityRepository extends Repository
{
    protected function entityId(object $entity): string
    {
        return $entity->id->toString();
    }

    protected function entityToRow(object $entity): array
    {
        return [
            'id' => $entity->id->toString(),
            'password_hash' => $entity->passwordHash,
            'version' => $entity->version,
        ];
    }

    protected function rowId(array $row): string
    {
        return $row['id'];
    }

    protected function rowToEntity(array $row): object
    {
        return new Identity(
            id: Uuid::fromString($row['id']),
            passwordHash: $row['password_hash'],
            version: $row['version'],
        );
    }

    public function find(UuidInterface $id): ?Identity
    {
        /** @var ?Row */
        $row = $this
            ->transaction
            ->execute(
                <<<'SQL'
                    select id, password_hash, version
                    from identity
                    where id = ?
                    SQL,
                [$id->toString()],
            )
            ->fetchRow();

        return $this->manageOne($row);
    }

    /**
     * @return list<Identity>
     */
    public function findAll(): array
    {
        return $this->manageAll(
            /** @phpstan-ignore argument.type */
            $this->transaction->query(
                <<<'SQL'
                    select id, password_hash, version
                    from identity
                    SQL,
            ),
        );
    }

    protected function insert(array $rows): void
    {
        $statement = $this->transaction->prepare(
            <<<'SQL'
                insert into identity (id, password_hash, version)
                values (:id, :password_hash, :version)
                SQL,
        );

        foreach ($rows as $row) {
            try {
                $statement->execute($row);
            } catch (PostgresQueryError $error) {
                if (str_contains(strtolower($error->getMessage()), 'duplicate key value violates unique constraint')) {
                    throw new EntityAlreadyExists(previous: $error);
                }

                throw $error;
            }
        }
    }

    protected function update(array $rows): void
    {
        $statement = $this->transaction->prepare(
            <<<'SQL'
                update identity
                set password_hash = :password_hash,
                    version = version + 1
                where id = :id and version = :version
                SQL,
        );

        foreach ($rows as $row) {
            $result = $statement->execute($row);

            if ($result->getRowCount() !== 1) {
                throw new ConcurrentModification();
            }
        }
    }

    protected function delete(array $rows): void
    {
        $statement = $this->transaction->prepare(
            <<<'SQL'
                delete from identity
                where id = ? and version = ?
                SQL,
        );

        foreach ($rows as $row) {
            $result = $statement->execute([$row['id'], $row['version']]);

            if ($result->getRowCount() !== 1) {
                throw new ConcurrentModification();
            }
        }
    }
}
