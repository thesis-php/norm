<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * @api
 */
final class UnitOfWorkClosed extends \LogicException implements Exception
{
    public static function create(): self
    {
        return new self('The unit of work has been flushed or closed and cannot be used anymore.');
    }
}
