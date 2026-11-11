<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\BeforeFlush;
use Thesis\Norm\EntitySet;
use Thesis\Norm\Writer;

/**
 * An entity set registered in a unit of work together with the writer of its changes.
 *
 * @internal
 *
 * @template Tx of object
 */
final readonly class Table
{
    /**
     * @param class-string $class
     * @param EntitySet<object> $entities
     * @param Writer<Tx, array<mixed>> $writer
     * @param list<class-string> $dependencies entity classes this table's rows reference
     * @param ?BeforeFlush<object> $beforeFlush
     */
    public function __construct(
        public string $class,
        public EntitySet $entities,
        public Writer $writer,
        public array $dependencies,
        public ?BeforeFlush $beforeFlush,
    ) {}
}
