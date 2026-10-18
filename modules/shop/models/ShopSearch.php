<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;

class ShopSearch extends ShopModel
{
    /**
     * @param string $where Built from fixed field names and bound parameter placeholders only
     * @param string $relevance Built from fixed field names and integer scores only
     * @param array<string, string> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $where, string $relevance, array $bindings, int $limit): array
    {
        $sql = $this->loadSqlFile('product-search.sql', [
            'WHERE' => $where,
            'RELEVANCE' => $relevance,
            'LIMIT' => (string)$limit,
        ]);
        $this->database->query($sql);
        foreach ($bindings as $param => $value) {
            $this->database->bind(param: $param, value: $value);
        }

        return $this->database->resultset();
    }
}
