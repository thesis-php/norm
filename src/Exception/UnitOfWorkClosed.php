<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * @api
 */
final class UnitOfWorkClosed extends \LogicException implements Exception {}
