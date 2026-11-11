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
 * @template-contravariant Tx of object
 * @template-contravariant TRow of array<mixed>
 */
interface Writer
{
    /**
     * @param Tx $transaction
     * @param non-empty-list<TRow> $rows
     * @throws EntityAlreadyExists if the storage rejects an entity as already existing
     */
    public function insert(object $transaction, array $rows): void;

    /**
     * @param Tx $transaction
     * @param non-empty-list<TRow> $rows
     * @throws ConcurrentModification
     */
    public function update(object $transaction, array $rows): void;

    /**
     * @param Tx $transaction
     * @param non-empty-list<TRow> $rows {@see Mapper::entityToRow()} snapshots taken when the entities were loaded
     * @throws ConcurrentModification
     */
    public function delete(object $transaction, array $rows): void;
}
