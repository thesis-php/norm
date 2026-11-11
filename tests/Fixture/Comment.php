<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

final class Comment
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $articleId
     */
    public function __construct(
        public readonly string $id,
        public string $articleId,
        public string $text = 'Text',
    ) {}
}
