<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Modules\Admin\Core\AdminController;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Baskets controller - handles shopping cart analytics and management for
 * the in-house PayPal/Stripe checkout basket (checkout_baskets / checkout_basket_items).
 *
 * "Abandoned" is not a stored status - the schema only tracks 'active' and
 * 'converted'. It is derived here as an active basket whose updated_at is
 * older than the configured threshold, so no schema change is required.
 *
 * Routes:
 *   - index()  → /admin/shop/baskets/
 *   - action() → /admin/shop/baskets/action/
 */
class BasketsController extends AdminController
{
    // =========================================================================
    // Public Actions
    // =========================================================================

    /**
     * Baskets analytics dashboard - shows active, abandoned, and converted baskets.
     */
    public function index(): void
    {
        $abandonedHours = (int)($this->osmium->config->shop->basketAbandonedHours ?? 2);

        $baskets = $this->admin->model->basket;

        $activeBaskets = $baskets->listActive($abandonedHours);
        $abandonedBaskets = $baskets->listAbandoned($abandonedHours);
        $convertedBaskets = $baskets->listConverted();

        $stats = $baskets->stats($abandonedHours);
        $activeValue = $baskets->activeValue();

        $activeBaskets = \array_map([$this, 'formatBasket'], $activeBaskets);
        $abandonedBaskets = \array_map([$this, 'formatBasket'], $abandonedBaskets);
        $convertedBaskets = \array_map([$this, 'formatBasket'], $convertedBaskets);

        $this->data['admin']['lists'] = $this->loadListDefinitions('baskets', __DIR__ . '/../lists');
        $this->data['admin']['activeBaskets'] = $activeBaskets;
        $this->data['admin']['abandonedBaskets'] = $abandonedBaskets;
        $this->data['admin']['convertedBaskets'] = $convertedBaskets;
        $this->data['admin']['stats'] = [
            'active_count' => (int)($stats['active_count'] ?? 0),
            'abandoned_now' => (int)($stats['abandoned_now'] ?? 0),
            'converted_24h' => (int)($stats['converted_24h'] ?? 0),
            'active_value_formatted' => '£' . \number_format(num: $activeValue, decimals: 2),
            'abandoned_hours' => $abandonedHours,
        ];

        $this->setView('baskets/index.phtml');
    }

    /**
     * Action endpoint - handles get_items, delete, delete_old actions.
     */
    public function action()
    {
        \header('Content-Type: application/json');

        $isPostMethod = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPostMethod) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int)($input['id'] ?? 0);

        $readOnlyActions = ['get_items'];
        $needsCsrfCheck = !\in_array(needle: $action, haystack: $readOnlyActions);
        $invalidToken = $needsCsrfCheck && !$this->admin->auth->validateCsrfJson($input);
        if ($invalidToken) {
            $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');
        }

        try {
            $result = $this->handleAction($action, $id, $input);
            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Analytics Logic
    // =========================================================================

    /**
     * Format a basket array with pre-computed display values.
     * Keeps formatting logic out of templates (SOC).
     */
    private function formatBasket(array $basket): array
    {
        $createdAt = \strtotime($basket['created_at'] ?? '');
        $updatedAt = \strtotime($basket['updated_at'] ?? '');

        $basket['created_at_formatted'] = $createdAt ? \date('d M H:i', $createdAt) : '-';
        $basket['updated_at_formatted'] = $updatedAt ? \date('d M H:i', $updatedAt) : '-';
        $basket['total_value_formatted'] = '£' . \number_format(
            num: (float)($basket['total_value'] ?? 0),
            decimals: 2,
        );
        $basket['session_id_short'] = \substr(string: $basket['session_id'] ?? '', offset: 0, length: 12);

        return $basket;
    }

    // =========================================================================
    // Action Handling
    // =========================================================================

    private function handleAction(string $action, int $id, array $input): array
    {
        return match ($action) {
            'get_items' => $this->getBasketItems($id),
            'delete' => $this->deleteBasket($id),
            'delete_old' => $this->deleteOldBaskets($input),
            default => throw new \InvalidArgumentException('Unknown action'),
        };
    }

    private function getBasketItems(int $id): array
    {
        if (!$id) throw new \InvalidArgumentException('Missing basket ID');

        $items = $this->admin->model->basket->getItems($id);
        $items = \array_map(fn(array $item): array => $item + [
            'unit_price_formatted' => '£' . \number_format((float)$item['unit_price'], 2),
            'line_total_formatted' => '£' . \number_format((float)$item['quantity'] * (float)$item['unit_price'], 2),
        ], $items);

        return ['success' => true, 'items' => $items];
    }

    private function deleteBasket(int $id): array
    {
        if (!$id) throw new \InvalidArgumentException('Missing basket ID');

        $this->admin->model->basket->delete($id);

        return ['success' => true, 'action' => 'delete', 'id' => $id];
    }

    private function deleteOldBaskets(array $input): array
    {
        $days = (int)($input['days'] ?? 30);

        $deleted = $this->admin->model->basket->deleteOldAbandoned($days);

        return ['success' => true, 'deleted' => $deleted];
    }
}
