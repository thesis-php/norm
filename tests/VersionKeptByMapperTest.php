<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Fixture\Article;
use Thesis\Norm\Fixture\Storage;
use Thesis\Norm\Fixture\VersionedArticleTable;

/**
 * A mapper may keep state of its own per entity, e.g. the version of an optimistic lock that the entity does not have:
 * the snapshot is taken via entityToRow() of the very entity rowToEntity() returned.
 */
#[Test]
final readonly class VersionKeptByMapperTest
{
    private const array ARTICLE = ['id' => 'a', 'title' => 'Title', 'published_at' => null, 'version' => 3];

    public function insertWritesDefaultVersionOfNewEntity(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new VersionedArticleTable());

        $articles->add(new Article('a', 'Title'));
        $unitOfWork->flush();

        Assert::same($storage->articleVersions, ['a' => 1]);
    }

    public function updateChecksVersionLoadedByMapper(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new VersionedArticleTable());

        $articles->manageOne(self::ARTICLE)->title = 'New title';
        $unitOfWork->flush();

        Assert::same($storage->articles, ['a' => ['title' => 'New title', 'id' => 'a', 'published_at' => null]]);
        Assert::same($storage->articleVersions, ['a' => 4]);
    }

    public function updateOfConcurrentlyChangedEntityThrowsConcurrentModification(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new VersionedArticleTable());

        $articles->manageOne(self::ARTICLE)->title = 'New title';
        $storage->articleVersions['a'] = 4;

        Expect::exception(ConcurrentModification::class);

        $unitOfWork->flush();
    }

    public function deleteOfConcurrentlyChangedEntityThrowsConcurrentModification(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new VersionedArticleTable());

        $articles->remove($articles->manageOne(self::ARTICLE));
        $storage->articleVersions['a'] = 4;

        Expect::exception(ConcurrentModification::class);

        $unitOfWork->flush();
    }

    public function reloadedRowOfManagedEntityDoesNotReplaceLoadedVersion(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new VersionedArticleTable());

        $article = $articles->manageOne(self::ARTICLE);
        $storage->articleVersions['a'] = 4;
        $articles->manageOne(['version' => 4] + self::ARTICLE);
        $article->title = 'New title';

        Expect::exception(ConcurrentModification::class);

        $unitOfWork->flush();
    }

    private static function storageWithArticle(): Storage
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => null];
        $storage->articleVersions['a'] = 3;

        return $storage;
    }
}
