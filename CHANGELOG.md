# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] 2026-10-29

The library is rethought as NORM (Not An ORM): a minimal unit of work on top of hand-written repositories, with no mapping or query layer.

### Added

- Add `Thesis\Norm\Exception` marker interface implemented by every exception of the package.
- Add `Mapper` and `Writer` interfaces: implement them (usually in one class) to convert entities to rows and to write rows to the storage.
- Add `UnitOfWork::entities()` returning an `EntitySet` with `add()`, `remove()`, `manageOne()` and `manageAll()`. Repositories of one unit of work share the entity set of the same entity class.
- Add `Exception\EntityAlreadyExists` to be thrown by `Writer::insert()` when the storage rejects a duplicate.
- Add `UnitOfWork::$isClosed`.
- Accessing `UnitOfWork::$transaction` after `flush()` or `close()` throws `UnitOfWorkClosed`, so finders do not query on behalf of a closed unit of work.
- Unchanged entities are no longer passed to `update()`: changes are detected by comparing the result of `entityToRow()` with its result right after loading, so storage-specific representations (date formats, scalar types, extra columns) do not cause spurious updates.

### Changed

- **BC Break:** The package is renamed from `thesis/orm` to `thesis/norm`, the namespace from `Thesis\ORM` to `Thesis\Norm`.
- **BC Break:** `Repository` is removed. Write your own repository class on top of `UnitOfWork::entities()` and add the finders you need, registering loaded rows via `EntitySet::manageOne()`/`manageAll()`.
- **BC Break:** `UnitOfWork::repository()`, `Repository::findBy()` and `Repository::key()` are removed.
- **BC Break:** Inserts, updates and deletes are passed to the `Writer` in batches.

### Removed

- **BC Break:** Remove `EntityManager`. Begin and commit transactions yourself.
- **BC Break:** Remove `Persister` and `Persister\InMemory`. Implement `Writer` instead.

### Fixed

- Entity sets now throw `UnitOfWorkClosed` after `UnitOfWork::close()` or a failed `UnitOfWork::flush()`.

## [0.2.0] 2026-02-22

### Added

- Add `Persister\InMemory` for testing.
- Add `UnitOfWork::close()` method.

### Changed

- **BC Break:** `Thesis\ORM\Transaction` moved to `Thesis\Transaction` in a separate [thesis/transaction](https://github.com/thesis-php/transaction) package.
- **BC Break:** Flip order of Flip `$class` and `$persister` parameters in `Repository::__construct()` and `UnitOfWork::repository()`.

### Fixed

- `UnitOfWork` is now always closed.
