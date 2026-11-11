<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * @api
 */
final class EntityNotManaged extends \LogicException implements Exception
{
    /**
     * @param class-string $class
     */
    public static function create(string $class, string $id): self
    {
        return new self(\sprintf('The given instance of %s with id "%s" is not managed by the unit of work.', $class, $id));
    }
}
