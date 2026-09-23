<?php

/**
 * Google Shopping Flux Module
 *
 * Main module class for exporting Prestashop products to Google Merchant Center.
 * Supports multi-language, multi-currency, and multi-shop configurations.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 * @version 1.8.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
} else {
    // Fallback for deployments where the zip was extracted without the
    // committed vendor/ directory (composer.json declares the same PSR-4
    // mapping for anyone who wants to run `composer dump-autoload` instead).
    require_once dirname(__FILE__) . '/src/GCategories.php';
    require_once dirname(__FILE__) . '/src/GLangAndCurrency.php';
    require_once dirname(__FILE__) . '/src/ArrayHelper.php';
    require_once dirname(__FILE__) . '/src/Traits/LifecycleTrait.php';
    require_once dirname(__FILE__) . '/src/Traits/AdminOptionsTrait.php';
    require_once dirname(__FILE__) . '/src/Traits/AdminCategoriesLangTrait.php';
    require_once dirname(__FILE__) . '/src/Traits/FeedGeneratorTrait.php';
    require_once dirname(__FILE__) . '/src/Traits/ReviewsFeedTrait.php';
}

use GShoppingFlux\Traits\AdminCategoriesLangTrait;
use GShoppingFlux\Traits\AdminOptionsTrait;
use GShoppingFlux\Traits\FeedGeneratorTrait;
use GShoppingFlux\Traits\LifecycleTrait;
use GShoppingFlux\Traits\ReviewsFeedTrait;

/**
 * GShoppingFlux Main Module
 *
 * Handles all module functionality including:
 * - Installation and uninstallation
 * - Hook registration and processing
 * - Admin panel configuration
 * - XML file generation and export
 */
class GShoppingFlux extends Module
{
    // ============================================================
    // TRAIT COMPOSITION
    // ============================================================
    //
    // Installation/hooks, the admin screens, feed generation and the
    // reviews feed each live in their own PSR-4 trait under src/Traits/
    // (see composer.json). This class stays the single Module entry
    // point PrestaShop instantiates by name; the traits below provide
    // every other method, sharing this instance's $this and properties.

    use LifecycleTrait;
    use AdminOptionsTrait;
    use AdminCategoriesLangTrait;
    use FeedGeneratorTrait;
    use ReviewsFeedTrait;

    // ============================================================
    // CLASS CONSTANTS
    // ============================================================

    /** Character encoding for XML output */
    const CHARSET = 'UTF-8';

    /** HTML entity encoding flags for XML */
    const REPLACE_FLAGS = ENT_COMPAT;

    /** Google Shopping <g:title> maximum length */
    const TITLE_MAX_LENGTH = 150;

    /** Google Shopping <g:short_title> maximum length */
    const SHORT_TITLE_MAX_LENGTH = 65;

    /** Google Shopping <g:description> maximum length */
    const DESCRIPTION_MAX_LENGTH = 4990;

    /** Maximum number of image_link/additional_image_link nodes per item */
    const MAX_PRODUCT_IMAGES = 10;

    /**
     * Default value for every GS_* module setting, seeded on install and
     * removed on full uninstall. Keeping a single source of truth here
     * avoids the two lists drifting apart.
     */
    const CONFIG_DEFAULTS = [
        'GS_PRODUCT_TYPE' => '',
        'GS_DESCRIPTION' => 'short',
        'GS_SHIPPING_MODE' => 'fixed',
        'GS_SHIPPING_PRICE_FIXED' => '1',
        'GS_SHIPPING_PRICE' => '0.00',
        'GS_SHIPPING_COUNTRY' => 'UK',
        'GS_SHIPPING_COUNTRIES' => '0',
        'GS_CARRIERS_EXCLUDED' => '0',
        'GS_IMG_TYPE' => 'large_default',
        'GS_MPN_TYPE' => 'reference',
        'GS_GENDER' => '',
        'GS_AGE_GROUP' => '',
        'GS_ATTRIBUTES' => '0',
        'GS_COLOR' => '',
        'GS_MATERIAL' => '',
        'GS_PATTERN' => '',
        'GS_SIZE' => '',
        'GS_EXPORT_MIN_PRICE' => '0.00',
        'GS_NO_GTIN' => '1',
        'GS_SHIPPING_DIMENSION' => '1',
        'GS_NO_BRAND' => '1',
        'GS_ID_EXISTS_TAG' => '1',
        'GS_EXPORT_NAP' => '0',
        'GS_QUANTITY' => '1',
        'GS_FEATURED_PRODUCTS' => '1',
        'GS_GEN_FILE_IN_ROOT' => '1',
        'GS_FILE_PREFIX' => '',
        'GS_LOCAL_SHOP_CODE' => '',
        'GS_CRON_TOKEN' => '',
    ];

