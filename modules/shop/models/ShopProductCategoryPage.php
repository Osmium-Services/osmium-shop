<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProductCategoryPage extends ShopModel
{
    public function categoryIDByPageID($pageID): ?int
    {
        $query = $this->loadSqlFile('category-page-get-category-id.sql');
        $this->database->query($query);
        $this->database->bind(':pageID', $pageID);
        $result = $this->database->single();

        if (!isset($result['productcategory_id'])) return null;

        return $result['productcategory_id'];
    }
}
