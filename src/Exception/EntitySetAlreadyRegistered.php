<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * The entity set of the class is already registered in the unit of work: it has a single owner, normally
 * the repository of the class, and everyone else gets it through that repository.
 *
 * @api
 */
final class EntitySetAlreadyRegistered extends \LogicException implements Exception
{
    /**
     * @param class-string $class
     */
    public static function create(string $class): self
    {
        return new self(\sprintf('Entity set of %s is already registered in the unit of work.', $class));
    }
}
