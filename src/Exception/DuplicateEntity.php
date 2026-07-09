<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * Two different entities with the same id were registered within one unit of work.
 *
 * Unlike {@see EntityAlreadyExists}, this is detected in memory and always means a bug.
 *
 * @api
 */
final class DuplicateEntity extends \LogicException implements Exception {}
