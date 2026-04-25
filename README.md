# PHP ClickHouse Client Benchmark

Compares three PHP ClickHouse clients across common insert and read workloads:

| Client | Protocol | Library |
|--------|----------|---------|
| PDO MySQL | MySQL wire protocol (port 9004) | built-in `ext-pdo` |
| HTTP Stream | HTTP (port 8123), raw `fopen`/`fgets` + curl streaming | no library — bare PHP |
| smi2/phpClickHouse | HTTP (port 8123) | [smi2/phpclickhouse](https://github.com/smi2/phpClickHouse) |

## Results summary

Tested on PHP 8.4.20, ClickHouse running locally via Docker.

### Insert

| Subject | PDO | HTTP Stream | smi2 | Winner |
|---------|-----|-------------|------|--------|
| 1k one-by-one | ~1 313 ms | ~1 569 ms | ~1 537 ms | PDO ×1.17 |
| 1k batch | ~3.1 ms | ~5.1 ms | ~14.9 ms | **PDO ×4.8** |
| 1m batch | ~2 746 ms | ~3 241 ms | ~12 767 ms | **PDO ×4.7** |

Memory for 1m batch: PDO ~1 MB, HTTP Stream ~3.4 MB, smi2 ~4.6 MB.

### Read

| Subject | PDO | PDO mem | HTTP Stream | HTTP mem | smi2 | smi2 mem | Winner |
|---------|-----|---------|-------------|----------|------|----------|--------|
| 1k all | ~2.4 ms | ~1 MB | ~4.6 ms | ~1.1 MB | ~6.8 ms | ~2.3 MB | PDO ×2.8 |
| 1k stream | ~2.1 ms | ~727 KB | ~3.9 ms | **~727 KB** | ~7.2 ms | ~1.4 MB | PDO ×3.5 |
| 1m all | ~1 061 ms | ~467 MB | ~2 214 ms | ~573 MB | ~2 378 ms | ~1.14 GB | PDO ×2.2 |
| 1m stream | ~633 ms | **~727 KB** | ~1 811 ms | **~727 KB** | ~2 211 ms | ~3 MB | **PDO ×3.5** |

**Key takeaways:**

- **PDO (MySQL wire protocol) is the fastest** across every workload — 2–5× faster than HTTP-based clients.
- **HTTP Stream (bare PHP curl/fopen)** is 2.5–4× faster than smi2 and uses far less memory for large operations: 1M batch insert uses 3.4 MB vs smi2's 4.6 MB; 1M stream read uses only 727 KB — identical to PDO — vs smi2's 3 MB.
- **smi2/phpClickHouse** is the slowest and most memory-hungry in every test. Its library overhead makes it a poor choice for high-throughput or memory-sensitive workloads.
- **Streaming reads over HTTP** (`fopen`+`fgets`, JSONEachRow) achieves O(1) memory regardless of result size, matching PDO's memory profile. The time cost vs PDO is ~2.9× for 1M rows.
- For one-by-one inserts all three clients are similarly slow (~1.3–1.6 s for 1 000 rows) since the bottleneck is the round-trip count, not the protocol.

### ClickHouse server-side view (`system.query_log`)

| Interface | Type | Queries | avg server time | avg server RAM | avg result size |
|-----------|------|---------|-----------------|----------------|-----------------|
| HTTP (smi2) | Insert | 3 303 | **0.4 ms** | 400 KB | 34 KB |
| HTTP (smi2) | Select | 42 | **155 ms** | 60 MB | 15.9 MB |
| MySQL (PDO) | Insert | 28 868 | 1.06 ms | **1.2 KB** | 18 KB |
| MySQL (PDO) | Select | 48 | 273 ms | **16.8 MB** | 18.6 MB |

From the server's perspective the picture is inverted: HTTP is faster server-side but consumes 3.5× more RAM per SELECT (ClickHouse must buffer the full result before sending an HTTP response). PDO MySQL streaming keeps server memory low but holds the cursor open while PHP reads row by row, increasing apparent query duration. The PHP-side overhead of HTTP JSON serialization outweighs the server-side speed advantage — which is why PDO wins end-to-end.

## Benchmark subjects

| Subject | Description |
|---------|-------------|
| `benchInsert1kOneByOne` | 1 000 rows, one INSERT per row |
| `benchInsert1kBatch` | 1 000 rows in a single batch call |
| `benchInsert1mBatch` | 1 000 000 rows in chunked batches |
| `benchRead1kAll` | SELECT 1 000 rows → fetch all at once |
| `benchRead1kStream` | SELECT 1 000 rows → iterate row by row |
| `benchRead1mAll` | SELECT 1 000 000 rows → fetch all at once |
| `benchRead1mStream` | SELECT 1 000 000 rows → iterate row by row |

## Requirements

- PHP 8.1+ with `pdo_mysql` extension
- Composer
- Docker + Docker Compose

## Setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Start ClickHouse
#    The init script creates the database, tables, and pre-populates
#    1 000 000 rows in bench_read on first container start.
docker compose up -d
```

Wait a few seconds for ClickHouse to finish initialisation before running benchmarks.

## Running benchmarks

```bash
# Insert benchmarks only
vendor/bin/phpbench run benchmarks/ --group=insert --progress=dots --report=default

# Read benchmarks only
vendor/bin/phpbench run benchmarks/ --group=read --progress=dots --report=default

# Single client
vendor/bin/phpbench run benchmarks/ --group=pdo --report=default
vendor/bin/phpbench run benchmarks/ --group=http --report=default
vendor/bin/phpbench run benchmarks/ --group=smi --report=default

# Everything
vendor/bin/phpbench run benchmarks/ --progress=dots --report=default
```

### Save results to a file for later comparison

```bash
vendor/bin/phpbench run benchmarks/ --report=default --dump-file=results.xml

# Compare two saved runs
vendor/bin/phpbench report --file=baseline.xml --file=results.xml --report=compare
```

## Configuration

Connection parameters can be overridden via environment variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `CLICKHOUSE_HOST` | `127.0.0.1` | ClickHouse host |
| `CLICKHOUSE_HTTP_PORT` | `8123` | HTTP port (smi2 client) |
| `CLICKHOUSE_MYSQL_PORT` | `9004` | MySQL protocol port (PDO client) |
| `CLICKHOUSE_USER` | `default` | Username |
| `CLICKHOUSE_PASSWORD` | `default` | Password |

Example:

```bash
CLICKHOUSE_HOST=192.168.1.10 vendor/bin/phpbench run benchmarks/ --report=default
```

## Resetting data between runs

The write table (`bench_write`) is truncated automatically before each insert benchmark iteration.

To restart the container while keeping existing ClickHouse data:

```bash
docker compose down && docker compose up -d
```

## Teardown (full cleanup)

To stop the container and delete all ClickHouse data stored in the local volume:

```bash
docker compose down
rm -rf docker/clickhouse/data
```

## Project structure

```
benchmarks/
  PdoBench.php              PDO MySQL benchmark class
  HttpStreamBench.php       Raw HTTP streaming benchmark (curl + fopen/fgets, no library)
  SmiClickHouseBench.php    smi2/phpClickHouse benchmark class
src/
  DataGenerator.php         Shared row data generator
docker/clickhouse/initdb/
  01_init.sql               DB/table creation + 1M read rows
docker-compose.yaml         ClickHouse service
phpbench.json               phpbench runner configuration
```
