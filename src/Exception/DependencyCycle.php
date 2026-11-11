<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * @api
 */
final class DependencyCycle extends \LogicException implements Exception
{
    /**
     * @param non-empty-list<class-string> $cycle entity classes, the first one repeated at the end
     */
    public static function create(array $cycle): self
    {
        return new self(\sprintf('Entity sets depend on each other in a cycle: %s.', implode(' -> ', $cycle)));
    }
}
