<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * @phpstan-type Row = array{id: non-empty-string, title: string, published_at: ?string}
 *
 * @implements Mapper<Article, Row>
 * @implements Writer<Storage, Row>
 */
final readonly class ArticleTable implements Mapper, Writer
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

    public function insert(object $transaction, array $rows): void
    {
        $transaction->log[] = ['insert', $rows];

        foreach ($rows as $row) {
            if (isset($transaction->rows[$row['id']])) {
                throw new EntityAlreadyExists();
            }

            $transaction->rows[$row['id']] = $row;
        }
    }

    public function update(object $transaction, array $rows): void
    {
        $transaction->log[] = ['update', $rows];

        foreach ($rows as $row) {
            if (!isset($transaction->rows[$row['id']])) {
                throw new ConcurrentModification();
            }

            $transaction->rows[$row['id']] = $row;
        }
    }

    public function delete(object $transaction, array $rows): void
    {
        $transaction->log[] = ['delete', $rows];

        foreach ($rows as $row) {
            if (!isset($transaction->rows[$row['id']])) {
                throw new ConcurrentModification();
            }

            unset($transaction->rows[$row['id']]);
        }
    }
}
