<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Repository;

/**
 * @phpstan-type Row = array{id: non-empty-string, title: string}
 *
 * @extends Repository<Storage, Article, Row>
 */
final class ArticleRepository extends Repository
{
    /**
     * @param non-empty-string $id
     */
    public function find(string $id): ?Article
    {
        return $this->manageOne($this->transaction->rows[$id] ?? null);
    }

    /**
     * @return list<Article>
     */
    public function findAll(): array
    {
        return $this->manageAll($this->transaction->rows);
    }

    protected function entityId(object $entity): string
    {
        return $entity->id;
    }

    protected function entityToRow(object $entity): array
    {
        return ['title' => $entity->title, 'id' => $entity->id];
    }

    protected function rowId(array $row): string
    {
        return $row['id'];
    }

    protected function rowToEntity(array $row): object
    {
        return new Article($row['id'], $row['title']);
    }

    protected function insert(array $rows): void
    {
        $this->transaction->log[] = ['insert', $rows];

        foreach ($rows as $row) {
            if (isset($this->transaction->rows[$row['id']])) {
                throw new EntityAlreadyExists();
            }

            $this->transaction->rows[$row['id']] = $row;
        }
    }

    protected function update(array $rows): void
    {
        $this->transaction->log[] = ['update', $rows];

        foreach ($rows as $row) {
            if (!isset($this->transaction->rows[$row['id']])) {
                throw new ConcurrentModification();
            }

            $this->transaction->rows[$row['id']] = $row;
        }
    }

    protected function delete(array $rows): void
    {
        $this->transaction->log[] = ['delete', $rows];

        foreach ($rows as $row) {
            if (!isset($this->transaction->rows[$row['id']])) {
                throw new ConcurrentModification();
            }

            unset($this->transaction->rows[$row['id']]);
        }
    }
}