    /**
     * Valid values for form fields backed by a fixed <select>/<switch>
     * option list, used both to render the option list and to whitelist
     * incoming POST data in the corresponding save*() method. An empty
     * string means "inherit from parent"/"no override" where applicable.
     */
    const VALID_CONDITIONS = ['', 'new', 'used', 'refurbished'];
    const VALID_AVAILABILITY = ['', 'in stock', 'preorder'];
    const VALID_GENDERS = ['', 'male', 'female', 'unisex'];
    const VALID_AGE_GROUPS = ['', 'newborn', 'infant', 'toddler', 'kids', 'adult'];
    const VALID_DESCRIPTIONS = ['short', 'long', 'short+long', 'meta'];
    const VALID_SHIPPING_MODES = ['none', 'fixed', 'full'];
    const VALID_MPN_TYPES = ['reference', 'supplier_reference'];

    // ============================================================
    // CLASS PROPERTIES
    // ============================================================

    /** HTML output buffer for admin panel */
    private $_html = '';

    /** Array of user group IDs */
    private $user_groups;

    /** Array of category values for export */
    private $categories_values;

    /** Total number of exported products */
    private $nb_total_products = 0;

    /** Count of products not exported */
    private $nb_not_exported_products = 0;

    /** Count of product combinations */
    private $nb_combinations = 0;

    /** Array tracking products with attributes */
    private $nb_prd_w_attr = [];

    /** Root category ID */
    private $id_root;

    /** Current shop object */
    private $shop;

    /** Module configuration cache */
    private $module_conf = [];

    /** Base URI for shop */
    private $uri;

    /** Stock management enabled flag */
    private $ps_stock_management;

    /** Shipping handling cost */
    private $ps_shipping_handling;

    /** Free shipping configuration */
    private $free_shipping;

    /** Admin confirmation/status message accumulated across save handlers */
    private $confirm = '';

    /** HelperForm field definitions for the form currently being rendered */
    private $fields_form = [];

    /** Module page identifier (basename of this file without extension) */
    private $page;

    /**
     * Per-generateFile()-run cache of Carrier::getCarriers() results keyed
     * by id_zone, so the "full" shipping mode doesn't re-query the same
     * zone's carrier list for every exported product.
     */
    private $carriersByZoneCache = [];

    // ============================================================
    // CONSTRUCTOR
    // ============================================================

    /**
     * Constructor - Initialize module properties
     *
     * Sets up basic module information, URI configuration,
     * and system settings for Prestashop compatibility.
     */
    public function __construct()
    {
        $this->name = 'gshoppingflux';
        $this->tab = 'smart_shopping';
        $this->version = '1.8.0';
        $this->author = 'Dim00z';
        $this->bootstrap = true;

        parent::__construct();

        $this->need_instance = 0;
        $this->page = basename(__FILE__, '.php');

        $this->displayName = $this->l('Google Shopping Flux');
        $this->description = $this->l('Export your products to Google Merchant Center, easily.');
        $this->ps_versions_compliancy = ['min' => '1.5.0.0', 'max' => '9.99.99'];

        // Build shop URI with protocol and domain
        $protocol = Tools::getCurrentUrlProtocolPrefix();
        $domain = !empty($this->context->shop->domain_ssl)
            ? $this->context->shop->domain_ssl
            : $this->context->shop->domain;
        $this->uri = $protocol . $domain . $this->context->shop->physical_uri;

        // Initialize module properties
        $this->categories_values = [];

        // Load Prestashop configuration
        $this->ps_stock_management = Configuration::get('PS_STOCK_MANAGEMENT');
        $this->ps_shipping_handling = (float) Configuration::get('PS_SHIPPING_HANDLING');
        $this->free_shipping = Configuration::getMultiple(['PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT']);
    }
}
