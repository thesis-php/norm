<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

/**
 * @phpstan-import-type Row from ArticleTable as ArticleRow
 * @phpstan-import-type Row from CommentTable as CommentRow
 */
final class Storage
{
    /**
     * @var array<non-empty-string, ArticleRow>
     */
    public array $articles = [];

    /**
     * Comments reference articles: a comment cannot be written without its article,
     * an article cannot be deleted while it has comments.
     *
     * @var array<non-empty-string, CommentRow>
     */
    public array $comments = [];

    /**
     * @var list<array{non-empty-string, non-empty-string, non-empty-list<array<mixed>>}> [operation, table, rows]
     */
    public array $log = [];

    /**
     * @param non-empty-string $articleId
     * @throws ForeignKeyViolation
     */
    public function ensureArticleExists(string $articleId): void
    {
        if (!isset($this->articles[$articleId])) {
            throw new ForeignKeyViolation();
        }
    }

    /**
     * @param non-empty-string $articleId
     * @throws ForeignKeyViolation
     */
    public function ensureArticleHasNoComments(string $articleId): void
    {
        foreach ($this->comments as $comment) {
            if ($comment['article_id'] === $articleId) {
                throw new ForeignKeyViolation();
            }
        }
    }
}
