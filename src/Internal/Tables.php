<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\Exception\DependencyCycle;

/**
 * Tables of a unit of work, one per entity class, in the order of registration.
 *
 * @internal
 *
 * @template Tx of object
 */
final class Tables
{
    /**
     * @var array<class-string, Table<Tx>>
     */
    private array $tables = [];

    /**
     * @param class-string $class
     * @return ?Table<Tx>
     */
    public function get(string $class): ?Table
    {
        return $this->tables[$class] ?? null;
    }

    /**
     * @param Table<Tx> $table
     */
    public function add(Table $table): void
    {
        $this->tables[$table->class] = $table;
    }

    /**
     * Every table goes after the tables it depends on, otherwise in the order of registration.
     * Dependencies on itself and on entity classes that are not registered are ignored.
     *
     * @return list<Table<Tx>>
     * @throws DependencyCycle
     */
    public function inDependencyOrder(): array
    {
        $ordered = [];
        $pending = $this->tables;

        while ($pending !== []) {
            $next = array_find(
                $pending,
                static fn(Table $table): bool => array_all(
                    $table->dependencies,
                    static fn(string $dependency): bool => $dependency === $table->class || !isset($pending[$dependency]),
                ),
            ) ?? throw DependencyCycle::create(self::findCycle($pending));

            $ordered[] = $next;
            unset($pending[$next->class]);
        }

        return $ordered;
    }

    /**
     * Every pending table has a pending dependency other than itself, so following them leads to a cycle.
     *
     * @param non-empty-array<class-string, Table<Tx>> $pending
     * @return non-empty-list<class-string> the cycle with its first class repeated at the end
     */
    private static function findCycle(array $pending): array
    {
        $path = [];
        $class = array_key_first($pending);

        while (!\in_array($class, $path, true)) {
            $path[] = $class;
            $class = array_find(
                $pending[$class]->dependencies ?? [],
                static fn(string $dependency): bool => $dependency !== $class && isset($pending[$dependency]),
            ) ?? throw new \LogicException('Unreachable: a pending table has no pending dependencies.');
        }

        return [...\array_slice($path, (int) array_search($class, $path, true)), $class];
    }

    public function close(): void
    {
        foreach ($this->tables as $table) {
            $table->entities->close();
        }

        $this->tables = [];
    }
}
