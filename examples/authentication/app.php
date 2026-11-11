<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresQueryError;
use Ramsey\Uuid\Uuid;
use Thesis\Norm\Exception\ConcurrentModification;
use Thesis\Norm\Exception\EntityAlreadyExists;
use Thesis\Norm\UnitOfWork;
use function Amp\async;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * @see https://www.postgresql.org/docs/current/errcodes-appendix.html
 */
const LOCK_NOT_AVAILABLE = '55P03';

$postgres = new PostgresConnectionPool(
    new PostgresConfig(
        host: \is_string($host = getenv('POSTGRES_HOST')) ? $host : 'localhost',
        user: 'postgres',
        password: 'postgres',
        database: 'postgres',
    ),
);

$postgres->query(
    <<<'SQL'
        create table if not exists identity (
            id uuid primary key,
            password_hash text not null,
            version smallint default 1 not null
        )
        SQL,
);

async(static function () use ($postgres): void {
    $transactor = new Transactor($postgres);
    $id = Uuid::uuid7();
    $password = bin2hex(random_bytes(16));
    $newPassword = bin2hex(random_bytes(16));

    // register
    $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $password): void {
        new IdentityRepository($unitOfWork)->add(Identity::register($id, $password));
    });

    // register twice
    try {
        $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $password): void {
            new IdentityRepository($unitOfWork)->add(Identity::register($id, $password));
        });

        throw new \LogicException('Expected EntityAlreadyExists');
    } catch (EntityAlreadyExists) {
        // a unique violation is reported by the writer, the transaction is rolled back
    }

    // change password
    $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $password, $newPassword): void {
        $identity = new IdentityRepository($unitOfWork)->find($id) ?? throw new \Exception('Not registered');
        $identity->changePassword($password, $newPassword);
    });

    // optimistic lock: a concurrent transaction changes the password between our read and our write,
    // our update checks the version loaded by our read and affects no row
    try {
        $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($transactor, $id, $newPassword): void {
            $identity = new IdentityRepository($unitOfWork)->find($id) ?? throw new \Exception('Not registered');

            $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $newPassword): void {
                $identity = new IdentityRepository($unitOfWork)->find($id) ?? throw new \Exception('Not registered');
                $identity->changePassword($newPassword, $newPassword);
            });

            $identity->changePassword($newPassword, $newPassword);
        });

        throw new \LogicException('Expected ConcurrentModification');
    } catch (ConcurrentModification) {
        // the transaction is rolled back, retry the whole transaction to apply the change
    }

    // change the password back under a pessimistic lock: other transactions cannot lock the row until commit
    $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($postgres, $id, $password, $newPassword): void {
        $identity = new IdentityRepository($unitOfWork)->lock($id) ?? throw new \Exception('Not registered');

        try {
            $postgres->execute('select from identity where id = ? for update nowait', [$id->toString()]);

            throw new \LogicException('Expected the row to be locked');
        } catch (PostgresQueryError $error) {
            if (($error->getDiagnostics()['sqlstate'] ?? null) !== LOCK_NOT_AVAILABLE) {
                throw $error;
            }
        }

        $identity->changePassword($newPassword, $password);
    });

    // authenticate: the identity is loaded for reading only, nothing is written
    $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $password): void {
        $identity = new IdentityRepository($unitOfWork)->find($id) ?? throw new \Exception('Not registered');
        $identity->authenticate($password);
    });

    echo "Registered, changed the password, lost a concurrent change, changed it back under a lock and authenticated {$id}\n";
})->await();
