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
     * @param class-string<TEntity> $class
     * @param Mapper<TEntity, array<mixed>> $mapper
     */
    public function __construct(
        private readonly string $class,
        private readonly Mapper $mapper,
    ) {}

    /**
     * Returns the entity with the given id as this unit of work sees it: an entity passed to add()
     * or already loaded is returned without calling $load, a removed one gives null. Otherwise the row
     * returned by $load is registered via manageOne(). Use it in finders by id, so that they see
     * the pending changes, unlike finders by other criteria, which reflect the storage.
     *
     * @param non-empty-string $id
     * @param \Closure(): ?array<mixed> $load loads the row with the given id from the storage
     * @return ?TEntity
     * @throws DuplicateEntity
     * @throws UnitOfWorkClosed
     */
    public function find(string $id, \Closure $load): ?object
    {
        $this->ensureNotClosed();

        $managed = $this->entities[$id] ?? null;

        if ($managed instanceof PersistedEntity) {
            return $managed->isRemoved ? null : $managed->entity;
        }

        if ($managed !== null) {
            return $managed->entity;
        }

        return $this->manageOne($load());
    }

    /**
     * Registers a row loaded from the storage and returns its entity.
     * Rows of removed entities still give their entities: finders by criteria reflect the storage.
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

        if ($this->mapper instanceof RowId) {
            $id = $this->mapper->rowId($row);
            $entity = null;
        } else {
            $entity = $this->mapper->rowToEntity($row);
            $id = $this->mapper->entityId($entity);
        }

        $managed = $this->entities[$id] ?? null;

        if ($managed instanceof PersistedEntity) {
            return $managed->entity;
        }

        if ($managed !== null) {
            throw DuplicateEntity::create($this->class, $id);
        }

        $entity ??= $this->mapper->rowToEntity($row);
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

        if ($managed->entity !== $entity) {
            throw DuplicateEntity::create($this->class, $id);
        }

        if ($managed instanceof PersistedEntity) {
            $managed->isRemoved = false;
        }
    }

    /**
     * A loaded entity is deleted on flush unless it is passed to add() again; removing it twice is a no-op.
     * An entity passed to add() is just forgotten: it is no longer managed.
     *
     * @param TEntity $entity
     * @throws EntityNotManaged
     * @throws UnitOfWorkClosed
     */
    public function remove(object $entity): void
    {
        $this->ensureNotClosed();

        $id = $this->mapper->entityId($entity);
        $managed = $this->entities[$id] ?? null;

        if ($managed === null || $managed->entity !== $entity) {
            throw EntityNotManaged::create($this->class, $id);
        }

        if ($managed instanceof PersistedEntity) {
            $managed->isRemoved = true;
        } else {
            unset($this->entities[$id]);
        }
    }

    /**
     * @internal
     *
     * @return list<TEntity> entities that will be inserted, updated (if changed) or deleted on flush
     */
    public function all(): array
    {
        $entities = [];

        foreach ($this->entities as $managed) {
            $entities[] = $managed->entity;
        }

        return $entities;
    }

    /**
     * @internal
     *
     * @param TEntity $entity
     * @return ?EntityState null if the entity is not managed
     */
    public function state(object $entity): ?EntityState
    {
        $managed = $this->entities[$this->mapper->entityId($entity)] ?? null;

        if ($managed instanceof PersistedEntity) {
            return $managed->state($entity, $this->mapper->entityToRow(...));
        }

        return $managed?->state($entity);
    }

    /**
     * @internal
     *
     * @return ChangeSet<TEntity, array<mixed>>
     */
    public function changes(): ChangeSet
    {
        $changeSet = new ChangeSet($this->mapper->entityToRow(...));

        foreach ($this->entities as $entity) {
            $entity->collectChanges($changeSet);
        }

        return $changeSet;
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
            throw UnitOfWorkClosed::create();
        }
    }
}
