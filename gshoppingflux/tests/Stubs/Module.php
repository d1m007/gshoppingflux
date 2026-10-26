<?php

/**
 * Minimal stand-in for PrestaShop's Module base class, so that the
 * GShoppingFlux class itself can be declared in tests. Its constructor
 * is never run (see Tests\ModuleTestCase): only the methods under test
 * are called, on an instance created without constructor.
 */
abstract class Module
{
    public $context;
    public $name = 'gshoppingflux';

    public function l($string, $specific = false, $locale = null)
    {
        return $string;
    }
}
