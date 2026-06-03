<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Read;

/**
 * Process-local projection for unit tests and single-process use. Mirrors the
 * filter + keyset-pagination semantics of PdoReadProjection (createdAt DESC,
 * id tiebreaker) so the read path can be tested without a database.
 */
final class InMemoryReadProjection implements ReadProjectionInterface
{
    /** @var array<string,array<string,mixed>> */
    private array $rows = [];

    public function upsert(array $snapshot): void
    {
        $id = (string) ($snapshot['id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('snapshot must have an id');
        }
        $this->rows[$id] = $snapshot;
    }

    public function get(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function list(array $filters, int $limit, ?string $cursor): array
    {
        $rows = array_values($this->rows);

        if (isset($filters['status'])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['status'] ?? null) === $filters['status']));
        }
        if (isset($filters['salesChannelId'])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['salesChannelId'] ?? null) === $filters['salesChannelId']));
        }

        // createdAt DESC, id DESC
        usort($rows, static function (array $a, array $b): int {
            return [$b['createdAt'] ?? '', $b['id'] ?? ''] <=> [$a['createdAt'] ?? '', $a['id'] ?? ''];
        });

        if ($cursor !== null && $cursor !== '') {
            $decoded = json_decode(base64_decode($cursor), true);
            if (is_array($decoded) && isset($decoded['createdAt'], $decoded['id'])) {
                $rows = array_values(array_filter($rows, static function (array $r) use ($decoded): bool {
                    return [$r['createdAt'] ?? '', $r['id'] ?? ''] < [$decoded['createdAt'], $decoded['id']];
                }));
            }
        }

        $page = array_slice($rows, 0, $limit);
        $nextCursor = null;
        if (count($rows) > $limit && $page !== []) {
            $last = $page[count($page) - 1];
            $nextCursor = base64_encode(json_encode(['createdAt' => $last['createdAt'] ?? null, 'id' => $last['id'] ?? null]));
        }

        return ['items' => $page, 'nextCursor' => $nextCursor];
    }

    public function delete(string $id): void
    {
        unset($this->rows[$id]);
    }
}
