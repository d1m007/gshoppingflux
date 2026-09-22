<?php

namespace GShoppingFlux\Tests\Unit;

use Category;
use GShoppingFlux\GCategories;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GCategories::getPath()'s breadcrumb-building recursion
 * against an in-memory category tree (see tests/Stubs/Category.php),
 * since there's no database to build a real one against.
 */
class GCategoriesTest extends TestCase
{
    protected function setUp(): void
    {
        Category::$fixtures = [
            1 => ['id_parent' => 0, 'active' => 1, 'name' => 'Root'],
            2 => ['id_parent' => 1, 'active' => 1, 'name' => 'Electronics'],
            3 => ['id_parent' => 2, 'active' => 1, 'name' => 'Phones'],
            4 => ['id_parent' => 1, 'active' => 0, 'name' => 'Discontinued'],
            5 => ['id_parent' => 1, 'active' => 1, 'name' => '10.Numbered Category'],
        ];
    }

    protected function tearDown(): void
    {
        Category::$fixtures = [];
    }

    public function testGetPathBuildsBreadcrumbUpToRoot()
    {
        $path = GCategories::getPath(3, '', 1, 1, 1);

        $this->assertSame('Electronics > Phones', $path);
    }

    public function testGetPathStopsAtRootWithoutIncludingItsName()
    {
        $path = GCategories::getPath(2, '', 1, 1, 1);

        $this->assertSame('Electronics', $path);
    }

    public function testGetPathReturnsUnchangedPathForInactiveCategory()
    {
        $path = GCategories::getPath(4, '', 1, 1, 1);

        $this->assertSame('', $path);
    }

    public function testGetPathReturnsUnchangedPathForUnknownCategory()
    {
        $path = GCategories::getPath(999, '', 1, 1, 1);

        $this->assertSame('', $path);
    }

    public function testGetPathStripsPrestashopNumericPrefixFromNames()
    {
        // PrestaShop stores same-named categories as "123.Category Name"
        // to keep them unique; the display breadcrumb must not show that.
        $path = GCategories::getPath(5, '', 1, 1, 1);

        $this->assertSame('Numbered Category', $path);
    }
}
