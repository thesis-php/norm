<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

use Thesis\Norm\EntitySet;
use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityNotManaged;
use Thesis\Norm\Exception\UnitOfWorkClosed;
use Thesis\Norm\UnitOfWork;

final readonly class ArticleRepository
{
    /**
     * @var EntitySet<Article>
     */
    private EntitySet $articles;

    /**
     * @param UnitOfWork<Storage> $unitOfWork
     * @throws UnitOfWorkClosed
     */
    public function __construct(
        private UnitOfWork $unitOfWork,
    ) {
        $this->articles = $unitOfWork->entities(Article::class, new ArticleTable());
    }

    /**
     * @param non-empty-string $id
     * @throws DuplicateEntity
     * @throws UnitOfWorkClosed
     */
    public function find(string $id): ?Article
    {
        return $this->articles->find($id, fn(): ?array => $this->unitOfWork->transaction->articles[$id] ?? null);
    }

    /**
     * @return list<Article>
     * @throws DuplicateEntity
     * @throws UnitOfWorkClosed
     */
    public function findAll(): array
    {
        return $this->articles->manageAll($this->unitOfWork->transaction->articles);
    }

    /**
     * @throws DuplicateEntity
     * @throws UnitOfWorkClosed
     */
    public function add(Article $article): void
    {
        $this->articles->add($article);
    }

    /**
     * @throws EntityNotManaged
     * @throws UnitOfWorkClosed
     */
    public function remove(Article $article): void
    {
        $this->articles->remove($article);
    }
}
