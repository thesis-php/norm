<?php

declare(strict_types=1);

namespace Thesis\Norm\Exception;

use Thesis\Norm\Exception;
use Thesis\Norm\Writer;

/**
 * Thrown by {@see Writer::update()} or {@see Writer::delete()} when a row was changed
 * or deleted by a concurrent transaction, e.g. an optimistic lock check affected no row.
 * Retrying the whole business transaction is legitimate.
 *
 * @api
 */
final class ConcurrentModification extends \RuntimeException implements Exception {}
