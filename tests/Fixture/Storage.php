<?php

declare(strict_types=1);

namespace Thesis\Norm\Fixture;

/**
 * @phpstan-import-type Row from ArticleRepository
 */
final class Storage
{
    /**
     * @var array<non-empty-string, Row>
     */
    public array $rows = [];

    /**
     * @var list<array{non-empty-string, non-empty-list<Row>}>
     */
    public array $log = [];
}
