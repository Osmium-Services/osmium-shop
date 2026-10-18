<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Core;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Modules\Shop\Models\ShopProduct;
use Osmium\Modules\Shop\Models\ShopProductCategory;
use Osmium\Modules\Shop\Models\ShopProductCategoryPage;
use Osmium\Modules\Shop\Models\ShopProductOption;
use Osmium\Modules\Shop\Models\ShopProductPage;

#[\AllowDynamicProperties]
class OsmiumShopModel
{
    public ShopProduct $product;
    public ShopProductCategory $productCategory;
    public ShopProductCategoryPage $productCategoryPage;
    public ShopProductOption $productOption;
    public ShopProductPage $productPage;

    public function __construct(OsmiumPDO $dataSource, object $models)
    {
        foreach ($models as $model => $modelData) {
            $className = $modelData[0];
            $tableName = $modelData[1];

            $this->$model = new $className($dataSource, $tableName);
        }
    }
}
