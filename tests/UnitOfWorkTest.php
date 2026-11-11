<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Norm\Exception\DependencyCycle;
use Thesis\Norm\Exception\EntitySetAlreadyRegistered;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Fixture\Article;
use Thesis\Norm\Fixture\ArticleTable;
use Thesis\Norm\Fixture\Comment;
use Thesis\Norm\Fixture\CommentTable;
use Thesis\Norm\Fixture\DependentTable;
use Thesis\Norm\Fixture\Storage;
use Thesis\Norm\Fixture\Tag;
use Thesis\Norm\Fixture\TagTable;

#[Test]
final readonly class UnitOfWorkTest
{
    private const array ARTICLE = ['id' => 'a', 'title' => 'Title', 'published_at' => null];
    private const array ARTICLE_X = ['id' => 'x', 'title' => 'Title', 'published_at' => null];
    private const array COMMENT = ['id' => 'c', 'article_id' => 'a', 'text' => 'Text'];

    public function insertsDependenciesFirstRegardlessOfRegistrationOrder(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $comments = $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());

        $comments->add(new Comment('c', 'a'));
        $articles->add(new Article('a', 'Title'));
        $unitOfWork->flush();

        Assert::same(array_keys($storage->articles), ['a']);
        Assert::same(array_keys($storage->comments), ['c']);
    }

    public function deletesDependentsFirstRegardlessOfRegistrationOrder(): void
    {
        $storage = self::storageWithArticleAndComment();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());
        $comments = $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        $articles->remove($articles->manageOne(self::ARTICLE));
        $comments->remove($comments->manageOne(self::COMMENT));
        $unitOfWork->flush();

        Assert::same($storage->articles, []);
        Assert::same($storage->comments, []);
    }

    public function updatesRunAfterAllInsertsAndBeforeAllDeletes(): void
    {
        $storage = self::storageWithArticleAndComment();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());
        $comments = $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        $articles->remove($articles->manageOne(self::ARTICLE));
        $articles->add(new Article('b', 'Title'));
        $comments->manageOne(self::COMMENT)->articleId = 'b';
        $unitOfWork->flush();

        Assert::same(array_keys($storage->articles), ['b']);
        Assert::same($storage->comments, ['c' => ['id' => 'c', 'article_id' => 'b', 'text' => 'Text']]);
        Assert::same(
            array_map(static fn(array $entry): array => [$entry[0], $entry[1]], $storage->log),
            [['insert', 'articles'], ['update', 'comments'], ['delete', 'articles']],
        );
    }

    public function independentEntitySetsAreFlushedInRegistrationOrder(): void
    {
        $storage = self::storageWithArticleAndComment();
        $storage->articles['x'] = self::ARTICLE_X;
        $unitOfWork = new UnitOfWork($storage);
        $comments = $unitOfWork->entities(Comment::class, new CommentTable());
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());

        $comments->add(new Comment('d', 'a'));
        $articles->add(new Article('b', 'Title'));
        $comments->remove($comments->manageOne(self::COMMENT));
        $articles->remove($articles->manageOne(self::ARTICLE_X));
        $unitOfWork->flush();

        Assert::same(
            array_map(static fn(array $entry): array => [$entry[0], $entry[1]], $storage->log),
            [['insert', 'comments'], ['insert', 'articles'], ['delete', 'articles'], ['delete', 'comments']],
        );
    }

    public function selfDependencyIsIgnored(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new DependentTable(new ArticleTable(), [Article::class]));

        $articles->add(new Article('a', 'Title'));
        $unitOfWork->flush();

        Assert::same(array_keys($storage->articles), ['a']);
    }

    public function dependencyOnUnregisteredEntityClassIsIgnored(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = self::ARTICLE;
        $unitOfWork = new UnitOfWork($storage);
        $comments = $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        $comments->add(new Comment('c', 'a'));
        $unitOfWork->flush();

        Assert::same(array_keys($storage->comments), ['c']);
    }

    public function dependencyCycleThrows(): void
    {
        Expect::exception(DependencyCycle::class)->withMessage('Entity sets depend on each other in a cycle: Thesis\Norm\Fixture\Article -> Thesis\Norm\Fixture\Comment -> Thesis\Norm\Fixture\Article.');

        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->entities(Article::class, new DependentTable(new ArticleTable(), [Comment::class]));
        $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        $unitOfWork->flush();
    }

    public function nothingIsWrittenOnDependencyCycle(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $unitOfWork->entities(Article::class, new DependentTable(new ArticleTable(), [Comment::class]))->add(new Article('a', 'Title'));
        $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        try {
            $unitOfWork->flush();
        } catch (DependencyCycle) {
        }

        Assert::same($storage->log, []);
        Assert::true($unitOfWork->isClosed);
    }

    public function dependencyCycleMessageOmitsEntitySetsOutsideOfCycle(): void
    {
        Expect::exception(DependencyCycle::class)->withMessage(\sprintf(
            'Entity sets depend on each other in a cycle: %s -> %s -> %s.',
            Article::class,
            Comment::class,
            Article::class,
        ));

        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->entities(Tag::class, new DependentTable(new TagTable(), [Article::class]));
        $unitOfWork->entities(Article::class, new DependentTable(new ArticleTable(), [Comment::class]));
        $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        $unitOfWork->flush();
    }

    public function dependencyCycleIsFollowedThroughPendingDependenciesOnly(): void
    {
        Expect::exception(DependencyCycle::class)->withMessage(\sprintf(
            'Entity sets depend on each other in a cycle: %s -> %s -> %s.',
            Article::class,
            Comment::class,
            Article::class,
        ));

        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->entities(Article::class, new DependentTable(new ArticleTable(), [Tag::class, Article::class, Comment::class]));
        $unitOfWork->entities(Comment::class, new DependentTable(new CommentTable(), [Article::class]));

        $unitOfWork->flush();
    }

    public function entitiesThrowsForAlreadyRegisteredClass(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->entities(Article::class, new ArticleTable());

        Expect::exception(EntitySetAlreadyRegistered::class)->withMessage(\sprintf(
            'Entity set of %s is already registered in the unit of work.',
            Article::class,
        ));

        $unitOfWork->entities(Article::class, new ArticleTable());
    }

    public function closeIsIdempotent(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());

        $unitOfWork->close();
        $unitOfWork->close();

        Assert::true($unitOfWork->isClosed);
    }

    public function unitOfWorkIsClosedAfterFlush(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());

        $unitOfWork->flush();

        Assert::true($unitOfWork->isClosed);
    }

    public function flushCannotBeRepeated(): void
    {
        Expect::exception(UnitOfWorkClosed::class);

        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->flush();

        $unitOfWork->flush();
    }

    public function closedUnitOfWorkCannotBeFlushed(): void
    {
        Expect::exception(UnitOfWorkClosed::class);

        $unitOfWork = new UnitOfWork(new Storage());
        $unitOfWork->close();

        $unitOfWork->flush();
    }

    public function closedUnitOfWorkWritesNothing(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $unitOfWork->entities(Article::class, new ArticleTable())->add(new Article('a', 'Title'));

        $unitOfWork->close();

        Assert::same($storage->log, []);
    }

    public function flushWithoutChangesDoesNotTouchLazyExecutor(): void
    {
        $storage = self::lazyStorage();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());
        $comments = $unitOfWork->entities(Comment::class, new CommentTable());

        $articles->manageOne(self::ARTICLE);
        $comments->add($comment = new Comment('c', 'a'));
        $comments->remove($comment);
        $unitOfWork->flush();

        Assert::true(new \ReflectionClass(Storage::class)->isUninitializedLazyObject($storage));
    }

    public function flushWithChangesInitializesLazyExecutor(): void
    {
        $storage = self::lazyStorage();
        $unitOfWork = new UnitOfWork($storage);

        $unitOfWork->entities(Article::class, new ArticleTable())->add(new Article('a', 'Title'));
        $unitOfWork->flush();

        Assert::false(new \ReflectionClass(Storage::class)->isUninitializedLazyObject($storage));
        Assert::same(array_keys($storage->articles), ['a']);
    }

    public function changesAreWrittenInBatches(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = self::ARTICLE;
        $storage->articles['x'] = self::ARTICLE_X;
        $storage->articles['y'] = ['id' => 'y', 'title' => 'Title', 'published_at' => null];
        $storage->articles['z'] = ['id' => 'z', 'title' => 'Title', 'published_at' => null];
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());

        $articles->add(new Article('b', 'B'));
        $articles->add(new Article('c', 'C'));
        $articles->manageOne($storage->articles['a'])->title = 'A';
        $articles->manageOne($storage->articles['x'])->title = 'X';
        $articles->remove($articles->manageOne($storage->articles['y']));
        $articles->remove($articles->manageOne($storage->articles['z']));
        $unitOfWork->flush();

        Assert::same($storage->log, [
            ['insert', 'articles', [
                ['title' => 'B', 'id' => 'b', 'published_at' => null],
                ['title' => 'C', 'id' => 'c', 'published_at' => null],
            ]],
            ['update', 'articles', [
                ['before' => ['title' => 'Title', 'id' => 'a', 'published_at' => null], 'after' => ['title' => 'A', 'id' => 'a', 'published_at' => null]],
                ['before' => ['title' => 'Title', 'id' => 'x', 'published_at' => null], 'after' => ['title' => 'X', 'id' => 'x', 'published_at' => null]],
            ]],
            ['delete', 'articles', [
                ['title' => 'Title', 'id' => 'y', 'published_at' => null],
                ['title' => 'Title', 'id' => 'z', 'published_at' => null],
            ]],
        ]);
    }

    public function deleteReceivesSnapshotRatherThanCurrentRow(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => '2026-01-01 00:00:00+00'];
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new ArticleTable());

        $article = $articles->manageOne($storage->articles['a']);
        $article->title = 'New title';
        $articles->remove($article);
        $unitOfWork->flush();

        Assert::same($storage->log, [['delete', 'articles', [
            ['title' => 'Title', 'id' => 'a', 'published_at' => '2026-01-01 00:00:00+00:00'],
        ]]]);
    }

    /**
     * A storage that is initialized on first use, like a transaction begun lazily by a DI container.
     */
    private static function lazyStorage(): Storage
    {
        return new \ReflectionClass(Storage::class)->newLazyGhost(static function (Storage $storage): void {});
    }

    private static function storageWithArticleAndComment(): Storage
    {
        $storage = new Storage();
        $storage->articles['a'] = self::ARTICLE;
        $storage->comments['c'] = self::COMMENT;

        return $storage;
    }
}
