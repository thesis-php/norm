<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresTransaction;
use Thesis\Norm\UnitOfWork;

/**
 * Runs a business transaction: begin → unit of work → work → flush → commit, rollback on failure.
 * The unit of work and the repositories created inside the work live only as long as the transaction.
 */
final readonly class Transactor
{
    public function __construct(
        private PostgresLink $postgres,
    ) {}

    /**
     * @template T
     * @param \Closure(UnitOfWork<PostgresTransaction>): T $work
     * @return T
     */
    public function transactional(\Closure $work): mixed
    {
        $transaction = $this->postgres->beginTransaction();
        $unitOfWork = new UnitOfWork($transaction);

        try {
            $result = $work($unitOfWork);
            $unitOfWork->flush();
            $transaction->commit();

            return $result;
        } catch (\Throwable $exception) {
            $unitOfWork->close();

            if ($transaction->isActive()) {
                $transaction->rollback();
            }

            throw $exception;
        }
    }
}
