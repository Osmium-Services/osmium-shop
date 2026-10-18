<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Models;

use Osmium\Core\Models\Model;

abstract class CheckoutModel extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    /**
     * @param array<int|string, mixed> $bindings
     * @param array<string, mixed> $replacements
     */
    protected function prepare(string $sqlFile, array $bindings = [], array $replacements = []): void
    {
        $this->database->query($this->loadSqlFile($sqlFile, $replacements));

        foreach ($bindings as $param => $value) {
            $this->database->bind(param: $param, value: $value);
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param array<string, mixed> $replacements
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sqlFile, array $bindings = [], array $replacements = []): array
    {
        $this->prepare($sqlFile, $bindings, $replacements);

        return $this->database->resultset();
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param array<string, mixed> $replacements
     * @return array<string, mixed>|null
     */
    protected function fetchOne(string $sqlFile, array $bindings = [], array $replacements = []): ?array
    {
        $this->prepare($sqlFile, $bindings, $replacements);

        return $this->database->single() ?: null;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param array<string, mixed> $replacements
     */
    protected function run(string $sqlFile, array $bindings = [], array $replacements = []): void
    {
        $this->prepare($sqlFile, $bindings, $replacements);
        $this->database->execute();
    }
}
