<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

final class Article
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        public readonly string $id,
        public string $title,
    ) {}
}
