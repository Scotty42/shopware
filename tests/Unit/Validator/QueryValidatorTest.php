<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Validator\QueryValidator;

/**
 * Pure-PHP unit tests for QueryValidator. No Shopware kernel required.
 */
final class QueryValidatorTest extends TestCase
{
    private QueryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new QueryValidator();
    }

    public function testAcceptsEmptyParams(): void
    {
        $this->validator->validateListParams([]);
        $this->expectNotToPerformAssertions();
    }

    public function testAcceptsAllValidParams(): void
    {
        $this->validator->validateListParams([
            'limit'         => '50',
            'status'        => 'open',
            'customerId'    => str_repeat('a', 32),
            'createdAfter'  => '2025-01-01T00:00:00+00:00',
            'createdBefore' => '2025-12-31T23:59:59+00:00',
            'cursor'        => base64_encode(json_encode([
                'createdAt' => '2025-06-01T00:00:00+00:00',
                'id'        => str_repeat('b', 32),
            ])),
        ]);
        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('invalidLimitProvider')]
    public function testRejectsInvalidLimit(mixed $limit): void
    {
        try {
            $this->validator->validateListParams(['limit' => $limit]);
            $this->fail('Expected ValidationException for limit=' . var_export($limit, true));
        } catch (ValidationException $e) {
            $this->assertSame('order.validation_failed', $e->getErrorCode());
            $this->assertCount(1, $e->getValidationErrors());
            $this->assertSame('/limit', $e->getValidationErrors()[0]['pointer']);
            $this->assertSame('invalid_limit', $e->getValidationErrors()[0]['code']);
        }
    }

    public static function invalidLimitProvider(): array
    {
        return [
            'zero'            => [0],
            'negative'        => [-1],
            'too_large'       => [201],
            'huge'            => [9999],
            'non_numeric'     => ['banana'],
            'empty_string'    => [''],
        ];
    }

    #[DataProvider('validStatusProvider')]
    public function testAcceptsValidStatus(string $status): void
    {
        $this->validator->validateListParams(['status' => $status]);
        $this->expectNotToPerformAssertions();
    }

    public static function validStatusProvider(): array
    {
        return [
            ['open'],
            ['in_progress'],
            ['completed'],
            ['cancelled'],
        ];
    }

    public function testRejectsUnknownStatus(): void
    {
        try {
            $this->validator->validateListParams(['status' => 'something_else']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getValidationErrors();
            $this->assertSame('/status', $errors[0]['pointer']);
            $this->assertSame('invalid_status', $errors[0]['code']);
        }
    }

    public function testRejectsCustomerIdShorterThan32(): void
    {
        try {
            $this->validator->validateListParams(['customerId' => 'abc123']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_customer_id', $e->getValidationErrors()[0]['code']);
        }
    }

    public function testRejectsCustomerIdWithUppercase(): void
    {
        try {
            $this->validator->validateListParams([
                'customerId' => strtoupper(str_repeat('a', 32)),
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_customer_id', $e->getValidationErrors()[0]['code']);
        }
    }

    public function testAcceptsCustomerIdWithLowercaseHex32(): void
    {
        $this->validator->validateListParams(['customerId' => '0123456789abcdef0123456789abcdef']);
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsInvalidDateAfter(): void
    {
        try {
            $this->validator->validateListParams(['createdAfter' => 'not-a-date']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getValidationErrors();
            $this->assertSame('/createdAfter', $errors[0]['pointer']);
            $this->assertSame('invalid_date', $errors[0]['code']);
        }
    }

    public function testRejectsInvalidDateBefore(): void
    {
        try {
            $this->validator->validateListParams(['createdBefore' => 'not-a-date']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('/createdBefore', $e->getValidationErrors()[0]['pointer']);
        }
    }

    public function testRejectsMalformedCursorBase64(): void
    {
        try {
            $this->validator->validateListParams(['cursor' => '@@@not-base64@@@']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_cursor', $e->getValidationErrors()[0]['code']);
        }
    }

    public function testRejectsCursorWithMissingFields(): void
    {
        $bad = base64_encode(json_encode(['only_one_field' => 'x']));
        try {
            $this->validator->validateListParams(['cursor' => $bad]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_cursor', $e->getValidationErrors()[0]['code']);
        }
    }

    public function testReportsAllErrorsInOneShot(): void
    {
        try {
            $this->validator->validateListParams([
                'limit'      => 999,
                'status'     => 'banana',
                'customerId' => 'too-short',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $codes = array_column($e->getValidationErrors(), 'code');
            $this->assertContains('invalid_limit', $codes);
            $this->assertContains('invalid_status', $codes);
            $this->assertContains('invalid_customer_id', $codes);
            $this->assertCount(3, $e->getValidationErrors());
        }
    }

    public function testAcceptsValidSalesChannelId(): void
    {
        $this->validator->validateListParams(['salesChannelId' => '0123456789abcdef0123456789abcdef']);
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsInvalidSalesChannelId(): void
    {
        try {
            $this->validator->validateListParams(['salesChannelId' => 'not-hex']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('/salesChannelId', $e->getValidationErrors()[0]['pointer']);
            $this->assertSame('invalid_sales_channel_id', $e->getValidationErrors()[0]['code']);
        }
    }
}
