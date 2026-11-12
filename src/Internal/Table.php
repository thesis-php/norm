<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\BeforeFlush;
use Thesis\Norm\EntitySet;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Writer;

/**
 * An entity set registered in a unit of work together with the writer of its changes. Both share the row type,
 * so that tables of different row types can be flushed together. The writer is called only for a non-empty batch.
 *
 * @internal
 *
 * @template TExecutor of object
 * @template TRow of array<mixed>
 */
final readonly class Table
{
    /**
     * @param class-string $class
     * @param EntitySet<object, TRow> $entities
     * @param ChangeSet<*, TRow> $changeSet filled by {@see EntitySet::collectChanges()}
     * @param Writer<TExecutor, TRow> $writer
     * @param list<class-string> $dependencies entity classes this table's rows reference
     * @param ?BeforeFlush<object> $beforeFlush
     */
    public function __construct(
        public string $class,
        public EntitySet $entities,
        private ChangeSet $changeSet,
        private Writer $writer,
        public array $dependencies,
        public ?BeforeFlush $beforeFlush,
    ) {}

    /**
     * @param TExecutor $executor
     * @throws EntityAlreadyExists
     */
    public function insert(object $executor): void
    {
        if ($this->changeSet->inserts !== []) {
            $this->writer->insert($executor, $this->changeSet->inserts);
        }
    }

    /**
     * @param TExecutor $executor
     * @throws ConcurrentModification
     */
    public function update(object $executor): void
    {
        if ($this->changeSet->updates !== []) {
            $this->writer->update($executor, $this->changeSet->updates);
        }
    }

    /**
     * @param TExecutor $executor
     * @throws ConcurrentModification
     */
    public function delete(object $executor): void
    {
        if ($this->changeSet->deletes !== []) {
            $this->writer->delete($executor, $this->changeSet->deletes);
        }
    }
}
