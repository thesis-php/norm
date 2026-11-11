<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\Change;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Mapper;
use Thesis\Norm\Writer;

/**
 * Adds an optimistic lock to {@see ArticleTable} with the version kept by the mapper rather than by {@see Article}:
 * rowToEntity() remembers the loaded version of each entity, entityToRow() puts it into the row.
 * Versions are stored in {@see Storage::$articleVersions}, the rest of the row is written by ArticleTable.
 *
 * @phpstan-import-type Row from ArticleTable as ArticleRow
 * @phpstan-type Row = array{id: non-empty-string, title: string, published_at: ?string, version: positive-int}
 *
 * @implements Mapper<Article, Row>
 * @implements Writer<Storage, Row>
 */
final readonly class VersionedArticleTable implements Mapper, Writer
{
    private const int DEFAULT_VERSION = 1;

    private ArticleTable $table;

    /**
     * @var \WeakMap<Article, positive-int>
     */
    private \WeakMap $versions;

    public function __construct()
    {
        $this->table = new ArticleTable();
        $this->versions = new \WeakMap();
    }

    public function entityId(object $entity): string
    {
        return $this->table->entityId($entity);
    }

    public function entityToRow(object $entity): array
    {
        return [
            ...$this->table->entityToRow($entity),
            'version' => $this->versions[$entity] ?? self::DEFAULT_VERSION,
        ];
    }

    public function rowToEntity(array $row): object
    {
        $article = $this->table->rowToEntity(self::withoutVersion($row));
        $this->versions[$article] = $row['version'];

        return $article;
    }

    public function insert(object $executor, array $rows): void
    {
        $this->table->insert($executor, array_map(self::withoutVersion(...), $rows));

        foreach ($rows as $row) {
            $executor->articleVersions[$row['id']] = $row['version'];
        }
    }

    public function update(object $executor, array $changes): void
    {
        foreach ($changes as $change) {
            self::checkVersion($executor, $change->before);
        }

        $this->table->update($executor, array_map(
            static fn(Change $change): Change => new Change(
                self::withoutVersion($change->before),
                self::withoutVersion($change->after),
            ),
            $changes,
        ));

        foreach ($changes as $change) {
            $executor->articleVersions[$change->before['id']] = $change->before['version'] + 1;
        }
    }

    public function delete(object $executor, array $rows): void
    {
        foreach ($rows as $row) {
            self::checkVersion($executor, $row);
        }

        $this->table->delete($executor, array_map(self::withoutVersion(...), $rows));

        foreach ($rows as $row) {
            unset($executor->articleVersions[$row['id']]);
        }
    }

    /**
     * @param Row $row
     * @throws ConcurrentModification
     */
    private static function checkVersion(Storage $storage, array $row): void
    {
        if (($storage->articleVersions[$row['id']] ?? null) !== $row['version']) {
            throw new ConcurrentModification();
        }
    }

    /**
     * @param Row $row
     * @return ArticleRow
     */
    private static function withoutVersion(array $row): array
    {
        unset($row['version']);

        return $row;
    }
}
