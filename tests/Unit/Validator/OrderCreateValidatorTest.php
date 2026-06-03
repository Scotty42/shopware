<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Validator\OrderCreateValidator;

class OrderCreateValidatorTest extends TestCase
{
    private OrderCreateValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OrderCreateValidator();
    }

    /**
     * @return array<string,mixed>
     */
    private function validRegisteredPayload(): array
    {
        return [
            'salesChannelId' => '0123456789abcdef0123456789abcdef',
            'customer'       => ['id' => 'fedcba9876543210fedcba9876543210'],
            'lineItems'      => [
                ['productId' => 'aaaa1111bbbb2222cccc3333dddd4444', 'quantity' => 2],
            ],
        ];
    }

    public function testAcceptsValidRegisteredCustomerPayload(): void
    {
        $this->validator->validate($this->validRegisteredPayload());
        $this->expectNotToPerformAssertions();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertFailsWithCode(array $payload, string $expectedCode): void
    {
        try {
            $this->validator->validate($payload);
            $this->fail('Expected ValidationException with code ' . $expectedCode);
        } catch (ValidationException $e) {
            $codes = array_column($e->getValidationErrors(), 'code');
            $this->assertContains($expectedCode, $codes, 'codes: ' . implode(',', $codes));
        }
    }

    public function testRequiresSalesChannelId(): void
    {
        $p = $this->validRegisteredPayload();
        unset($p['salesChannelId']);
        $this->assertFailsWithCode($p, 'required');
    }

    public function testRequiresNonEmptyLineItems(): void
    {
        $p = $this->validRegisteredPayload();
        $p['lineItems'] = [];
        $this->assertFailsWithCode($p, 'required');
    }

    public function testRejectsLineItemWithoutProductId(): void
    {
        $p = $this->validRegisteredPayload();
        $p['lineItems'] = [['quantity' => 1]];
        $this->assertFailsWithCode($p, 'required');
    }

    public function testRejectsNonPositiveQuantity(): void
    {
        $p = $this->validRegisteredPayload();
        $p['lineItems'] = [['productId' => 'aaaa1111bbbb2222cccc3333dddd4444', 'quantity' => 0]];
        $this->assertFailsWithCode($p, 'invalid_quantity');
    }

    public function testRejectsMissingCustomerContext(): void
    {
        $p = $this->validRegisteredPayload();
        unset($p['customer']);
        $this->assertFailsWithCode($p, 'customer_context_required');
    }

    public function testRejectsGuestOrderAsUnsupported(): void
    {
        $p = $this->validRegisteredPayload();
        unset($p['customer']);
        $p['billingAddress'] = [
            'firstName' => 'Ada', 'lastName' => 'Lovelace', 'street' => 'Main 1',
            'zipcode' => '12345', 'city' => 'Berlin', 'countryCode' => 'DE',
        ];
        $this->assertFailsWithCode($p, 'guest_orders_not_supported');
    }
}
