<?php

namespace GShoppingFlux\Tests\Unit;

use GShoppingFlux\ArrayHelper;
use PHPUnit\Framework\TestCase;

class ArrayHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
    }

    public function testSafeExplodeReturnsEmptyArrayForEmptyString()
    {
        $this->assertSame([], ArrayHelper::safeExplode(''));
        $this->assertSame([], ArrayHelper::safeExplode(null));
    }

    public function testSafeExplodeSplitsOnDelimiter()
    {
        $this->assertSame(['1', '2', '3'], array_values(ArrayHelper::safeExplode('1;2;3')));
    }

    public function testSafeExplodeKeepsLegitimateZeroValue()
    {
        // '0' is a real value (e.g. a carrier/category id) and must survive,
        // unlike PHP's own empty()-based filtering which would drop it.
        $this->assertSame(['0', '5'], array_values(ArrayHelper::safeExplode('0;5')));
    }

    public function testSafeExplodeDropsEmptySegments()
    {
        $this->assertSame(['1', '2'], array_values(ArrayHelper::safeExplode('1;;2;')));
    }

    public function testSafeImplodeReturnsEmptyStringForNonArray()
    {
        $this->assertSame('', ArrayHelper::safeImplode('not-an-array'));
        $this->assertSame('', ArrayHelper::safeImplode(null));
    }

    public function testSafeImplodeJoinsWithDelimiter()
    {
        $this->assertSame('1;2;3', ArrayHelper::safeImplode(['1', '2', '3']));
    }

    public function testSafeImplodeKeepsLegitimateZeroValue()
    {
        $this->assertSame('0;5', ArrayHelper::safeImplode(['0', '5']));
    }

    public function testSafeImplodeDropsEmptyAndNullValues()
    {
        $this->assertSame('1;2', ArrayHelper::safeImplode(['1', '', null, '2']));
    }

    public function testSafeImplodeThenSafeExplodeRoundTripsAZeroValue()
    {
        // The bug this pair of methods used to have: safeImplode() kept a
        // '0' but the (now-removed) explodeAndFilter() used !empty() and
        // silently dropped it back out again.
        $imploded = ArrayHelper::safeImplode(['0', '3']);
        $this->assertSame(['0', '3'], array_values(ArrayHelper::safeExplode($imploded)));
    }

    public function testGetColumnExtractsColumn()
    {
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];

        $this->assertSame(['a', 'b'], ArrayHelper::getColumn($rows, 'name'));
    }

    public function testGetColumnExtractsColumnIndexedByKey()
    {
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ];

        $this->assertSame([1 => 'a', 2 => 'b'], ArrayHelper::getColumn($rows, 'name', 'id'));
    }

    public function testIsAssociativeDetectsAssociativeArray()
    {
        $this->assertTrue(ArrayHelper::isAssociative(['a' => 1, 'b' => 2]));
        $this->assertFalse(ArrayHelper::isAssociative([1, 2, 3]));
        $this->assertFalse(ArrayHelper::isAssociative([]));
        $this->assertFalse(ArrayHelper::isAssociative('not-an-array'));
    }

    public function testGetNestedValueReadsDirectKey()
    {
        $this->assertSame('value', ArrayHelper::getNestedValue(['key' => 'value'], 'key'));
    }

    public function testGetNestedValueReadsDotNotation()
    {
        $data = ['a' => ['b' => ['c' => 'deep']]];
        $this->assertSame('deep', ArrayHelper::getNestedValue($data, 'a.b.c'));
    }

    public function testGetNestedValueReturnsDefaultWhenMissing()
    {
        $this->assertSame('fallback', ArrayHelper::getNestedValue(['a' => 1], 'missing', 'fallback'));
        $this->assertNull(ArrayHelper::getNestedValue(['a' => 1], 'missing'));
    }

    public function testGetValueReadsFromPostBeforeGet()
    {
        $_GET['field'] = 'from-get';
        $_POST['field'] = 'from-post';

        $this->assertSame('from-post', ArrayHelper::getValue('field'));
    }

    public function testGetValueReturnsDefaultWhenUnset()
    {
        $this->assertSame('default', ArrayHelper::getValue('missing', 'default'));
    }

    public function testGetArrayValueImplodesArrayInput()
    {
        $_POST['field'] = ['a', 'b'];

        $this->assertSame('a;b', ArrayHelper::getArrayValue('field'));
    }

    public function testGetArrayValueReturnsEmptyStringForNonArrayInput()
    {
        $_POST['field'] = 'scalar';

        $this->assertSame('', ArrayHelper::getArrayValue('field'));
    }
}
