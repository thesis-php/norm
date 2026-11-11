---
name: norm-repository
description: 'Write a repository on top of thesis/norm (NORM, Not An ORM): an entity table implementing Mapper and Writer (row phpstan-type, entityToRow/rowToEntity round trip, batched insert/update/delete, optimistic lock via version, EntityAlreadyExists on SQLSTATE 23505), a repository class with finders via EntitySet::find()/manageOne()/manageAll(), optional RowId/DependsOn/BeforeFlush, the unit of work lifecycle and tests. Use when the user asks to add or change a repository, a table, a finder or persistence of an entity in a project using thesis/norm, or mentions UnitOfWork, EntitySet, Mapper, Writer.'
---

# Writing a repository with thesis/norm

NORM gives an identity map, change detection and an ordered flush. Everything else is hand-written: SQL,
conversion between entities and rows, and writing rows. Verify signatures against the installed
`vendor/thesis/norm/src/` before writing code — the API is pre-1.0.

## Pieces

| Piece | Who writes it | What it does |
|---|---|---|
| Entity | you | plain object, no base class, no attributes |
| Table: `Mapper<TEntity, TRow>` + `Writer<TExecutor, TRow>` | you, one `final readonly` class | converts entities ↔ rows, writes batches of rows |
| Repository | you, one `final readonly` class | gets an `EntitySet` from the unit of work, exposes domain finders and `add()`/`remove()` |
| `UnitOfWork<TExecutor>` | library | one per business transaction; `entities()`, `flush()`, `close()`, `$executor` |
| `EntitySet<TEntity>` | library | identity map + change tracking of one entity class |

## 1. Row type

Declare the row once as a phpstan-type on the table and import it where needed:

```php
/**
 * @phpstan-type Row = array{id: non-empty-string, title: string, published_at: ?string, version: positive-int}
 *
 * @implements Mapper<Article, Row>
 * @implements Writer<PostgresLink, Row>
 */
final readonly class ArticleTable implements Mapper, Writer
```

Rules:

- **Only scalars, nulls and arrays of them in a row.** Change detection compares rows with `!==`; an object
  (`DateTimeImmutable`, `UuidInterface`, a value object) compares by reference and makes the entity look changed
  on every flush. Format dates to strings, UUIDs via `toString()`, enums via `->value`.
- Keys and value formats are the ones `entityToRow()` produces; they need not match the raw driver row
  (the snapshot is normalized via `entityToRow(rowToEntity($row))`), but `rowToEntity()` must accept the raw row.
- Nested arrays are fine for aggregates (`'lines' => [...]`), diff them in `update()` via `Change::$before`/`$after`.

## 2. Mapper: a lossless round trip

```php
public function entityId(object $entity): string
{
    return $entity->id->toString(); // non-empty, unique within the entity class
}

public function entityToRow(object $entity): array
{
    return [
        'id' => $entity->id->toString(),
        'title' => $entity->title,
        'published_at' => $entity->publishedAt?->format('Y-m-d H:i:sP'),
        'version' => $entity->version,
    ];
}

public function rowToEntity(array $row): object
{
    return new Article(
        id: Uuid::fromString($row['id']),
        title: $row['title'],
        publishedAt: $row['published_at'] === null ? null : new \DateTimeImmutable($row['published_at']),
        version: $row['version'],
    );
}
```

`entityToRow(rowToEntity($row))` must be stable: a lossy conversion hides changes or causes spurious updates.
Hydrate private state without calling domain constructors with side effects (no events recorded on hydration).

If the id can be taken from a row cheaply, also implement `RowId` (`rowId(array $row): string`): otherwise every
loaded row of an already managed entity is hydrated just to get its id.

## 3. Writer: batches, one prepared statement

Every method gets the executor of the unit of work and a non-empty list. Prepare once, execute per row:

```php
private const string UNIQUE_VIOLATION = '23505';

public function insert(object $executor, array $rows): void
{
    $statement = $executor->prepare('insert into article (id, title, published_at, version) values (:id, :title, :published_at, :version)');

    foreach ($rows as $row) {
        try {
            $statement->execute($row);
        } catch (PostgresQueryError $error) {
            if (($error->getDiagnostics()['sqlstate'] ?? null) === self::UNIQUE_VIOLATION) {
                throw new EntityAlreadyExists(previous: $error);
            }

            throw $error;
        }
    }
}

public function update(object $executor, array $changes): void
{
    $statement = $executor->prepare('update article set title = :title, published_at = :published_at, version = version + 1 where id = :id and version = :version');

    foreach ($changes as $change) {
        $result = $statement->execute([
            'id' => $change->before['id'],
            'title' => $change->after['title'],
            'published_at' => $change->after['published_at'],
            'version' => $change->before['version'], // optimistic lock against the loaded version
        ]);

        if ($result->getRowCount() !== 1) {
            throw new ConcurrentModification();
        }
    }
}

public function delete(object $executor, array $rows): void
{
    $statement = $executor->prepare('delete from article where id = ? and version = ?');

    foreach ($rows as $row) { // rows as loaded
        if ($statement->execute([$row['id'], $row['version']])->getRowCount() !== 1) {
            throw new ConcurrentModification();
        }
    }
}
```

