<?php

declare(strict_types=1);

namespace Osmium\Modules\Admin\Controllers;

use Osmium\Core\Library\Input;
use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\DiscountListView;
use Osmium\Modules\Admin\Services\DiscountLiveSummary;
use Osmium\Modules\Admin\Services\DiscountRules;
use Osmium\Core\Library\PaymentProviders;

require_once \Osmium\Core\Library\AdminPaths::path('core/AdminController.php');

/**
 * Discounts controller - handles sitewide and per-product discounts.
 *
 * Routes:
 *   - index()  → /admin/shop/discounts/           (list view)
 *   - edit()   → /admin/shop/discounts/edit/       (create/edit form)
 *   - action() → /admin/shop/discounts/action/     (AJAX operations; 'duplicate' is a form post that redirects)
 *
 * Covers sitewide, per-product and coupon-code discounts. See
 * PaymentProviders for why discounts only take effect when a supported
 * payment provider is configured.
 */
class DiscountsController extends AdminController
{
    private const RECORD_TYPE = 'discount';

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $lists = $this->loadListDefinitions('discounts', __DIR__ . '/../lists');
        $discounts = $this->loadListRows($lists['active'], $this->admin->model->discount);

        $formattedDiscounts = [];
        foreach ($discounts as $discount) {
            $formattedDiscounts[] = DiscountListView::row($discount);
        }

        $this->data['admin']['lists'] = $lists;
        $this->data['admin']['discounts'] = $formattedDiscounts;
        $this->data['admin']['liveSummary'] = DiscountLiveSummary::build($formattedDiscounts);
        $this->data['admin']['deletedDiscounts'] = $this->loadListRows($lists['deleted'], $this->admin->model->discount);
        $this->data['admin']['activeEditors'] = $this->loadActiveEditors();
        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['paymentProviderSupportsDiscounts'] = $this->paymentProviderSupportsDiscounts();
        $this->data['admin']['currentPaymentProviderLabel'] = $this->currentPaymentProviderLabel();
        $this->data['admin']['hostedPayments'] = PaymentProviders::hosted($this->osmium);

