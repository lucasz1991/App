<?php

namespace App\Support\Operations;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class OperationsTransaction
{
    use DetectsConcurrencyErrors;

    /** Known operation boundaries per connection, never a process-wide isolation change. */
    private static array $boundaries = [];

    public static function run(Closure $callback, int $attempts = 3): mixed
    {
        return (new self)->execute($callback, $attempts);
    }

    private function execute(Closure $callback, int $attempts): mixed
    {
        if ($attempts < 1) {
            throw new LogicException('At least one transaction attempt is required.');
        }
        $connection = DB::connection();
        $key = spl_object_id($connection);
        $mysql = $connection->getDriverName() === 'mysql';
        if ($connection->transactionLevel() > 0) {
            if ($mysql && ! isset(self::$boundaries[$key])) {
                throw new LogicException('Operations cannot join an unknown outer MySQL transaction.');
            }

            // A deadlock at a savepoint must reach the outer boundary for a full retry.
            return $connection->transaction($callback, 1);
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($mysql) {
                // Applies to the NEXT transaction only; refresh it before every retry.
                $connection->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            self::$boundaries[$key] = true;
            try {
                return $connection->transaction($callback, 1);
            } catch (Throwable $exception) {
                if ($attempt === $attempts || ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }
            } finally {
                unset(self::$boundaries[$key]);
            }
        }

        throw new LogicException('Unreachable transaction state.');
    }
}
