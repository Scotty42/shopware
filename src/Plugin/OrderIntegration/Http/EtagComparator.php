<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Http;

/**
 * Evaluates an If-Match header against the resource's current ETag for
 * optimistic concurrency (docs/order-api-concept.md §5.5).
 *
 * Note on comparison strength: this API issues *weak* ETags (W/"...") derived
 * from versionId + updatedAt (see OrderMapper::etagFor). RFC 7232 specifies a
 * strong comparison for If-Match, under which weak validators never match. To
 * keep If-Match usable with our weak validators we deliberately use a weak
 * comparison (strip the optional W/ prefix and quotes, then compare). This is
 * a documented, intentional deviation for a service-to-service API.
 */
final class EtagComparator
{
    /**
     * True when the (non-empty) If-Match header satisfies $currentEtag.
     * Supports the "*" wildcard and a comma-separated list of candidates.
     * Returns false for null/empty — the caller decides whether If-Match is
     * mandatory and raises 428 itself.
     */
    public function ifMatchSatisfied(?string $ifMatch, string $currentEtag): bool
    {
        if ($ifMatch === null) {
            return false;
        }

        $ifMatch = trim($ifMatch);
        if ($ifMatch === '') {
            return false;
        }

        if ($ifMatch === '*') {
            return true;
        }

        $current = $this->normalize($currentEtag);
        foreach (explode(',', $ifMatch) as $candidate) {
            if ($this->normalize($candidate) === $current) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $tag): string
    {
        $tag = trim($tag);
        if (str_starts_with($tag, 'W/')) {
            $tag = substr($tag, 2);
        }

        return trim($tag, '"');
    }
}
