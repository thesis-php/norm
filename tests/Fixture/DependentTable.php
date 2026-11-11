<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\BeforeFlush;
use Thesis\Norm\DependsOn;
use Thesis\Norm\EntityState;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * Adds dependencies to a table, forwarding its {@see BeforeFlush} hook if it has one.
 *
 * @template TEntity of object
 * @template TRow of array<mixed>
 * @implements Mapper<TEntity, TRow>
 * @implements Writer<Storage, TRow>
 * @implements BeforeFlush<TEntity>
 */
final readonly class DependentTable implements Mapper, Writer, DependsOn, BeforeFlush
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

    public function beforeFlush(object $entity, EntityState $state): void
    {
        if ($this->table instanceof BeforeFlush) {
            $this->table->beforeFlush($entity, $state);
        }
    }

    public function entityId(object $entity): string
    {
        return $this->table->entityId($entity);
    }

    public function entityToRow(object $entity): array
    {
        return $this->table->entityToRow($entity);
    }

    public function rowToEntity(array $row): object
    {
        return $this->table->rowToEntity($row);
    }

    public function insert(object $executor, array $rows): void
    {
        $this->table->insert($executor, $rows);
    }

    public function update(object $executor, array $changes): void
    {
        $this->table->update($executor, $changes);
    }

    public function delete(object $executor, array $rows): void
    {
        $this->table->delete($executor, $rows);
    }
}
