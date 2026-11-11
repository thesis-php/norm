<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * Converts entities of one type to storage rows and back.
 *
 * entityToRow() and rowToEntity() must be mutually inverse: change detection compares
 * entityToRow() results, so a lossy conversion either hides changes or produces spurious updates.
 * Implement {@see RowId} as well to avoid hydrating rows of already managed entities.
 *
 * Rows must consist of scalars, nulls and arrays of them only. Change detection compares rows with `!==`,
 * which compares objects by reference: an object in a row (a date, a value object) would make
 * the entity look changed on every flush. Convert such values to scalars, e.g. dates to strings.
 *
 * @api
 *
 * @template TEntity of object
 * @template TRow of array<mixed> row of scalars, nulls and arrays of them
 */
interface Mapper
{
    /**
     * @param TEntity $entity
     * @return non-empty-string
     */
    public function entityId(object $entity): string;

    /**
     * @param TEntity $entity
     * @return TRow
     */
    public function entityToRow(object $entity): array;

    /**
     * @param TRow $row
     * @return TEntity
     */
    public function rowToEntity(array $row): object;
}
