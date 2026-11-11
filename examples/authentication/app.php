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
    $transactor = new Transactor($postgres);
    $id = Uuid::uuid7();
    $password = bin2hex(random_bytes(16));
    $newPassword = bin2hex(random_bytes(16));

    // register
    $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $password): void {
        new IdentityRepository($unitOfWork)->add(Identity::register($id, $password));
    });

    // change password
    $transactor->transactional(static function (UnitOfWork $unitOfWork) use ($id, $password, $newPassword): void {
        $identity = new IdentityRepository($unitOfWork)->find($id) ?? throw new \Exception('Not registered');
        $identity->changePassword($password, $newPassword);
    });

    // find all
    dump(new IdentityRepository(new UnitOfWork($postgres))->findAll());

})->await();
