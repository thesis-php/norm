# Thesis NORM: Not An ORM

NORM is a minimal unit of work for hand-written repositories. It gives you an identity map, change detection
and an ordered flush — and nothing else: no mapping metadata, no query builder, no proxies, no lazy loading.
You write the SQL (or whatever your storage speaks), the conversion between entities and rows,
and the code that writes rows. NORM decides **what** to write and **when**.

**Built for coding agents:** what an ORM generates, an agent writes as plain code,
see [why](#why-norm-fits-coding-agents).

|                          | ORM                                 | NORM                                                    |
|--------------------------|-------------------------------------|---------------------------------------------------------|
| Mapping                  | attributes / XML / reflection       | your `entityToRow()` / `rowToEntity()`                  |
| Queries                  | query builder, DQL, criteria        | your SQL, executed by your driver                       |
| Writes                   | generated SQL                       | your `insert()` / `update()` / `delete()`, batched      |
| Identity map             | yes                                 | yes, per entity class                                   |
| Change detection         | yes                                 | yes, by comparing rows                                  |
| Relations, lazy loading  | yes                                 | no: load aggregates explicitly                          |

## Why NORM fits coding agents

Boilerplate is cheap for an agent, hidden magic is not. NORM does only the hard part — identity map, change detection,
flush order — the rest is plain code:

- **Nothing is hidden.** Mapping, queries and writes are PHP an agent reads and debugs like any other code.
- **SQL made for the entity.** Use everything your database offers (`json_agg`, `jsonb`, `unnest`, `skip locked`),
  not the subset an ORM supports.
- **Small contract.** Two classes and two interfaces at the core, three optional table interfaces
  and a ready [`thesis-norm-repository`](skills/thesis-norm-repository/SKILL.md) skill.
- **Checked by tools.** PHPStan generics tie rows, mappers and writers together; misuse throws a dedicated exception.
- **Every table looks the same.** One is a template for the next.

## Installation

```shell
composer require thesis/norm
```

### AI skill

The package ships the [`thesis-norm-repository`](skills/thesis-norm-repository/SKILL.md) skill that teaches coding agents
to write tables and repositories. With [`llm/skills`](https://github.com/roxblnfk/skills) installed in your project
it is synced automatically.

## Quick start

An entity is a plain object:

```php
final class Identity
{
    public function __construct(
        public readonly UuidInterface $id,
        public private(set) string $passwordHash,
        public readonly int $version,
    ) {}
}
```

A **table** converts entities to rows and back ([`Mapper`](src/Mapper.php)) and writes rows to the storage
([`Writer`](src/Writer.php)). Usually one class implements both:

```php
/**
 * @phpstan-type Row = array{
 *     id: non-empty-string,
 *     password_hash: non-empty-string,
 *     version: positive-int,
 * }
 * @implements Mapper<Identity, Row>
 * @implements Writer<PostgresLink, Row>
 */
final readonly class IdentityTable implements Mapper, Writer
{
    public function entityId(object $entity): string
    {
        return $entity->id->toString();
    }

    public function entityToRow(object $entity): array
    {
        return [
            'id' => $entity->id->toString(),
            'password_hash' => $entity->passwordHash,
            'version' => $entity->version,
        ];
    }

    public function rowToEntity(array $row): object
    {
        return new Identity(
            id: Uuid::fromString($row['id']),
            passwordHash: $row['password_hash'],
            version: $row['version'],
        );
    }

    public function insert(object $executor, array $rows): void
    {
        // insert into identity ...
    }

    public function update(object $executor, array $changes): void
    {
        // update identity ... where version = :before.version
    }

    public function delete(object $executor, array $rows): void
    {
        // delete from identity ...
    }
}
```

A **repository** is your own class. It gets the [`EntitySet`](src/EntitySet.php) of its entity class
from the unit of work and exposes only what the domain needs:

```php
final readonly class IdentityRepository
{
    /** @var EntitySet<Identity> */
    private EntitySet $identities;

    /** @param UnitOfWork<PostgresLink> $unitOfWork */
    public function __construct(
        private UnitOfWork $unitOfWork,
    ) {
        $this->identities = $unitOfWork->entities(Identity::class, new IdentityTable());
    }

    public function find(UuidInterface $id): ?Identity
    {
        return $this->identities->find(
            id: $id->toString(),
            load: fn(): ?array => $this
                ->unitOfWork
                ->executor
                ->execute(
                    <<<'SQL'
                        select id, password_hash, version
                        from identity
                        where id = ?
                        SQL,
                    [$id->toString()],
                )
                ->fetchRow(),
        );
    }

    public function add(Identity $identity): void
    {
        $this->identities->add($identity);
    }
}
```

A **unit of work** is one business transaction. Create it together with its repositories, change entities,
flush once:

```php
$transaction = $postgres->beginTransaction();
$unitOfWork = new UnitOfWork($transaction);

try {
    $identity = new IdentityRepository($unitOfWork)->find($id) ?? throw new NotRegistered();
    $identity->changePassword($oldPassword, $newPassword);

    $unitOfWork->flush(); // the changed row is passed to IdentityTable::update()
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

Wrap this lifecycle in a helper once — see [`Transactor`](examples/authentication/Transactor.php) and the runnable
[authentication example](examples/authentication) (`make example` runs it against Postgres).

## Contract

### `UnitOfWork<TExecutor>`

- `new UnitOfWork($executor)` — `$executor` is whatever runs your queries, normally a transaction: `flush()` is
  atomic only within one, see [Guarantees](#guarantees). A connection or a pool fits a unit of work that only reads.
  To keep transactions short, pass a lazily begun one, see [Locking](#locking).
  It is available as `$unitOfWork->executor` and is passed to every `Writer` call.
- `entities(class-string $class, Mapper&Writer $table): EntitySet` — the entity set of the class. Every call with
  the same class returns the same set, so all repositories of one unit of work share one identity map per class.
  The table of the first call wins.
- `flush()` — writes all changes once, see [Guarantees](#guarantees).
- `close()` — abandons the unit of work without writing anything.

A unit of work is not safe for concurrent use: create one per scope (a request, a message, a command)
and do not share it between fibers.

### `EntitySet<TEntity>`

- `find($id, $load)` — for finders by id. Returns an entity passed to `add()` or already loaded without calling
  `$load`, `null` for a removed one, otherwise registers the row returned by `$load`.
- `manageOne(?array $row)` / `manageAll(iterable $rows)` — for finders by other criteria. Register rows loaded
  from the storage and return their entities; an already managed entity is returned as is, its row is ignored.
  They reflect the storage, not pending changes: a removed entity is still returned, an added one is not found.
- `add($entity)` — the entity will be inserted. Adding a removed entity back cancels the removal.
- `remove($entity)` — a loaded entity will be deleted; an added one is just forgotten.

### `Mapper<TEntity, TRow>`

- `entityId()` — a non-empty string unique among entities of the class.
- `entityToRow()` and `rowToEntity()` must be mutually inverse: change detection compares `entityToRow()` results.
- **Rows contain only scalars, nulls and arrays of them.** Rows are compared with `!==`, which compares objects
  by reference: a `DateTimeImmutable` in a row makes the entity look changed on every flush. Format such values.

### `Writer<TExecutor, TRow>`

Stateless; every method receives the executor and a non-empty batch:

- `insert($executor, list<TRow> $rows)` — throw `EntityAlreadyExists` when the storage rejects a duplicate
  (e.g. SQLSTATE `23505`);
- `update($executor, list<Change<TRow>> $changes)` — only the rows that differ from their snapshots;
  `$change->before` is the row as loaded, `$change->after` the row now. Check optimistic locks against `before`,
  write only the changed columns or diff nested collections of an aggregate;
- `delete($executor, list<TRow> $rows)` — the rows as loaded.

Throw `ConcurrentModification` when an update or a delete affects no row.

### Optional table interfaces

- [`RowId`](src/RowId.php) — `rowId(TRow $row)` takes the id right from a row, so that rows of already managed
  entities are not hydrated just to get their id.
- [`DependsOn`](src/DependsOn.php) — `dependsOn()` lists the entity classes the table's rows reference
  (e.g. via foreign keys), see [Guarantees](#guarantees).
- [`BeforeFlush`](src/BeforeFlush.php) — `beforeFlush($entity, EntityState $state)` is called for every managed
  entity before changes are collected, with its state: `New`, `Unchanged`, `Changed` or `Removed`. Use it to release
  domain events into an outbox, cascade removals or touch `updatedAt`. A hook may change, add, remove and load
  entities of any entity set; entities that were not managed when the flush started do not get the hook.

## Guarantees

- **Identity map.** Within a unit of work, one row gives one object. Adding another object with the id
  of a managed entity throws `DuplicateEntity`.
- **Change detection.** A snapshot is taken as `entityToRow()` of the freshly loaded entity, not as the raw row,
  so storage-specific representations (date formats, `'1'` vs `1`, extra selected columns) do not cause spurious
  updates. Unchanged entities are not written.
- **Flush order.** `BeforeFlush` hooks run first. Then all inserts, then all updates, then all deletes. Inserts
  and updates go in the dependency order of entity sets (`DependsOn`), deletes in the reverse one; independent
  entity sets keep the order of registration. A dependency cycle throws `DependencyCycle` before anything is
  written. Rows within one batch are ordered by the writer.
- **Atomicity — only via a transaction.** The unit of work neither begins nor commits transactions, it just passes
  its executor to every `Writer` call. If the executor is a transaction, commit it after `flush()` and roll it back
  on failure, and the flush is all or nothing. With a connection in autocommit mode or a pool, a failed write
  (e.g. `ConcurrentModification` on update) leaves the earlier writes in the storage, and the closed unit of work
  cannot retry the rest.
- **No changes, no writes.** The executor is used only by the writers, and a writer is called only for a non-empty
  batch. A flush without changes does not touch the executor, so a lazily begun transaction is not begun at all,
  see [Locking](#locking).
- **Closing.** The unit of work is closed once the changes are collected, so entities cannot be changed while
  they are being written. After `flush()` — successful or not — or `close()`, `flush()`, `entities()`, `$executor`
  and every entity set throw `UnitOfWorkClosed`, so finders do not query on behalf of a finished business transaction.

## Locking

The table and the writer are the same for both kinds of locks: `update()` and `delete()` check the version
loaded into `$change->before` and throw `ConcurrentModification`. What differs is how the repository reads
and when the transaction begins.

### Optimistic: short transactions, the default

The version check catches concurrent changes, so reading does not need a transaction:

- Finders read via a pool injected into the repository, not via `$unitOfWork->executor`.
- The executor of the unit of work is a transaction begun lazily, on first use: a lazy service of your DI container
  or a PHP lazy object. A flush without changes does not touch it, so a business transaction that changes nothing
  never begins a database transaction, and the one that does holds it only while writing.
- Whoever owns the scope (a middleware of the bus or the HTTP kernel) commits or rolls the transaction back only if
  it has begun: `ReflectionClass::isUninitializedLazyObject()` or a flag of its own.

```php
final readonly class IdentityRepository
{
    /** @var EntitySet<Identity> */
    private EntitySet $identities;

    /** @param UnitOfWork<PostgresTransaction> $unitOfWork on a lazily begun transaction */
    public function __construct(
        private UnitOfWork $unitOfWork,
        private PostgresConnectionPool $postgres,
    ) {
        $this->identities = $unitOfWork->entities(Identity::class, new IdentityTable());
    }

    public function find(UuidInterface $id): ?Identity
    {
        return $this->identities->find(
            id: $id->toString(),
            load: fn(): ?array => $this->postgres
                ->execute('select id, password_hash, version from identity where id = ?', [$id->toString()])
                ->fetchRow(),
        );
    }
}
```

- Selects via a pool do not see one snapshot: two of them may see different states of the storage. The version
  protects only the rows that are written; if a decision depends on an aggregate that is not changed, lock it
  or bump its version too.
- A version that comes from the client (a form field, `If-Match`) is compared by the domain before changing
  the entity; the writer then checks the loaded one.

### Pessimistic: lock for the whole business transaction

For aggregates under contention, where waiting is cheaper than retrying (a balance, a stock, a counter):

```php
public function lock(UuidInterface $id): ?Identity
{
    return $this->identities->manageOne(
        $this->unitOfWork->executor
            ->execute('select id, password_hash, version from identity where id = ? for update', [$id->toString()])
            ->fetchRow(),
    );
}
```

- **Lock via `$unitOfWork->executor`, never via a pool.** In autocommit mode the lock is released right after
  the select, silently. A lazily begun transaction begins at the lock and lasts until the commit: keep this work
  short and do not call external services inside.
- **Register via `manageOne()`, not `find()`.** `find()` does not query for an entity that is already managed
  and would return it without taking the lock. `manageOne()` always runs the query; if the entity was loaded
  earlier without a lock, the row is ignored and the stale entity is returned, but the version check on update
  still catches a concurrent change. Lock an aggregate before any other load of it in the unit of work.
- Deadlocks (SQLSTATE `40P01`) and `lock_not_available` (`55P03`, with `nowait` or `lock_timeout`) are thrown by your
  driver, not by NORM: retry the whole transaction, like on `ConcurrentModification`. For queues use `skip locked`.

## Writing is not reading for display

Repositories load only the aggregates that are going to change: by id, sometimes [`for update`](#locking).
Every managed entity costs a hydration, a snapshot and a comparison on flush. Lists, search results and reports are read
by separate query services directly via the connection into read models, bypassing the unit of work.
A `findAll()` through an entity set is an anti-pattern.

## Exceptions

Every exception implements `Thesis\Norm\Exception`.

| Exception                | Thrown by                            | Meaning                                            | Retry?                     |
|--------------------------|--------------------------------------|----------------------------------------------------|----------------------------|
| `EntityAlreadyExists`    | your `Writer::insert()`              | a concurrent transaction inserted the same entity  | yes, the whole transaction |
| `ConcurrentModification` | your `Writer::update()` / `delete()` | the row was changed or deleted concurrently        | yes, the whole transaction |
| `DuplicateEntity`        | `EntitySet`                          | another object with the same id is already managed | no, a bug                  |
| `EntityNotManaged`       | `EntitySet::remove()`                | the object is not managed by the unit of work      | no, a bug                  |
| `UnitOfWorkClosed`       | `UnitOfWork`, `EntitySet`            | the unit of work is used after flush or close      | no, a bug                  |
| `DependencyCycle`        | `UnitOfWork::flush()`                | `DependsOn` declarations form a cycle              | no, a bug                  |
