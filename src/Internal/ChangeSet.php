<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

/**
 * @internal
 *
 * @template TEntity of object
 * @template TRow of array<mixed>
 */
final class ChangeSet
{
    /**
     * @param \Closure(TEntity): TRow $entityToRow
     */
    public function __construct(
        private readonly \Closure $entityToRow,
    ) {}

    /**
     * @var list<TRow>
     */
    public private(set) array $inserts = [];

    /**
     * @param TEntity $entity
     */
    public function insert(object $entity): void
    {
        $this->inserts[] = ($this->entityToRow)($entity);
    }

    /**
     * @var list<TRow>
     */
    public private(set) array $updates = [];

    /**
     * @param TEntity $entity
     * @param TRow $snapshot
     */
    public function update(object $entity, array $snapshot): void
    {
        $row = ($this->entityToRow)($entity);

        $sortedRow = $row;
        ksort($sortedRow);

        ksort($snapshot);

        if ($sortedRow !== $snapshot) {
            $this->updates[] = $row;
        }
    }

    /**
     * @var list<TRow>
     */
    public private(set) array $deletes = [];

    /**
     * @param TRow $row
     */
    public function delete(array $row): void
    {
        $this->deletes[] = $row;
    }
}
