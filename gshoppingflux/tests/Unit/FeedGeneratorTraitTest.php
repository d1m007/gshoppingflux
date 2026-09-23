<?php

namespace GShoppingFlux\Tests\Unit;

use GShoppingFlux\Traits\FeedGeneratorTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises the pure-string pieces of FeedGeneratorTrait (CDATA escaping,
 * word-boundary truncation) through a bare test double, without needing
 * any of the PrestaShop core classes the rest of the trait depends on —
 * those methods are never invoked here.
 */
class FeedGeneratorTraitTest extends TestCase
{
    private function callPrivate($method, array $args)
    {
        $object = new class() {
            use FeedGeneratorTrait;
        };

        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    public function testCdataSafeLeavesOrdinaryStringUntouched()
    {
        $this->assertSame('Red T-Shirt', $this->callPrivate('cdataSafe', ['Red T-Shirt']));
    }

    public function testCdataSafeEscapesCdataTerminator()
    {
        // A literal "]]>" in product data must not be able to close the
        // enclosing <![CDATA[ ... ]]> section early.
        $result = $this->callPrivate('cdataSafe', ['before]]>after']);

        $this->assertSame('before]]]]><![CDATA[>after', $result);
        $this->assertStringNotContainsString(']]>after', $result);
    }

    public function testCdataSafeCastsNonStringInput()
    {
        $this->assertSame('123', $this->callPrivate('cdataSafe', [123]));
    }

    public function testTruncateAtWordBoundaryLeavesShortStringUnchanged()
    {
        $this->assertSame('short', $this->callPrivate('truncateAtWordBoundary', ['short', 150]));
    }

    public function testTruncateAtWordBoundarySnapsToLastSpace()
    {
        $result = $this->callPrivate('truncateAtWordBoundary', ['one two three four', 10]);

        // Hard cut at 9 chars would be "one two t"; it snaps back to the
        // last space so the cut word isn't left dangling.
        $this->assertSame('one two', $result);
    }

    public function testTruncateAtWordBoundaryKeepsHardCutWhenNoSpaceToSnapTo()
    {
        // Regression test: strrpos() returning false must not be fed
        // straight into substr()'s length argument (false casts to 0
        // there), which used to silently produce an empty string.
        $result = $this->callPrivate('truncateAtWordBoundary', ['abcdefghij', 5]);

        $this->assertSame('abcd', $result);
        $this->assertNotSame('', $result);
    }
}
