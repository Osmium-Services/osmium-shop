<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProductFeature extends ShopModel
{
    public function getAllFeatures(): array
    {
        $query = $this->loadSqlFile('feature-get-all.sql');
        $this->database->query($query);
        $results = $this->database->resultset();

        foreach ($results as &$row) { // Resolve URLs from PAGES constant
            if ($row['link_page_id']) {
                $page = $this->pagesById()[$row['link_page_id']] ?? null;
                $row['href'] = ($page && $page['deleted_at'] === null) ? $page['url'] : null;
                continue;
            }

            $row['href'] = null;
        }
        unset($row);

        $AllFeatures = \array_filter(array: $results, callback: fn($row) => $row['href'] !== null); // Filter out features with no valid href (page was deleted)

        return $AllFeatures;
    }
}
