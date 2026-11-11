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
            ) ?? throw new DependencyCycle(\sprintf(
                'Entity sets cannot be ordered, they depend on each other in a cycle: %s.',
                implode(', ', array_keys($pending)),
            ));

            $ordered[] = $next;
            unset($pending[$next->class]);
        }

        return $ordered;
    }

    public function close(): void
    {
        foreach ($this->tables as $table) {
            $table->entities->close();
        }

        $this->tables = [];
    }
}
