<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * Another instance of the entity with the same id is already managed by the unit of work.
 *
 * @api
 */
final class DuplicateEntity extends \LogicException implements Exception
{
    /**
     * @param class-string $class
     */
    public static function create(string $class, string $id): self
    {
        return new self(\sprintf('Another instance of %s with id "%s" is already managed by the unit of work.', $class, $id));
    }
}
