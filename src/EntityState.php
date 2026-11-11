<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * State of a managed entity passed to {@see BeforeFlush::beforeFlush()}.
 *
 * @api
 */
enum EntityState
{
    /**
     * Passed to {@see EntitySet::add()}, not loaded from the storage.
     */
    case New;

    /**
     * Loaded from the storage, {@see Mapper::entityToRow()} matches the snapshot taken when it was loaded.
     */
    case Unchanged;

    /**
     * Loaded from the storage, {@see Mapper::entityToRow()} differs from the snapshot taken when it was loaded.
     */
    case Changed;

    /**
     * Loaded from the storage and passed to {@see EntitySet::remove()}.
     */
    case Removed;
}
