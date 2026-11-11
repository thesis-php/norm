<?php

declare(strict_types=1);

namespace Thesis\Norm;

/**
 * A changed row passed to {@see Writer::update()}.
 *
 * Having both versions lets the writer check an optimistic lock against the loaded version
 * (even if the entity increments the version itself), update only the changed columns
 * or diff nested collections of an aggregate.
 *
 * @api
 *
 * @template-covariant TRow of array<mixed>
 */
final readonly class Change
{
    /**
     * @param TRow $before {@see Mapper::entityToRow()} snapshot taken when the entity was loaded
     * @param TRow $after {@see Mapper::entityToRow()} result at flush, different from $before
     */
    public function __construct(
        public array $before,
        public array $after,
    ) {}
}
