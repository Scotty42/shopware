<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Erp;

/**
 * Pure decision logic for the ERP pull/acknowledge workflow.
 *
 * "Synced" means the order has been pulled by the ERP iPaaS and acknowledged,
 * recorded as an ISO-8601 timestamp under order.customFields[FIELD]. Using a
 * customField keeps the flag schema-free (no Shopware migration) while still
 * being filterable via the DAL (customFields.<key>).
 *
 * Framework-free so it is fully unit-testable.
 */
final class ErpSyncPolicy
{
    /** customFields key carrying the acknowledgement timestamp. */
    public const FIELD = 'erpSyncedAt';

    /** customFields key carrying the ERP's own order identifier. */
    public const FIELD_ERP_ID = 'erpOrderId';

    /**
     * @param array<string,mixed>|null $customFields
     */
    public function isSynced(?array $customFields): bool
    {
        return !empty($customFields[self::FIELD]);
    }

    /**
     * DAL update patch that stamps an order as synced. customFields updates are
     * merged by Shopware, so other keys are preserved.
     *
     * @return array{id:string,customFields:array<string,string>}
     */
    public function acknowledgementPatch(string $orderId, \DateTimeInterface $now, ?string $erpOrderId = null): array
    {
        $customFields = [self::FIELD => $now->format(\DateTimeInterface::ATOM)];
        if ($erpOrderId !== null) {
            $customFields[self::FIELD_ERP_ID] = $erpOrderId;
        }

        return ['id' => $orderId, 'customFields' => $customFields];
    }

    /**
     * Plans a batch acknowledgement without touching the database. Partitions
     * the requested ids into newly-acknowledged (with patches), already-synced
     * (idempotent no-op, first timestamp preserved), and not-found.
     *
     * @param array<string,array<string,mixed>|null> $existingCustomFieldsById
     *        map orderId => its customFields (or null) for every order that exists
     * @param list<string> $requestedIds
     * @param array<string,string> $erpOrderIds optional map of shopwareId => erpOrderId
     *
     * @return array{
     *     patches: list<array{id:string,customFields:array<string,string>}>,
     *     acknowledged: list<string>,
     *     alreadySynced: list<string>,
     *     notFound: list<string>
     * }
     */
    public function planAcknowledgement(array $existingCustomFieldsById, array $requestedIds, \DateTimeInterface $now, array $erpOrderIds = []): array
    {
        $patches = [];
        $acknowledged = [];
        $alreadySynced = [];
        $notFound = [];

        foreach (array_values(array_unique($requestedIds)) as $id) {
            if (!array_key_exists($id, $existingCustomFieldsById)) {
                $notFound[] = $id;
                continue;
            }

            if ($this->isSynced($existingCustomFieldsById[$id])) {
                $alreadySynced[] = $id;
                continue;
            }

            $patches[] = $this->acknowledgementPatch($id, $now, $erpOrderIds[$id] ?? null);
            $acknowledged[] = $id;
        }

        return [
            'patches'       => $patches,
            'acknowledged'  => $acknowledged,
            'alreadySynced' => $alreadySynced,
            'notFound'      => $notFound,
        ];
    }
}
