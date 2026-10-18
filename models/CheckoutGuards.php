<?php

declare(strict_types=1);

namespace Osmium\Modules\Checkout\Services;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * CheckoutGuards
 *
 * The points where an installed service can refuse a basket change or an order.
 * A service refuses by throwing a ShopOrderException, whose message the customer
 * sees. A service that crashes any other way is logged and skipped, so a bug in
 * an optional service never stops the shop selling.
 */
class CheckoutGuards
{
    public function __construct(private OsmiumPDO $database) {}

    /**
     * @param array<string, mixed> $payload
     * @throws ShopOrderException When a service refuses
     */
    public function check(string $event, array $payload): void
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->database)));

        $payload['dataSource'] = $this->database;

        try {
            $hooks->handle($event, $payload);
        } catch (ShopOrderException $refusal) {
            throw $refusal;
        } catch (\Throwable $e) {
            \error_log("CheckoutGuards: a handler failed on '{$event}': {$e->getMessage()}");
        }
    }
}
