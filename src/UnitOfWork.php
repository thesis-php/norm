<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Thesis\Norm\Exception\UnitOfWorkClosed;

/**
 * A single business transaction. Entities are tracked in {@see EntitySet}s obtained via entities(),
 * and flush() writes their changes out once. A unit of work cannot be reused afterwards.
 *
 * @api
 *
 * @template-covariant Tx of object
 */
final class UnitOfWork
{
    /**
     * @param Tx $transaction
     */
    public function __construct(object $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * Not accessible once the unit of work is closed, so that finders do not query on behalf of a closed unit of work.
     *
     * @var Tx
     * @throws UnitOfWorkClosed
     */
    public private(set) object $transaction {
        get {
            $this->ensureNotClosed();

            return $this->transaction;
        }
    }

    /**
     * @var array<class-string, EntitySet<object>>
     */
    private array $entitySets = [];

    /**
     * @var array<class-string, \Closure(): void>
     */
    private array $flushers = [];

    /**
     * Returns the entity set for the given entity class, creating it on the first call.
     * Subsequent calls with the same class return the same set: the table of the first call is used.
     *
     * @template TEntity of object
     * @template TRow of array<mixed>
     * @param class-string<TEntity> $class
     * @param Mapper<TEntity, TRow>&Writer<Tx, TRow> $table
     * @return EntitySet<TEntity>
     * @throws UnitOfWorkClosed
     */
    public function entities(string $class, Mapper&Writer $table): EntitySet
    {
        $this->ensureNotClosed();

        if (isset($this->entitySets[$class])) {
            /** @var EntitySet<TEntity> */
            return $this->entitySets[$class];
        }

        // Rows coming from the storage are untyped: they are trusted to match the table's row type.
        /** @var Mapper<TEntity, array<mixed>>&Writer<Tx, array<mixed>> $table */
        $entitySet = new EntitySet($table); // @phpstan-ignore varTag.type
        $this->entitySets[$class] = $entitySet;
        $this->flushers[$class] = fn() => $entitySet->flush($table, $this->transaction);

        return $entitySet;
    }

    /**
     * Writes the changes of every entity set in the order the sets were created.
     * The unit of work is closed afterwards, whether the flush succeeded or not.
     *
     * @throws UnitOfWorkClosed
     */
    public function flush(): void
    {
        $this->ensureNotClosed();

        try {
            foreach ($this->flushers as $flusher) {
                $flusher();
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
        foreach ($this->entitySets as $entitySet) {
            $entitySet->close();
        }

        $this->entitySets = [];
        $this->flushers = [];
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
