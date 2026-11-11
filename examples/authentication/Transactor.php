<?php

declare(strict_types=1);

namespace Authentication;

use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresTransaction;
use Amp\Sql\SqlException;
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

            // Still active only when an exception propagates before commit(): commit() (even a failed one)
            // and a lost connection end the transaction, rollback() would throw then.
            if ($transaction->isActive()) {
                try {
                    $transaction->rollback();
                } catch (SqlException) { // @phpstan-ignore catch.neverThrown (amphp does not declare SqlException of the ROLLBACK query)
                    // ROLLBACK fails only when the connection is lost, Postgres rolls back then:
                    // keep the original exception.
                }
            }
        }
    }
}
