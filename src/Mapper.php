<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * Converts entities of one type to storage rows and back.
 *
 * entityToRow() and rowToEntity() must be mutually inverse: change detection compares
 * entityToRow() results, so a lossy conversion either hides changes or produces spurious updates.
 *
 * @api
 *
 * @template TEntity of object
 * @template TRow of array<mixed>
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
     * @return non-empty-string
     */
    public function rowId(array $row): string;

    /**
     * @param TRow $row
     * @return TEntity
     */
    public function rowToEntity(array $row): object;
}