        $this->setView('discounts/index.phtml');
    }

    public function edit(): void
    {
        $discountId = (int) $this->osmium->getStashedParam(key: 'id', default: 0);
        $this->osmium->clearStashedQuerystring();

        $isPost = $this->isPost();
        if ($isPost) {
            $error = $this->handleFormSubmit();
            if ($error) $this->data['admin']['error'] = $error;
        }

        $discount = $this->loadDiscountForEdit($discountId);
        $selectedProductIds = $discountId ? $this->loadSelectedProductIds($discountId) : [];
        $selectedCategoryIds = $discountId ? $this->admin->model->discount->getCategoryIds($discountId) : [];
        $selectedBrandIds = $discountId ? $this->admin->model->discount->getBrandIds($discountId) : [];

        $this->data['admin']['discount'] = $discount ?? $this->getEmptyDiscount();
        $this->data['admin']['discountId'] = $discountId;
        $this->data['admin']['isNew'] = !$discountId;
        $this->data['admin']['showSavedMessage'] = $this->consumeSavedFlag();
        $this->data['admin']['error'] = $this->data['admin']['error'] ?? null;
        $this->data['admin']['products'] = $this->admin->model->product->listForPicker();
        $this->data['admin']['selectedProductIds'] = $selectedProductIds;
        $this->data['admin']['categories'] = $this->categoryOptions();
        $this->data['admin']['brands'] = $this->admin->model->brand->getActive();
        $this->data['admin']['discountForm'] = [
            'isProductType' => ($this->data['admin']['discount']['type'] ?? 'sitewide') === 'product',
            'isCouponType' => ($this->data['admin']['discount']['type'] ?? 'sitewide') === 'coupon',
            'selectedProductIds' => $selectedProductIds,
            'selectedCategoryIds' => $selectedCategoryIds,
            'selectedBrandIds' => $selectedBrandIds,
            'startsAtLocal' => $this->datetimeLocal($this->data['admin']['discount']['starts_at'] ?? null),
            'endsAtLocal' => $this->datetimeLocal($this->data['admin']['discount']['ends_at'] ?? null),
        ];
        $this->data['admin']['paymentProviderSupportsDiscounts'] = $this->paymentProviderSupportsDiscounts();
        $this->data['admin']['currentPaymentProviderLabel'] = $this->currentPaymentProviderLabel();
        $this->data['admin']['hostedPayments'] = PaymentProviders::hosted($this->osmium);

        $this->setView('discounts/edit.phtml');
    }

    public function action()
    {
        $isDuplicate = $this->isPost() && Input::post()->string(key: 'action') === 'duplicate';
        if ($isDuplicate) $this->duplicate(); // A plain form post that redirects, not JSON

        \header('Content-Type: application/json');

        $isNotPost = !$this->isPost();
        if ($isNotPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int) ($input['id'] ?? 0);

        $presenceHandled = $this->admin->presence->handleAction(
            action: $action,
            input: $input,
            recordType: self::RECORD_TYPE,
        );
        if ($presenceHandled) exit;

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        $csrfInvalid = !$csrfValid;
        if ($csrfInvalid) {
            $this->admin->jsonError('Invalid request token. Please refresh and try again.');
        }

        $missingParams = !$action || !$id;
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        $recordName = $this->getRecordName($id);

        try {
            $result = match ($action) {
                'delete' => $this->softDelete($id),
                'restore' => $this->restore($id),
                'permanently_delete' => $this->hardDelete($id),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            $this->logAction(action: $action, id: $id, name: $recordName);
            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Gating - discounts only take effect when a supported payment provider
    // is configured
    // =========================================================================

    private function paymentProviderSupportsDiscounts(): bool
    {
        $provider = PaymentProviders::configured(
            $this->osmium->config->checkout ?? new \stdClass(),
            PaymentProviders::external($this->osmium),
        );

        return PaymentProviders::supportsDiscounts($provider);
    }

    private function currentPaymentProviderLabel(): string
    {
        $external = PaymentProviders::external($this->osmium);
        $provider = PaymentProviders::configured($this->osmium->config->checkout ?? new \stdClass(), $external);

        return PaymentProviders::label($provider, $external);
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    // =========================================================================
    // Index Helpers
    // =========================================================================

    private function loadActiveEditors(): array
    {
        return $this->admin->presence->list(
            currentUserId: $this->getCurrentUserId(),
            recordType: self::RECORD_TYPE,
        );
    }

    // =========================================================================
    // Edit Helpers
    // =========================================================================

    private function getEmptyDiscount(): array
    {
        return [
            'id' => 0,
            'name' => '',
            'type' => 'sitewide',
            'code' => '',
            'value_type' => 'percent',
            'value' => '',
            'starts_at' => '',
            'ends_at' => '',
            'usage_limit' => '',
            'usage_limit_per_customer' => '',
            'min_spend_inc_tax' => '',
            'active' => 1,
        ];
    }

    private function loadDiscountForEdit(int $discountId): ?array
    {
        if (!$discountId) return null;

        $discount = $this->admin->model->discount->getById($discountId);

        if (!$discount) $this->redirect('shop/discounts/');

        $this->admin->presence->registerCurrentUser(
            recordType: self::RECORD_TYPE,
            recordId: $discountId,
        );

        return $discount;
    }

    /**
     * A date/time in the format a <input type="datetime-local"> expects, or '' when unset.
     */
    private function datetimeLocal(?string $value): string
    {
        if (!$value) return '';

        return \date('Y-m-d\TH:i', \strtotime($value));
    }

    private function loadSelectedProductIds(int $discountId): array
    {
        return $this->admin->model->discount->getProductIds($discountId);
    }

    private function consumeSavedFlag(): bool
    {
        $notSaved = !isset($_SESSION['discount_saved']);
        if ($notSaved) return false;

        unset($_SESSION['discount_saved']);
        return true;
    }

    private function handleFormSubmit(): ?string
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        $csrfInvalid = !$csrfValid;
        if ($csrfInvalid) return 'Invalid form submission. Please try again.';

        $input = Input::post();
        $discountId = $input->nullableInt(key: 'id');
        $name = $input->string(key: 'name');
        $type = $input->string(key: 'type', default: 'sitewide');
        $code = \strtoupper($input->string(key: 'code'));
        $valueType = $input->string(key: 'value_type', default: 'percent');
        $value = $input->float(key: 'value');
        if ($valueType === 'free_delivery') $value = 0.0; // Free delivery takes no amount off the goods
        $startsAt = DiscountRules::parseDate($input->string(key: 'starts_at'));
        $endsAt = DiscountRules::parseDate($input->string(key: 'ends_at'));
        $usageLimit = DiscountRules::parseInt($input->string(key: 'usage_limit'));
        $perCustomerKey = 'usage_limit_per_customer';
        $usageLimitPerCustomer = DiscountRules::parseInt($input->string(key: $perCustomerKey));
        $minSpendIncTax = $type === 'coupon' ? DiscountRules::parseMoney($input->string(key: 'min_spend_inc_tax')) : null;
        $active = (int) $input->has(key: 'active');
        $productIds = \array_map('intval', $input->array(key: 'product_ids'));
        $categoryIds = \array_map('intval', $input->array(key: 'category_ids'));
        $brandIds = \array_map('intval', $input->array(key: 'brand_ids'));

        $validationError = DiscountRules::validate(
            name: $name,
            type: $type,
            code: $code,
            valueType: $valueType,
            value: $value,
            startsAt: $startsAt,
            endsAt: $endsAt,
            productIds: $productIds,
            codeInUse: fn(string $code): bool => $this->codeAlreadyUsed(code: $code, excludingDiscountId: $discountId),
            minSpendIncTax: $minSpendIncTax,
            categoryIds: $categoryIds,
            brandIds: $brandIds,
        );
        if ($validationError) return $validationError;

        $scopedProductIds = $type === 'product' ? $productIds : [];
        $scopedCategoryIds = $type === 'product' ? $categoryIds : [];
        $scopedBrandIds = $type === 'product' ? $brandIds : [];
        $scopedCode = $type === 'coupon' ? $code : null;

        if ($discountId) {
            $this->updateDiscount(
                discountId: $discountId,
                name: $name,
                type: $type,
                code: $scopedCode,
                valueType: $valueType,
                value: $value,
                startsAt: $startsAt,
                endsAt: $endsAt,
                usageLimit: $usageLimit,
                usageLimitPerCustomer: $usageLimitPerCustomer,
                minSpendIncTax: $minSpendIncTax,
                active: $active,
                productIds: $scopedProductIds,
                categoryIds: $scopedCategoryIds,
                brandIds: $scopedBrandIds,
            );
        } else {
            $this->insertDiscount(
                name: $name,
                type: $type,
                code: $scopedCode,
                valueType: $valueType,
                value: $value,
                startsAt: $startsAt,
                endsAt: $endsAt,
                usageLimit: $usageLimit,
                usageLimitPerCustomer: $usageLimitPerCustomer,
                minSpendIncTax: $minSpendIncTax,
                active: $active,
                productIds: $scopedProductIds,
                categoryIds: $scopedCategoryIds,
                brandIds: $scopedBrandIds,
            );
        }

        return null; // Never reached due to redirects
    }

    private function codeAlreadyUsed(string $code, ?int $excludingDiscountId): bool
    {
        $existingId = $this->admin->model->discount->findIdByCode($code);

        $noneFound = $existingId === null;
        if ($noneFound) return false;

        return $existingId !== (int)$excludingDiscountId;
    }

    private function updateDiscount(
        int $discountId,
        string $name,
        string $type,
        ?string $code,
        string $valueType,
        float $value,
        ?string $startsAt,
        ?string $endsAt,
        ?int $usageLimit,
        ?int $usageLimitPerCustomer,
        ?float $minSpendIncTax,
        int $active,
        array $productIds,
        array $categoryIds,
        array $brandIds,
    ) {
        $this->admin->model->discount->update($discountId, [
            'name' => $name,
            'type' => $type,
            'code' => $code,
            'value_type' => $valueType,
            'value' => $value,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'usage_limit' => $usageLimit,
            'usage_limit_per_customer' => $usageLimitPerCustomer,
            'min_spend_inc_tax' => $minSpendIncTax,
            'active' => $active,
        ]);

        $this->saveProductScope(discountId: $discountId, productIds: $productIds, categoryIds: $categoryIds, brandIds: $brandIds);

        $this->admin->model->changelog->log(
            description: "Updated discount: {$name}",
            recordType: self::RECORD_TYPE,
            recordId: $discountId,
        );

        $_SESSION['discount_saved'] = true;
        $this->redirect("shop/discounts/edit/?id={$discountId}");
    }

    private function insertDiscount(
        string $name,
        string $type,
        ?string $code,
        string $valueType,
        float $value,
        ?string $startsAt,
        ?string $endsAt,
        ?int $usageLimit,
        ?int $usageLimitPerCustomer,
        ?float $minSpendIncTax,
        int $active,
        array $productIds,
        array $categoryIds,
        array $brandIds,
    ) {
        $newDiscountId = $this->admin->model->discount->insert([
            'name' => $name,
            'type' => $type,
            'code' => $code,
            'value_type' => $valueType,
            'value' => $value,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'usage_limit' => $usageLimit,
            'usage_limit_per_customer' => $usageLimitPerCustomer,
            'min_spend_inc_tax' => $minSpendIncTax,
            'active' => $active,
        ]);

        $this->saveProductScope(discountId: $newDiscountId, productIds: $productIds, categoryIds: $categoryIds, brandIds: $brandIds);

        $this->admin->model->changelog->log(
            description: "Created discount: {$name}",
            recordType: self::RECORD_TYPE,
            recordId: $newDiscountId,
        );

        $_SESSION['discount_saved'] = true;
        $this->redirect("shop/discounts/edit/?id={$newDiscountId}");
    }

    private function saveProductScope(int $discountId, array $productIds, array $categoryIds, array $brandIds): void
    {
        $this->admin->model->discount->replaceProducts($discountId, $productIds);
        $this->admin->model->discount->replaceCategories($discountId, $categoryIds);
        $this->admin->model->discount->replaceBrands($discountId, $brandIds);
    }

    /** Live categories for the scope picker, by name. */
    private function categoryOptions(): array
    {
        $categories = $this->admin->model->productCategory->getSimpleList();
        \usort($categories, static fn(array $a, array $b): int => \strcasecmp($a['label'], $b['label']));

        return $categories;
    }

    // =========================================================================
    // Action Helpers
    // =========================================================================

    private function getRecordName(int $id): string
    {
        $discount = $this->admin->model->discount->getById($id);
        return $discount['name'] ?? "Discount #{$id}";
    }

    /**
     * Copy a discount, with its products, as a paused draft and open it for editing.
     * The code is cleared because codes are unique; the copy has no effect until it is edited and switched on.
     */
    private function duplicate(): void
    {
        $csrfInvalid = !$this->admin->auth->validateCsrf();
        if ($csrfInvalid) $this->redirect('shop/discounts/');

        $sourceId = Input::post()->nullableInt(key: 'id');
        $source = $sourceId ? $this->admin->model->discount->getById($sourceId) : null;
        if (!$source) $this->redirect('shop/discounts/');

        $name = \mb_substr('Copy of ' . $source['name'], 0, 255);

        $newDiscountId = $this->admin->model->discount->insert([
            'name' => $name,
            'type' => $source['type'],
            'code' => null,
            'value_type' => $source['value_type'],
            'value' => $source['value'],
            'starts_at' => $source['starts_at'],
            'ends_at' => $source['ends_at'],
            'usage_limit' => $source['usage_limit'],
            'usage_limit_per_customer' => $source['usage_limit_per_customer'],
            'min_spend_inc_tax' => $source['min_spend_inc_tax'],
            'active' => 0,
        ]);

        $this->saveProductScope(
            discountId: $newDiscountId,
            productIds: $this->loadSelectedProductIds((int)$sourceId),
            categoryIds: $this->admin->model->discount->getCategoryIds((int)$sourceId),
            brandIds: $this->admin->model->discount->getBrandIds((int)$sourceId),
        );

        $this->admin->model->changelog->log(
            description: "Duplicated discount: {$source['name']}",
            recordType: self::RECORD_TYPE,
            recordId: $newDiscountId,
        );

        $this->redirect("shop/discounts/edit/?id={$newDiscountId}");
    }

    private function softDelete(int $id): array
    {
        $this->admin->model->discount->softDelete($id);
        return ['success' => true, 'action' => 'delete', 'id' => $id];
    }

    private function restore(int $id): array
    {
        $this->admin->model->discount->restore($id);
        return ['success' => true, 'action' => 'restore', 'id' => $id];
    }

    private function hardDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \RuntimeException('Permission denied');

        $this->admin->model->discount->hardDelete($id);
        return ['success' => true, 'action' => 'permanently_delete', 'id' => $id];
    }

    private function logAction(string $action, int $id, string $name): void
    {
        $labels = [
            'delete' => 'Deleted',
            'restore' => 'Restored',
            'permanently_delete' => 'Permanently deleted',
        ];

        $label = $labels[$action] ?? \ucfirst($action);

        $this->admin->model->changelog->log(
            description: "{$label} discount: {$name}",
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }

    // =========================================================================
    // Shared Utilities
    // =========================================================================

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
