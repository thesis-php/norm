<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\EntityState;
use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityNotManaged;

/**
 * An entity that does not exist in the storage yet. Once removed, it is no longer managed.
 *
 * @internal
 *
 * @template TEntity of object
 */
final readonly class NewEntity
{
    /**
     * @param TEntity $entity
     */
    public function __construct(
        public object $entity,
    ) {}

    /**
     * @param TEntity $entity
     * @throws DuplicateEntity
     */
    public function add(object $entity): void
    {
        if ($entity !== $this->entity) {
            throw new DuplicateEntity();
        }
    }

    /**
     * @param TEntity $entity
     * @throws EntityNotManaged
     */
    public function remove(object $entity): void
    {
        if ($entity !== $this->entity) {
            throw new EntityNotManaged();
        }
    }

    /**
     * @param TEntity $entity
     */
    public function state(object $entity): ?EntityState
    {
        return $entity === $this->entity ? EntityState::New : null;
    }

    /**
     * @param ChangeSet<TEntity, *> $changeSet
     */
    public function collectChanges(ChangeSet $changeSet): void
    {
        $changeSet->insert($this->entity);
    }
}
