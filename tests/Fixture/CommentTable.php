<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\Change;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * @phpstan-type Row = array{id: non-empty-string, article_id: non-empty-string, text: string}
 *
 * @implements Mapper<Comment, Row>
 * @implements Writer<Storage, Row>
 */
final readonly class CommentTable implements Mapper, Writer
{
    public function entityId(object $entity): string
    {
        return $entity->id;
    }

    public function entityToRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'article_id' => $entity->articleId,
            'text' => $entity->text,
        ];
    }

    public function rowToEntity(array $row): object
    {
        return new Comment(
            id: $row['id'],
            articleId: $row['article_id'],
            text: $row['text'],
        );
    }

    public function insert(object $transaction, array $rows): void
    {
        $transaction->log[] = ['insert', 'comments', $rows];

        foreach ($rows as $row) {
            if (isset($transaction->comments[$row['id']])) {
                throw new EntityAlreadyExists();
            }

            $transaction->ensureArticleExists($row['article_id']);
            $transaction->comments[$row['id']] = $row;
        }
    }

    public function update(object $transaction, array $changes): void
    {
        $transaction->log[] = ['update', 'comments', array_map(
            static fn(Change $change): array => ['before' => $change->before, 'after' => $change->after],
            $changes,
        )];

        foreach ($changes as $change) {
            $row = $change->after;

            if (!isset($transaction->comments[$row['id']])) {
                throw new ConcurrentModification();
            }

            $transaction->ensureArticleExists($row['article_id']);
            $transaction->comments[$row['id']] = $row;
        }
    }

    public function delete(object $transaction, array $rows): void
    {
        $transaction->log[] = ['delete', 'comments', $rows];

        foreach ($rows as $row) {
            if (!isset($transaction->comments[$row['id']])) {
                throw new ConcurrentModification();
            }

            unset($transaction->comments[$row['id']]);
        }
    }
}
