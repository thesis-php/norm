<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityNotManaged;

/**
 * An entity that does not exist in the storage yet.
 *
 * @internal
 *
 * @template TEntity of object
 */
final class NewEntity
{
    /**
     * @var ?TEntity
     */
    public private(set) ?object $entity = null;

    /**
     * @param TEntity $entity
     */
    public function __construct(object $entity)
    {
        $this->entity = $entity;
    }

    /**
     * @param TEntity $entity
     * @throws DuplicateEntity
     */
    public function add(object $entity): void
    {
        if ($this->entity !== null && $this->entity !== $entity) {
            throw new DuplicateEntity();
        }

        $this->entity = $entity;
    }

    /**
     * @param TEntity $entity
     * @throws EntityNotManaged
     */
    public function remove(object $entity): void
    {
        if ($this->entity !== null && $this->entity !== $entity) {
            throw new EntityNotManaged();
        }

        $this->entity = null;
    }

    /**
     * @param ChangeSet<TEntity, *> $changeSet
     */
    public function collectChanges(ChangeSet $changeSet): void
    {
        if ($this->entity !== null) {
            $changeSet->insert($this->entity);
        }
    }
}
