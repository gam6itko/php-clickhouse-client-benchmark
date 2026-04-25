# PHP ClickHouse Client Benchmark

Compares two PHP ClickHouse clients across common insert and read workloads:

| Client | Protocol | Library |
|--------|----------|---------|
| PDO MySQL | MySQL wire protocol (port 9004) | built-in `ext-pdo` |
| smi2/phpClickHouse | HTTP (port 8123) | [smi2/phpclickhouse](https://github.com/smi2/phpClickHouse) |

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
