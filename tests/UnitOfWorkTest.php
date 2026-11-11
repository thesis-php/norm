<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Norm\Exception\DependencyCycle;
use Thesis\Norm\Fixture\Article;
use Thesis\Norm\Fixture\ArticleTable;
use Thesis\Norm\Fixture\Comment;
use Thesis\Norm\Fixture\CommentTable;
use Thesis\Norm\Fixture\DependentTable;
use Thesis\Norm\Fixture\Storage;

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

    private static function storageWithArticleAndComment(): Storage
    {
        $storage = new Storage();
        $storage->articles['a'] = self::ARTICLE;
        $storage->comments['c'] = self::COMMENT;

        return $storage;
    }
}
