<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Validator;

use Scotty42\OrderIntegration\Exception\ValidationException;

/**
 * Validates the POST /orders payload up front so the creation pipeline is
 * never handed a request it cannot fulfil.
 *
 * Previously create() only checked salesChannelId + lineItems; a request
 * without a customer context produced either an address-less order or a 500
 * from deep inside OrderPersister. This validator turns those cases into a
 * clear 422 with JSON Pointer errors.
 *
 * Supported creation path (Phase 1): a registered customer (customer.id).
 * Guest orders (billingAddress without a customer) are explicitly rejected as
 * not-yet-supported rather than half-processed — see follow-up.
 */
class OrderCreateValidator
{
    public function validate(array $data): void
    {
        $errors = [];

        if (empty($data['salesChannelId'])) {
            $errors[] = $this->err('/salesChannelId', 'required', 'salesChannelId is required');
        }

        if (empty($data['lineItems']) || !is_array($data['lineItems'])) {
            $errors[] = $this->err('/lineItems', 'required', 'lineItems must be a non-empty array');
        } else {
            foreach ($data['lineItems'] as $i => $item) {
                if (!is_array($item) || empty($item['productId'])) {
                    $errors[] = $this->err("/lineItems/{$i}/productId", 'required', 'productId is required');
                }
                if (isset($item['quantity'])) {
                    $qty = filter_var($item['quantity'], FILTER_VALIDATE_INT);
                    if ($qty === false || $qty < 1) {
                        $errors[] = $this->err("/lineItems/{$i}/quantity", 'invalid_quantity', 'quantity must be a positive integer');
                    }
                }
            }
        }

        $hasCustomer = !empty($data['customer']['id']);
        $hasBilling  = !empty($data['billingAddress']) && is_array($data['billingAddress']);

        if (!$hasCustomer && !$hasBilling) {
            $errors[] = $this->err(
                '/customer',
                'customer_context_required',
                'Provide customer.id for a registered customer (the supported path), '
                . 'or billingAddress for a guest order.'
            );
        } elseif (!$hasCustomer && $hasBilling) {
            // Billing address present but no registered customer = guest order.
            // The creation service cannot build guest orders yet; reject
            // explicitly instead of failing deep in the checkout pipeline.
            $errors[] = $this->err(
                '/customer/id',
                'guest_orders_not_supported',
                'Guest order creation (billingAddress without customer.id) is not yet supported. '
                . 'Supply a registered customer.id.'
            );
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @return array{pointer:string,code:string,message:string}
     */
    private function err(string $pointer, string $code, string $message): array
    {
        return ['pointer' => $pointer, 'code' => $code, 'message' => $message];
    }
}
