<?php

declare(strict_types=1);

namespace Gam6itko\PhpClickhouseClientBenchmark\Bench;

use ClickHouseDB\Client;
use Gam6itko\PhpClickhouseClientBenchmark\DataGenerator;
use PhpBench\Attributes as Bench;

#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Iterations(3)]
#[Bench\Revs(1)]
class SmiClickHouseBench
{
    private const COLUMNS = ['id', 'str_val', 'int_val', 'float_val'];

    private Client $client;

    public function setUp(): void
    {
        $this->client = new Client([
            'host'     => getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
            'port'     => getenv('CLICKHOUSE_HTTP_PORT') ?: '8123',
            'username' => getenv('CLICKHOUSE_USER') ?: 'default',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: 'default',
        ]);
        $this->client->database('benchmark');
        $this->client->setTimeout(300);
        $this->client->setConnectTimeOut(5);
    }

    public function setUpInsert(): void
    {
        $this->setUp();
        $this->client->write('TRUNCATE TABLE benchmark.bench_write');
    }

    // ── Insert ────────────────────────────────────────────────────────────────

    /**
     * Each row is a separate HTTP POST to ClickHouse.
     */
    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'smi'])]
    #[Bench\Timeout(120.0)]
    public function benchInsert1kOneByOne(): void
    {
        foreach (DataGenerator::rows(1_000) as $row) {
            $this->client->insert('bench_write', [$row], self::COLUMNS);
        }
    }

    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'smi'])]
    #[Bench\Timeout(30.0)]
    public function benchInsert1kBatch(): void
    {
        $this->client->insert('bench_write', DataGenerator::rows(1_000), self::COLUMNS);
    }

    /**
     * 1M rows chunked at 10 000 per insert() call to avoid building a single
     * multi-hundred-MB SQL string and to stay within ClickHouse's max_query_size.
     */
    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'smi'])]
    #[Bench\Timeout(600.0)]
    public function benchInsert1mBatch(): void
    {
        $chunkSize = 10_000;
        $chunk     = [];
        $i         = 0;

        foreach (DataGenerator::rowsGenerator(1_000_000) as $row) {
            $chunk[] = $row;
            if (++$i % $chunkSize === 0) {
                $this->client->insert('bench_write', $chunk, self::COLUMNS);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->client->insert('bench_write', $chunk, self::COLUMNS);
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'smi'])]
    #[Bench\Timeout(30.0)]
    public function benchRead1kAll(): void
    {
        $rows = $this->client
            ->select('SELECT id, str_val, int_val, float_val FROM bench_read LIMIT 1000')
            ->rows();
        unset($rows);
    }

    /**
     * selectGenerator() buffers the full HTTP response to php://temp before yielding.
     * Not true HTTP streaming, but the lowest-memory iteration pattern the library offers.
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'smi'])]
    #[Bench\Timeout(30.0)]
    public function benchRead1kStream(): void
    {
        foreach ($this->client->selectGenerator(
            'SELECT id, str_val, int_val, float_val FROM bench_read LIMIT 1000'
        ) as $row) {
        }
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'smi'])]
    #[Bench\Timeout(300.0)]
    public function benchRead1mAll(): void
    {
        $rows = $this->client
            ->select('SELECT id, str_val, int_val, float_val FROM bench_read LIMIT 1000000')
            ->rows();
        unset($rows);
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'smi'])]
    #[Bench\Timeout(300.0)]
    public function benchRead1mStream(): void
    {
        foreach ($this->client->selectGenerator(
            'SELECT id, str_val, int_val, float_val FROM bench_read LIMIT 1000000'
        ) as $row) {
        }
    }
}
