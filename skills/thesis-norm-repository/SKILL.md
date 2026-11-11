---
name: thesis-norm-repository
description: 'Write tables (Mapper + Writer) and repositories on top of thesis/norm (NORM, Not An ORM): row types, entity-row mapping, batched writes, optimistic and pessimistic locking, finders via EntitySet, the unit of work lifecycle and tests. Use when adding or changing a repository, a table, a finder or persistence of an entity in a project using thesis/norm, or when UnitOfWork, EntitySet, Mapper or Writer are mentioned.'
---

# Writing a repository with thesis/norm

NORM gives an identity map, change detection and an ordered flush; SQL, mapping and writes are hand-written.
The API is pre-1.0: check signatures in `vendor/thesis/norm/src/` first.

- **Entity** — a plain object.
- **Table** — one `final readonly` class implementing `Mapper<TEntity, TRow>` and `Writer<TExecutor, TRow>`.
- **Repository** — a `final readonly` class: gets an `EntitySet` from `UnitOfWork::entities()`, exposes domain finders
  and `add()`/`remove()` if needed.

For a new entity, recommend a locking mode and let the user confirm it (section 5).

## 1. Row type

```php
/**
 * @phpstan-type Row = array{id: non-empty-string, title: string, published_at: ?string, version: positive-int}
 *
 * @implements Mapper<Article, Row>
 * @implements Writer<PostgresLink, Row>
 */
final readonly class ArticleTable implements Mapper, Writer
```

- **Only scalars, nulls and arrays of them.** Rows are compared with `!==`: an object (date, UUID, value object)
  makes the entity look changed on every flush. Format dates, `toString()` UUIDs, `->value` enums.
- The row is what `entityToRow()` returns; `rowToEntity()` must also accept the raw driver row.
- Nested arrays are fine for aggregates (`'lines' => [...]`); diff them in `update()`.

## 2. Mapper

```php
public function entityId(object $entity): string
{
    return $entity->id->toString();
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

- `entityToRow(rowToEntity($row))` must be lossless and stable.
- Hydration has no side effects: no domain events, no validation that can fail on stored data.
- Implement `RowId::rowId(array $row): string` when the id is in the row, to skip hydrating managed entities.

## 3. Writer

Each method gets the unit of work's executor and a non-empty batch. Prepare once, execute per row:

```php
public function insert(object $executor, array $rows): void
{
    $statement = $executor->prepare('insert into article (id, title, published_at, version) values (:id, :title, :published_at, :version)');

    foreach ($rows as $row) {
        try {
            $statement->execute($row);
        } catch (PostgresQueryError $error) {
            if (($error->getDiagnostics()['sqlstate'] ?? null) === '23505') {
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
            'version' => $change->before['version'],
        ]);

        if ($result->getRowCount() !== 1) {
            throw new ConcurrentModification();
        }
    }
}

public function delete(object $executor, array $rows): void
{
    // the same with 'delete from article where id = ? and version = ?' and ConcurrentModification
}
```

- Duplicates by SQLSTATE `23505`, never by the message.
- `update()` gets only changed rows: lock against `before`, write from `after`.
- Stateless: nothing cached between calls.

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
            $this->unitOfWork->executor->execute('select ... where author_id = ? and published_at is null', [$authorId->toString()]),
        );
    }
}
```

- **Every loaded row goes through the entity set**: `find()` by id (sees pending adds and removals, skips the query
  for managed entities), `manageOne()`/`manageAll()` by other criteria (reflect the storage). Never return
  `rowToEntity()` results directly.
- No `save()`: changes are detected. Read `$this->unitOfWork->executor` at query time, not in the constructor.
- **Load only what will change.** Lists, search and reports are query services reading straight into read models;
  `findAll()` through an entity set is an anti-pattern.

## 5. Locking

The table is the same: `update()`/`delete()` check `before['version']`. The finders differ.

**Recommend, then confirm.** Pick a mode from the domain and the code, give a one-line reason and ask the user
to confirm (skip if the user has chosen already or named a `for update` finder):

