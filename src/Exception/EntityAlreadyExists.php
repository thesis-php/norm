<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;

/**
 * The storage rejected an insert because the entity is already there.
 *
 * Unlike {@see DuplicateEntity}, this is not a programming error: a concurrent
 * transaction may have inserted the same entity in between. Retrying is legitimate.
 *
 * @api
 */
final class EntityAlreadyExists extends \RuntimeException implements Exception {}
