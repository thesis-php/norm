<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresQueryError;
use Ramsey\Uuid\Uuid;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * The version for optimistic locking is a storage concern, so it is kept here rather than in Identity:
 * rowToEntity() remembers the loaded version of each entity, entityToRow() puts it into the row,
 * so that it gets into the snapshot and update() and delete() can check it against Change::$before.
 * A WeakMap does not keep the entities alive, and the entities of different units of work are different objects,
 * so one table may outlive a unit of work.
 *
 * @phpstan-type Row = array{id: non-empty-string, password_hash: non-empty-string, version: positive-int}
 *
 * @implements Mapper<Identity, Row>
 * @implements Writer<PostgresLink, Row>
 */
final readonly class IdentityTable implements Mapper, Writer
{
    /**
     * @see https://www.postgresql.org/docs/current/errcodes-appendix.html
     */
    private const string UNIQUE_VIOLATION = '23505';
    private const int DEFAULT_VERSION = 1;

    /**
     * @var \WeakMap<Identity, positive-int>
     */
    private \WeakMap $versions;

    public function __construct()
    {
        $this->versions = new \WeakMap();
    }

    public function entityId(object $entity): string
    {
        return $entity->id->toString();
    }

    public function entityToRow(object $entity): array
    {
        return [
            'id' => $entity->id->toString(),
            'password_hash' => $entity->passwordHash,
            // a new identity is not in the map yet
            'version' => $this->versions[$entity] ?? self::DEFAULT_VERSION,
        ];
    }

    public function rowToEntity(array $row): object
    {
        $identity = new Identity(
            id: Uuid::fromString($row['id']),
            passwordHash: $row['password_hash'],
        );
        $this->versions[$identity] = $row['version'];

        return $identity;
    }

    public function insert(object $executor, array $rows): void
    {
        $statement = $executor->prepare(
            <<<'SQL'
                insert into identity (id, password_hash, version)
                values (:id, :password_hash, :version)
                SQL,
        );

        foreach ($rows as $row) {
            try {
                $statement->execute($row);
            } catch (PostgresQueryError $error) {
                if (($error->getDiagnostics()['sqlstate'] ?? null) === self::UNIQUE_VIOLATION) {
                    throw new EntityAlreadyExists(previous: $error);
                }

                throw $error;
            }
        }
    }

    public function update(object $executor, array $changes): void
    {
        $statement = $executor->prepare(
            <<<'SQL'
                update identity
                set password_hash = :password_hash,
                    version = version + 1
                where id = :id and version = :version
                SQL,
        );

        foreach ($changes as $change) {
            $result = $statement->execute([
                'id' => $change->before['id'],
                'password_hash' => $change->after['password_hash'],
                'version' => $change->before['version'],
            ]);

            if ($result->getRowCount() !== 1) {
                throw new ConcurrentModification();
            }
        }
    }

    public function delete(object $executor, array $rows): void
    {
        $statement = $executor->prepare(
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
