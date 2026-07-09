# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- **BC Break:** Flip order of Flip `$class` and `$persister` parameters in `Repository::__construct()` and `UnitOfWork::repository()`.

### Fixed

- `UnitOfWork` is now always closed.
