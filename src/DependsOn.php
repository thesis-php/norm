<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * Optionally implemented by the table passed to {@see UnitOfWork::entities()} along with
 * {@see Mapper} and {@see Writer}, when its rows reference entities of other classes (e.g. via foreign keys).
 * A table without dependencies does not implement it.
 *
 * @api
 */
interface DependsOn
{
    /**
     * @return non-empty-list<class-string>
     */
    public function dependsOn(): array;
}
