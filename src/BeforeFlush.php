<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * Optionally implemented by the table passed to {@see UnitOfWork::entities()} along with
 * {@see Mapper} and {@see Writer} to act on entities right before their changes are collected:
 * release domain events into an outbox, cascade removals, write an audit trail, touch `updatedAt`.
 *
 * {@see UnitOfWork::flush()} first takes the list of entities managed by all entity sets, then calls the hooks
 * of their tables in the dependency order of the entity sets (see {@see DependsOn}), passing the state
 * each entity has at the moment of the call. A hook may change, add, remove and load entities of any
 * entity set, reached through the repositories injected into the table: the changes are flushed,
 * but entities that were not managed when the flush started do not get the hook.
 * Changes are collected only after all hooks have run.
 *
 * @api
 *
 * @template-contravariant TEntity of object
 */
interface BeforeFlush
{
    /**
     * @param TEntity $entity
     */
    public function beforeFlush(object $entity, EntityState $state): void;
}
