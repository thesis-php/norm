<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\EntityState;

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
