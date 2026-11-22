<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;

/**
 * The brand a page belongs to, when the page is a brand's own page (productbrands.page_id).
 */
class ShopBrand extends ShopModel
{
    /** @return array{id: int, name: string, info_external: ?string, logo: ?string}|null null when the page is not a brand's page */
    public function byPageID(int $pageID): ?array
    {
        $this->database->query($this->loadSqlFile('brand-get-by-page.sql'));
        $this->database->bind(':pageID', $pageID);
        $row = $this->database->single();

        return $row ? $row : null;
    }
}