- **Optimistic** (the default, also when unsure — say so): conflicts are rare (one owner edits it: profile,
  settings, draft), slow work or external calls between read and write, edits via forms (version from the client),
  mostly read.
- **Pessimistic**: a hot row with many concurrent writers (balance, stock, counter, limit, seats), expensive retries,
  check-then-act on data that is not written (lock the parent), workers claiming jobs (`for update skip locked`).
- **Both**: usually calm, but with one or two contended operations (`reserve()`, `decrement()`, `allocate()`).
- Signals: verbs `reserve`/`allocate`/`claim`/`decrement`/`withdraw`, concurrent consumers, quantities changed
  by different users, existing `for update` queries → pessimistic; an owner (`ownerId`) whose commands alone
  change it → optimistic.

**Optimistic: short transactions.** Finders read via a pool injected into the repository instead of the executor.
The unit of work gets a transaction begun lazily (a lazy DI service or a PHP lazy object): `flush()` without changes
does not touch it, and the scope owner commits only a begun one. Selects via a pool are not one snapshot: if a
decision depends on an aggregate that is not written, lock it or bump its version. Compare a client's version
in the domain before the change.

**Pessimistic: lock the row until commit.**

```php
public function lock(UuidInterface $id): ?Article
{
    return $this->articles->manageOne(
        $this->unitOfWork->executor
            ->execute('select id, title, published_at, version from article where id = ? for update', [$id->toString()])
            ->fetchRow(),
    );
}
```

- **Via `$this->unitOfWork->executor`, never via a pool**: in autocommit the lock is released at once.
- **`manageOne()`, never `find()`**: `find()` skips the query for a managed entity and takes no lock.
  Lock before any other load of the aggregate in the unit of work.
- Keep the locked work short, no external calls. Deadlock `40P01` and `lock_not_available` `55P03` come
  from the driver: retry the whole transaction.

## 6. Optional table interfaces

- `DependsOn::dependsOn(): non-empty-list<class-string>` — entity classes referenced by foreign keys: inserts and
  updates go after them, deletes before. Not implemented without dependencies.
- `BeforeFlush::beforeFlush(object $entity, EntityState $state)` — for every managed entity before changes are
  collected: outbox (`releaseEvents()` into another entity set), cascades, `updatedAt` on `Changed`.

## 7. Lifecycle

One unit of work per business transaction, scoped together with its repositories:

```php
$transaction = $postgres->beginTransaction();
$unitOfWork = new UnitOfWork($transaction);

try {
    $result = $work($unitOfWork);
    $unitOfWork->flush();
    $transaction->commit();
} finally {
    $unitOfWork->close();

    // Still active only when an exception propagates before commit(): commit() (even a failed one)
    // and a lost connection end the transaction, rollback() would throw then.
    if ($transaction->isActive()) {
        try {
            $transaction->rollback();
        } catch (\Amp\Sql\SqlException) {
            // ROLLBACK fails only when the connection is lost, Postgres rolls back then:
            // keep the original exception.
        }
    }
}
```

- A unit of work that writes needs a transaction: `flush()` is atomic only within one.
- Not reusable after `flush()` or `close()`, never shared between fibers.
- Retry the whole transaction on `EntityAlreadyExists` and `ConcurrentModification`; `DuplicateEntity`,
  `EntityNotManaged`, `UnitOfWorkClosed`, `DependencyCycle` are bugs.

## 8. Tests

Against a real database (a `compose.yaml` service), rolling back the transaction after each test:

- **Table**: the round trip for every row shape; an unchanged loaded entity writes nothing; a duplicate insert →
  `EntityAlreadyExists`; a stale update/delete → `ConcurrentModification` (build `new Change($before, $after)`).
- **Repository**: `new UnitOfWork($transaction)` per test, load, change, `flush()`, assert rows; `find()` returns
  the same instance twice and sees `add()`/`remove()`; for `lock()`, `for update nowait` from another
  transaction fails with `55P03`.
- **Handlers**: use a repository over a test database; extract an interface only if a fake is really needed.
