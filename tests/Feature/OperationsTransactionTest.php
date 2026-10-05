<?php

namespace Tests\Feature;

use App\Support\Operations\OperationsTransaction;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

class OperationsTransactionTest extends TestCase
{
    public function test_mysql_reapplies_next_transaction_isolation_before_every_concurrency_retry(): void
    {
        $connection = Mockery::mock(Connection::class);
        $pdo = Mockery::mock(PDO::class);
        DB::shouldReceive('connection')->twice()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('mysql');
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(0);
        $connection->shouldReceive('getPdo')->times(3)->andReturn($pdo);
        $pdo->shouldReceive('exec')->with('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')->times(3)->andReturn(0);
        $attempt = 0;
        $connection->shouldReceive('transaction')->with(Mockery::type(\Closure::class), 1)->times(3)->andReturnUsing(function ($callback) use (&$attempt) {
            if (++$attempt === 1) {
                throw new PDOException('Deadlock found when trying to get lock', 40001);
            }

            return $callback();
        });
        $this->assertSame('first', OperationsTransaction::run(fn () => 'first', 2));
        $this->assertSame('second', OperationsTransaction::run(fn () => 'second', 1));
    }

    public function test_mysql_joins_only_a_known_operations_boundary(): void
    {
        $connection = Mockery::mock(Connection::class);
        $pdo = Mockery::mock(PDO::class);
        DB::shouldReceive('connection')->twice()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('mysql');
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(0, 1);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')->andReturn(0);
        $connection->shouldReceive('transaction')->with(Mockery::type(\Closure::class), 1)->twice()->andReturnUsing(fn ($callback) => $callback());
        $this->assertSame(17, OperationsTransaction::run(fn () => OperationsTransaction::run(fn () => 17)));
    }

    public function test_unknown_mysql_outer_boundary_fails_without_running_work(): void
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $this->expectException(LogicException::class);
        OperationsTransaction::run(fn () => $this->fail('Unknown outer transaction must not execute operations.'));
    }

    public function test_application_error_is_not_retried_and_boundary_is_cleaned(): void
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')->with(Mockery::type(\Closure::class), 1)->once()->andReturnUsing(fn ($callback) => $callback());
        $this->expectException(LogicException::class);
        OperationsTransaction::run(fn () => throw new LogicException('Application refusal'), 3);
    }
}
