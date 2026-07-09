<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Ramsey\Uuid\Uuid;
use Thesis\Norm\UnitOfWork;
use function Amp\async;

require_once __DIR__ . '/../../vendor/autoload.php';

$postgres = new PostgresConnectionPool(
    new PostgresConfig(
        host: 'localhost',
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
    $id = Uuid::uuid7();
    $password = bin2hex(random_bytes(16));
    $newPassword = bin2hex(random_bytes(16));

    // register
    $transaction = $postgres->beginTransaction();
    $unitOfWork = new UnitOfWork($transaction);
    $repository = new IdentityRepository($unitOfWork);
    $identity = Identity::register($id, $password);
    $repository->add($identity);
    $unitOfWork->flush();
    $transaction->commit();

    // change password
    $transaction = $postgres->beginTransaction();
    $unitOfWork = new UnitOfWork($transaction);
    $repository = new IdentityRepository($unitOfWork);
    $identity = $repository->find($id) ?? throw new \Exception('Not registered');
    $identity->changePassword($password, $newPassword);
    $unitOfWork->flush();
    $transaction->commit();

    // find all
    dump(new IdentityRepository(new UnitOfWork($postgres))->findAll());

})->await();
