<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Thesis\Norm\Exception\UnitOfWorkClosed;

/**
 * A single business transaction. Repositories attach themselves to it on construction,
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
    public function __construct(
        public readonly object $transaction,
    ) {}

    /**
     * @var list<callable(): void>
     */
    private array $flushListeners = [];

    /**
     * The listener is called at most once: flush() closes the unit of work,
     * and close() discards the listeners without calling them.
     *
     * @param callable(): void $listener
     * @throws UnitOfWorkClosed
     */
    public function onFlush(callable $listener): void
    {
        $this->ensureNotClosed();

        $this->flushListeners[] = $listener;
    }

    /**
     * Writes the changes of every attached repository in the order the repositories were constructed.
     * The unit of work is closed afterwards, whether the flush succeeded or not.
     *
     * @throws UnitOfWorkClosed
     */
    public function flush(): void
    {
        $this->ensureNotClosed();

        try {
            foreach ($this->flushListeners as $flushListener) {
                $flushListener();
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
        $this->flushListeners = [];
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
