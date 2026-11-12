# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.1] 2026-11-12

### Changed

- **BC Break:** `EntitySet` gets the row type of its table as the second template parameter, `EntitySet<TEntity, TRow>`, returned by `UnitOfWork::entities()`. `find()`, `manageOne()` and `manageAll()` accept `TRow` instead of any array, so PHPStan reports rows that do not match the table. Rows of a database driver are untyped: bring them to `TRow` in the repository, e.g. with a `@var` in one private method that runs the select. Update `@var EntitySet<Entity>` to `@var EntitySet<Entity, Row>`.

## [0.4.0] 2026-11-12

The abstract `Repository` is split into composable parts: a table converts and writes rows, an entity set tracks entities, and the repository is your own class.

### Added

- Add `Mapper` and `Writer` interfaces: implement them (usually in one class, a table) to convert entities to rows and to write rows to the storage.
- Add `UnitOfWork::entities()` returning an `EntitySet` with `add()`, `remove()`, `manageOne()` and `manageAll()`. It can be called once per entity class, normally by the repository of the class: a second call throws `Exception\EntitySetAlreadyRegistered`.
- Add `EntitySet::find()` for finders by id: it returns an entity passed to `add()` or already loaded without querying the storage and `null` for a removed entity, otherwise it loads and registers the row. Finders by other criteria keep reflecting the storage.
- Add optional `RowId` interface: implement it in a table to take the entity id right from a loaded row. Without it, `EntitySet::manageOne()` gets the id of a row by hydrating it.
- Add `DependsOn` interface: implement it in a table whose rows reference other entities, so that `UnitOfWork::flush()` inserts and updates them after the referenced ones and deletes them before. A dependency cycle throws `Exception\DependencyCycle` naming the classes of the cycle.
- Add `BeforeFlush` interface: implement it in a table to act on every managed entity right before `UnitOfWork::flush()` collects changes (release events into an outbox, cascade removals, touch `updatedAt`). The hook receives the entity and its `EntityState` (`New`, `Unchanged`, `Changed`, `Removed`) and may change, add, remove and load entities of any entity set.
- Add `Change` passed to `Writer::update()` with the row as loaded (`before`) and as it is now (`after`).
- Add named constructors to `DuplicateEntity`, `EntityNotManaged` and `UnitOfWorkClosed`, which are now thrown with descriptive messages: `DuplicateEntity` and `EntityNotManaged` name the entity class and id.
- Guarantee that `UnitOfWork::flush()` without changes does not touch the executor, so a lazily begun transaction is not begun at all.
- Add `thesis-norm-repository` AI skill (`skills/thesis-norm-repository/SKILL.md`) describing how to write tables and repositories, distributed with the package and synced by [`llm/skills`](https://github.com/roxblnfk/skills).

### Changed

- **BC Break:** `UnitOfWork::$transaction` is renamed to `$executor` and the `Tx` template to `TExecutor`: a unit of work may run on a transaction, a connection or a pool.
- **BC Break:** Accessing `UnitOfWork::$executor` after `flush()` or `close()` throws `UnitOfWorkClosed`, so finders do not query on behalf of a closed unit of work.
- **BC Break:** `Writer::update()` receives a list of `Change` objects instead of a list of rows, so that optimistic locks can check the loaded version and only the changed columns can be written.
- **BC Break:** The snapshot of a loaded entity is now the result of `entityToRow()` right after loading instead of the raw row, so storage-specific representations (date formats, scalar types, extra columns) do not cause spurious updates. `Writer::delete()` and `Change::$before` receive this snapshot: columns that are selected but not returned by `entityToRow()` no longer reach the writer.
- **BC Break:** `UnitOfWork::flush()` writes in phases: all inserts, then all updates, then all deletes (in the reverse order), instead of flushing repositories one by one. Hierarchies with foreign keys can now be inserted and deleted in one unit of work.
- The unit of work is closed once the changes are collected, so entities cannot be changed while they are being written.

### Removed

- **BC Break:** Remove the abstract `Repository`. Implement `Mapper` (`entityId()`, `entityToRow()`, `rowToEntity()`, optionally `RowId::rowId()`) and `Writer` (`insert()`, `update()`, `delete()`, now receiving the executor) in a table, and write your own repository class on top of `UnitOfWork::entities()`, registering loaded rows via `EntitySet::find()`, `manageOne()` or `manageAll()`.
- **BC Break:** Remove `UnitOfWork::onFlush()`. Implement `BeforeFlush` in a table to act on entities before the flush.

### Fixed

- Removing an entity passed to `add()` forgets it, so removing it again or removing another instance with the same id throws `EntityNotManaged` instead of being silently accepted, as `Repository::remove()` did.

## [0.3.0] 2026-10-29

The library is rethought as NORM (Not An ORM): a minimal unit of work on top of hand-written repositories, with no mapping or query layer.

### Added

- Add `Thesis\Norm\Exception` marker interface implemented by every exception of the package.
- Add `Exception\EntityAlreadyExists` to be thrown by `Repository::insert()` when the storage rejects a duplicate.
- Add `UnitOfWork::onFlush()` and `UnitOfWork::$isClosed`.
- Add `Repository::manageOne()` and `Repository::manageAll()` to register loaded rows in the unit of work.
- Unchanged entities are no longer passed to `update()`: changes are detected by comparing the result of `entityToRow()` with the loaded row.

### Changed

- **BC Break:** The package is renamed from `thesis/orm` to `thesis/norm`, the namespace from `Thesis\ORM` to `Thesis\Norm`.
- **BC Break:** `Repository` is now an abstract class to extend. Implement `entityId()`, `entityToRow()`, `rowId()`, `rowToEntity()`, `insert()`, `update()` and `delete()`; write finder methods yourself using `$this->transaction`.
- **BC Break:** `Repository` is constructed with a `UnitOfWork` and attaches itself to it, `UnitOfWork::repository()` is removed.
- **BC Break:** `Repository::findBy()` and `Repository::key()` are removed: add your own finders that call `manageOne()`/`manageAll()`.
- **BC Break:** Inserts, updates and deletes are passed to the repository in batches.

### Removed

- **BC Break:** Remove `EntityManager`. Begin and commit transactions yourself.
- **BC Break:** Remove `Persister` and `Persister\InMemory`. Implement storage access in your `Repository` subclass instead.

### Fixed

- Repositories now throw `UnitOfWorkClosed` after `UnitOfWork::close()` or a failed `UnitOfWork::flush()`.

## [0.2.0] 2026-02-22

### Added

- Add `Persister\InMemory` for testing.
- Add `UnitOfWork::close()` method.

### Changed

- **BC Break:** `Thesis\ORM\Transaction` moved to `Thesis\Transaction` in a separate [thesis/transaction](https://github.com/thesis-php/transaction) package.
- **BC Break:** Flip order of `$class` and `$persister` parameters in `Repository::__construct()` and `UnitOfWork::repository()`.

### Fixed

- `UnitOfWork` is now always closed.

[0.4.1]: https://github.com/thesis-php/norm/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/thesis-php/norm/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/thesis-php/norm/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/thesis-php/norm/compare/0.1.0...0.2.0
