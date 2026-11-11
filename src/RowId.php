<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * Optionally implemented by the table passed to {@see UnitOfWork::entities()} along with {@see Mapper}
 * to take the entity id right from a loaded row. Without it, {@see EntitySet::manageOne()} hydrates
 * every row via {@see Mapper::rowToEntity()} to get its id, even if the entity is already managed.
 *
 * @api
 *
 * @template-contravariant TRow of array<mixed>
 */
interface RowId
{
    /**
     * @param TRow $row
     * @return non-empty-string the same id as {@see Mapper::entityId()} of the row's entity
     */
    public function rowId(array $row): string;
}
