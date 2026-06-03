<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Read;

/**
 * Production read projection backed by PostgreSQL (JSONB).
 * Schema: see docs/infrastructure-setup.md (table order_read_projection).
 */
final class PdoReadProjection implements ReadProjectionInterface
{
    public function __construct(private readonly \Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider $connection)
    {
    }

    public function upsert(array $snapshot): void
    {
        $sql = 'INSERT INTO order_read_projection (id, status, sales_channel_id, created_at, updated_at, data)
                VALUES (:id, :status, :scid, :created_at, :updated_at, :data)
                ON CONFLICT (id) DO UPDATE SET
                    status = EXCLUDED.status,
                    sales_channel_id = EXCLUDED.sales_channel_id,
                    created_at = EXCLUDED.created_at,
                    updated_at = EXCLUDED.updated_at,
                    data = EXCLUDED.data';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'id'         => (string) $snapshot['id'],
            'status'     => $snapshot['status'] ?? null,
            'scid'       => $snapshot['salesChannelId'] ?? null,
            'created_at' => $snapshot['createdAt'] ?? null,
            'updated_at' => $snapshot['updatedAt'] ?? null,
            'data'       => json_encode($snapshot),
        ]);
    }

    public function get(string $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT data FROM order_read_projection WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetchColumn();

        if (!is_string($data)) {
            return null;
        }
        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function list(array $filters, int $limit, ?string $cursor): array
    {
        $where = ['1=1'];
        $params = [];

        if (isset($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (isset($filters['salesChannelId'])) {
            $where[] = 'sales_channel_id = :scid';
            $params['scid'] = $filters['salesChannelId'];
        }

        if ($cursor !== null && $cursor !== '') {
            $decoded = json_decode(base64_decode($cursor), true);
            if (is_array($decoded) && isset($decoded['createdAt'], $decoded['id'])) {
                $where[] = '(created_at, id) < (:c_created, :c_id)';
                $params['c_created'] = $decoded['createdAt'];
                $params['c_id'] = $decoded['id'];
            }
        }

        $sql = 'SELECT id, created_at, data FROM order_read_projection
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit + 1, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map(static function (array $r): array {
            $decoded = json_decode((string) $r['data'], true);

            return is_array($decoded) ? $decoded : [];
        }, $rows);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[count($rows) - 1];
            $nextCursor = base64_encode(json_encode(['createdAt' => $last['created_at'], 'id' => $last['id']]));
        }

        return ['items' => $items, 'nextCursor' => $nextCursor];
    }

    public function delete(string $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM order_read_projection WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
