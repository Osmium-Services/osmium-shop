<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProductPage extends ShopModel
{
    public function getProductIDByPageID(int $pageID): ?int
    {
        $query = $this->loadSqlFile('product-page-get-product-id.sql');
        $this->database->query($query);
        $this->database->bind(':pageID', $pageID);
        $result = $this->database->single();

        if (!isset($result['product_id'])) return null;

        return $result['product_id'];
    }
}
