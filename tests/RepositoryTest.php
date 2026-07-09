<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;
use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\Exception\EntityNotManaged;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Fixture\Article;
use Thesis\Norm\Fixture\ArticleRepository;
use Thesis\Norm\Fixture\Storage;

#[Test]
final readonly class RepositoryTest
{
    public function addInsertsOnFlush(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $repository->add(new Article('a', 'Title'));

        Assert::same($storage->rows, []);

        $unitOfWork->flush();

        Assert::same($storage->log, [['insert', [['title' => 'Title', 'id' => 'a']]]]);
    }

    public function findReturnsSameInstance(): void
    {
        $storage = self::storageWithArticle();
        $repository = new ArticleRepository(new UnitOfWork($storage));

        Assert::same($repository->find('a'), $repository->find('a'));
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

    public function changedEntityIsUpdated(): void
    {
        $storage = self::storageWithArticle();
        $unitOfWork = new UnitOfWork($storage);
        $repository = new ArticleRepository($unitOfWork);

        $article = $repository->find('a');
        Assert::notNull($article);
        $article->title = 'New';
        $unitOfWork->flush();

        Assert::same($storage->log, [['update', [['title' => 'New', 'id' => 'a']]]]);
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

        Assert::same($storage->rows, []);
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

    #[ExpectException(DuplicateEntity::class)]
    public function addingAnotherEntityWithSameIdThrows(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(new Storage()));

        $repository->add(new Article('a', 'Title'));
        $repository->add(new Article('a', 'Title'));
    }

    #[ExpectException(DuplicateEntity::class)]
    public function addingEntityThatExistsInStorageThrows(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(self::storageWithArticle()));

        $repository->find('a');
        $repository->add(new Article('a', 'Title'));
    }

    #[ExpectException(EntityNotManaged::class)]
    public function removingUnmanagedEntityThrows(): void
    {
        $repository = new ArticleRepository(new UnitOfWork(new Storage()));

        $repository->remove(new Article('a', 'Title'));
    }

    #[ExpectException(EntityAlreadyExists::class)]
    public function storageErrorIsPropagated(): void
    {
        $unitOfWork = new UnitOfWork(self::storageWithArticle());
        $repository = new ArticleRepository($unitOfWork);

        $repository->add(new Article('a', 'Title'));
        $unitOfWork->flush();
    }

    #[ExpectException(UnitOfWorkClosed::class)]
    public function repositoryCannotBeUsedAfterFlush(): void
    {
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
        $storage->rows['a'] = ['id' => 'a', 'title' => 'Title'];

        return $storage;
    }
}
