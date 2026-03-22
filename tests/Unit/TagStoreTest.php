<?php
declare(strict_types=1);

namespace EdgeCache\Tests\Unit;

use EdgeCache\TagStore;
use PHPUnit\Framework\TestCase;

final class TagStoreTest extends TestCase
{
    private TagStore $tags;
    private InMemoryAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryAdapter();
        $this->tags = new TagStore($this->adapter);
    }

    public function test_generation_is_stable_for_same_tags(): void
    {
        $gen1 = $this->tags->getGeneration(['post:1', 'term:2']);
        $gen2 = $this->tags->getGeneration(['post:1', 'term:2']);

        $this->assertSame($gen1, $gen2);
    }

    public function test_generation_changes_after_invalidation(): void
    {
        $before = $this->tags->getGeneration(['post:1']);
        $this->tags->invalidate('post:1');
        $after = $this->tags->getGeneration(['post:1']);

        $this->assertNotSame($before, $after);
    }

    public function test_invalidating_one_tag_does_not_affect_others(): void
    {
        $genA = $this->tags->getGeneration(['tag_a']);
        $genB = $this->tags->getGeneration(['tag_b']);

        $this->tags->invalidate('tag_a');

        $genA2 = $this->tags->getGeneration(['tag_a']);
        $genB2 = $this->tags->getGeneration(['tag_b']);

        $this->assertNotSame($genA, $genA2);
        $this->assertSame($genB, $genB2);
    }

    public function test_is_valid_returns_true_for_current_generation(): void
    {
        $gen = $this->tags->getGeneration(['x', 'y']);
        $this->assertTrue($this->tags->isValid($gen, ['x', 'y']));
    }

    public function test_is_valid_returns_false_after_invalidation(): void
    {
        $gen = $this->tags->getGeneration(['x']);
        $this->tags->invalidate('x');
        $this->assertFalse($this->tags->isValid($gen, ['x']));
    }

    public function test_tag_order_does_not_matter(): void
    {
        $gen1 = $this->tags->getGeneration(['b', 'a', 'c']);
        $gen2 = $this->tags->getGeneration(['c', 'a', 'b']);

        $this->assertSame($gen1, $gen2);
    }
}
