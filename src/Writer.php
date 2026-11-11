<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;

/**
 * Writes changes of one entity type to the storage. Called by {@see UnitOfWork::flush()}: inserts of all
 * entity sets are written first, then updates, then deletes. Use {@see DependsOn} to order entity sets.
 *
 * @api
 *
 * @template-contravariant TExecutor of object the executor of the unit of work, see {@see UnitOfWork::$executor}
 * @template-contravariant TRow of array<mixed>
 */
interface Writer
{
    /**
     * @param TExecutor $executor
     * @param non-empty-list<TRow> $rows
     * @throws EntityAlreadyExists if the storage rejects an entity as already existing
     */
    public function insert(object $executor, array $rows): void;

    /**
     * @param TExecutor $executor
     * @param non-empty-list<Change<TRow>> $changes only the rows that differ from their snapshots
     * @throws ConcurrentModification
     */
    public function update(object $executor, array $changes): void;

    /**
     * @param TExecutor $executor
     * @param non-empty-list<TRow> $rows {@see Mapper::entityToRow()} snapshots taken when the entities were loaded
     * @throws ConcurrentModification
     */
    public function delete(object $executor, array $rows): void;
}
