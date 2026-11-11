<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

final readonly class Tag
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        public string $id,
    ) {}
}
