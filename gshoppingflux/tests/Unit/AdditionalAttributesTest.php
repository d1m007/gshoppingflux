<?php

namespace GShoppingFlux\Tests\Unit;

use Category;
use Configuration;
use Currency;
use GShoppingFlux;
use GShoppingFlux\Tests\ModuleTestCase;
use Product;

/**
 * Covers the "Additional Google attributes" of the feed: the pure value
 * helpers, and the XML built by buildAdditionalAttributesXml() /
 * buildAvailabilityXml(), checking above all that a shop which didn't
 * enable any new option (or was upgraded without the new GS_* keys)
 * keeps the exact same feed.
 */
class AdditionalAttributesTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Category::$fixtures = [];
    }

    /**
     * Module configuration as generateFile() loads it, for a shop where
     * none of the 1.9.0 GS_* keys exist yet, merged with $overrides.
     */
    private function moduleConf(array $overrides = [])
    {
        $module = $this->makeModule();

        return array_merge($module->getConfigFieldsValues(1), $overrides);
    }

    private function makeFeedModule(array $conf, array $properties = [])
    {
        return $this->makeModule(array_merge([
            'module_conf' => $conf,
            'free_shipping' => ['PS_SHIPPING_FREE_PRICE' => '0', 'PS_SHIPPING_FREE_WEIGHT' => '0'],
            'categories_values' => [],
            'ps_stock_management' => '1',
        ], $properties));
    }

    private function product(array $overrides = [])
    {
        return array_merge([
            'id_product' => 7,
            'name' => 'Basic T-shirt',
            'category_default' => 3,
            'id_manufacturer' => 0,
            'id_supplier' => 0,
            'on_sale' => '0',
            'condition' => 'new',
            'reference' => 'REF-7',
            'size' => '',
            'width' => '30',
            'height' => '2',
            'depth' => '40',
            'weight' => '0.25',
            'wholesale_price' => '4.20',
            'cache_is_pack' => '0',
            'available_date' => '0000-00-00',
            'item_group_id' => 7,
            'variant_options' => [],
        ], $overrides);
    }

    // ---- no regression ------------------------------------------------

    public function testUpgradedShopGetsHistoricalDefaults()
    {
        $conf = $this->moduleConf();

        $this->assertSame(1, $conf['unit_pricing_measure'], 'unit_pricing_measure must stay exported by default');
        $this->assertSame('preorder', $conf['oos_availability']);
        $this->assertSame(0, $conf['gtin_isbn']);
        $this->assertSame([], $conf['excluded_destinations[]']);
        foreach (['custom_label_0', 'custom_label_4', 'shipping_label', 'return_policy_label', 'size_system', 'min_handling_time'] as $field) {
            $this->assertSame('', $conf[$field], $field);
        }
    }

    public function testNoAdditionalAttributeIsExportedByDefault()
    {
        $module = $this->makeFeedModule($this->moduleConf());
        $xml = $this->callPrivate($module, 'buildAdditionalAttributesXml', [
            $this->product(['size' => 'XL', 'cache_is_pack' => '1']),
            new Currency(),
            1,
            1,
            12,
            [['id_feature' => 4, 'name' => 'Composition', 'value' => 'Cotton']],
        ]);

        $this->assertSame('', $xml);
    }

    public function testDefaultOutOfStockAvailabilityStaysPreorder()
    {
        $module = $this->makeFeedModule($this->moduleConf(['quantity' => 1]));
        $p = new Product();
        $p->orderableWhenOutOfStock = true;

        $xml = $this->callPrivate($module, 'buildAvailabilityXml', [
            ['category_default' => 3, 'quantity' => 0, 'available_for_order' => 1, 'available_date' => '2099-01-01'],
            $p,
            true,
        ]);

        $this->assertSame('<g:quantity>0</g:quantity>' . "\n" . '<g:availability>preorder</g:availability>' . "\n", $xml);
    }

    public function testAvailabilityOutputIsUnchangedForEveryStockCase()
    {
        $module = $this->makeFeedModule($this->moduleConf(['quantity' => 0]));
        $p = new Product();

        $in_stock = $this->callPrivate($module, 'buildAvailabilityXml', [['category_default' => 3, 'quantity' => 5, 'available_for_order' => 1], $p]);
        $out_of_stock = $this->callPrivate($module, 'buildAvailabilityXml', [['category_default' => 3, 'quantity' => 0, 'available_for_order' => 1], $p]);

        $this->assertSame('<g:availability>in stock</g:availability>' . "\n", $in_stock);
        $this->assertSame('<g:availability>out of stock</g:availability>' . "\n", $out_of_stock);

        $module = $this->makeFeedModule($this->moduleConf(['quantity' => 0]), ['ps_stock_management' => '0']);
        $not_orderable = $this->callPrivate($module, 'buildAvailabilityXml', [['category_default' => 3, 'quantity' => 0, 'available_for_order' => 0], $p]);
        $this->assertSame('<g:availability>out of stock</g:availability>' . "\n", $not_orderable);
    }

    // ---- availability -------------------------------------------------

    public function testBackorderAndAvailabilityDate()
    {
        $module = $this->makeFeedModule($this->moduleConf(['oos_availability' => 'backorder', 'availability_date' => 1, 'quantity' => 0]));
        $p = new Product();
        $p->orderableWhenOutOfStock = true;
        $product = ['category_default' => 3, 'quantity' => 0, 'available_for_order' => 1, 'available_date' => '2099-01-15'];

        $xml = $this->callPrivate($module, 'buildAvailabilityXml', [$product, $p, true]);
        $this->assertSame('<g:availability>backorder</g:availability>' . "\n" . '<g:availability_date>2099-01-15T00:00+0100</g:availability_date>' . "\n", $xml);

        // The local inventory feed doesn't ask for the date
        $xml = $this->callPrivate($module, 'buildAvailabilityXml', [$product, $p]);
        $this->assertSame('<g:availability>backorder</g:availability>' . "\n", $xml);
    }

    public function testAvailabilityDateOnlyForFutureDatesOfPreorderedProducts()
    {
        $module = $this->makeFeedModule($this->moduleConf(['availability_date' => 1, 'quantity' => 0]));
        $p = new Product();
        $p->orderableWhenOutOfStock = true;

        $past = $this->callPrivate($module, 'buildAvailabilityXml', [['category_default' => 3, 'quantity' => 0, 'available_for_order' => 1, 'available_date' => '2001-01-01'], $p, true]);
        $in_stock = $this->callPrivate($module, 'buildAvailabilityXml', [['category_default' => 3, 'quantity' => 2, 'available_for_order' => 1, 'available_date' => '2099-01-01'], $p, true]);

        $this->assertStringNotContainsString('availability_date', $past);
        $this->assertStringNotContainsString('availability_date', $in_stock);
    }

    public function testCategoryBackorderOverrideGetsAvailabilityDate()
    {
        $module = $this->makeFeedModule(
            $this->moduleConf(['availability_date' => 1, 'quantity' => 0]),
            ['categories_values' => [3 => ['gcat_avail' => 'backorder']]]
        );

        $xml = $this->callPrivate($module, 'buildAvailabilityXml', [['category_default' => 3, 'quantity' => 0, 'available_for_order' => 1, 'available_date' => '2099-03-01'], new Product(), true]);

        $this->assertStringContainsString('<g:availability>backorder</g:availability>', $xml);
        $this->assertStringContainsString('<g:availability_date>2099-03-01T00:00+0100</g:availability_date>', $xml);
    }

    // ---- full attribute block -----------------------------------------

    public function testEnabledAttributesAreExported()
    {
        Configuration::$values = ['PS_DIMENSION_UNIT' => 'cm', 'PS_WEIGHT_UNIT' => 'kg', 'PS_PURCHASE_MINIMUM' => '20'];
        Category::$fixtures = [3 => ['id_parent' => 2, 'active' => 1, 'name' => 'T-shirts']];
        $conf = $this->moduleConf([
            'item_group_title' => 1,
            'variant_option' => 1,
            'size_system' => 'EU',
            'size_type' => 'regular',
            'adult' => 1,
            'is_bundle' => 1,
            'cost_of_goods' => 1,
            'product_dimensions' => 1,
            'energy_class' => '10',
            'eprel_code' => '11',
            'product_detail' => 1,
            'size[]' => ['12'],
            'ships_from_country' => 'FR',
            'min_handling_time' => '1',
            'max_handling_time' => '3',
            'shipping_country' => 'FR',
            'free_shipping_threshold' => 1,
            'minimum_order_value' => 1,
            'excluded_destinations[]' => ['Display_ads', 'Not_a_destination'],
            'custom_label_0' => 'category_default',
            'custom_label_1' => 'on_sale',
            'custom_label_2' => 'feature_4',
            'custom_label_3' => 'manufacturer',
            'shipping_label' => 'reference',
            'return_policy_label' => 'condition',
        ]);
        $module = $this->makeFeedModule($conf, [
            'free_shipping' => ['PS_SHIPPING_FREE_PRICE' => '50', 'PS_SHIPPING_FREE_WEIGHT' => '0'],
            'categoryNamesCache' => [],
        ]);
        $features = [
            ['id_feature' => 4, 'name' => 'Composition', 'value' => 'Cotton'],
            ['id_feature' => 5, 'name' => 'Fit', 'value' => 'Slim'],
            ['id_feature' => 10, 'name' => 'Energy', 'value' => 'a+'],
            ['id_feature' => 11, 'name' => 'EPREL', 'value' => 'EPREL-123456'],
            ['id_feature' => 12, 'name' => 'Size', 'value' => 'XL'],
        ];

        $xml = $this->callPrivate($module, 'buildAdditionalAttributesXml', [
            $this->product([
                'size' => 'XL',
                'cache_is_pack' => '1',
                'variant_options' => [['name' => 'Size', 'value' => 'XL'], ['name' => 'Color', 'value' => 'Blue']],
            ]),
            new Currency(null, 'USD', 2),
            1,
            1,
            12,
            $features,
        ]);

        $expected = [
            '<g:item_group_title><![CDATA[Basic T-shirt]]></g:item_group_title>',
            "<g:variant_option>\n\t<g:name><![CDATA[Size]]></g:name>\n\t<g:value><![CDATA[XL]]></g:value>\n</g:variant_option>",
            "<g:variant_option>\n\t<g:name><![CDATA[Color]]></g:name>\n\t<g:value><![CDATA[Blue]]></g:value>\n</g:variant_option>",
            '<g:size_system>EU</g:size_system>',
            '<g:size_type>regular</g:size_type>',
            '<g:adult>yes</g:adult>',
            '<g:is_bundle>yes</g:is_bundle>',
            '<g:cost_of_goods_sold>8.40 USD</g:cost_of_goods_sold>',
            '<g:product_length>40.00 cm</g:product_length>',
            '<g:product_width>30.00 cm</g:product_width>',
            '<g:product_height>2.00 cm</g:product_height>',
            '<g:product_weight>0.25 kg</g:product_weight>',
            '<g:energy_efficiency_class>A+</g:energy_efficiency_class>',
            "<g:certification>\n\t<g:certification_authority>EC</g:certification_authority>\n\t<g:certification_name>EPREL</g:certification_name>\n\t<g:certification_code>123456</g:certification_code>\n</g:certification>",
            "<g:product_detail>\n\t<g:attribute_name><![CDATA[Fit]]></g:attribute_name>\n\t<g:attribute_value><![CDATA[Slim]]></g:attribute_value>\n</g:product_detail>",
            '<g:ships_from_country>FR</g:ships_from_country>',
            '<g:min_handling_time>1</g:min_handling_time>',
            '<g:max_handling_time>3</g:max_handling_time>',
            "<g:free_shipping_threshold>\n\t<g:country><![CDATA[FR]]></g:country>\n\t<g:price_threshold>100.00 USD</g:price_threshold>\n</g:free_shipping_threshold>",
            "<g:minimum_order_value>\n\t<g:country><![CDATA[FR]]></g:country>\n\t<g:price>40.00 USD</g:price>\n</g:minimum_order_value>",
            '<g:excluded_destination>Display_ads</g:excluded_destination>',
            '<g:custom_label_0><![CDATA[T-shirts]]></g:custom_label_0>',
            '<g:custom_label_1><![CDATA[not_on_sale]]></g:custom_label_1>',
            '<g:custom_label_2><![CDATA[Cotton]]></g:custom_label_2>',
            '<g:shipping_label><![CDATA[REF-7]]></g:shipping_label>',
            '<g:return_policy_label><![CDATA[new]]></g:return_policy_label>',
        ];
        foreach ($expected as $fragment) {
            $this->assertStringContainsString($fragment, $xml);
        }

        // Features already mapped elsewhere (size, energy, EPREL, labels) aren't repeated as details
        $this->assertSame(1, substr_count($xml, '<g:product_detail>'));
        $this->assertStringNotContainsString('Not_a_destination', $xml);
        // No manufacturer on this product: no empty custom_label_3
        $this->assertStringNotContainsString('custom_label_3', $xml);
    }

    public function testVariantAttributesAreOnlyExportedForCombinations()
    {
        $module = $this->makeFeedModule($this->moduleConf(['item_group_title' => 1, 'variant_option' => 1, 'size_system' => 'EU']));
        $xml = $this->callPrivate($module, 'buildAdditionalAttributesXml', [
            $this->product(['variant_options' => [['name' => 'Size', 'value' => 'XL']]]),
            new Currency(),
            1,
            1,
            false,
            [],
        ]);

        // Simple product with no size: neither variant attributes nor size_system
        $this->assertSame('', $xml);
    }

    // ---- pure helpers -------------------------------------------------

    public function testNormalizeIsbn()
    {
        $module = $this->makeModule();

        $this->assertSame('9782070368228', $this->callPrivate($module, 'normalizeIsbn', ['978-2-07-036822-8']));
        $this->assertSame('9780306406157', $this->callPrivate($module, 'normalizeIsbn', ['0-306-40615-2']));
        $this->assertSame('9780804429573', $this->callPrivate($module, 'normalizeIsbn', ['080442957x']));
        $this->assertSame('', $this->callPrivate($module, 'normalizeIsbn', ['12345']));
        $this->assertSame('', $this->callPrivate($module, 'normalizeIsbn', [null]));
    }

    public function testDates()
    {
        $module = $this->makeModule();
        $now = strtotime('2026-06-01 12:00:00');

        $this->assertTrue($this->callPrivate($module, 'isEmptyDate', ['0000-00-00 00:00:00']));
        $this->assertTrue($this->callPrivate($module, 'isEmptyDate', [null]));
        $this->assertFalse($this->callPrivate($module, 'isEmptyDate', ['2026-06-01']));
        $this->assertSame('2026-12-01T10:30+0100', $this->callPrivate($module, 'formatGoogleDate', ['2026-12-01 10:30:00']));
        $this->assertSame('', $this->callPrivate($module, 'formatGoogleDate', ['not a date']));

        $this->assertSame(
            '2026-06-10T08:00+0200/2026-06-20T23:59+0200',
            $this->callPrivate($module, 'buildSalePriceEffectiveDate', ['2026-06-10 08:00:00', '2026-06-20 23:59:00', $now])
        );
        // No start date: the sale has already started
        $this->assertSame(
            '2026-06-01T12:00+0200/2026-06-20T23:59+0200',
            $this->callPrivate($module, 'buildSalePriceEffectiveDate', ['0000-00-00 00:00:00', '2026-06-20 23:59:00', $now])
        );
        // No end date, or an inverted period: not exported
        $this->assertSame('', $this->callPrivate($module, 'buildSalePriceEffectiveDate', ['2026-06-10 08:00:00', '0000-00-00 00:00:00', $now]));
        $this->assertSame('', $this->callPrivate($module, 'buildSalePriceEffectiveDate', ['2026-06-20 08:00:00', '2026-06-10 08:00:00', $now]));

        $this->assertSame('2026-07-01T00:00+0200', $this->callPrivate($module, 'formatAvailabilityDate', ['2026-07-01', $now]));
        $this->assertSame('', $this->callPrivate($module, 'formatAvailabilityDate', ['2026-05-01', $now]));
        $this->assertSame('', $this->callPrivate($module, 'formatAvailabilityDate', ['0000-00-00', $now]));
    }

    public function testMeasures()
    {
        $module = $this->makeModule();

        $this->assertSame('20.00 cm', $this->callPrivate($module, 'formatProductDimension', [20, 'cm']));
        $this->assertSame('25.00 cm', $this->callPrivate($module, 'formatProductDimension', [250, 'mm']));
        $this->assertSame('200.00 cm', $this->callPrivate($module, 'formatProductDimension', [2, ' M ']));
        $this->assertSame('12.00 in', $this->callPrivate($module, 'formatProductDimension', ['12', 'IN']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductDimension', [0, 'cm']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductDimension', [0.5, 'cm']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductDimension', [4000, 'cm']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductDimension', [5, 'ft']));

        $this->assertSame('1.50 kg', $this->callPrivate($module, 'formatProductWeight', [1.5, 'kg']));
        $this->assertSame('0.50 g', $this->callPrivate($module, 'formatProductWeight', [500, 'mg']));
        $this->assertSame('3.00 lb', $this->callPrivate($module, 'formatProductWeight', [3, 'lbs']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductWeight', [0, 'kg']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductWeight', [3000, 'kg']));
        $this->assertSame('', $this->callPrivate($module, 'formatProductWeight', [1, '']));
    }

    public function testNormalizeEnergyClass()
    {
        $module = $this->makeModule();

        $this->assertSame('A++', $this->callPrivate($module, 'normalizeEnergyClass', ['a++', GShoppingFlux::VALID_ENERGY_CLASSES]));
        $this->assertSame('A+++', $this->callPrivate($module, 'normalizeEnergyClass', [' A + + + ', GShoppingFlux::VALID_ENERGY_CLASSES]));
        $this->assertSame('G', $this->callPrivate($module, 'normalizeEnergyClass', ['g', GShoppingFlux::VALID_ENERGY_CLASSES]));
        $this->assertSame('', $this->callPrivate($module, 'normalizeEnergyClass', ['H', GShoppingFlux::VALID_ENERGY_CLASSES]));
        $this->assertSame('', $this->callPrivate($module, 'normalizeEnergyClass', ['', GShoppingFlux::VALID_ENERGY_CLASSES]));
    }

    public function testIndexFeatureValuesKeepsFirstNonEmptyValue()
    {
        $module = $this->makeModule();

        $this->assertSame([4 => 'Cotton', 5 => 'Slim'], $this->callPrivate($module, 'indexFeatureValues', [[
            ['id_feature' => 4, 'value' => ''],
            ['id_feature' => '4', 'value' => 'Cotton'],
            ['id_feature' => 4, 'value' => 'Wool'],
            ['id_feature' => 5, 'value' => ' Slim '],
            ['id_feature' => 0, 'value' => 'Orphan'],
        ]]));
    }

    public function testProductDetailXml()
    {
        $module = $this->makeModule();
        $features = [
            ['id_feature' => 1, 'name' => 'Color', 'value' => 'Red'],
            ['id_feature' => 2, 'name' => 'Battery', 'value' => '3]]>000 mAh'],
            ['id_feature' => 3, 'name' => 'Empty', 'value' => ''],
            ['id_feature' => 4, 'name' => str_repeat('n', 200), 'value' => str_repeat('v', 1200)],
        ];

        $xml = $this->callPrivate($module, 'buildProductDetailXml', [$features, ['1']]);

        $this->assertStringNotContainsString('Color', $xml);
        $this->assertStringNotContainsString('Empty', $xml);
        $this->assertStringContainsString('<g:attribute_value><![CDATA[3]]]]><![CDATA[>000 mAh]]></g:attribute_value>', $xml);
        $this->assertStringContainsString('<![CDATA[' . str_repeat('n', 140) . ']]>', $xml);
        $this->assertStringContainsString('<![CDATA[' . str_repeat('v', 1000) . ']]>', $xml);
        $this->assertSame(2, substr_count($xml, '<g:product_detail>'));
    }

    public function testProductDetailXmlIsCappedAt100()
    {
        $module = $this->makeModule();
        $features = [];
        for ($i = 1; $i <= 120; ++$i) {
            $features[] = ['id_feature' => $i, 'name' => 'F' . $i, 'value' => 'V'];
        }

        $xml = $this->callPrivate($module, 'buildProductDetailXml', [$features, []]);

        $this->assertSame(100, substr_count($xml, '<g:product_detail>'));
    }

    public function testVariantOptionXml()
    {
        $module = $this->makeModule();
        $options = [['name' => 'Size', 'value' => 'M'], ['name' => '', 'value' => 'orphan']];
        for ($i = 0; $i < 40; ++$i) {
            $options[] = ['name' => 'G' . $i, 'value' => 'V'];
        }

        $xml = $this->callPrivate($module, 'buildVariantOptionXml', [$options]);

        $this->assertStringStartsWith("<g:variant_option>\n\t<g:name><![CDATA[Size]]></g:name>\n\t<g:value><![CDATA[M]]></g:value>\n</g:variant_option>\n", $xml);
        $this->assertStringNotContainsString('orphan', $xml);
        $this->assertSame(30, substr_count($xml, '<g:variant_option>'));
        $this->assertSame('', $this->callPrivate($module, 'buildVariantOptionXml', [[]]));
    }

    public function testResolveLabelValue()
    {
        $module = $this->makeModule();
        $data = [
            'manufacturer' => 'Acme',
            'supplier' => '',
            'on_sale' => 'on_sale',
            'features' => [8 => 'Summer collection'],
        ];

        $this->assertSame('Acme', $this->callPrivate($module, 'resolveLabelValue', ['manufacturer', $data]));
        $this->assertSame('on_sale', $this->callPrivate($module, 'resolveLabelValue', ['on_sale', $data]));
        $this->assertSame('Summer collection', $this->callPrivate($module, 'resolveLabelValue', ['feature_8', $data]));
        $this->assertSame('', $this->callPrivate($module, 'resolveLabelValue', ['feature_9', $data]));
        $this->assertSame('', $this->callPrivate($module, 'resolveLabelValue', ['supplier', $data]));
        $this->assertSame('', $this->callPrivate($module, 'resolveLabelValue', ['features', $data]));
        $this->assertSame('', $this->callPrivate($module, 'resolveLabelValue', ['unknown', $data]));
        $this->assertSame(str_repeat('x', 100), $this->callPrivate($module, 'resolveLabelValue', ['manufacturer', ['manufacturer' => str_repeat('x', 150)]]));
    }

    public function testIsPackProduct()
    {
        $module = $this->makeModule();

        $this->assertTrue($this->callPrivate($module, 'isPackProduct', [['cache_is_pack' => '1']]));
        $this->assertTrue($this->callPrivate($module, 'isPackProduct', [['cache_is_pack' => '0', 'product_type' => 'pack']]));
        $this->assertFalse($this->callPrivate($module, 'isPackProduct', [['cache_is_pack' => '0', 'product_type' => 'standard']]));
        $this->assertFalse($this->callPrivate($module, 'isPackProduct', [[]]));
    }
}
