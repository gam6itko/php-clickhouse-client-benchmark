# PHP ClickHouse Client Benchmark

Compares two PHP ClickHouse clients across common insert and read workloads:

| Client | Protocol | Library |
|--------|----------|---------|
| PDO MySQL | MySQL wire protocol (port 9004) | built-in `ext-pdo` |
| smi2/phpClickHouse | HTTP (port 8123) | [smi2/phpclickhouse](https://github.com/smi2/phpClickHouse) |

## Results summary

Tested on PHP 8.4.20, ClickHouse running locally via Docker.

### Insert

| Subject | PDO | smi2 | Winner |
|---------|-----|------|--------|
| 1k one-by-one | ~1 382 ms | ~1 594 ms | PDO ×1.15 |
| 1k batch | ~3.2 ms | ~14.9 ms | **PDO ×4.7** |
| 1m batch | ~2 816 ms | ~12 807 ms | **PDO ×4.5** |

Memory for 1m batch: PDO ~1 MB vs smi2 ~4.6 MB.

### Read

| Subject | PDO | PDO mem | smi2 | smi2 mem | Winner |
|---------|-----|---------|------|----------|--------|
| 1k all | ~2.6 ms | ~1 MB | ~7.1 ms | ~2.3 MB | PDO ×2.7 |
| 1k stream | ~2.5 ms | ~727 KB | ~6.7 ms | ~1.4 MB | PDO ×2.7 |
| 1m all | ~1 047 ms | ~467 MB | ~2 392 ms | ~1.14 GB | PDO ×2.3 |
| 1m stream | ~623 ms | **~727 KB** | ~2 182 ms | ~3.1 MB | **PDO ×3.5** |

**Key takeaway:** PDO (MySQL wire protocol) outperforms smi2/phpClickHouse (HTTP) across every workload — 2–5× faster and 2–4× less memory. The gap is most dramatic with large batch inserts and streaming reads. PDO streaming 1M rows uses only 727 KB regardless of result size, making it the only practical option for large dataset processing.

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
  SmiClickHouseBench.php    smi2/phpClickHouse benchmark class
src/
  DataGenerator.php         Shared row data generator
docker/clickhouse/initdb/
  01_init.sql               DB/table creation + 1M read rows
docker-compose.yaml         ClickHouse service
phpbench.json               phpbench runner configuration
```
