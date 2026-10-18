<?php

declare(strict_types=1);

namespace Tests\Integration\Checkout;

use Osmium\Modules\Checkout\Models\CheckoutBasket;
use Osmium\Modules\Checkout\Services\CheckoutBasketService;
use Osmium\Modules\Checkout\Services\ShopOrderException;
use Tests\Integration\DatabaseTestCase;

class CheckoutBasketServiceTest extends DatabaseTestCase
{
    private CheckoutBasketService $baskets;
    private int $testProductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baskets = new CheckoutBasketService(new CheckoutBasket($this->db, 'checkout_baskets'));

        $this->testProductId = $this->createTestProduct();
    }

    public function test_add_line_creates_a_priced_line(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $basketId = $this->baskets->getOrCreateBasket($sessionId);

        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 2);

        $lines = $this->baskets->getLines($basketId)['lines'];

        $this->assertCount(expectedCount: 1, haystack: $lines);
        $this->assertSame(expected: 2, actual: (int)$lines[0]['quantity']);
        $this->assertGreaterThan(expected: 0.0, actual: (float)$lines[0]['unit_price_exc_tax']);
    }

    public function test_adding_the_same_product_twice_increments_quantity(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $basketId = $this->baskets->getOrCreateBasket($sessionId);

        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 3);

        $lines = $this->baskets->getLines($basketId)['lines'];

        $this->assertCount(expectedCount: 1, haystack: $lines);
        $this->assertSame(expected: 4, actual: (int)$lines[0]['quantity']);
    }

    public function test_adding_an_unknown_product_throws(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $basketId = $this->baskets->getOrCreateBasket($sessionId);

        $this->expectException(ShopOrderException::class);

        $this->baskets->addLine(basketId: $basketId, productId: 999999999, quantity: 1);
    }

    public function test_get_lines_returns_price_and_weight_as_float(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $basketId = $this->baskets->getOrCreateBasket($sessionId);
        $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $lines = $this->baskets->getLines($basketId)['lines'];

        $this->assertIsFloat($lines[0]['unit_price_exc_tax']);
        $this->assertIsFloat($lines[0]['weight']);
    }

    public function test_remove_line_deletes_it(): void
    {
        $sessionId = 'test-session-' . \uniqid();
        $basketId = $this->baskets->getOrCreateBasket($sessionId);
        $itemId = $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $removed = $this->baskets->removeLine(basketId: $basketId, itemId: $itemId);

        $this->assertTrue($removed);
        $this->assertCount(expectedCount: 0, haystack: $this->baskets->getLines($basketId)['lines']);
    }

    public function test_update_quantity_changes_the_line(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('test-session-' . \uniqid());
        $itemId = $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 1);

        $updated = $this->baskets->updateQuantity(basketId: $basketId, itemId: $itemId, quantity: 5);

        $this->assertTrue($updated);
        $this->assertSame(expected: 5, actual: (int)$this->baskets->getLines($basketId)['lines'][0]['quantity']);
    }

    public function test_update_quantity_below_one_removes_the_line(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('test-session-' . \uniqid());
        $itemId = $this->baskets->addLine(basketId: $basketId, productId: $this->testProductId, quantity: 2);

        $this->baskets->updateQuantity(basketId: $basketId, itemId: $itemId, quantity: 0);

        $this->assertCount(expectedCount: 0, haystack: $this->baskets->getLines($basketId)['lines']);
    }

    public function test_update_quantity_for_an_unknown_item_returns_false(): void
    {
        $basketId = $this->baskets->getOrCreateBasket('test-session-' . \uniqid());

        $this->assertFalse($this->baskets->updateQuantity(basketId: $basketId, itemId: 999999999, quantity: 3));
    }
}
