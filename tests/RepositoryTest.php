<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Expect;
use Testo\Test;
use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Exception\EntityNotManaged;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Fixture\Article;
use Thesis\Norm\Fixture\ArticleRepository;
use Thesis\Norm\Fixture\ArticleTable;
use Thesis\Norm\Fixture\Comment;
use Thesis\Norm\Fixture\CommentTable;
use Thesis\Norm\Fixture\Storage;

#[Test]
final readonly class RepositoryTest
{
    private const array ARTICLE = ['id' => 'a', 'title' => 'Title', 'published_at' => null];

    public function addInsertsOnFlush(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $repository->add(new Article('a', 'Title'));

        Assert::same($storage->articles, []);

        $unitOfWork->flush();

        Assert::same($storage->log, [['insert', 'articles', [['title' => 'Title', 'id' => 'a', 'published_at' => null]]]]);
    }

    public function findReturnsSameInstance(): void
    {
        $storage = self::storageWithArticle();
        $repository = new ArticleRepository(new UnitOfWork($storage));

        Assert::same($repository->find('a'), $repository->find('a'));
    }

    public function repositoriesOfOneUnitOfWorkShareEntities(): void
    {
        $unitOfWork = new UnitOfWork(self::storageWithArticle());

        Assert::same(
            new ArticleRepository($unitOfWork)->find('a'),
            new ArticleRepository($unitOfWork)->find('a'),
        );
    }

    public function findReturnsAddedEntity(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(new Storage()));
        $article = new Article('a', 'Title');

        $repository->add($article);

        Assert::same($repository->find('a'), $article);
    }

    public function findReturnsNullForRemovedEntity(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(self::storageWithArticle()));
        $article = $repository->find('a');
        Assert::notNull($article);

        $repository->remove($article);

        Assert::null($repository->find('a'));
    }

    public function findReturnsEntityRemovedAndAddedBack(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(self::storageWithArticle()));
        $article = $repository->find('a');
        Assert::notNull($article);

        $repository->remove($article);
        $repository->add($article);

        Assert::same($repository->find('a'), $article);
    }

    public function findDoesNotLoadManagedEntity(): void
    {
        $articles = new UnitOfWork(new Storage())->entities(Article::class, new ArticleTable());
        $article = $articles->manageOne(self::ARTICLE);

        Assert::same($articles->find('a', static fn(): never => throw new \LogicException('Must not load')), $article);
    }

    public function findLoadsUnmanagedEntity(): void
    {
        $articles = new UnitOfWork(new Storage())->entities(Article::class, new ArticleTable());

        $article = $articles->find('a', static fn(): array => self::ARTICLE);

        Assert::same($articles->manageOne(self::ARTICLE), $article);
    }

    public function rowIsIdentifiedByRowIdWithoutHydration(): void
    {
        $articles = new UnitOfWork(new Storage())->entities(Article::class, new ArticleTable());
        $article = $articles->manageOne(self::ARTICLE);

        // A partial row cannot be hydrated, but rowId() is enough to find the managed entity.
        Assert::same($articles->manageOne(['id' => 'a']), $article);
    }

    public function rowIsIdentifiedByHydratedEntityWithoutRowId(): void
    {
        $comments = new UnitOfWork(new Storage())->entities(Comment::class, new CommentTable());
        $comment = $comments->manageOne(['id' => 'c', 'article_id' => 'a', 'text' => 'Text']);

        Assert::same($comments->manageOne(['id' => 'c', 'article_id' => 'a', 'text' => 'Text']), $comment);
    }

    public function findReturnsNullForMissingRow(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(new Storage()));

        Assert::null($repository->find('a'));
    }

    public function unchangedEntityIsNotUpdated(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $repository->findAll();
        $unitOfWork->flush();

        Assert::same($storage->log, []);
    }

    public function unchangedEntityIsNotUpdatedWhenStorageFormatDiffers(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => '2026-01-01 00:00:00+00'];
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $repository->find('a');
        $unitOfWork->flush();

        Assert::same($storage->log, []);
    }

    public function changedEntityIsUpdated(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $article = $repository->find('a');
        Assert::notNull($article);
        $article->title = 'New';
        $unitOfWork->flush();

        Assert::same($storage->log, [['update', 'articles', [[
            'before' => ['title' => 'Title', 'id' => 'a', 'published_at' => null],
            'after' => ['title' => 'New', 'id' => 'a', 'published_at' => null],
        ]]]]);
    }

    public function updateReceivesNormalizedSnapshotAsBefore(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => '2026-01-01 00:00:00+00'];
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $article = $repository->find('a');
        Assert::notNull($article);
        $article->title = 'New';
        $unitOfWork->flush();

        Assert::same($storage->log, [['update', 'articles', [[
            'before' => ['title' => 'Title', 'id' => 'a', 'published_at' => '2026-01-01 00:00:00+00:00'],
            'after' => ['title' => 'New', 'id' => 'a', 'published_at' => '2026-01-01 00:00:00+00:00'],
        ]]]]);
    }

    public function removedEntityIsDeleted(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $article = $repository->find('a');
        Assert::notNull($article);
        $repository->remove($article);
        $unitOfWork->flush();

        Assert::same($storage->articles, []);
    }

    public function removedThenAddedEntityIsKept(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $article = $repository->find('a');
        Assert::notNull($article);
        $repository->remove($article);
        $repository->add($article);
        $unitOfWork->flush();

        Assert::same($storage->log, []);
    }

    public function addedThenRemovedEntityIsNotInserted(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $article = new Article('a', 'Title');
        $repository->add($article);
        $repository->remove($article);
        $unitOfWork->flush();

        Assert::same($storage->log, []);
    }

    public function addingAnotherEntityWithSameIdThrows(): void
    {
        Expect::exception(DuplicateEntity::class)->withMessage('Another instance of Thesis\Norm\Fixture\Article with id "a" is already managed by the unit of work.');

        $repository = new ArticleRepository(new UnitOfWork(new Storage()));

        $repository->add(new Article('a', 'Title'));
        $repository->add(new Article('a', 'Title'));
    }

    public function addingEntityThatExistsInStorageThrows(): void
    {
        Expect::exception(DuplicateEntity::class)->withMessage('Another instance of Thesis\Norm\Fixture\Article with id "a" is already managed by the unit of work.');

        $repository = new ArticleRepository(new UnitOfWork(self::storageWithArticle()));

        $repository->find('a');
        $repository->add(new Article('a', 'Title'));
    }

    public function removingUnmanagedEntityThrows(): void
    {
        Expect::exception(EntityNotManaged::class)->withMessage('The given instance of Thesis\Norm\Fixture\Article with id "a" is not managed by the unit of work.');

        $repository = new ArticleRepository(new UnitOfWork(new Storage()));

        $repository->remove(new Article('a', 'Title'));
    }

    public function removingAnotherEntityWithSameIdAfterAddAndRemoveThrows(): void
    {
        Expect::exception(EntityNotManaged::class)->withMessage('The given instance of Thesis\Norm\Fixture\Article with id "a" is not managed by the unit of work.');

        $repository = new ArticleRepository(new UnitOfWork(new Storage()));
        $article = new Article('a', 'Title');
        $repository->add($article);
        $repository->remove($article);

        $repository->remove(new Article('a', 'Title'));
    }

    #[ExpectException(EntityNotManaged::class)]
    public function removingAddedEntityTwiceThrows(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(new Storage()));
        $article = new Article('a', 'Title');
        $repository->add($article);
        $repository->remove($article);

        $repository->remove($article);
    }

    public function removingLoadedEntityTwiceIsIdempotent(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);
        $article = $repository->find('a');
        Assert::notNull($article);

        $repository->remove($article);
        $repository->remove($article);
        $unitOfWork->flush();

        Assert::same($storage->articles, []);
    }

    public function anotherEntityWithSameIdCanBeAddedAfterAddAndRemove(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);
        $repository->add($article = new Article('a', 'Title'));
        $repository->remove($article);

        $repository->add(new Article('a', 'New title'));
        $unitOfWork->flush();

        Assert::same($storage->articles, ['a' => ['title' => 'New title', 'id' => 'a', 'published_at' => null]]);
    }

    public function findLoadsEntityAddedAndRemoved(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(self::storageWithArticle()));
        $repository->add($added = new Article('a', 'New title'));
        $repository->remove($added);

        $found = $repository->find('a');

        Assert::notNull($found);
        Assert::notSame($found, $added);
        Assert::same($found->title, 'Title');
    }

    #[ExpectException(EntityAlreadyExists::class)]
    public function storageErrorIsPropagated(): void
    {
        $unitOfWork = new UnitOfWork(self::storageWithArticle());
        $repository = new ArticleRepository($unitOfWork);

        $repository->add(new Article('a', 'Title'));
        $unitOfWork->flush();
    }

    public function repositoryCannotBeUsedAfterFlush(): void
    {
        Expect::exception(UnitOfWorkClosed::class)->withMessage('The unit of work has been flushed or closed and cannot be used anymore.');

        $unitOfWork = new UnitOfWork(new Storage());
        $repository = new ArticleRepository($unitOfWork);
        $unitOfWork->flush();

        $repository->add(new Article('a', 'Title'));
    }

    #[ExpectException(UnitOfWorkClosed::class)]
    public function repositoryCannotBeUsedAfterClose(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());
        $repository = new ArticleRepository($unitOfWork);
        $unitOfWork->close();

        $repository->add(new Article('a', 'Title'));
    }

    #[ExpectException(UnitOfWorkClosed::class)]
    public function repositoryCannotBeCreatedAfterClose(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->close();

        new ArticleRepository($unitOfWork);
    }

    #[ExpectException(UnitOfWorkClosed::class)]
    public function transactionCannotBeAccessedAfterFlush(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->flush();

        $_ = $unitOfWork->transaction;
    }

    #[ExpectException(UnitOfWorkClosed::class)]
    public function transactionCannotBeAccessedAfterClose(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->close();

        $_ = $unitOfWork->transaction;
    }

    public function unitOfWorkIsClosedAfterFailedFlush(): void
    {
        $unitOfWork = new UnitOfWork(self::storageWithArticle());
        $repository = new ArticleRepository($unitOfWork);
        $repository->add(new Article('a', 'Title'));

        try {
            $unitOfWork->flush();
        } catch (\Throwable) {
        }

        Assert::true($unitOfWork->isClosed);
    }

    private static function storageWithArticle(): Storage
    {
        $storage = new Storage();
        $storage->articles['a'] = self::ARTICLE;

        return $storage;
    }
}