- Detect duplicates by SQLSTATE `23505`, never by the error text.
- `update()` receives only changed rows; check the lock against `before`, write values from `after`.
  Writing only the changed columns is possible by comparing `before` and `after`.
- Keep the writer stateless: no caching of statements or entities between calls.

## 4. Repository

```php
final readonly class ArticleRepository
{
    /** @var EntitySet<Article> */
    private EntitySet $articles;

    /** @param UnitOfWork<PostgresLink> $unitOfWork */
    public function __construct(
        private UnitOfWork $unitOfWork,
    ) {
        $this->articles = $unitOfWork->entities(Article::class, new ArticleTable());
    }

    public function find(UuidInterface $id): ?Article
    {
        return $this->articles->find(
            $id->toString(),
            fn(): ?array => $this->unitOfWork->executor
                ->execute('select id, title, published_at, version from article where id = ?', [$id->toString()])
                ->fetchRow(),
        );
    }

    /** @return list<Article> */
    public function findDraftsOf(UuidInterface $authorId): array
    {
        return $this->articles->manageAll(
            $this->unitOfWork->executor->execute('select ... where author_id = ? and published_at is null for update', [$authorId->toString()]),
        );
    }

    public function add(Article $article): void
    {
        $this->articles->add($article);
    }
}
```

- **Every loaded row goes through the entity set**: `find()` for finders by id (sees pending adds and removals,
  does not query for managed entities), `manageOne()`/`manageAll()` for other criteria (reflect the storage).
  Never return `rowToEntity()` results directly — they would bypass the identity map and change detection.
- Add `add()`/`remove()` only if the domain needs them. No `save()`: changes of managed entities are detected.
- Read the executor via `$this->unitOfWork->executor` at query time, not in the constructor.
- **Load only what is going to change** (by id, sometimes `for update`). Lists, search and reports belong to
  separate query services that read straight from the connection into read models, bypassing the unit of work.
  A `findAll()` through an entity set is an anti-pattern.

## 5. Optional table interfaces

- `DependsOn::dependsOn(): non-empty-list<class-string>` — entity classes this table's rows reference by foreign
  key. Then inserts/updates go after them and deletes before them. Do not implement it without dependencies.
- `BeforeFlush::beforeFlush(object $entity, EntityState $state)` — called for every managed entity before changes
  are collected (`New`, `Unchanged`, `Changed`, `Removed`). Use it for outbox (`releaseEvents()` → add messages to
  another entity set), cascades, `updatedAt` on `Changed`. Entities added inside hooks do not get the hook.

## 6. Lifecycle

One unit of work per business transaction, created together with its repositories (scoped in the DI container):

```php
$transaction = $postgres->beginTransaction();
$unitOfWork = new UnitOfWork($transaction);

try {
    $result = $work($unitOfWork);
    $unitOfWork->flush();
    $transaction->commit();
} catch (\Throwable $exception) {
    $unitOfWork->close();
    $transaction->rollback();
    throw $exception;
}
```

After `flush()` (successful or not) or `close()` the unit of work cannot be reused. Never share one between fibers.
`EntityAlreadyExists` and `ConcurrentModification` are worth retrying the whole transaction;
`DuplicateEntity`, `EntityNotManaged`, `UnitOfWorkClosed`, `DependencyCycle` are bugs.

## 7. Tests

- **Table**: test against a real database (a service in `compose.yaml`). For every row shape assert the round trip
  `entityToRow(rowToEntity($row))` and that a loaded and flushed entity without changes produces no queries.
  Test `insert()` of a duplicate → `EntityAlreadyExists`, `update()`/`delete()` of a stale version →
  `ConcurrentModification`. Build `new Change($before, $after)` directly to test `update()`.
- **Repository**: create `new UnitOfWork($transaction)` per test, load via the finder, change, `flush()`, then
  assert rows in the database. Check that `find()` returns the same instance twice and sees `add()`/`remove()`.
- **Domain handlers**: they depend on the repository class; give them a repository over a unit of work on a test
  database, or extract an interface if a fake is really needed.
- Roll back the transaction at the end of each test instead of truncating tables.
