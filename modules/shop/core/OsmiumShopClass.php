<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Core;

use Osmium\Core\Osmium;
use Osmium\Modules\Shop\Core\OsmiumShopModel;

#[\AllowDynamicProperties]
class OsmiumShop
{
    public OsmiumShopModel $model;
    public ?int $categoryID = null;
    public ?int $productID = null;
    public ?int $osmiumPageID = null;

    public function __construct(private Osmium $osmium)
    {
        $this->initAutoloader();
        $this->validateConfig();
        $this->loadModel(\dirname(__DIR__) . '/config/models/models.json.php');
        $this->setOsmiumPageID($this->osmium->model->page->id);
        $this->setCategoryID();
        $this->setProductID();
    }

    public function loadModel(string $configFilePath): object
    {
        $this->model = new OsmiumShopModel(
            $this->osmium->dataSource,
            $this->osmium->fileSystem->readJSONFile($configFilePath)
        );

        return $this;
    }

    public function addSalesTax(
        float $priceExcludingTax,
        float $taxRatePercent = 20.0
    ): float {
        return $priceExcludingTax + ($priceExcludingTax * ($taxRatePercent / 100));
    }

    public function getSavingsOnRRP(
        float $priceExcTax,
        float $rrpExcTax,
        float $taxRatePercent = 20.0
    ): array {
        $rrp            = $this->addSalesTax($rrpExcTax, $taxRatePercent);
        $selling        = $this->addSalesTax($priceExcTax, $taxRatePercent);
        $savings        = number_format($rrp - $selling, 2);
        $savingsPercent = $this->getSavingsPercent($rrp, $selling);

        return [
            'savings' => $savings,
            'savingsPercent' => $savingsPercent
        ];
    }

    public function getSavingsPercent(float $originalPrice, float $discountedPrice): int
    {
        $eitherZero = $originalPrice == 0 || $discountedPrice == 0;
        if ($eitherZero) return 0;

        $savings = $originalPrice - $discountedPrice;

        return (int) round(($savings / $originalPrice) * 100, 0);
    }

    private function initAutoloader()
    {
        spl_autoload_register(
            function ($class) {
                $folders = [__DIR__, \dirname(__DIR__) . '/models'];
                foreach ($folders as $folder) {
                    $classPath = $folder . '/' . $class . '.php';
                    $classExists = file_exists($classPath);
                    if ($classExists) {
                        require $classPath;
                        return;
                    }
                }
            }
        );
    }

    private function setOsmiumPageID(int $pageID)
    {
        $this->osmiumPageID = $pageID;

        return $this;
    }

    private function setCategoryID()
    {
        $this->categoryID =
            $this->model->productCategoryPage->categoryIDByPageID(
                $this->osmiumPageID
            );

        return $this;
    }

    private function setProductID()
    {
        $this->productID = $this->model->productPage->getProductIDByPageID(
            $this->osmiumPageID
        );

        return $this;
    }

    private function validateConfig()
    {
        $config = $this->osmium->config->shop;

        $boolean  = [ // Currency, tax and country are validated by StoreFinance (StoreFinance::forOsmium())
            'categoryShowCounts',
            'categoryShowSummaryText',
            'categoryShowCallToAction',
            'productShowSummary',
            'productShowPriceExcTax',
            'productShowDeliveryMessage'
        ];

        foreach ($boolean as $key) {
            $notBool = !is_bool($config->$key);
            if ($notBool) throw new \Exception("Invalid Shop {$key} config value");
        }

        return $this;
    }
}

// Backward compatibility alias
\class_alias(OsmiumShop::class, 'OsmiumShop');
