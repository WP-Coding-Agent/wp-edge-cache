<?php
declare(strict_types=1);

namespace EdgeCache;

/**
 * Tag-based cache invalidation.
 *
 * Maintains tag → generation mappings. Each tag has a generation counter.
 * Cache keys include the generation of their tags at write time. When a tag
 * is invalidated, its generation increments, making all entries referencing
 * the old generation effectively stale.
 *
 * This avoids the need to enumerate and delete individual keys.
 */
final class TagStore
{
    public function __construct(
        private readonly CacheAdapterInterface $adapter,
    ) {}

    /**
     * Get the compound generation string for a set of tags.
     * Used when writing a cache entry to stamp its tag versions.
     *
     * @param string[] $tags
     * @return string Deterministic hash of current tag generations.
     */
    public function getGeneration(array $tags): string
    {
        if (empty($tags)) {
            return '';
        }

        sort($tags);
        $generations = [];

        foreach ($tags as $tag) {
            $gen = $this->adapter->get($this->tagKey($tag));
            if ($gen === false) {
                $gen = 1;
                $this->adapter->set($this->tagKey($tag), $gen, 0);
            }
            $generations[] = "{$tag}:{$gen}";
        }

        return md5(implode('|', $generations));
    }

    /**
     * Invalidate one or more tags by incrementing their generation.
     *
     * @param string|string[] $tags
     */
    public function invalidate(string|array $tags): void
    {
        if (is_string($tags)) {
            $tags = [$tags];
        }

        foreach ($tags as $tag) {
            $current = $this->adapter->get($this->tagKey($tag));
            $next = ($current === false) ? 2 : (int)$current + 1;
            $this->adapter->set($this->tagKey($tag), $next, 0);
        }
    }

    /**
     * Verify that a cached entry's tag generation still matches.
     *
     * @param string   $storedGeneration The generation hash from when the entry was cached.
     * @param string[] $tags             The tags associated with the entry.
     * @return bool True if the entry is still valid for its tags.
     */
    public function isValid(string $storedGeneration, array $tags): bool
    {
        if (empty($tags) && $storedGeneration === '') {
            return true;
        }

        return $storedGeneration === $this->getGeneration($tags);
    }

    private function tagKey(string $tag): string
    {
        return "tag_gen:{$tag}";
    }
}
