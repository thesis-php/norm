<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\DependencyCycle;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Internal\Table;
use Thesis\Norm\Internal\Tables;

/**
 * A single business transaction. Entities are tracked in {@see EntitySet}s obtained via entities(),
 * and flush() writes their changes out once. A unit of work cannot be reused afterwards.
 *
 * A unit of work is not safe for concurrent use: create one per scope (a request, a message, a command)
 * together with the repositories that use it and do not share it between fibers.
 *
 * @api
 *
 * @template-covariant TExecutor of object whatever runs the queries, normally a transaction: see flush() on atomicity
 */
final class UnitOfWork
{
    /**
     * @param TExecutor $executor
     */
    public function __construct(object $executor)
    {
        $this->executor = $executor;
        $this->tables = new Tables();
    }

    /**
     * Not accessible once the unit of work is closed, so that finders do not query on behalf of a closed unit of work.
     *
     * @var TExecutor
     * @throws UnitOfWorkClosed
     */
    public private(set) object $executor {
        get {
            $this->ensureNotClosed();

            return $this->executor;
        }
    }

    /**
     * @var Tables<TExecutor>
     */
    private readonly Tables $tables;

    /**
     * Returns the entity set for the given entity class, creating it on the first call.
     * Subsequent calls with the same class return the same set: the table of the first call is used.
     *
     * If the table implements {@see DependsOn}, its rows are inserted and updated after the rows
     * of the entity classes it depends on and deleted before them.
     * If the table implements {@see BeforeFlush}, its hook is called for the managed entities on flush.
     *
     * @template TEntity of object
     * @template TRow of array<mixed>
     * @param class-string<TEntity> $class
     * @param Mapper<TEntity, TRow>&Writer<TExecutor, TRow> $table
     * @return EntitySet<TEntity>
     * @throws UnitOfWorkClosed
     */
    public function entities(string $class, Mapper&Writer $table): EntitySet
    {
        $this->ensureNotClosed();

        $registered = $this->tables->get($class);

        if ($registered !== null) {
            /** @var EntitySet<TEntity> */
            return $registered->entities;
        }

        // Rows coming from the storage are untyped: they are trusted to match the table's row type.
        /** @var Mapper<TEntity, array<mixed>>&Writer<TExecutor, array<mixed>> $table */
        $entitySet = new EntitySet($class, $table); // @phpstan-ignore varTag.type
        $this->tables->add(new Table(
            class: $class,
            entities: $entitySet,
            writer: $table,
            dependencies: $table instanceof DependsOn ? $table->dependsOn() : [],
            beforeFlush: $table instanceof BeforeFlush ? $table : null,
        ));

        return $entitySet;
    }

    /**
     * Calls the {@see BeforeFlush} hooks, then writes the changes of every entity set in three phases:
     * all inserts, then all updates, then all deletes. Inserts and updates go in the dependency order
     * of the entity sets (see {@see DependsOn}), deletes in the reverse one. Independent entity sets keep
     * the order they were created in.
     *
     * The unit of work is closed once the changes are collected, so the entities cannot be changed while
     * they are being written. It stays closed afterwards, whether the flush succeeded or not.
     *
     * The unit of work neither begins nor commits transactions, so the writes are atomic only if the executor
     * is a transaction: commit it after flush() and roll it back on failure. With a connection in autocommit mode
     * or a pool, a failed write (e.g. ConcurrentModification on update) leaves the earlier writes in the storage.
     * Pass those only to a unit of work that does not write or when a partial write is acceptable.
     *
     * The executor is used only by the writers, and a writer is called only for a non-empty batch: without changes
     * the executor is not touched, so a lazily begun transaction (e.g. a lazy service of a DI container) is not
     * begun at all, unless a BeforeFlush hook or a finder uses it.
     *
     * @throws ConcurrentModification if a writer detects a concurrent update or delete, retry the whole transaction
     * @throws DependencyCycle if the entity sets depend on each other in a cycle
     * @throws EntityAlreadyExists if a writer reports a duplicate insert, retry the whole transaction
     * @throws UnitOfWorkClosed
     */
    public function flush(): void
    {
        // Throws UnitOfWorkClosed if the unit of work is closed.
        $executor = $this->executor;

        try {
            $this->callBeforeFlushHooks();
            // Changes of all entity sets are collected before anything is written.
            $changes = array_map(
                static fn(Table $table): array => [$table->writer, $table->entities->changes()],
                $this->tables->inDependencyOrder(),
            );
            $this->close();

            foreach ($changes as [$writer, $changeSet]) {
                if ($changeSet->inserts !== []) {
                    $writer->insert($executor, $changeSet->inserts);
                }
            }

            foreach ($changes as [$writer, $changeSet]) {
                if ($changeSet->updates !== []) {
                    $writer->update($executor, $changeSet->updates);
                }
            }

            foreach (array_reverse($changes) as [$writer, $changeSet]) {
                if ($changeSet->deletes !== []) {
                    $writer->delete($executor, $changeSet->deletes);
                }
            }
        } finally {
            $this->close();
        }
    }

    public private(set) bool $isClosed = false;

    /**
     * Abandons the unit of work without writing anything.
     */
    public function close(): void
    {
        $this->tables->close();
        $this->isClosed = true;
    }

    /**
     * @throws DependencyCycle
     */
    private function callBeforeFlushHooks(): void
    {
        // Entities are listed upfront, so that the ones managed by the hooks do not get the hook.
        $calls = [];

        foreach ($this->tables->inDependencyOrder() as $table) {
            if ($table->beforeFlush !== null) {
                foreach ($table->entities->all() as $entity) {
                    $calls[] = [$table->entities, $table->beforeFlush, $entity];
                }
            }
        }

        foreach ($calls as [$entities, $beforeFlush, $entity]) {
            // The state is taken right before the call to reflect what the previous hooks did.
            $state = $entities->state($entity);

            if ($state !== null) {
                $beforeFlush->beforeFlush($entity, $state);
            }
        }
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
