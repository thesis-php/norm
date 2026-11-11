<?php

declare(strict_types=1);

namespace Thesis\Norm;

use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\Fixture\Article;
use Thesis\Norm\Fixture\ArticleTable;
use Thesis\Norm\Fixture\Comment;
use Thesis\Norm\Fixture\CommentTable;
use Thesis\Norm\Fixture\DependentTable;
use Thesis\Norm\Fixture\HookedTable;
use Thesis\Norm\Fixture\Storage;

#[Test]
final readonly class BeforeFlushTest
{
    public function hookReceivesEveryManagedEntityWithItsState(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => null];
        $storage->articles['b'] = ['id' => 'b', 'title' => 'Title', 'published_at' => null];
        $storage->articles['c'] = ['id' => 'c', 'title' => 'Title', 'published_at' => null];
        $unitOfWork = new UnitOfWork($storage);
        $calls = [];
        $articles = $unitOfWork->entities(Article::class, new HookedTable(
            new ArticleTable(),
            static function (Article $article, EntityState $state) use (&$calls): void {
                $calls[] = [$article->id, $state];
            },
        ));

        $articles->manageOne($storage->articles['a']);
        $articles->manageOne($storage->articles['b'])->title = 'New title';
        $articles->remove($articles->manageOne($storage->articles['c']));
        $articles->add(new Article('d', 'Title'));
        $articles->add($removed = new Article('e', 'Title'));
        $articles->remove($removed);
        $unitOfWork->flush();

        Assert::same($calls, [
            ['a', EntityState::Unchanged],
            ['b', EntityState::Changed],
            ['c', EntityState::Removed],
            ['d', EntityState::New],
        ]);
    }

    public function changesMadeByHookAreFlushed(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => null];
        $storage->articles['b'] = ['id' => 'b', 'title' => 'Title', 'published_at' => null];
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new HookedTable(
            new ArticleTable(),
            static function (Article $article, EntityState $state): void {
                if ($state === EntityState::Changed) {
                    $article->publishedAt = new \DateTimeImmutable('2026-01-01 00:00:00+00:00');
                }
            },
        ));

        $articles->manageOne($storage->articles['a'])->title = 'New title';
        $articles->manageOne($storage->articles['b']);
        $unitOfWork->flush();

        Assert::same($storage->articles, [
            'a' => ['title' => 'New title', 'id' => 'a', 'published_at' => '2026-01-01 00:00:00+00:00'],
            'b' => ['id' => 'b', 'title' => 'Title', 'published_at' => null],
        ]);
    }

    public function entitiesManagedByHookAreFlushedWithoutHook(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $calls = [];
        $record = static function (object $entity, EntityState $state) use (&$calls): void {
            $calls[] = [$entity::class, $state];
        };
        $articles = $unitOfWork->entities(Article::class, new HookedTable(
            new ArticleTable(),
            static function (Article $article, EntityState $state) use ($unitOfWork, $record): void {
                $record($article, $state);
                $unitOfWork->entities(Comment::class, new HookedTable(new CommentTable(), $record))
                    ->add(new Comment('c', $article->id));
            },
        ));

        $articles->add(new Article('a', 'Title'));
        $unitOfWork->flush();

        Assert::same($calls, [[Article::class, EntityState::New]]);
        Assert::same(array_keys($storage->articles), ['a']);
        Assert::same(array_keys($storage->comments), ['c']);
    }

    public function hooksRunInDependencyOrderAndSeeChangesOfEarlierHooks(): void
    {
        $storage = new Storage();
        $storage->articles['a'] = ['id' => 'a', 'title' => 'Title', 'published_at' => null];
        $storage->comments['c'] = ['id' => 'c', 'article_id' => 'a', 'text' => 'Text'];
        $unitOfWork = new UnitOfWork($storage);
        $calls = [];
        $comments = $unitOfWork->entities(Comment::class, new DependentTable(
            new HookedTable(
                new CommentTable(),
                static function (Comment $comment, EntityState $state) use (&$calls): void {
                    $calls[] = [$comment->id, $state];
                },
            ),
            [Article::class],
        ));
        $articles = $unitOfWork->entities(Article::class, new HookedTable(
            new ArticleTable(),
            static function (Article $article, EntityState $state) use ($unitOfWork, $comments, &$calls): void {
                $calls[] = [$article->id, $state];

                if ($state !== EntityState::Removed) {
                    return;
                }

                foreach ($unitOfWork->transaction->comments as $row) {
                    if ($row['article_id'] === $article->id) {
                        $comments->remove($comments->manageOne($row));
                    }
                }
            },
        ));

        $comments->manageOne($storage->comments['c']);
        $articles->remove($articles->manageOne($storage->articles['a']));
        $unitOfWork->flush();

        Assert::same($calls, [['a', EntityState::Removed], ['c', EntityState::Removed]]);
        Assert::same($storage->articles, []);
        Assert::same($storage->comments, []);
    }

    public function nothingIsWrittenWhenHookThrows(): void
    {
        $storage = new Storage();
        $unitOfWork = new UnitOfWork($storage);
        $articles = $unitOfWork->entities(Article::class, new HookedTable(
            new ArticleTable(),
            static fn(): never => throw new \RuntimeException(),
        ));

        $articles->add(new Article('a', 'Title'));

        try {
            $unitOfWork->flush();
        } catch (\Throwable) {
        }

        Assert::same($storage->log, []);
        Assert::true($unitOfWork->isClosed);
    }

    #[ExpectException(UnitOfWorkClosed::class)]
    public function entitiesCannotBeChangedWhileWriting(): void
    {
        $unitOfWork = new UnitOfWork(new Storage());
        $articles = null;
        $articles = $unitOfWork->entities(Article::class, new HookedTable(
            new ArticleTable(),
            static function (): void {},
            static function () use (&$articles): void {
                $articles?->add(new Article('b', 'Title'));
            },
        ));

        $articles->add(new Article('a', 'Title'));
        $unitOfWork->flush();
    }
}
