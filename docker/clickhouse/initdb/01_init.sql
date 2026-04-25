CREATE DATABASE IF NOT EXISTS benchmark;

CREATE TABLE IF NOT EXISTS benchmark.bench_write
(
    id        UInt64,
    str_val   String,
    int_val   Int32,
    float_val Float64
)
ENGINE = MergeTree()
ORDER BY id;

CREATE TABLE IF NOT EXISTS benchmark.bench_read
(
    id        UInt64,
    str_val   String,
    int_val   Int32,
    float_val Float64
)
ENGINE = MergeTree()
ORDER BY id;

TRUNCATE TABLE benchmark.bench_read;

INSERT INTO benchmark.bench_read
SELECT
    number                                   AS id,
    concat('str_', toString(number))         AS str_val,
    toInt32(number % 100000)                 AS int_val,
    toFloat64(number) * 0.001                AS float_val
FROM numbers(1000000);