<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;
class ShopProductCategory extends ShopModel
{
    public private(set) int $id = 0;
    public private(set) int $parentID = 0;
    public private(set) string $label = '';
    public private(set) string $title = '';
    public private(set) string $subtitle = '';
    public private(set) int $sort = 0;
    public private(set) string $description = '';
    public private(set) string $socialImage = '';

    public function loadByID(?int $categoryID = null)
    {
        $invalidId = $categoryID < 1;
        if ($invalidId) return false;

        $query = $this->loadSqlFile('category-get-by-id.sql');
        $this->database->query($query);
        $this->database->bind(':categoryID', $categoryID);
        $data = $this->database->single();

        $noData = !\is_array($data);
        if ($noData) return false;

        $this->id = $categoryID;
        $this->parentID = $data['parent_id'] ?? 0;
        $this->label = $data['label'] ?? '';
        $this->title = $data['title'] ?? '';
        $this->subtitle = $data['subtitle'] ?? '';
        $this->sort = $data['sort'] ?? 0;
        $this->description = $data['description'] ?? '';
        $this->socialImage = $data['social_image'] ?? '';

        return true;
    }

    public function subcategories(): array
    {
        $query = $this->loadSqlFile('category-subcategories.sql');
        $this->database->query($query);
        $this->database->bind(':thisID', $this->id);
        $results = $this->database->resultset();

        foreach ($results as &$row) { // Get URLs from PAGES constant
            $page = $this->pagesById()[$row['page_id']] ?? null;
            $row['link'] = ($page && $page['deleted_at'] === null) ? $page['url'] : null;
        }
        unset($row);

        return $results;
    }
}
