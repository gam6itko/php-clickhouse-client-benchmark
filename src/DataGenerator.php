<?php

declare(strict_types=1);

namespace Gam6itko\PhpClickhouseClientBenchmark;

final class DataGenerator
{
    /**
     * @return list<array{0: int, 1: string, 2: int, 3: float}>
     */
    public static function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [$i, 'str_' . $i, $i % 100000, $i * 0.001];
        }

        return $rows;
    }

    /**
     * Yields rows one at a time to avoid materialising the full array in memory.
     *
     * @return \Generator<int, array{0: int, 1: string, 2: int, 3: float}>
     */
    public static function rowsGenerator(int $count): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            yield [$i, 'str_' . $i, $i % 100000, $i * 0.001];
        }
    }
}
