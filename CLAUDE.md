# thesis/norm

NORM (Not An ORM): a minimal unit of work for hand-written repositories. See README.md for the concepts
and the public contract, `skills/thesis-norm-repository/SKILL.md` for how users write tables and repositories.

## Commands

Everything runs in Docker through `make` (no local PHP needed). `make help` lists all targets.

- `make test` — Testo test suite (`ARGS='--filter=...'` to narrow it down).
- `make phpstan fixer-check rector-check deps-analyze` — static checks; `make fix` applies fixer and Rector.
- `make infect` — mutation testing; MSI must stay at 100%. Kill escaped mutants with tests, remove code
  that only produces equivalent mutants.
- `make check` — everything CI runs, including `infect`.
- `make example` — runs `examples/authentication` against the `postgres` service; it must keep passing.
- `make install`/`make update` also sync AI skills via `llm/skills` (`skills.json`) into `.claude/skills`
  (not committed): the Testo skills (`testo-write-tests`, `testo-mutation-testing`, …) and this package's own
  `skills/thesis-norm-repository`.
  Edit skills in `skills/`, never in `.claude/skills`.

## Code conventions

- PHP 8.4, `declare(strict_types=1)`, `final` classes, `readonly` where possible, property hooks and asymmetric
  visibility instead of getters.
- Every class in `src/` is marked `@api` (public contract, BC matters) or `@internal` (may change any time;
  internal classes live in `Thesis\Norm\Internal`, internal methods of API classes are marked `@internal` too).
- Generics are part of the contract: keep templates, variance (`@template-covariant`/`-contravariant`) and
  `@phpstan-type` rows precise. PHPStan runs at `level: max` with strict rules.
- Declare checked exceptions with `@throws` on every method that can throw them, including propagated ones.
- Exceptions implement `Thesis\Norm\Exception`; exceptions thrown by the library are created via named
  constructors (`DuplicateEntity::create($class, $id)`) with messages naming the entity class and id.
- Optional capabilities of a table are separate interfaces checked with `instanceof` (`RowId`, `DependsOn`,
  `BeforeFlush`), not methods with default implementations.

## Tests

- Testo, not PHPUnit: test classes are `#[Test] final readonly class ...Test` in `tests/`, public methods are tests.
  Use `Testo\Assert` for assertions, `Testo\Expect::exception(X::class)->withMessage('...')` (or
  `#[ExpectException]`) for exceptions.
- Test names describe behaviour (`findReturnsNullForRemovedEntity`). Write the failing test first.
- Fixtures in `tests/Fixture`: `Storage` is an in-memory executor with a write log and FK checks,
  `ArticleTable` (implements `RowId`) and `CommentTable` (does not), decorators `DependentTable` and `HookedTable`.

## Changelog

Every user-visible change goes to `CHANGELOG.md` under `[Unreleased]` (Keep a Changelog: Added / Changed /
Removed / Fixed). Prefix incompatible changes with `**BC Break:**`. Until 1.0, BC breaks are allowed in minor
versions but must be listed.

## Commits

Short imperative subject in English, like the existing history (`Add EntitySet::find() to read own writes by id`).
