<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityNotManaged;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Internal\ChangeSet;
use Thesis\Norm\Internal\NewEntity;
use Thesis\Norm\Internal\PersistedEntity;

/**
 * Identity map and change tracking for entities of one type within a unit of work.
 * Obtain it via {@see UnitOfWork::entities()}.
 *
 * @api
 *
 * @template TEntity of object
 */
final class EntitySet
{
    /**
     * @var array<non-empty-string, NewEntity<TEntity>|PersistedEntity<TEntity, array<mixed>>>
     */
    private array $entities = [];

    private bool $isClosed = false;

    /**
     * @internal
     *
     * @param Mapper<TEntity, array<mixed>> $mapper
     */
    public function __construct(
        private readonly Mapper $mapper,
    ) {}

    /**
     * Registers a row loaded from the storage and returns its entity.
     * If the entity is already managed, the same instance is returned and the row is ignored.
     * The row must match the mapper's row type: it is passed to the mapper as is.
     *
     * @param ?array<mixed> $row
     * @return ($row is null ? null : TEntity)
     * @throws DuplicateEntity if the entity has already been passed to add()
     * @throws UnitOfWorkClosed
     */
    public function manageOne(?array $row): ?object
    {
        $this->ensureNotClosed();

        if ($row === null) {
            return null;
        }

        $id = $this->mapper->rowId($row);

        $managed = $this->entities[$id] ?? null;

        if ($managed instanceof PersistedEntity) {
            return $managed->entity;
        }

        if ($managed instanceof NewEntity && $managed->entity !== null) {
            throw new DuplicateEntity();
        }

        $entity = $this->mapper->rowToEntity($row);
        // The snapshot is taken via entityToRow() rather than from the raw row, so that storage-specific
        // representations (date formats, scalar types, extra columns) do not make the entity look changed.
        $this->entities[$id] = new PersistedEntity($entity, $this->mapper->entityToRow($entity));

        return $entity;
    }

    /**
     * @param iterable<array<mixed>> $rows
     * @return list<TEntity>
     * @throws DuplicateEntity if one of the entities has already been passed to add()
     * @throws UnitOfWorkClosed
     */
    public function manageAll(iterable $rows): array
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
    public function add(object $entity): void
    {
        $this->ensureNotClosed();

        $id = $this->mapper->entityId($entity);

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
    public function remove(object $entity): void
    {
        $this->ensureNotClosed();

        ($this->entities[$this->mapper->entityId($entity)] ?? throw new EntityNotManaged())->remove($entity);
    }

    /**
     * @internal
     *
     * @template Tx of object
     * @param Writer<Tx, array<mixed>> $writer
     * @param Tx $transaction
     */
    public function flush(Writer $writer, object $transaction): void
    {
        $changeSet = new ChangeSet($this->mapper->entityToRow(...));

        foreach ($this->entities as $entity) {
            $entity->collectChanges($changeSet);
        }

        if ($changeSet->inserts !== []) {
            $writer->insert($transaction, $changeSet->inserts);
        }

        if ($changeSet->updates !== []) {
            $writer->update($transaction, $changeSet->updates);
        }

        if ($changeSet->deletes !== []) {
            $writer->delete($transaction, $changeSet->deletes);
        }
    }

    /**
     * @internal
     */
    public function close(): void
    {
        $this->entities = [];
        $this->isClosed = true;
    }

    /**
     * @throws UnitOfWorkClosed
     */
    private function ensureNotClosed(): void
    {
        if ($this->isClosed) {
            throw new UnitOfWorkClosed();
        }
    }
}
