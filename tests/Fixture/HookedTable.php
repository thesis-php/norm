<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\BeforeFlush;
use Thesis\Norm\EntityState;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * Adds a {@see BeforeFlush} hook and a callback run before every insert to a table.
 *
 * @template TEntity of object
 * @template TRow of array<mixed>
 * @implements Mapper<TEntity, TRow>
 * @implements Writer<Storage, TRow>
 * @implements BeforeFlush<TEntity>
 */
final readonly class HookedTable implements Mapper, Writer, BeforeFlush
{
    /**
     * @param Mapper<TEntity, TRow>&Writer<Storage, TRow> $table
     * @param \Closure(TEntity, EntityState): void $beforeFlush
     * @param ?\Closure(): void $beforeInsert
     */
    public function __construct(
        private Mapper&Writer $table,
        private \Closure $beforeFlush,
        private ?\Closure $beforeInsert = null,
    ) {}

    public function beforeFlush(object $entity, EntityState $state): void
    {
        ($this->beforeFlush)($entity, $state);
    }

    public function entityId(object $entity): string
    {
        return $this->table->entityId($entity);
    }

    public function entityToRow(object $entity): array
    {
        return $this->table->entityToRow($entity);
    }

    public function rowId(array $row): string
    {
        return $this->table->rowId($row);
    }

    public function rowToEntity(array $row): object
    {
        return $this->table->rowToEntity($row);
    }

    public function insert(object $transaction, array $rows): void
    {
        if ($this->beforeInsert !== null) {
            ($this->beforeInsert)();
        }

        $this->table->insert($transaction, $rows);
    }

    public function update(object $transaction, array $changes): void
    {
        $this->table->update($transaction, $changes);
    }

    public function delete(object $transaction, array $rows): void
    {
        $this->table->delete($transaction, $rows);
    }
}
