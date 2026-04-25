<?php

declare(strict_types=1);

namespace Gam6itko\PhpClickhouseClientBenchmark\Bench;

use Gam6itko\PhpClickhouseClientBenchmark\DataGenerator;
use PhpBench\Attributes as Bench;

#[Bench\OutputTimeUnit('milliseconds')]
#[Bench\Iterations(3)]
#[Bench\Revs(1)]
class HttpStreamBench
{
    private string $baseUrl;

    public function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: '127.0.0.1';
        $port = getenv('CLICKHOUSE_HTTP_PORT') ?: '8123';
        $user = getenv('CLICKHOUSE_USER') ?: 'default';
        $pass = getenv('CLICKHOUSE_PASSWORD') ?: 'default';
        $this->baseUrl = sprintf('http://%s:%s/?user=%s&password=%s&database=benchmark', $host, $port,
            urlencode($user), urlencode($pass));
    }

    public function setUpInsert(): void
    {
        $this->setUp();
        $this->exec('TRUNCATE TABLE benchmark.bench_write');
    }

    // ── Insert ────────────────────────────────────────────────────────────────

    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'http'])]
    #[Bench\Timeout(120.0)]
    public function benchInsert1kOneByOne(): void
    {
        $columns = ['id', 'str_val', 'int_val', 'float_val'];
        foreach (DataGenerator::rows(1_000) as $row) {
            $this->insertBatch($columns, [$row]);
        }
    }

    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'http'])]
    #[Bench\Timeout(30.0)]
    public function benchInsert1kBatch(): void
    {
        $this->insertBatch(['id', 'str_val', 'int_val', 'float_val'], DataGenerator::rows(1_000));
    }

    /**
     * 1M rows chunked at 10 000 per POST — JSONEachRow body is streamed via curl
     * CURLOPT_READFUNCTION so each chunk never materialises as a full string in memory.
     */
    #[Bench\BeforeMethods('setUpInsert')]
    #[Bench\Groups(['insert', 'http'])]
    #[Bench\Timeout(600.0)]
    public function benchInsert1mBatch(): void
    {
        $columns   = ['id', 'str_val', 'int_val', 'float_val'];
        $chunkSize = 10_000;
        $chunk     = [];
        $i         = 0;

        foreach (DataGenerator::rowsGenerator(1_000_000) as $row) {
            $chunk[] = $row;
            if (++$i % $chunkSize === 0) {
                $this->insertBatch($columns, $chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->insertBatch($columns, $chunk);
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Collects all rows into an array — comparable to smi2 ->rows().
     * Parses JSON on-the-fly as HTTP data arrives; never buffers the raw response.
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'http'])]
    #[Bench\Timeout(30.0)]
    public function benchRead1kAll(): void
    {
        $rows = iterator_to_array(
            $this->selectStream('SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000')
        );
        unset($rows);
    }

    /**
     * True line-by-line streaming: one fgets() call per row, JSON-decoded immediately,
     * previous row is discarded before the next is read.
     * Peak memory ≈ O(1) regardless of result-set size.
     */
    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'http'])]
    #[Bench\Timeout(30.0)]
    public function benchRead1kStream(): void
    {
        foreach ($this->selectStream(
            'SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000'
        ) as $row) {
        }
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'http'])]
    #[Bench\Timeout(300.0)]
    public function benchRead1mAll(): void
    {
        $rows = iterator_to_array(
            $this->selectStream('SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000000')
        );
        unset($rows);
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\Groups(['read', 'http'])]
    #[Bench\Timeout(300.0)]
    public function benchRead1mStream(): void
    {
        foreach ($this->selectStream(
            'SELECT id, str_val, int_val, float_val FROM benchmark.bench_read LIMIT 1000000'
        ) as $row) {
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Opens a persistent HTTP connection and yields one decoded row per fgets() call.
     * No intermediate buffer — the OS TCP buffer is the only one between ClickHouse and PHP.
     */
    private function selectStream(string $sql): \Generator
    {
        $url = $this->baseUrl . '&query=' . urlencode($sql . ' FORMAT JSONEachRow');

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept-Encoding: identity\r\n",
        ]]);

        $stream = fopen($url, 'r', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('Failed to open HTTP stream to ClickHouse');
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $line = rtrim($line);
                if ($line !== '') {
                    yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * POSTs rows in JSONEachRow format. Body is built via curl CURLOPT_READFUNCTION
     * so the JSON string for each row is generated on demand rather than concatenated
     * into one large string first.
     *
     * @param string[]                                             $columns
     * @param list<array{0: int, 1: string, 2: int, 3: float}>   $rows
     */
    private function insertBatch(array $columns, array $rows): void
    {
        $url = $this->baseUrl . '&query=' . urlencode('INSERT INTO bench_write FORMAT JSONEachRow');

        // Build a generator so we never hold the full body in one string.
        $lines = (static function () use ($columns, $rows): \Generator {
            foreach ($rows as $row) {
                yield json_encode(array_combine($columns, $row), JSON_THROW_ON_ERROR) . "\n";
            }
        })();

        $buffer = '';
        $done   = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Transfer-Encoding: chunked', 'Expect:'],
            CURLOPT_READFUNCTION   => static function ($ch, $fd, $length) use ($lines, &$buffer, &$done): string {
                if ($done) {
                    return '';
                }
                while (strlen($buffer) < $length && $lines->valid()) {
                    $buffer .= $lines->current();
                    $lines->next();
                }
                if ($buffer === '') {
                    $done = true;
                    return '';
                }
                $chunk  = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);
                return $chunk;
            },
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("ClickHouse INSERT failed (HTTP {$httpCode}): {$response}");
        }
    }

    private function exec(string $sql): void
    {
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'content' => $sql,
        ]]);
        $result = file_get_contents($this->baseUrl, false, $context);
        if ($result === false) {
            throw new \RuntimeException("ClickHouse query failed: {$sql}");
        }
    }
}
