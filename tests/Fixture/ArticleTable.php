<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\Change;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Mapper;
use Thesis\Norm\RowId;
use Thesis\Norm\Writer;

/**
 * @phpstan-type Row = array{id: non-empty-string, title: string, published_at: ?string}
 *
 * @implements Mapper<Article, Row>
 * @implements Writer<Storage, Row>
 * @implements RowId<Row>
 */
final readonly class ArticleTable implements Mapper, Writer, RowId
{
    public function entityId(object $entity): string
    {
        return $entity->id;
    }

    public function entityToRow(object $entity): array
    {
        return [
            'title' => $entity->title,
            'id' => $entity->id,
            'published_at' => $entity->publishedAt?->format('Y-m-d H:i:sP'),
        ];
    }

    public function rowId(array $row): string
    {
        return $row['id'];
    }

    public function rowToEntity(array $row): object
    {
        return new Article(
            id: $row['id'],
            title: $row['title'],
            publishedAt: $row['published_at'] === null ? null : new \DateTimeImmutable($row['published_at']),
        );
    }

    public function insert(object $executor, array $rows): void
    {
        $executor->log[] = ['insert', 'articles', $rows];

        foreach ($rows as $row) {
            if (isset($executor->articles[$row['id']])) {
                throw new EntityAlreadyExists();
            }

            $executor->articles[$row['id']] = $row;
        }
    }

    public function update(object $executor, array $changes): void
    {
        $executor->log[] = ['update', 'articles', array_map(
            static fn(Change $change): array => ['before' => $change->before, 'after' => $change->after],
            $changes,
        )];

        foreach ($changes as $change) {
            $row = $change->after;

            if (!isset($executor->articles[$row['id']])) {
                throw new ConcurrentModification();
            }

            $executor->articles[$row['id']] = $row;
        }
    }

    public function delete(object $executor, array $rows): void
    {
        $executor->log[] = ['delete', 'articles', $rows];

        foreach ($rows as $row) {
            if (!isset($executor->articles[$row['id']])) {
                throw new ConcurrentModification();
            }

            $executor->ensureArticleHasNoComments($row['id']);

            unset($executor->articles[$row['id']]);
        }
    }
}
