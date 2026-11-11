<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\DependsOn;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * Adds dependencies to a table.
 *
 * @template TEntity of object
 * @template TRow of array<mixed>
 * @implements Mapper<TEntity, TRow>
 * @implements Writer<Storage, TRow>
 */
final readonly class DependentTable implements Mapper, Writer, DependsOn
{
    /**
     * @param Mapper<TEntity, TRow>&Writer<Storage, TRow> $table
     * @param non-empty-list<class-string> $dependsOn
     */
    public function __construct(
        private Mapper&Writer $table,
        private array $dependsOn,
    ) {}

    public function dependsOn(): array
    {
        return $this->dependsOn;
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
