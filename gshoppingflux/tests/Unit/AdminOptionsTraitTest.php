<?php

namespace GShoppingFlux\Tests\Unit;

use Configuration;
use GShoppingFlux;
use GShoppingFlux\Tests\ModuleTestCase;

/**
 * Covers the saving/sanitizing of the "Additional Google attributes"
 * admin options, without a PrestaShop admin request.
 */
class AdminOptionsTraitTest extends ModuleTestCase
{
    public function testSanitizeLabelSource()
    {
        $module = $this->makeModule();
        $valid = GShoppingFlux::VALID_LABEL_SOURCES;

        $this->assertSame('manufacturer', $this->callPrivate($module, 'sanitizeLabelSource', ['manufacturer', $valid]));
        $this->assertSame('feature_12', $this->callPrivate($module, 'sanitizeLabelSource', ['feature_12', $valid]));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeLabelSource', ['feature_0', $valid]));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeLabelSource', ['feature_1;DROP', $valid]));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeLabelSource', ['features', $valid]));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeLabelSource', [false, $valid]));
    }

    public function testSanitizeFeatureId()
    {
        $module = $this->makeModule();

        $this->assertSame('7', $this->callPrivate($module, 'sanitizeFeatureId', ['7']));
        $this->assertSame('7', $this->callPrivate($module, 'sanitizeFeatureId', ['007']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeFeatureId', ['0']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeFeatureId', ['-3']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeFeatureId', ['']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeFeatureId', [false]));
    }

    public function testSanitizeCountryCode()
    {
        $module = $this->makeModule();

        $this->assertSame('FR', $this->callPrivate($module, 'sanitizeCountryCode', [' fr ']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeCountryCode', ['FRA']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeCountryCode', ['F1']));
        $this->assertSame('', $this->callPrivate($module, 'sanitizeCountryCode', [false]));
    }

    public function testSanitizeHandlingTimes()
    {
        $module = $this->makeModule();

        $this->assertSame(['1', '3'], $this->callPrivate($module, 'sanitizeHandlingTimes', ['1', '3']));
        $this->assertSame(['1', '3'], $this->callPrivate($module, 'sanitizeHandlingTimes', ['3', '1']));
        $this->assertSame(['0', ''], $this->callPrivate($module, 'sanitizeHandlingTimes', ['0', '']));
        $this->assertSame(['', '2'], $this->callPrivate($module, 'sanitizeHandlingTimes', ['-1', ' 2 ']));
        $this->assertSame(['', ''], $this->callPrivate($module, 'sanitizeHandlingTimes', ['abc', false]));
    }

    public function testSaveAdditionalAttributesOptionsRoundTrip()
    {
        $module = $this->makeModule();
        $_POST = [
            'gtin_isbn' => '1',
            'oos_availability' => 'backorder',
            'unit_pricing_measure' => '0',
            'size_system' => 'EU',
            'size_type' => 'not-a-size-type',
            'ships_from_country' => 'de',
            'min_handling_time' => '5',
            'max_handling_time' => '2',
            'excluded_destinations' => ['Display_ads', 'Evil', 'Free_listings'],
            'energy_class' => '9',
            'custom_label_0' => 'supplier',
            'custom_label_4' => 'feature_3',
            'shipping_label' => '<script>',
        ];

        $this->assertTrue($this->callPrivate($module, 'saveAdditionalAttributesOptions', [1, 1]));
        $conf = $module->getConfigFieldsValues(1);

        $this->assertSame(1, $conf['gtin_isbn']);
        $this->assertSame('backorder', $conf['oos_availability']);
        $this->assertSame(0, $conf['unit_pricing_measure'], 'An explicitly disabled option must not fall back to its default');
        $this->assertSame('EU', $conf['size_system']);
        $this->assertSame('', $conf['size_type']);
        $this->assertSame('DE', $conf['ships_from_country']);
        $this->assertSame('2', $conf['min_handling_time']);
        $this->assertSame('5', $conf['max_handling_time']);
        $this->assertSame(['Display_ads', 'Free_listings'], array_values($conf['excluded_destinations[]']));
        $this->assertSame('9', $conf['energy_class']);
        $this->assertSame('supplier', $conf['custom_label_0']);
        $this->assertSame('feature_3', $conf['custom_label_4']);
        $this->assertSame('', $conf['shipping_label']);
        $this->assertSame(0, $conf['adult']);
    }

    public function testInvalidOutOfStockAvailabilityFallsBackToPreorder()
    {
        $module = $this->makeModule();
        $_POST = ['oos_availability' => 'in stock'];

        $this->callPrivate($module, 'saveAdditionalAttributesOptions', [1, 1]);

        $this->assertSame('preorder', Configuration::$values['GS_OOS_AVAILABILITY']);
    }

    public function testEveryNewSettingHasADefault()
    {
        $module = $this->makeModule();
        $this->callPrivate($module, 'saveAdditionalAttributesOptions', [1, 1]);

        foreach (array_keys(Configuration::$values) as $key) {
            $this->assertArrayHasKey($key, GShoppingFlux::CONFIG_DEFAULTS, $key . ' is saved but has no default (it would never be removed on uninstall)');
        }
    }
}
