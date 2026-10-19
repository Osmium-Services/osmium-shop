<?php

declare(strict_types=1);

namespace {
    use Osmium\Core\Library\Autoload;
    use Osmium\Core\Library\FileSystem;
    use Osmium\Modules\Shop\Core\OsmiumShopModel;

    require_once \dirname(__DIR__, 4) . '/core/library/Autoload.php';
    require_once \dirname(__DIR__, 4) . '/core/library/FileSystem.php';

    /**
     * The shop module's global-alias class, for tests of admin controllers that build it. The real one needs a
     * booted site; this one has the real shop models over the test database and nothing else.
     * Declared once, here, so every test file that needs it agrees on its shape.
     */
    if (!\class_exists('OsmiumShop', false)) {
        (new Autoload('app/config'))->register(); // The shop models are found by short name

        class OsmiumShop
        {
            public OsmiumShopModel $model;

            public function __construct(public object $osmium)
            {
                $this->model = new OsmiumShopModel(
                    $osmium->dataSource,
                    (new FileSystem())->readJSONFile('app/services/osmium-shop/modules/shop/config/models/models.json.php'),
                );
            }
        }
    }
}
