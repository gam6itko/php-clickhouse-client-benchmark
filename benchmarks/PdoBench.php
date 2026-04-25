<?php

declare(strict_types=1);

namespace Gam6itko\PhpClickhouseClientBenchmark\Bench;

use Gam6itko\PhpClickhouseClientBenchmark\DataGenerator;
use PDO;
use PhpBench\Attributes as Bench;

#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Iterations(3)]
#[Bench\Revs(1)]
class PdoBench
{
    private PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=benchmark;charset=utf8',
                getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
                (int) (getenv('CLICKHOUSE_MYSQL_PORT') ?: 9004),
            ),
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'default',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    public function setUpInsert(): void
    {
        $this->setUp();
        $this->pdo->exec('TRUNCATE TABLE benchmark.bench_write');
    }

    // ── Insert ────────────────────────────────────────────────────────────────

    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'pdo'])]
    #[Bench\Timeout(120.0)]
    public function benchInsert1kOneByOne(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO benchmark.bench_write (id, str_val, int_val, float_val) VALUES (?, ?, ?, ?)'
        );
        foreach (DataGenerator::rows(1_000) as $row) {
            $stmt->execute($row);
        }
    }

    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'pdo'])]
    #[Bench\Timeout(30.0)]
    public function benchInsert1kBatch(): void
    {
        $this->insertBatchRaw(DataGenerator::rows(1_000));
    }

    /**
     * 1M rows chunked at 1 000 rows per INSERT to stay within PDO's 65 535 placeholder limit.
     * All chunks are wrapped in a single transaction.
     */
    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'pdo'])]
    #[Bench\Timeout(600.0)]
    public function benchInsert1mBatch(): void
    {
        $chunkSize = 1_000;
        $chunk     = [];
        $i         = 0;

        foreach (DataGenerator::rowsGenerator(1_000_000) as $row) {
            $chunk[] = $row;
            if (++$i % $chunkSize === 0) {
                $this->insertBatchRaw($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->insertBatchRaw($chunk);
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'pdo'])]
    #[Bench\Timeout(30.0)]
    public function benchRead1kAll(): void
    {
        $rows = $this->pdo
            ->query('SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000')
            ->fetchAll();
        unset($rows);
    }

    /**
     * Unbuffered cursor — rows are streamed from the MySQL wire protocol one at a time.
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'pdo'])]
    #[Bench\Timeout(30.0)]
    public function benchRead1kStream(): void
    {
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = $this->pdo->query(
            'SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000'
        );
        while ($stmt->fetch()) {
        }
        $stmt->closeCursor();
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'pdo'])]
    #[Bench\Timeout(300.0)]
    public function benchRead1mAll(): void
    {
        $rows = $this->pdo
            ->query('SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000000')
            ->fetchAll();
        unset($rows);
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'pdo'])]
    #[Bench\Timeout(300.0)]
    public function benchRead1mStream(): void
    {
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stmt = $this->pdo->query(
            'SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000000'
        );
        while ($stmt->fetch()) {
        }
        $stmt->closeCursor();
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Builds and executes a multi-row raw INSERT.
     * Uses PDO::quote() for string escaping. Avoids placeholder limits.
     *
     * @param list<array{0: int, 1: string, 2: int, 3: float}> $rows
     */
    private function insertBatchRaw(array $rows): void
    {
        $parts = [];
        foreach ($rows as $row) {
            $id       = (int)   $row[0];
            $strVal   =         $this->pdo->quote((string) $row[1]);
            $intVal   = (int)   $row[2];
            $floatVal = (float) $row[3];
            $parts[]  = "({$id}, {$strVal}, {$intVal}, {$floatVal})";
        }

        $this->pdo->exec(
            'INSERT INTO benchmark.bench_write (id, str_val, int_val, float_val) VALUES '
            . implode(',', $parts)
        );
    }
}
