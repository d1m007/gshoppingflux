<?php

namespace GShoppingFlux\Tests;

use Configuration;
use GShoppingFlux;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Base test case for the methods of the real GShoppingFlux class: the
 * instance is created without running its constructor (which needs a
 * PrestaShop context), and private properties are set by reflection.
 */
abstract class ModuleTestCase extends TestCase
{
    protected function setUp(): void
    {
        Configuration::$values = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Configuration::$values = [];
        $_POST = [];
    }

    /**
     * @param array $properties Private property name => value
     * @return GShoppingFlux
     */
    protected function makeModule(array $properties = [])
    {
        $module = (new ReflectionClass(GShoppingFlux::class))->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $property = new ReflectionProperty(GShoppingFlux::class, $name);
            $property->setAccessible(true);
            $property->setValue($module, $value);
        }

        return $module;
    }

    protected function callPrivate($object, $method, array $args = [])
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
