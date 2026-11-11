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
 * @phpstan-type Row = array{id: non-empty-string, password_hash: non-empty-string, version: positive-int}
 *
 * @implements Mapper<Identity, Row>
 * @implements Writer<PostgresLink, Row>
 */
final readonly class IdentityTable implements Mapper, Writer
{
    public function entityId(object $entity): string
    {
        return $entity->id->toString();
    }

    public function entityToRow(object $entity): array
    {
        return [
            'id' => $entity->id->toString(),
            'password_hash' => $entity->passwordHash,
            'version' => $entity->version,
        ];
    }

    public function rowToEntity(array $row): object
    {
        return new Identity(
            id: Uuid::fromString($row['id']),
            passwordHash: $row['password_hash'],
            version: $row['version'],
        );
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
                if (str_contains(strtolower($error->getMessage()), 'duplicate key value violates unique constraint')) {
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
