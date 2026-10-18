<?php

declare(strict_types=1);

namespace Osmium\Modules\Shop\Models;

use Osmium\Core\Models\Model;

abstract class ShopModel extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';
}
