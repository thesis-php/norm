<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Exception\EntityNotManaged;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Internal\ChangeSet;
use Thesis\Norm\Internal\NewEntity;
use Thesis\Norm\Internal\PersistedEntity;

/**
 * @api
 *
 * @template-covariant Tx of object
 * @template TEntity of object
 * @template TRow of array<mixed>
 */
abstract class Repository
{
    /**
     * @var Tx
     */
    protected object $transaction { get => $this->unitOfWork->transaction; }

    /**
     * @param UnitOfWork<Tx> $unitOfWork
     * @throws UnitOfWorkClosed
     */
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
    ) {
        $unitOfWork->onFlush($this->flush(...));
    }

    /**
     * @var array<non-empty-string, NewEntity<TEntity>|PersistedEntity<TEntity, TRow>>
     */
    private array $entities = [];

    /**
     * @param ?TRow $row
     * @return ($row is null ? null : TEntity)
     * @throws DuplicateEntity if the entity has already been passed to add()
     * @throws UnitOfWorkClosed
     */
    final protected function manageOne(?array $row): ?object
    {
        $this->ensureNotClosed();

        if ($row === null) {
            return null;
        }

        $id = $this->rowId($row);

        $managed = $this->entities[$id] ?? null;

        if ($managed instanceof PersistedEntity) {
            return $managed->entity;
        }

        if ($managed instanceof NewEntity && $managed->entity !== null) {
            throw new DuplicateEntity();
        }

        $entity = $this->rowToEntity($row);
        $this->entities[$id] = new PersistedEntity($entity, $row);

        return $entity;
    }

    /**
     * @param iterable<TRow> $rows
     * @return list<TEntity>
     */
    final protected function manageAll(iterable $rows): array
    {
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->manageOne($row);
        }

        return $entities;
    }

    /**
     * @param TEntity $entity
     * @throws DuplicateEntity
     * @throws UnitOfWorkClosed
     */
    final public function add(object $entity): void
    {
        $this->ensureNotClosed();

        $id = $this->entityId($entity);

        $managed = $this->entities[$id] ?? null;

        if ($managed === null) {
            $this->entities[$id] = new NewEntity($entity);

            return;
        }

        $managed->add($entity);
    }

    /**
     * @param TEntity $entity
     * @throws EntityNotManaged
     * @throws UnitOfWorkClosed
     */
    final public function remove(object $entity): void
    {
        $this->ensureNotClosed();

        ($this->entities[$this->entityId($entity)] ?? throw new EntityNotManaged())->remove($entity);
    }

    /**
     * @param TEntity $entity
     * @return non-empty-string
     */
    abstract protected function entityId(object $entity): string;

    /**
     * @param TEntity $entity
     * @return TRow
     */
    abstract protected function entityToRow(object $entity): array;

    /**
     * @param TRow $row
     * @return non-empty-string
     */
    abstract protected function rowId(array $row): string;

    /**
     * @param TRow $row
     * @return TEntity
     */
    abstract protected function rowToEntity(array $row): object;

    /**
     * @param non-empty-list<TRow> $rows
     * @throws EntityAlreadyExists if the storage rejects an entity as already existing
     */
    abstract protected function insert(array $rows): void;

    /**
     * @param non-empty-list<TRow> $rows
     * @throws ConcurrentModification
     */
    abstract protected function update(array $rows): void;

    /**
     * @param non-empty-list<TRow> $rows
     * @throws ConcurrentModification
     */
    abstract protected function delete(array $rows): void;

    private function flush(): void
    {
        try {
            $changeSet = new ChangeSet($this->entityToRow(...));

            foreach ($this->entities as $entity) {
                $entity->collectChanges($changeSet);
            }

            if ($changeSet->inserts !== []) {
                $this->insert($changeSet->inserts);
            }

            if ($changeSet->updates !== []) {
                $this->update($changeSet->updates);
            }

            if ($changeSet->deletes !== []) {
                $this->delete($changeSet->deletes);
            }
        } finally {
            $this->entities = [];
        }
    }

    /**
     * @throws UnitOfWorkClosed
     */
    private function ensureNotClosed(): void
    {
        if ($this->unitOfWork->isClosed) {
            throw new UnitOfWorkClosed();
        }
    }
}
