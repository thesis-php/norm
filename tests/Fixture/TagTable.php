<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * Only logs writes: tags are used to test the order of entity sets.
 *
 * @phpstan-type Row = array{id: non-empty-string}
 *
 * @implements Mapper<Tag, Row>
 * @implements Writer<Storage, Row>
 */
final readonly class TagTable implements Mapper, Writer
{
    public function entityId(object $entity): string
    {
        return $entity->id;
    }

    public function entityToRow(object $entity): array
    {
        return ['id' => $entity->id];
    }

    public function rowToEntity(array $row): object
    {
        return new Tag($row['id']);
    }

    public function insert(object $executor, array $rows): void
    {
        $executor->log[] = ['insert', 'tags', $rows];
    }

    public function update(object $executor, array $changes): void
    {
        $executor->log[] = ['update', 'tags', array_map(static fn($change): array => $change->after, $changes)];
    }

    public function delete(object $executor, array $rows): void
    {
        $executor->log[] = ['delete', 'tags', $rows];
    }
}
