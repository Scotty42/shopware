<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Validator;

use Scotty42\OrderIntegration\Exception\ValidationException;

class QueryValidator
{
    private const VALID_STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];
    private const HEX_PATTERN = '/^[0-9a-f]{32}$/';

    /** Canonical RFC 4122 UUID (with dashes), case-insensitive. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function validateListParams(array $params): void
    {
        $errors = [];

        if (isset($params['limit'])) {
            $limit = filter_var($params['limit'], FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 200) {
                $errors[] = [
                    'pointer' => '/limit',
                    'code'    => 'invalid_limit',
                    'message' => 'limit must be an integer between 1 and 200',
                ];
            }
        }

        if (isset($params['status']) && !in_array($params['status'], self::VALID_STATUSES, true)) {
            $errors[] = [
                'pointer' => '/status',
                'code'    => 'invalid_status',
                'message' => sprintf(
                    'status must be one of: %s',
                    implode(', ', self::VALID_STATUSES)
                ),
            ];
        }

        if (isset($params['customerId']) && !self::isValidId((string) $params['customerId'])) {
            $errors[] = [
                'pointer' => '/customerId',
                'code'    => 'invalid_customer_id',
                'message' => 'customerId must be a 32-character hex id or a canonical UUID',
            ];
        }

        foreach (['createdAfter', 'createdBefore'] as $field) {
            if (isset($params[$field])) {
                try {
                    new \DateTimeImmutable($params[$field]);
                } catch (\Exception) {
                    $errors[] = [
                        'pointer' => '/' . $field,
                        'code'    => 'invalid_date',
                        'message' => $field . ' must be a valid ISO 8601 date-time string',
                    ];
                }
            }
        }

        if (isset($params['cursor'])) {
            $decoded = base64_decode($params['cursor'], strict: true);
            if ($decoded === false) {
                $errors[] = [
                    'pointer' => '/cursor',
                    'code'    => 'invalid_cursor',
                    'message' => 'cursor must be a valid base64-encoded string',
                ];
            } else {
                $parsed = json_decode($decoded, true);
                if (!is_array($parsed) || empty($parsed['createdAt']) || empty($parsed['id'])) {
                    $errors[] = [
                        'pointer' => '/cursor',
                        'code'    => 'invalid_cursor',
                        'message' => 'cursor payload is malformed',
                    ];
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Accepts both Shopware's internal 32-char hex id and a canonical
     * RFC 4122 UUID (with dashes). Callers should feed the value through
     * normalizeId() before building Shopware filters.
     */
    public static function isValidId(string $id): bool
    {
        return (bool) (preg_match(self::HEX_PATTERN, $id) || preg_match(self::UUID_PATTERN, $id));
    }

    /**
     * Normalizes an id to Shopware's internal form: l-case, dashes removed.
     */
    public static function normalizeId(string $id): string
    {
        return strtolower(str_replace('-', '', $id));
    }
}
