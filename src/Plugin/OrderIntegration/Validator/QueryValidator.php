<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Validator;

use Scotty42\OrderIntegration\Exception\ValidationException;

class QueryValidator
{
    private const VALID_STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];
    private const HEX_PATTERN = '/^[0-9a-f]{32}$/';

    /** Allowed `sort` values: <field>:<asc|desc> over a small whitelist. */
    private const SORT_PATTERN = '/^(createdAt|updatedAt|orderNumber):(asc|desc)$/';

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

        if (isset($params['sort']) && !preg_match(self::SORT_PATTERN, (string) $params['sort'])) {
            $errors[] = [
                'pointer' => '/sort',
                'code'    => 'invalid_sort',
                'message' => 'sort must be one of createdAt|updatedAt|orderNumber followed by :asc or :desc',
            ];
        }

        if (isset($params['customerId']) && !preg_match(self::HEX_PATTERN, $params['customerId'])) {
            $errors[] = [
                'pointer' => '/customerId',
                'code'    => 'invalid_customer_id',
                'message' => 'customerId must be a 32-character hexadecimal string',
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
                if (!is_array($parsed) || !$this->isValidCursorPayload($parsed)) {
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
     * Accepts the keyset cursor shape emitted by the list endpoint
     * ({field, value, id, dir}) as well as the legacy {createdAt, id} shape.
     *
     * @param array<string,mixed> $parsed
     */
    private function isValidCursorPayload(array $parsed): bool
    {
        $keyset = !empty($parsed['id'])
            && !empty($parsed['field'])
            && array_key_exists('value', $parsed);

        $legacy = !empty($parsed['id']) && !empty($parsed['createdAt']);

        return $keyset || $legacy;
    }
}
