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
        } finally {
            $unitOfWork->close();

            // Inactive after commit() (even a failed one) or when the connection is lost:
            // rollback() would throw then and replace the original exception.
            if ($transaction->isActive()) {
                try {
                    $transaction->rollback();
                } catch (\Throwable) {
                    // Active here only while an exception propagates: it matters more than a failed rollback.
                    // Postgres rolls back on disconnect, amphp retries in the transaction's destructor.
                }
            }
        }
    }
}
