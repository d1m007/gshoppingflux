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
 * @version 1.7.6
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Load required classes and helpers
require_once dirname(__FILE__) . '/classes/GCategories.php';
require_once dirname(__FILE__) . '/classes/GLangAndCurrency.php';
require_once dirname(__FILE__) . '/helpers/ArrayHelper.php';

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
    // CLASS CONSTANTS
    // ============================================================

    /** Character encoding for XML output */
    const CHARSET = 'UTF-8';

    /** HTML entity encoding flags for XML */
    const REPLACE_FLAGS = ENT_COMPAT;

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
        $this->version = '1.7.6';
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

    /**
     * Install module and create database tables
     *
     * Registers hooks, creates database tables, and initializes
     * configuration values for each shop.
     *
     * @param bool $delete_params Whether to delete existing parameters
     * @return bool True on success, false on failure
     */

    // ============================================================
    // INSTALLATION & LIFECYCLE METHODS
    // ============================================================

    public function install($delete_params = true)
    {
        // Check parent installation and hook registration
        if (
            !parent::install()
            || !$this->registerHook('actionObjectCategoryAddAfter')
            || !$this->registerHook('actionObjectCategoryDeleteAfter')
            || !$this->registerHook('actionShopDataDuplication')
            || !$this->registerHook('actionCarrierUpdate')
            || !$this->installDb()
        ) {
            return false;
        }

        // Initialize configuration for each shop
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $shop_id) {
            $shop_group_id = Shop::getGroupFromShop($shop_id);

            // Initialize database for shop
            if (!$this->initDb((int) $shop_id)) {
                return false;
            }
            // Initialize configuration values if requested
            if ($delete_params) {
                if (!$this->initializeConfigurationValues($shop_id, $shop_group_id)) {
                    return false;
                }
            }
        }

        // Create export directory for XML files
        if (!is_dir(dirname(__FILE__) . '/export')) {
            @mkdir(dirname(__FILE__) . '/export', 0755, true);
        }
        @chmod(dirname(__FILE__) . '/export', 0755);

        return true;
    }

    /**
     * Initialize configuration values for a shop
     *
     * Sets default configuration for all module parameters.
     *
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return bool True on success
     */
    private function initializeConfigurationValues($shop_id, $shop_group_id)
    {
        $configs = [
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
        ];

        foreach ($configs as $key => $value) {
            if (!Configuration::updateValue($key, $value, false, (int) $shop_group_id, (int) $shop_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create database tables for module
     *
     * Creates three tables:
     * - gshoppingflux: Main category mapping
     * - gshoppingflux_lc: Language-currency configuration
     * - gshoppingflux_lang: Multilingual category names
     *
     * @return bool True on success, false on failure
     */
    public function installDb()
    {
        // Main Google Shopping category mapping table
        $result1 = Db::getInstance()->execute('
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gshoppingflux` (
				`id_gcategory` INT(11) UNSIGNED NOT NULL,
				`export` INT(11) UNSIGNED NOT NULL,
				`condition` VARCHAR( 12 ) NOT NULL,
				`availability` VARCHAR( 12 ) NOT NULL,
				`gender` VARCHAR( 8 ) NOT NULL,
				`age_group` VARCHAR( 8 ) NOT NULL,
				`color` VARCHAR( 64 ) NOT NULL,
				`material` VARCHAR( 64 ) NOT NULL,
				`pattern` VARCHAR( 64 ) NOT NULL,
				`size` VARCHAR( 64 ) NOT NULL,
				`id_shop` INT(11) UNSIGNED NOT NULL,
		  	INDEX (`id_gcategory`, `id_shop`)
		  	) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;');

        // Language-currency configuration table
        $result2 = Db::getInstance()->execute('
				CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gshoppingflux_lc` (
					`id_glang` INT(11) UNSIGNED NOT NULL,
					`id_currency` VARCHAR(255) NOT NULL,
					`tax_included` TINYINT(1) NOT NULL,
					`id_shop` INT(11) UNSIGNED NOT NULL,
			  INDEX (`id_glang`, `id_shop`)
			) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;');

        // Multilingual category names table
        $result3 = Db::getInstance()->execute('
				CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gshoppingflux_lang` (
					`id_gcategory` INT(11) UNSIGNED NOT NULL,
					`id_lang` INT(11) UNSIGNED NOT NULL,
					`id_shop` INT(11) UNSIGNED NOT NULL,
					`gcategory` VARCHAR( 255 ) NOT NULL,
			  INDEX (`id_gcategory`, `id_lang`, `id_shop`)
			) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;');

        return $result1 && $result2 && $result3;
    }

    /**
     * Initialize database for specific shop
     *
     * Creates default category mappings and language-currency pairs
     * for a shop during installation or new shop creation.
     *
     * @param int $id_shop Shop ID to initialize
     * @return bool True on success
     */
    public function initDb($id_shop)
    {
        // Get all languages for shop
        $languages = Language::getLanguages(true, $id_shop);
        $id_lang = $this->context->language->id;

        // Get shop root category
        $shop = new Shop($id_shop);
        $root = Category::getRootCategory($id_lang, $shop);

        // Get all active categories
        $categs = Db::getInstance()->executeS('
			SELECT c.id_category, c.id_parent, c.active
			FROM ' . _DB_PREFIX_ . 'category c
			INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category=c.id_category AND cs.id_shop=' . (int) $id_shop . ')
			ORDER BY c.id_category ASC, c.level_depth ASC, cs.position ASC;');

        // Initialize each category
        foreach ($categs as $kc => $cat) {

            // Initialize language array
            $category_names = [];
            foreach ($languages as $lang) {
                $category_names[$lang['id_lang']] = '';
            }

            $condition = '';
            $availability = '';
            $gender = '';
            $age_group = '';
            $color = '';
            $material = '';
            $pattern = '';
            $size = '';

            // Check if category already exists
            $cat_exists = GCategories::get($cat['id_category'], $id_lang, $id_shop);

            if ((!count($cat_exists) || $cat_exists === false) && ($cat['id_category'] > 0)) {
                // Set default values for root category
                if ($root->id_category == $cat['id_category']) {
                    foreach ($languages as $key => $lang) {
                        $str[$lang['id_lang']] = $this->l('Google Category Example > Google Sub-Category Example');
                    }

                    $condition = 'new';
                    $availability = 'in stock';
                }

                // Add category mapping
                GCategories::add($cat['id_category'], $category_names, $cat['active'], $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $id_shop);
            }
        }

        // Initialize language-currency pairs
        foreach ($languages as $lang) {
            if (!count(GLangAndCurrency::getLangCurrencies($lang['id_lang'], $id_shop))) {
                GLangAndCurrency::add($lang['id_lang'], $this->context->currency->id, 1, $id_shop);
            }
        }

        return true;
    }

    /**
     * Uninstall module and optionally drop tables
     *
     * @param bool $delete_params Whether to delete database tables
     * @return bool True on success
     */
    public function uninstall($delete_params = true)
    {
        if (!parent::uninstall()) {
            return false;
        }

        if ($delete_params) {
            if (!$this->uninstallDB()) {
                return false;
            }

            // Delete all configuration values
            $config_keys = [
                'GS_PRODUCT_TYPE',
                'GS_DESCRIPTION',
                'GS_SHIPPING_MODE',
                'GS_SHIPPING_PRICE',
                'GS_SHIPPING_COUNTRY',
                'GS_SHIPPING_COUNTRIES',
                'GS_CARRIERS_EXCLUDED',
                'GS_IMG_TYPE',
                'GS_MPN_TYPE',
                'GS_GENDER',
                'GS_AGE_GROUP',
                'GS_ATTRIBUTES',
                'GS_COLOR',
                'GS_MATERIAL',
                'GS_PATTERN',
                'GS_SIZE',
                'GS_EXPORT_MIN_PRICE',
                'GS_NO_GTIN',
                'GS_SHIPPING_DIMENSION',
                'GS_NO_BRAND',
                'GS_ID_EXISTS_TAG',
                'GS_EXPORT_NAP',
                'GS_QUANTITY',
                'GS_FEATURED_PRODUCTS',
                'GS_GEN_FILE_IN_ROOT',
                'GS_FILE_PREFIX',
                'GS_LOCAL_SHOP_CODE'
            ];

            foreach ($config_keys as $key) {
                if (!Configuration::deleteByName($key)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Drop database tables
     *
     * @return bool True on success
     */
    private function uninstallDb()
    {
        $tables = ['gshoppingflux', 'gshoppingflux_lc', 'gshoppingflux_lang'];
        foreach ($tables as $table) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reset module to initial state
     *
     * Uninstalls and reinstalls module without deleting configuration.
     *
     * @return bool True on success
     */
    public function reset()
    {
        if (!$this->uninstall(false)) {
            return false;
        }
        if (!$this->install(false)) {
            return false;
        }

        return true;
    }

    // ============================================================
    // HOOKS - Category Management
    // ============================================================


    /**
     * Hook: After category creation
     *
     * Reinitialize database when new category is added to ensure
     * it's properly mapped in Google Shopping configuration.
     *
     * @param array $params Hook parameters
     * @return void
     */
    public function hookActionObjectCategoryAddAfter($params)
    {
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $id_shop) {
            $this->initDb($id_shop);
        }
    }

    /**
     * Hook: After category deletion
     *
     * Reinitialize database when category is deleted to remove
     * any associated Google Shopping mappings.
     *
     * @param array $params Hook parameters
     * @return void
     */
    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $id_shop) {
            $this->initDb($id_shop);
        }
    }
    /**
     * Hook: When shop data is duplicated
     *
     * Copy Google Shopping configuration from source shop to destination shop
     * when shop duplication occurs.
     *
     * @param array $params Hook parameters containing 'old_id_shop' and 'new_id_shop'
     * @return void
     */
    public function hookActionShopDataDuplication($params)
    {
        $old_shop_id = (int) $params['old_id_shop'];
        $new_shop_id = (int) $params['new_id_shop'];

        // Copy main category mappings
        $categories = Db::getInstance()->executeS('
            SELECT * FROM `' . _DB_PREFIX_ . 'gshoppingflux`
            WHERE id_shop = ' . $old_shop_id . '
        ');

        foreach ($categories as $category) {
            Db::getInstance()->insert('gshoppingflux', [
                'id_gcategory' => (int) $category['id_gcategory'],
                'export' => (int) $category['export'],
                'condition' => $category['condition'],
                'availability' => $category['availability'],
                'gender' => $category['gender'],
                'age_group' => $category['age_group'],
                'color' => $category['color'],
                'material' => $category['material'],
                'pattern' => $category['pattern'],
                'size' => $category['size'],
                'id_shop' => $new_shop_id,
            ]);

            $new_gcategory_id = Db::getInstance()->Insert_ID();

            // Copy language translations
            $translations = Db::getInstance()->executeS('
                SELECT id_lang, gcategory
                FROM `' . _DB_PREFIX_ . 'gshoppingflux_lang`
                WHERE id_gcategory = ' . (int) $category['id_gcategory'] . '
                AND id_shop = ' . $old_shop_id . '
            ');

            foreach ($translations as $translation) {
                Db::getInstance()->insert('gshoppingflux_lang', [
                    'id_gcategory' => (int) $new_gcategory_id,
                    'id_lang' => (int) $translation['id_lang'],
                    'id_shop' => $new_shop_id,
                    'gcategory' => $translation['gcategory'],
                ]);
            }
        }
    }

    /**
     * Hook: When carrier is updated
     *
     * Update excluded carriers list if a carrier ID changes.
     *
     * @param array $params Hook parameters containing carrier info
     * @return void
     */
    public function hookActionCarrierUpdate($params)
    {
        $shop_id = $this->context->shop->id;
        $shop_group_id = Shop::getGroupFromShop($shop_id);
        $old_carrier_id = (int) $params['id_carrier'];
        $new_carrier_id = (int) $params['carrier']->id;

        // Get current excluded carriers
        $carriers_excluded = explode(';', Configuration::get('GS_CARRIERS_EXCLUDED', 0, $shop_group_id, $shop_id));

        // Replace old carrier ID with new one
        $key = array_search($old_carrier_id, $carriers_excluded);
        if ($key !== false) {
            $carriers_excluded[$key] = $new_carrier_id;
            Configuration::updateValue('GS_CARRIERS_EXCLUDED', ArrayHelper::safeImplode($carriers_excluded), false, (int) $shop_group_id, (int) $shop_id);
        }
    }

    // ============================================================
    // CONFIGURATION & DISPLAY METHODS
    // ============================================================

    /**
     * Get price display precision
     *
     * Returns the number of decimal places to display for prices.
     * Ensures PS9 compatibility.
     *
     * @return int Precision value (typically 2)
     */
    private function getPriceDisplayPrecision()
    {
        if (defined('PS_PRICE_DISPLAY_PRECISION')) {
            return (int) PS_PRICE_DISPLAY_PRECISION;
        }
        return (int) Configuration::get('PS_PRICE_DISPLAY_PRECISION', 2);
    }

    /**
     * Get module admin panel content
     *
     * Renders configuration forms and category lists for admin interface.
     * Handles form submissions and exports.
     *
     * @return string HTML content for admin panel
     */
    public function getContent()
    {
        $id_lang = $this->context->language->id;
        $languages = $this->context->controller->getLanguages();
        $shops = Shop::getShops(true, null, true);
        $shop_id = $this->context->shop->id;
        $shop_group_id = Shop::getGroupFromShop($shop_id);

        // Check for multishop restrictions
        if (count($shops) > 1 && Shop::getContext() != 1) {
            $this->_html .= $this->getWarningMultishopHtml();
            return $this->_html;
        }

        // Display current shop info
        if (Shop::isFeatureActive()) {
            $this->_html .= $this->getCurrentShopInfoMsg();
        }

        // Handle form submissions
        $this->processFormSubmissions($languages, $shop_id, $shop_group_id);

        // Render forms and lists
        $this->renderAdminContent();

        return $this->_html;
    }

    /**
     * Process admin form submissions
     *
     * @param array $languages Array of available languages
     * @param int $shop_id Current shop ID
     * @param int $shop_group_id Current shop group ID
     * @return void
     */
    private function processFormSubmissions($languages, $shop_id, $shop_group_id)
    {
        // Process main flux options
        if (Tools::isSubmit('submitFluxOptions')) {
            $this->saveFluxOptions($languages, $shop_id, $shop_group_id);
        }
        // Process local inventory options
        elseif (Tools::isSubmit('submitLocalInventoryFluxOptions')) {
            $this->saveLocalInventoryOptions($shop_id, $shop_group_id);
        }
        // Process reviews export
        elseif (Tools::isSubmit('submitReviewsFluxOptions')) {
            $this->confirm = $this->l('The settings have been updated.');
            $this->generateXMLFiles(0, $shop_id, $shop_group_id, false, true);
        }
        // Process category update
        elseif (Tools::isSubmit('updateCategory')) {
            $this->saveCategory($shop_group_id, $shop_id);
        }
        // Process language update
        elseif (Tools::isSubmit('updateLanguage')) {
            $this->saveLanguage($shop_id, $shop_group_id);
        }
    }

    /**
     * Save flux (main export) options
     *
     * @param array $languages Available languages
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return void
     */
    private function saveFluxOptions($languages, $shop_id, $shop_group_id)
    {
        $updated = true;

        // Build product type array
        $product_type = [];
        $product_type_lang = Tools::getValue('product_type');
        foreach ($languages as $k => $lang) {
            $product_type[$lang['id_lang']] = $product_type_lang[$k];
        }

        // Update all configuration values
        $updated &= Configuration::updateValue('GS_PRODUCT_TYPE', $product_type, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_DESCRIPTION', Tools::getValue('description'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_MODE', Tools::getValue('shipping_mode'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_PRICE', (float) Tools::getValue('shipping_price'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_COUNTRY', Tools::getValue('shipping_country'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_COUNTRIES', ArrayHelper::safeImplode('shipping_countries'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_CARRIERS_EXCLUDED', ArrayHelper::safeImplode('carriers_excluded'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_IMG_TYPE', Tools::getValue('img_type'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_MPN_TYPE', Tools::getValue('mpn_type'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_GENDER', Tools::getValue('gender'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_AGE_GROUP', Tools::getValue('age_group'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_ATTRIBUTES', Tools::getValue('export_attributes'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_COLOR', ArrayHelper::safeImplode('color'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_MATERIAL', ArrayHelper::safeImplode('material'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_PATTERN', ArrayHelper::safeImplode('pattern'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SIZE', ArrayHelper::safeImplode('size'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_EXPORT_MIN_PRICE', (float) Tools::getValue('export_min_price'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_NO_GTIN', (bool) Tools::getValue('no_gtin'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_DIMENSION', (bool) Tools::getValue('shipping_dimension'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_NO_BRAND', (bool) Tools::getValue('no_brand'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_ID_EXISTS_TAG', (bool) Tools::getValue('id_exists_tag'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_EXPORT_NAP', (bool) Tools::getValue('export_nap'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_QUANTITY', (bool) Tools::getValue('quantity'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_FEATURED_PRODUCTS', (bool) Tools::getValue('featured_products'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_GEN_FILE_IN_ROOT', (bool) Tools::getValue('gen_file_in_root'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_FILE_PREFIX', trim(Tools::getValue('file_prefix')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_AUTOEXPORT_ON_SAVE', (bool) Tools::getValue('autoexport_on_save'), false, (int) $shop_group_id, (int) $shop_id);

        if (!$updated) {
            $shop = new Shop($shop_id);
            $this->_html .= $this->displayError(sprintf($this->l('Unable to update settings for shop: %s'), $shop->name));
        } else {
            $this->confirm = $this->l('The settings have been updated.');
            $this->generateXMLFiles(0, $shop_id, $shop_group_id);
            $this->_html .= $this->displayConfirmation($this->confirm);
        }
    }

    /**
     * Save local inventory options
     *
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return void
     */
    private function saveLocalInventoryOptions($shop_id, $shop_group_id)
    {
        $updated = Configuration::updateValue('GS_LOCAL_SHOP_CODE', Tools::getValue('store_code'), false, (int) $shop_group_id, (int) $shop_id);

        if (!$updated) {
            $shop = new Shop($shop_id);
            $this->_html .= $this->displayError(sprintf($this->l('Unable to update settings for shop: %s'), $shop->name));
        } else {
            $this->confirm = $this->l('The settings have been updated.');
            $this->generateXMLFiles(0, $shop_id, $shop_group_id, true);
            $this->_html .= $this->displayConfirmation($this->confirm);
        }
    }

    /**
     * Save category mapping update
     *
     * @param int $shop_group_id Shop group ID
     * @param int $shop_id Shop ID
     * @return void
     */
    private function saveCategory($shop_group_id, $shop_id)
    {
        $id_gcategory = (int) Tools::getValue('id_gcategory', 0);
        $export = (int) Tools::getValue('export', 0);
        $condition = Tools::getValue('condition');
        $availability = Tools::getValue('availability');
        $gender = Tools::getValue('gender');
        $age_group = Tools::getValue('age_group');
        $color = ArrayHelper::safeImplode((array) Tools::getValue('color'));
        $material = ArrayHelper::safeImplode((array) Tools::getValue('material'));
        $pattern = ArrayHelper::safeImplode((array) Tools::getValue('pattern'));
        $size = ArrayHelper::safeImplode((array) Tools::getValue('size'));

        if (Tools::isSubmit('updatecateg')) {
            $gcateg = [];
            foreach (Language::getLanguages(false) as $lang) {
                $gcateg[$lang['id_lang']] = Tools::getValue('gcategory_' . (int) $lang['id_lang']);
            }

            GCategories::update($id_gcategory, $gcateg, $export, $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $shop_id);
            $this->confirm = $this->l('Google category has been updated.');
        }

        // Auto-export if enabled
        if (Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id) == 1) {
            $this->generateXMLFiles(0, $shop_id, $shop_group_id);
        }

        $this->_html .= $this->displayConfirmation($this->confirm);
    }

    /**
     * Save language-currency configuration
     *
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return void
     */
    private function saveLanguage($shop_id, $shop_group_id)
    {
        $id_glang = (int) Tools::getValue('id_glang', 0);
        $currencies = ArrayHelper::safeImplode('currencies');
        $tax_included = (int) Tools::getValue('tax_included', 0);
        $export = (int) Tools::getValue('active', 0);

        if (Tools::isSubmit('updatelang')) {
            GLangAndCurrency::update($id_glang, $currencies, $tax_included, (int) Shop::getContextShopID());
            $this->confirm = $this->l('Language configuration has been saved.');
        }

        if ($export && Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id) == 1) {
            $this->generateXMLFiles($id_glang, $shop_id, $shop_group_id);
        } else {
            $this->_html .= $this->displayConfirmation($this->confirm);
        }
    }

    /**
     * Render admin panel content
     *
     * @return void
     */
    private function renderAdminContent()
    {
        $id_lang = $this->context->language->id;
        $shop_id = $this->context->shop->id;

        // Check if categories exist
        $categories = GCategories::gets((int) $id_lang, null, (int) $shop_id);
        if (!count($categories)) {
            return;
        }

        // Determine which forms/lists to display
        if ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !Tools::getValue('updategshoppingflux')) {
            $this->_html .= $this->renderCategForm();
            $this->_html .= $this->renderCategList();
        } elseif ((Tools::getIsset('updategshoppingflux_lc') || Tools::getIsset('statusgshoppingflux_lc')) && !Tools::getValue('updategshoppingflux_lc')) {
            $this->_html .= $this->renderLangForm();
            $this->_html .= $this->renderLangList();
        } else {
            $this->_html .= $this->renderForm();
            $this->_html .= $this->renderLocalInventoryForm();
            $this->_html .= $this->renderReviewsForm();
            $this->_html .= $this->renderCategList();
            $this->_html .= $this->renderLangList();
            $this->_html .= $this->renderInfo();
        }
    }

    // ============================================================
    // HELPER METHODS - FORM RENDERING
    // ============================================================

    /**
     * Display warning for multishop context
     *
     * @return string HTML warning message
     */
    private function getWarningMultishopHtml()
    {
        return '<p class="alert alert-warning">' . $this->l('You cannot manage Google categories from a "All Shops" or a "Group Shop" context, select directly the shop you want to edit') . '</p>';
    }

    /**
     * Display current shop info message
     *
     * @return string HTML info message
     */
    private function getCurrentShopInfoMsg()
    {
        $shop_info = null;

        if (Shop::getContext() == Shop::CONTEXT_SHOP) {
            $shop_info = sprintf($this->l('The modifications will be applied to shop: %s'), $this->context->shop->name);
        } elseif (Shop::getContext() == Shop::CONTEXT_GROUP) {
            $shop_info = sprintf($this->l('The modifications will be applied to this group: %s'), Shop::getContextShopGroup()->name);
        } else {
            $shop_info = $this->l('The modifications will be applied to all shops');
        }

        return '<div class="alert alert-info">' . $shop_info . '</div>';
    }

    /**
     * Encode URL for XML output
     *
     * Properly encodes URL components while preserving structure.
     * Handles special characters and internationalized domain names.
     *
     * @param string $url URL to encode
     * @return string Properly encoded URL
     */
    private function linkencode($url)
    {
        // Parse URL into components
        $components = parse_url($url);

        // Handle scheme
        if (!empty($components['scheme'])) {
            $components['scheme'] .= '://';
        }

        // Handle authentication
        if (!empty($components['pass']) && !empty($components['user'])) {
            $components['user'] .= ':';
            $components['pass'] = rawurlencode($components['pass']) . '@';
        } elseif (!empty($components['user'])) {
            $components['user'] .= '@';
        } else {
            $components['user'] = '';
            $components['pass'] = '';
        }

        // Handle host and port
        if (!empty($components['port']) && !empty($components['host'])) {
            $components['host'] = $components['host'] . ':';
        } elseif (empty($components['host'])) {
            $components['host'] = '';
            $components['port'] = '';
        }

        // Handle path encoding
        if (!empty($components['path'])) {
            $path_parts = [];
            $path_tokens = explode('/', trim($components['path'], '/'));
            foreach ($path_tokens as $token) {
                $path_parts[] = rawurlencode($token);
            }
            $components['path'] = '/' . implode('/', $path_parts);
        }

        // Handle query string
        if (!empty($components['query'])) {
            $components['query'] = '?' . $components['query'];
        } else {
            $components['query'] = '';
        }

        // Handle fragment
        if (!empty($components['fragment'])) {
            $components['fragment'] = '#' . $components['fragment'];
        } else {
            $components['fragment'] = '';
        }

        return implode('', [
            $components['scheme'],
            $components['user'],
            $components['pass'],
            $components['host'],
            $components['port'],
            $components['path'],
            $components['query'],
            $components['fragment']
        ]);
    }
    /**
     * Remove HTML tags and clean whitespace
     *
     * Strips HTML/XML tags and normalizes whitespace for text export.
     * Useful for cleaning product descriptions for XML feed.
     *
     * @param string $string Input string with potential HTML
     * @return string Cleaned string
     */
    private function rip_tags($string)
    {
        // Remove HTML/XML tags
        $string = preg_replace('/<[^>]*>/', ' ', $string);

        // Remove control characters
        $string = str_replace("\r", '', $string);
        $string = str_replace("\n", ' ', $string);
        $string = str_replace("\t", ' ', $string);

        // Normalize multiple spaces to single space
        $string = trim(preg_replace('/ {2,}/', ' ', $string));

        return $string;
    }

    private function generateXMLFiles($lang_id, $shop_id, $shop_group_id, $local_inventory = false, $reviews = false)
    {
        if (isset($lang_id) && $lang_id != 0) {
            $count = $this->generateLangFileList($lang_id, $shop_id, $local_inventory);
            $languages = GLangAndCurrency::getLangCurrencies($lang_id, $shop_id);
        } else {
            $count = $this->generateShopFileList($shop_id, $local_inventory, $reviews);
            $languages = GLangAndCurrency::getAllLangCurrencies(1);
            if ($reviews) {
                if (Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $shop_group_id, $shop_id) == 1) {
                    $get_file_url = $this->uri . $this->_getOutputFileName(0, 0, $shop_id, $local_inventory, $reviews);
                } else {
                    $get_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName(0, 0, $shop_id, $local_inventory, $reviews);
                }
                $this->confirm .= '<br /> <a href="' . $get_file_url . '" target="_blank">' . $get_file_url . '</a> : ' . $count['nb_reviews'] . ' ' . $this->l('reviews exported');
                $this->_html .= $this->displayConfirmation(html_entity_decode($this->confirm));

                return;
            }
        }

        foreach ($languages as $i => $lang) {
            $currencies = ArrayHelper::explodeAndFilter($lang['id_currency']);
            foreach ($currencies as $curr) {
                $currency = new Currency($curr);
                if (Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $shop_group_id, $shop_id) == 1) {
                    $get_file_url = $this->uri . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $shop_id, $local_inventory);
                } else {
                    $get_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $shop_id, $local_inventory);
                }

                $this->confirm .= '<br /> <a href="' . $get_file_url . '" target="_blank">' . $get_file_url . '</a> : ' . ($count[$i]['nb_products'] - $count[$i]['nb_combinations']) . ' ' . $this->l('products exported');

                if ($count[$i]['nb_combinations'] > 0) {
                    $this->confirm .= ': ' . $count[$i]['nb_prod_w_attr'] . ' ' . $this->l('products with attributes');
                    $this->confirm .= ', ' . $count[$i]['nb_combinations'] . ' ' . $this->l('attributes combinations');
                    $this->confirm .= '.<br/> ' . $this->l('Total') . ': ' . $count[$i]['nb_products'] . ' ' . $this->l('exported products');

                    if ($count[$i]['non_exported_products'] > 0) {
                        $this->confirm .= ', ' . $this->l('and') . ' ' . $count[$i]['non_exported_products'] . ' ' . $this->l('not-exported products (non-available)');
                    }
                    $this->confirm .= '.';
                } else {
                    $this->confirm .= '.';
                }
            }
        }
        $this->_html .= $this->displayConfirmation(html_entity_decode($this->confirm));

        return;
    }

    // ============================================================
    // HELPER METHODS - FORM RENDERING & DISPLAY
    // ============================================================

    /**
     * Get shop features for attribute selection
     *
     * Retrieves all product features available in a specific shop and language.
     * Used to populate feature selection dropdowns in admin forms.
     *
     * @param int $id_lang Language ID for feature names
     * @param int $id_shop Shop ID to scope features
     * @return array Array of feature objects with id_feature and name
     */
    public function getShopFeatures($id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT fl.* FROM ' . _DB_PREFIX_ . 'feature f
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (fl.id_feature = f.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_shop fs ON (fs.id_feature = f.id_feature)
			WHERE fl.id_lang = ' . (int) $id_lang . ' AND fs.id_shop = ' . (int) $id_shop . '
			ORDER BY f.id_feature ASC');
    }

    /**
     * Get shop attributes for attribute group selection
     *
     * Retrieves all attribute groups available in a specific shop and language.
     * Used to populate attribute selection dropdowns in admin forms.
     *
     * @param int $id_lang Language ID for attribute names
     * @param int $id_shop Shop ID to scope attributes
     * @return array Array of attribute group objects
     */
    public function getShopAttributes($id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT agl.* FROM ' . _DB_PREFIX_ . 'attribute_group_lang agl
			LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group_shop ags ON (ags.id_attribute_group = agl.id_attribute_group)
			WHERE agl.id_lang = ' . (int) $id_lang . ' AND ags.id_shop = ' . (int) $id_shop . '
			ORDER BY ags.id_attribute_group ASC');
    }

    /**
     * Get product features for a specific product
     *
     * Retrieves all feature values associated with a product in a specific language and shop.
     * Used when generating product XML to retrieve gender, age_group, color, etc.
     *
     * @param int $id_product Product ID
     * @param int $id_lang Language ID for feature values
     * @param int $id_shop Shop ID
     * @return array Array of product features with values
     */
    public function getProductFeatures($id_product, $id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT fl.*, fv.value FROM ' . _DB_PREFIX_ . 'feature_product fp
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (fl.id_feature = fp.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_shop fs ON (fs.id_feature = fp.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fv ON (fv.id_feature_value = fp.id_feature_value AND fv.id_lang = fl.id_lang)
			WHERE fp.id_product = ' . (int) $id_product . ' AND fl.id_lang = ' . (int) $id_lang . ' AND fs.id_shop = ' . (int) $id_shop . '
			ORDER BY fp.id_feature ASC');
    }

    /**
     * Render main configuration form
     *
     * Generates the main admin panel form with all module configuration options
     * including product type, shipping, image type, attributes, and export settings.
     *
     * @return string Generated HTML form
     */
    public function renderForm()
    {
        // Initialize form helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues($this->context->shop->id),
            'id_language' => $this->context->language->id,
            'languages' => $this->context->controller->getLanguages(),
        ];

        // Get shop data for form options
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;
        $img_types = ImageType::getImagesTypes('products');

        // Build feature selection options
        $features = [
            [
                'id_feature' => '',
                'name' => $this->l('Product feature doesn\'t exist'),
            ],
        ];
        $features = array_merge($features, $this->getShopFeatures($id_lang, $id_shop));

        // Build description type options
        $descriptions = [
            [
                'id_desc' => 'short',
                'name' => $this->l('Short description'),
            ],
            [
                'id_desc' => 'long',
                'name' => $this->l('Long description'),
            ],
            [
                'id_desc' => 'short+long',
                'name' => $this->l('Short and long description'),
            ],
            [
                'id_desc' => 'meta',
                'name' => $this->l('Meta description'),
            ],
        ];

        // Build MPN type options
        $mpn_types = [
            [
                'id_mpn' => 'reference',
                'name' => $this->l('Reference'),
            ],
            [
                'id_mpn' => 'supplier_reference',
                'name' => $this->l('Supplier reference'),
            ],
        ];

        // Form description with helpful links and instructions
        $form_desc = html_entity_decode($this->l('Please visit and read the <a href="http://support.google.com/merchants/answer/188494" target="_blank">Google Shopping Products Feed Specification</a> if you don\'t know how to configure these options. <br/> If all your shop products match the same Google Shopping category, you can attach it to your home category in the table below, sub-categories will automatically get the same setting. No need to fill each Google category field. <br/> Products in categories with no Google category specified are exported in the Google Shopping category linked to the nearest parent.'));


        // Build form fields array
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Parameters'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    // Product type field
                    [
                        'type' => 'text',
                        'label' => $this->l('Default product type'),
                        'name' => 'product_type[]',
                        // 'class' => 'fixed-width-xl',
                        'lang' => true,
                        'desc' => $this->l('Your shop\'s default product type, ie: if you sell pants and shirts, and your main categories are "Men", "Women", "Kids", enter "Clothing" here. That will be exported as your shop main category. This setting is optional and can be left empty. Besides the module requires that at least main category of your shop is correctly linked to a Google product category.'),
                    ],
                    // Description type selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Description type'),
                        'name' => 'description',
                        'default_value' => $helper->tpl_vars['fields_value']['description'],
                        'options' => [
                            // 'default' => array('value' => 0, 'label' => $this->l('Choose description type')),
                            'query' => $descriptions,
                            'id' => 'id_desc',
                            'name' => 'name',
                        ],
                    ],
                    // Shipping method selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Shipping Methods'),
                        'name' => 'shipping_mode',
                        'options' => [
                            'query' => [
                                [
                                    'id_mode' => 'none',
                                    'name' => $this->l('No shipping method'),
                                ],
                                [
                                    'id_mode' => 'fixed',
                                    'name' => $this->l('Price fixed'),
                                ],
                                [
                                    'id_mode' => 'full',
                                    'name' => $this->l('Generate shipping costs in several countries [EXPERIMENTAL]'),
                                ],
                            ],
                            'id' => 'id_mode',
                            'name' => 'name',
                        ],
                    ],
                    // Fixed shipping price
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping price'),
                        'name' => 'shipping_price',
                        'class' => 'fixed-width-xs',
                        'prefix' => $this->context->currency->sign,
                        'desc' => $this->l('This field is used for "Price fixed".'),
                    ],
                    // Shipping country field
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping country'),
                        'name' => 'shipping_country',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->l('This field is used for "Price fixed".'),
                    ],
                    // Multi-country shipping selection
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Shipping countries'),
                        'name' => 'shipping_countries[]',
                        'options' => [
                            'query' => array_merge([
                                [
                                    'id_country' => 'all',
                                    'name' => $this->l('All'),
                                ],
                            ], Country::getCountries($this->context->language->id, true)),
                            'id' => 'id_country',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('This field is used for "Generate shipping costs in several countries". Hold [Ctrl] key pressed to select multiple country.'),
                    ],
                    // Carrier exclusion selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Carriers to exclude'),
                        'name' => 'carriers_excluded[]',
                        'options' => [
                            'query' => array_merge([
                                [
                                    'id_carrier' => 'no',
                                    'name' => $this->l('No'),
                                ],
                            ], Carrier::getCarriers($this->context->language->id, false, false, null, null, Carrier::ALL_CARRIERS)),
                            'id' => 'id_carrier',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('This field is used for "Generate shipping costs in several countries". Hold [Ctrl] key pressed to select multiple carriers.'),
                    ],
                    // Image type selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Images type'),
                        'name' => 'img_type',
                        'default_value' => $helper->tpl_vars['fields_value']['img_type'],
                        'options' => [
                            // 'default' => array('value' => 0, 'label' => $this->l('Choose image type')),
                            'query' => $img_types,
                            'id' => 'name',
                            'name' => 'name',
                        ],
                    ],
                    // MPN type selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Manufacturers References type (MPN)'),
                        'name' => 'mpn_type',
                        'default_value' => $helper->tpl_vars['fields_value']['mpn_type'],
                        'options' => [
                            'query' => $mpn_types,
                            'id' => 'id_mpn',
                            'name' => 'name',
                        ],
                    ],
                    // Minimum price filter
                    [
                        'type' => 'text',
                        'label' => $this->l('Minimum product price'),
                        'name' => 'export_min_price',
                        'class' => 'fixed-width-xs',
                        'prefix' => $this->context->currency->sign,
                        'desc' => $this->l('Products at lower price are not exported. Enter 0.00 for no use.'),
                        'required' => true,
                    ],
                    // Gender feature selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Products gender feature'),
                        'name' => 'gender',
                        'default_value' => $helper->tpl_vars['fields_value']['gender'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                    ],
                    // Age group feature selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Products age group feature'),
                        'name' => 'age_group',
                        'default_value' => $helper->tpl_vars['fields_value']['age_group'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                    ],
                    // Color feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products color feature'),
                        'name' => 'color[]',
                        'default_value' => $helper->tpl_vars['fields_value']['color[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple color features.'),
                    ],
                    // Material feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products material feature'),
                        'name' => 'material[]',
                        'default_value' => $helper->tpl_vars['fields_value']['material[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple material features.'),
                    ],
                    // Pattern feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products pattern feature'),
                        'name' => 'pattern[]',
                        'default_value' => $helper->tpl_vars['fields_value']['pattern[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple pattern features.'),
                    ],
                    // Size feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products size feature'),
                        'name' => 'size[]',
                        'default_value' => $helper->tpl_vars['fields_value']['size[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple size features.'),
                    ],
                    // Export attributes toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export attributes combinations'),
                        'name' => 'export_attributes',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('If checked, one product is exported for each attributes combination. Products should have at least one attribute filled in order to be exported as combinations.'),
                    ],
                    // GTIN export toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export products with no GTIN code'),
                        'name' => 'no_gtin',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('Allow export of products, that no not have a GTIN code (EAN13/UPC)'),
                    ],
                    // Shipping dimensions export toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export products shipping dimensions'),
                        'name' => 'shipping_dimension',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('Allow export of dimension for each products, if typed in product details'),
                    ],
                    // No brand products toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export products with no brand'),
                        'name' => 'no_brand',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('Allow export of products, that no not have a brand (Manufacturer)'),
                    ],
                    // Identifier exists tag toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Set <identifier_exists> tag to FALSE'),
                        'name' => 'id_exists_tag',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('If your product is new (which you submit through the condition attribute) and it doesn’t have a gtin and brand or mpn and brand.') . ' <a href="https://support.google.com/merchants/answer/6324478?hl=en" target="_blank">' . $this->l('identifier_exists: Definition') . '</a>',
                    ],
                    // Non-available products export toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export non-available products'),
                        'name' => 'export_nap',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    // Quantity export toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export product quantity'),
                        'name' => 'quantity',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    // On sale indicator export toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Export "On Sale" indication'),
                        'name' => 'featured_products',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    // File generation location toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Generate the files to the root of the site'),
                        'name' => 'gen_file_in_root',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    // File prefix field
                    [
                        'type' => 'text',
                        'label' => $this->l('prefix for output filename'),
                        'name' => 'file_prefix',
                        'class' => 'fixed-width-lg',
                        'desc' => $this->l('Allows you to prefix feed filename. Makes it a little harder for other to guess your feed names'),
                    ],
                    // Auto-export toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Automatic export on saves?'),
                        'name' => 'autoexport_on_save',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('When disabled, you have to "Save & Export" manually or run the CRON job, to generate new files.'),
                    ],
                ],
                'description' => $form_desc,
                'submit' => [
                    'name' => 'submitFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Render local inventory form
     *
     * Generates admin form for local inventory feed configuration.
     * Allows setting the store code for local inventory exports.
     *
     * @return string Generated HTML form
     */
    public function renderLocalInventoryForm()
    {
        // Initialize form helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigLocalInventoryFieldsValues($this->context->shop->id),
            'id_language' => $this->context->language->id,
            'languages' => $this->context->controller->getLanguages(),
        ];

        // Build form fields
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Local Inventory Parameters'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Your store code'),
                        'name' => 'store_code',
                        'desc' => $this->l('Your store code'),
                    ]
                ],
                'submit' => [
                    'name' => 'submitLocalInventoryFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Render reviews export form
     *
     * Generates admin form for customer reviews export configuration.
     * Provides option to export product reviews to Google Shopping.
     *
     * @return string Generated HTML form
     */
    public function renderReviewsForm()
    {
        // Initialize form helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigLocalInventoryFieldsValues($this->context->shop->id),
            'id_language' => $this->context->language->id,
            'languages' => $this->context->controller->getLanguages(),
        ];

        // Build form fields - simple form with just export button
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Reviews'),
                    'icon' => 'icon-cogs',
                ],
                'submit' => [
                    'name' => 'submitReviewsFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Get configuration field values for main flux form
     *
     * Retrieves all stored configuration values for the module and formats them
     * for display in admin forms. Handles multi-language values and arrays.
     *
     * @param int $shop_id Shop ID to retrieve config for
     * @return array Associative array of configuration field values
     */
    public function getConfigFieldsValues($shop_id)
    {
        $shop_group_id = Shop::getGroupFromShop($shop_id);

        // Initialize all variables with default values
        $product_type = [];
        $description = 'short';
        $shipping_price_fixed = true;
        $shipping_mode = 'fixed';
        $shipping_price = 0;
        $shipping_country = 'UK';
        $shipping_countries = 'all';
        $img_type = 'large_default';
        $mpn_type = '';
        $gender = '';
        $age_group = '';
        $export_attributes = '';
        $color = [];
        $material = [];
        $pattern = [];
        $size = [];
        $export_min_price = 0;
        $no_gtin = true;
        $shipping_dimension = true;
        $no_brand = true;
        $id_exists_tag = true;
        $export_nap = true;
        $quantity = true;
        $featured_products = true;
        $gen_file_in_root = true;
        $autoexport_on_save = true;
        $file_prefix = '';

        // Retrieve multi-language product types
        foreach (Language::getLanguages(false) as $lang) {
            $product_type[$lang['id_lang']] = Configuration::get('GS_PRODUCT_TYPE', $lang['id_lang'], $shop_group_id, $shop_id);
        }

        // Retrieve all configuration values
        $description = Configuration::get('GS_DESCRIPTION', 0, $shop_group_id, $shop_id);
        $shipping_mode = Configuration::get('GS_SHIPPING_MODE', 0, $shop_group_id, $shop_id);
        $shipping_price_fixed &= (bool) Configuration::get('GS_SHIPPING_PRICE_FIXED', 0, $shop_group_id, $shop_id);
        $shipping_price = (float) Configuration::get('GS_SHIPPING_PRICE', 0, $shop_group_id, $shop_id);
        $shipping_country = Configuration::get('GS_SHIPPING_COUNTRY', 0, $shop_group_id, $shop_id);
        $shipping_countries = ArrayHelper::explodeAndFilter(Configuration::get('GS_SHIPPING_COUNTRIES', 0, $shop_group_id, $shop_id));
        $carriers_excluded = ArrayHelper::explodeAndFilter(Configuration::get('GS_CARRIERS_EXCLUDED', 0, $shop_group_id, $shop_id));
        $img_type = Configuration::get('GS_IMG_TYPE', 0, $shop_group_id, $shop_id);
        $mpn_type = Configuration::get('GS_MPN_TYPE', 0, $shop_group_id, $shop_id);
        $gender = Configuration::get('GS_GENDER', 0, $shop_group_id, $shop_id);
        $age_group = Configuration::get('GS_AGE_GROUP', 0, $shop_group_id, $shop_id);
        $export_attributes = Configuration::get('GS_ATTRIBUTES', 0, $shop_group_id, $shop_id);
        $color = ArrayHelper::explodeAndFilter(Configuration::get('GS_COLOR', 0, $shop_group_id, $shop_id));
        $material = ArrayHelper::explodeAndFilter(Configuration::get('GS_MATERIAL', 0, $shop_group_id, $shop_id));
        $pattern = ArrayHelper::explodeAndFilter(Configuration::get('GS_PATTERN', 0, $shop_group_id, $shop_id));
        $size = ArrayHelper::explodeAndFilter(Configuration::get('GS_SIZE', 0, $shop_group_id, $shop_id));
        $export_min_price = (float) Configuration::get('GS_EXPORT_MIN_PRICE', 0, $shop_group_id, $shop_id);
        $no_gtin &= (bool) Configuration::get('GS_NO_GTIN', 0, $shop_group_id, $shop_id);
        $shipping_dimension &= (bool) Configuration::get('GS_SHIPPING_DIMENSION', 0, $shop_group_id, $shop_id);
        $no_brand &= (bool) Configuration::get('GS_NO_BRAND', 0, $shop_group_id, $shop_id);
        $id_exists_tag &= (bool) Configuration::get('GS_ID_EXISTS_TAG', 0, $shop_group_id, $shop_id);
        $export_nap &= (bool) Configuration::get('GS_EXPORT_NAP', 0, $shop_group_id, $shop_id);
        $quantity &= (bool) Configuration::get('GS_QUANTITY', 0, $shop_group_id, $shop_id);
        $featured_products &= (bool) Configuration::get('GS_FEATURED_PRODUCTS', 0, $shop_group_id, $shop_id);
        $gen_file_in_root &= (bool) Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $shop_group_id, $shop_id);
        $autoexport_on_save &= (bool) Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id);
        $file_prefix = Configuration::get('GS_FILE_PREFIX', 0, $shop_group_id, $shop_id);

        // Return formatted array for form display
        return [
            'product_type[]' => $product_type,
            'description' => $description,
            'shipping_mode' => $shipping_mode,
            'shipping_price_fixed' => (int) $shipping_price_fixed,
            'shipping_price' => (float) $shipping_price,
            'shipping_country' => $shipping_country,
            'shipping_countries[]' => $shipping_countries,
            'carriers_excluded[]' => $carriers_excluded,
            'img_type' => $img_type,
            'mpn_type' => $mpn_type,
            'gender' => $gender,
            'age_group' => $age_group,
            'export_attributes' => (int) $export_attributes,
            'color[]' => $color,
            'material[]' => $material,
            'pattern[]' => $pattern,
            'size[]' => $size,
            'export_min_price' => (float) $export_min_price,
            'no_gtin' => (int) $no_gtin,
            'shipping_dimension' => (int) $shipping_dimension,
            'no_brand' => (int) $no_brand,
            'id_exists_tag' => (int) $id_exists_tag,
            'export_nap' => (int) $export_nap,
            'quantity' => (int) $quantity,
            'featured_products' => (int) $featured_products,
            'gen_file_in_root' => (int) $gen_file_in_root,
            'file_prefix' => $file_prefix,
            'autoexport_on_save' => (int) $autoexport_on_save,
        ];
    }

    /**
     * Get local inventory configuration field values
     *
     * Retrieves stored local inventory configuration values for form display.
     *
     * @param int $shop_id Shop ID
     * @return array Array with store_code configuration value
     */
    public function getConfigLocalInventoryFieldsValues($shop_id)
    {
        $shop_group_id = Shop::getGroupFromShop($shop_id);
        $store_code = Configuration::get('GS_LOCAL_SHOP_CODE', 0, $shop_group_id, $shop_id);

        return [
            'store_code' => $store_code,
        ];
    }

    /**
     * Render category mapping edit form
     *
     * Generates admin form for editing a category's Google Shopping category mapping,
     * condition, availability, and product attributes (gender, age_group, color, etc.).
     *
     * @return string Generated HTML form
     */
    public function renderCategForm()
    {
        // Initialize helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $back_url = $helper->currentIndex . '&token=' . $helper->token;
        $helper->fields_value = $this->getGCategFieldsValues();
        $helper->languages = $this->context->controller->getLanguages();
        $helper->tpl_vars = [
            'back_url' => $back_url,
            'show_cancel_button' => true,
        ];
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        // Build condition options
        $conditions = [
            [
                'id_cond' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id_cond' => 'new',
                'name' => $this->l('Category\'s products are new'),
            ],
            [
                'id_cond' => 'used',
                'name' => $this->l('Category\'s products are used'),
            ],
            [
                'id_cond' => 'refurbished',
                'name' => $this->l('Category\'s products are refurbished'),
            ],
        ];

        // Build availability options
        $avail_modes = [
            [
                'id_mode' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id_mode' => 'in stock',
                'name' => $this->l('Category\'s products are in stock'),
            ],
            [
                'id_mode' => 'preorder',
                'name' => $this->l('Category\'s products avail. on preorder'),
            ],
        ];

        // Build gender options
        $gender_modes = [
            [
                'id' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id' => 'male',
                'name' => $this->l('Category\'s products are for men'),
            ],
            [
                'id' => 'female',
                'name' => $this->l('Category\'s products are for women'),
            ],
            [
                'id' => 'unisex',
                'name' => $this->l('Category\'s products are unisex'),
            ],
        ];

        // Build age group options
        $age_modes = [
            [
                'id' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id' => 'newborn',
                'name' => $this->l('Newborn'),
            ],
            [
                'id' => 'infant',
                'name' => $this->l('Infant'),
            ],
            [
                'id' => 'toddler',
                'name' => $this->l('Toddler'),
            ],
            [
                'id' => 'kids',
                'name' => $this->l('Kids'),
            ],
            [
                'id' => 'adult',
                'name' => $this->l('Adult'),
            ],
        ];

        // Get available attributes for selection
        $attributes = [
            [
                'id_attribute_group' => '',
                'name' => $this->l('Products attribute doesn\'t exist'),
            ],
        ];
        $attributes = array_merge($attributes, $this->getShopAttributes($id_lang, $id_shop));

        // Form descriptions
        $gcat_desc = '<a href="http://www.google.com/support/merchants/bin/answer.py?answer=160081&query=product_type" target="_blank">' . $this->l('See Google Categories') . '</a> ';
        $form_desc = html_entity_decode($this->l('Default: System tries to get the value of the product attribute. If not found, system tries to get the category\'s attribute value. <br> If not found, it tries to get the parent category\'s attribute, and so till the root category. At last, if empty, value is not exported.'));

        // Build form fields array
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !ArrayHelper::getValue('updategshoppingflux')) ? $this->l('Update the matching Google category') : $this->l('Add a new Google category'),
                    'icon' => 'icon-link',
                ],
                'input' => [
                    // Category breadcrumb display (read-only)
                    [
                        'type' => 'free',
                        'label' => $this->l('Category'),
                        'name' => 'breadcrumb',
                    ],
                    // Google category name (multi-language)
                    [
                        'type' => 'text',
                        'label' => $this->l('Matching Google category'),
                        'name' => 'gcategory',
                        'lang' => true,
                        'desc' => $gcat_desc,
                    ],
                    // Export toggle
                    [
                        'type' => 'switch',
                        'name' => 'export',
                        'label' => $this->l('Export products from this category'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    // Product condition selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Condition'),
                        'name' => 'condition',
                        'default_value' => $helper->fields_value['condition'],
                        'options' => [
                            'query' => $conditions,
                            'id' => 'id_cond',
                            'name' => 'name',
                        ],
                    ],
                    // Product availability selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Products\' availability'),
                        'name' => 'availability',
                        'default_value' => $helper->fields_value['availability'],
                        'options' => [
                            'query' => $avail_modes,
                            'id' => 'id_mode',
                            'name' => 'name',
                        ],
                    ],
                    // Gender attribute selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Gender attribute'),
                        'name' => 'gender',
                        'default_value' => $helper->fields_value['gender'],
                        'options' => [
                            'query' => $gender_modes,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    // Age group selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Age group'),
                        'name' => 'age_group',
                        'default_value' => $helper->fields_value['age_group'],
                        'options' => [
                            'query' => $age_modes,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    // Color attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products color attribute'),
                        'name' => 'color[]',
                        'default_value' => $helper->fields_value['color[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple color attributes.'),
                    ],
                    // Material attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products material attribute'),
                        'name' => 'material[]',
                        'default_value' => $helper->fields_value['material[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple material attributes.'),
                    ],
                    // Pattern attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products pattern attribute'),
                        'name' => 'pattern[]',
                        'default_value' => $helper->fields_value['pattern[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple pattern attributes.'),
                    ],
                    // Size attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products size attribute'),
                        'name' => 'size[]',
                        'default_value' => $helper->fields_value['size[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple size attributes.'),
                    ],
                ],
                'description' => $form_desc,
                'submit' => [
                    'name' => 'submitCategory',
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        // Update button instead of save when editing
        if ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !ArrayHelper::getValue('updategshoppingflux')) {
            $fields_form['form']['submit'] = [
                'name' => 'updateCategory',
                'title' => $this->l('Update'),
            ];
        }

        // Add hidden fields for update operations
        if (Tools::isSubmit('updategshoppingflux') || Tools::isSubmit('statusgshoppingflux')) {
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'updatecateg',
            ];
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'id_gcategory',
            ];
            $helper->fields_value['updatecateg'] = '';
        }

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Get Google category form field values
     *
     * Retrieves existing Google category configuration for form population.
     * Used when editing an existing category mapping.
     *
     * @return array Array of form field values
     */
    public function getGCategFieldsValues()
    {
        $gcatexport_active = '';
        $gcatcondition_edit = '';
        $gcatavail_edit = '';
        $gcatgender_edit = '';
        $gcatage_edit = '';
        $gcatcolor_edit = '';
        $gcatmaterial_edit = '';
        $gcatpattern_edit = '';
        $gcatsize_edit = '';
        $gcategory_edit = '';
        $gcatlabel_edit = '';

        // Load existing values if editing
        if (Tools::isSubmit('updategshoppingflux') || Tools::isSubmit('statusgshoppingflux')) {
            $id_lang = $this->context->cookie->id_lang;
            $gcateg = GCategories::getCategLang(ArrayHelper::getValue('id_gcategory'), (int) Shop::getContextShopID(), $id_lang);

            // Decode HTML entities in category names
            foreach ($gcateg['gcategory'] as $key => $categ) {
                $gcateg['gcategory'][$key] = Tools::htmlentitiesDecodeUTF8($categ);
            }

            // Extract values from loaded data
            $gcatexport_active = $gcateg['export'];
            $gcatcondition_edit = $gcateg['condition'];
            $gcatavail_edit = $gcateg['availability'];
            $gcatgender_edit = $gcateg['gender'];
            $gcatage_edit = $gcateg['age_group'];
            $gcatcolor_edit = $gcateg['color'];
            $gcatmaterial_edit = $gcateg['material'];
            $gcatpattern_edit = $gcateg['pattern'];
            $gcatsize_edit = $gcateg['size'];
            $gcategory_edit = $gcateg['gcategory'];
            $gcatlabel_edit = $gcateg['breadcrumb'];
        }

        // Build return array with field values
        $fields_values = [
            'id_gcategory' => ArrayHelper::getValue('id_gcategory'),
            'breadcrumb' => (isset($gcatlabel_edit) ? $gcatlabel_edit : ''),
            'export' => ArrayHelper::getValue('export', isset($gcatexport_active) ? $gcatexport_active : ''),
            'condition' => ArrayHelper::getValue('condition', isset($gcatcondition_edit) ? $gcatcondition_edit : ''),
            'availability' => ArrayHelper::getValue('availability', isset($gcatavail_edit) ? $gcatavail_edit : ''),
            'gender' => ArrayHelper::getValue('gender', isset($gcatgender_edit) ? $gcatgender_edit : ''),
            'age_group' => ArrayHelper::getValue('age_group', isset($gcatage_edit) ? $gcatage_edit : ''),
            'color[]' => ArrayHelper::explodeAndFilter(ArrayHelper::getValue('color[]', isset($gcatcolor_edit) ? $gcatcolor_edit : '')),
            'material[]' => ArrayHelper::explodeAndFilter(ArrayHelper::getValue('material[]', isset($gcatmaterial_edit) ? $gcatmaterial_edit : '')),
            'pattern[]' => ArrayHelper::explodeAndFilter(ArrayHelper::getValue('pattern[]', isset($gcatpattern_edit) ? $gcatpattern_edit : '')),
            'size[]' => ArrayHelper::explodeAndFilter(ArrayHelper::getValue('size[]', isset($gcatsize_edit) ? $gcatsize_edit : '')),
        ];

        // Initialize Google category names for all languages
        if (ArrayHelper::getValue('submitAddmodule')) {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['gcategory'][$lang['id_lang']] = '';
            }
        } else {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['gcategory'][$lang['id_lang']] = ArrayHelper::getValue('gcategory_' . (int) $lang['id_lang'], isset($gcategory_edit[$lang['id_lang']]) ? html_entity_decode($gcategory_edit[$lang['id_lang']]) : '');
            }
        }

        return $fields_values;
    }

    /**
     * Get language configuration form field values
     *
     * Retrieves language and currency configuration for form population.
     * Used when editing language-currency mappings.
     *
     * @return array Array of language configuration field values
     */
    public function getGLangFieldsValues()
    {
        // Initialize variables
        $glangcurrency_edit = '';
        $glangexport_active = '';

        // Load existing values if editing
        if (Tools::isSubmit('updategshoppingflux_lc') || Tools::isSubmit('statusgshoppingflux_lc')) {
            $glang = GLangAndCurrency::getLangCurrencies(ArrayHelper::getValue('id_glang'), (int) Shop::getContextShopID());
            $glangcurrency_edit = explode(';', $glang[0]['id_currency']);
            $glangtax_included = $glang[0]['tax_included'];
            $glangexport_active = $glang[0]['active'];
        }

        // Get language data
        $language = Language::getLanguage(ArrayHelper::getValue('id_glang'));

        // Build return array
        $fields_values = [
            'id_glang' => ArrayHelper::getValue('id_glang'),
            'name' => $language['name'],
            'iso_code' => $language['iso_code'],
            'language_code' => $language['language_code'],
            'currencies[]' => ArrayHelper::getValue('currencies[]', $glangcurrency_edit),
            'tax_included' => ArrayHelper::getValue('tax_included', $glangtax_included),
            'active' => ArrayHelper::getValue('active', $glangexport_active),
        ];

        return $fields_values;
    }

    /**
     * Render language-currency configuration form
     *
     * Generates admin form for editing language-currency pair configuration.
     * Allows setting currency and tax inclusion for each language.
     *
     * @return string Generated HTML form
     */
    public function renderLangForm()
    {
        // Initialize form helper
        $this->fields_form = [];
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->table = 'gshoppingflux_lc';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $back_url = $helper->currentIndex . '&token=' . $helper->token;
        $helper->fields_value = $this->getGLangFieldsValues();
        $helper->tpl_vars = [
            'back_url' => $back_url,
            'show_cancel_button' => true,
        ];

        // Get available currencies
        $currencies = Currency::getCurrencies();

        // Form description
        $form_desc = html_entity_decode($this->l('Select currency to export with this language.'));

        // Build form fields
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Language export settings'),
                    'icon' => 'icon-globe',
                ],
                'input' => [
                    // Language name display (read-only)
                    [
                        'type' => 'free',
                        'label' => $this->l('Language'),
                        'name' => 'name',
                    ],
                    // Language code display (read-only)
                    [
                        'type' => 'free',
                        'label' => $this->l('Language code'),
                        'name' => 'language_code',
                    ],
                    // Active toggle (disabled)
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enabled'),
                        'name' => 'active',
                        'is_bool' => true,
                        'disabled' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    // Currency multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Currencies'),
                        'name' => 'currencies[]',
                        'default_value' => $helper->fields_value['currencies[]'],
                        'options' => [
                            'query' => $currencies,
                            'id' => 'id_currency',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple currencies.'),
                    ],
                    // Tax inclusion toggle
                    [
                        'type' => 'switch',
                        'label' => $this->l('Prices exported tax included'),
                        'name' => 'tax_included',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'inc_tax',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'ex_tax',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('If disabled, prices are exported ex tax.'),
                    ],
                ],
                'description' => $form_desc,
                'submit' => [
                    'name' => 'submitCategory',
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        // Update button instead of 'Save' when editing
        if ((Tools::getIsset('updategshoppingflux_lc') || Tools::getIsset('statusgshoppingflux_lc')) && !ArrayHelper::getValue('updategshoppingflux_lc')) {
            $fields_form['form']['submit'] = [
                'name' => 'updateLanguage',
                'title' => $this->l('Update'),
            ];
        }

        // Add hidden fields for update operations
        if (Tools::isSubmit('updategshoppingflux_lc') || Tools::isSubmit('statusgshoppingflux_lc')) {
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'updatelang',
            ];
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'id_glang',
            ];
            $helper->fields_value['updatelang'] = '';
        }

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Render language list table
     *
     * Generates a list view table of all language-currency pairs configured
     * for the current shop. Shows language names, codes, currencies, and status.
     *
     * @return string Generated HTML list table
     */
    public function renderLangList()
    {
        // Define table columns
        $fields_list = [
            'id_glang' => [
                'title' => $this->l('ID'),
            ],
            'flag' => [
                'title' => $this->l('Flag'),
                'image' => 'l',
            ],
            'name' => [
                'title' => $this->l('Language'),
            ],
            'language_code' => [
                'title' => $this->l('Language code'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'currency' => [
                'title' => $this->l('Currency'),
            ],
            'tax_included' => [
                'title' => $this->l('Tax'),
            ],
            'active' => [
                'title' => $this->l('Enabled'),
                'align' => 'center',
                'active' => 'status',
                'type' => 'bool',
                'class' => 'fixed-width-sm',
            ],
        ];

        // Add shop name column if multishop is active
        if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(true, null, true)) > 1) {
            $fields_list = array_merge($fields_list, [
                'shop_name' => [
                    'title' => $this->l('Shop name'),
                    'width' => '15%',
                ],
            ]);
        }

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->show_toolbar = false;
        $helper->simple_header = true;
        $helper->identifier = 'id_glang';
        $helper->imageType = 'jpg';
        $helper->table = 'gshoppingflux_lc';
        $helper->actions = [
            'edit',
        ];
        $helper->title = $this->l('Export languages and currencies');
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->tpl_vars = [
            'languages' => $this->context->controller->getLanguages(),
        ];
        $glangflux = GLangAndCurrency::getAllLangCurrencies(0);
        foreach ($glangflux as $k => $v) {
            $currencies = explode(';', $glangflux[$k]['id_currency']);
            $arrCurr = [];
            foreach ($currencies as $idc) {
                $currency = new Currency($idc);
                $arrCurr[] = $currency->iso_code;
            }
            if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE')) {
                $shop = Shop::getShop($glangflux[$k]['id_shop']);
                $glangflux[$k]['shop_name'] = $shop['name'];
            }
            $glangflux[$k]['currency'] = implode(' - ', $arrCurr);
            if ($glangflux[$k]['tax_included'] == 1) {
                $glangflux[$k]['tax_included'] = $this->l('Inc Tax ');
            } else {
                $glangflux[$k]['tax_included'] = $this->l('Ex Tax');
            }
        }

        return $helper->generateList($glangflux, $fields_list);
    }

    /**
     * Render category list table
     *
     * Generates tree-view list table of all categories with Google Shopping mappings.
     * Displays category hierarchy, Google category names, condition, availability,
     * and attribute mappings (gender, age_group, color, material, pattern, size).
     * Shows export status for each category.
     *
     * @return string Generated HTML list table using HelperList
     */
    public function renderCategList()
    {
        $gcategories = $this->makeCatTree();

        $fields_list = [
            'id_gcategory' => [
                'title' => $this->l('ID'),
            ],
        ];

        if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(true, null, true)) > 1) {
            $fields_list = array_merge($fields_list, [
                'shop_name' => [
                    'title' => $this->l('Shop name'),
                    'width' => '15%',
                ],
            ]);
        }

        $fields_list = array_merge($fields_list, [
            'gcat_name' => [
                'title' => $this->l('Category'),
                'width' => '30%',
            ],
            'gcategory' => [
                'title' => $this->l('Matching Google category'),
                'width' => '70%',
            ],
            'condition' => [
                'title' => $this->l('Condit.'),
            ],
            'availability' => [
                'title' => $this->l('Avail.'),
            ],
            'gender' => [
                'title' => $this->l('Gender'),
            ],
            'age_group' => [
                'title' => $this->l('Age'),
            ],
            'gid_colors' => [
                'title' => $this->l('Color'),
            ],
            'gid_materials' => [
                'title' => $this->l('Material'),
            ],
            'gid_patterns' => [
                'title' => $this->l('Pattern'),
            ],
            'gid_sizes' => [
                'title' => $this->l('Size'),
            ],
            'export' => [
                'title' => $this->l('Export'),
                'align' => 'center',
                'is_bool' => true,
                'active' => 'status',
            ],
        ]);

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_gcategory';
        $helper->table = 'gshoppingflux';
        $helper->actions = [
            'edit',
        ];
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->title = $this->l('Google categories');
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        return $helper->generateList($gcategories, $fields_list);
    }

    /**
     * Render module information panel
     *
     * Generates information display panel showing:
     * - Generated feed file URLs for all languages/currencies
     * - Local inventory feed URLs
     * - Reviews feed URL
     * - CRON task URLs for automatic feed generation
     * - Help links and forum information
     *
     * Useful for admins to copy feed URLs into Google Merchant Center account
     * and set up automated exports via CRON jobs.
     *
     * @return string Generated HTML information panel using HelperForm
     */
    public function renderInfo()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->languages = $this->context->controller->getLanguages();
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Get active langs on shop
        $languages = GLangAndCurrency::getAllLangCurrencies(1);
        $shops = Shop::getShops(true, null, true);
        $output = '';

        foreach ($languages as $lang) {
            $currencies = explode(';', $lang['id_currency']);
            foreach ($currencies as $curr) {
                $currency = new Currency($curr);
                if (Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $this->context->shop->id_shop_group, $this->context->shop->id) == 1) {
                    $get_file_url = $this->uri . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id);
                    $get_local_file_url = $this->uri . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id, true);
                } else {
                    $get_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id);
                    $get_local_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id, true);
                }
                $output .= '<a href="' . $get_file_url . '">' . $get_file_url . '</a> <br /> ';
                $output .= '<a href="' . $get_local_file_url . '">' . $get_local_file_url . '</a> <br /> ';
            }
        }
        if (Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $this->context->shop->id_shop_group, $this->context->shop->id) == 1) {
            $get_reviews_file_url = $this->uri . $this->_getOutputFileName('', '', $this->context->shop->id, false, true);
        } else {
            $get_reviews_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName('', '', $this->context->shop->id, false, true);
        }
        $output .= '<a href="' . $get_reviews_file_url . '">' . $get_reviews_file_url . '</a> <br /> ';

        $info_cron = '<a href="' . $this->uri . 'modules/' . $this->name . '/cron.php" target="_blank">' . $this->uri . 'modules/' . $this->name . '/cron.php</a>';
        $info_cron .= '<br/><a href="' . $this->uri . 'modules/' . $this->name . '/cron.php?local=true" target="_blank">' . $this->uri . 'modules/' . $this->name . '/cron.php?local=true</a>';
        $info_cron .= '<br/><a href="' . $this->uri . 'modules/' . $this->name . '/cron.php?reviews=true" target="_blank">' . $this->uri . 'modules/' . $this->name . '/cron.php?reviews=true</a>';

        if (count($languages) > 1) {
            $files_desc = $this->l('Configure these URLs in your Google Merchant Center account.');
        } else {
            $files_desc = $this->l('Configure this URL in your Google Merchant Center account.');
        }

        $cron_desc = $this->l('Install a CRON task to update the feed frequently.');

        if (count($shops) > 1) {
            $cron_desc .= ' ' . $this->l('Please note that as multishop feature is active, you\'ll have to install several CRON tasks, one for each shop.');
        }

        $form_desc = $this->l('Report bugs and find help on forum: <a href="https://www.prestashop.com/forums/topic/661366-free-module-google-shopping-flux/" target="_blank">https://www.prestashop.com/forums/topic/661366-free-module-google-shopping-flux/</a>');
        $helper->fields_value = [
            'info_files' => $output,
            'info_cron' => $info_cron,
        ];

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Files information'),
                    'icon' => 'icon-info',
                ],
                'input' => [
                    [
                        'type' => 'free',
                        'label' => $this->l('Generated files links:'),
                        'name' => 'info_files',
                        'desc' => $files_desc,
                    ],
                    [
                        'type' => 'free',
                        'label' => $this->l('Automatic files generation:'),
                        'name' => 'info_cron',
                        'desc' => $cron_desc,
                    ],
                ],
                'description' => html_entity_decode($form_desc, self::REPLACE_FLAGS, self::CHARSET),
            ],
        ];

        return $helper->generateForm([
            $fields_form,
        ]);
    }

    /**
     * Get custom nested categories tree
     *
     * Retrieves hierarchical category tree with Google Shopping mapping information.
     * Handles category nesting, breadcrumb generation, and attribute mapping display.
     * Optimized with caching to improve performance on large category trees.
     *
     * @param int $shop_id Shop ID to scope categories
     * @param int|null $root_category Root category ID to start tree from (optional)
     * @param int $id_lang Language ID for category names
     * @param bool $active Filter only active categories (default: true)
     * @param array|null $groups Group IDs to filter by (optional, if groups enabled)
     * @param bool $use_shop_restriction Apply shop restrictions in query (default: true)
     * @param string $sql_filter Additional SQL WHERE conditions (default: empty)
     * @param string $sql_sort Custom SQL ORDER BY clause (default: empty)
     * @param string $sql_limit Custom SQL LIMIT clause (default: empty)
     * @return array Nested category tree with Google Shopping configuration and attributes
     */
    public function customGetNestedCategories($shop_id, $root_category = null, $id_lang = false, $active = true, $groups = null, $use_shop_restriction = true, $sql_filter = '', $sql_sort = '', $sql_limit = '')
    {
        if (isset($root_category) && !Validate::isInt($root_category)) {
            exit(Tools::displayError());
        }

        if (!Validate::isBool($active)) {
            exit(Tools::displayError());
        }

        if (isset($groups) && Group::isFeatureActive() && !is_array($groups)) {
            $groups = (array) $groups;
        }

        $cache_id = 'Category::getNestedCategories_' . md5((int) $shop_id . (int) $root_category . (int) $id_lang . (int) $active . (int) $active . (isset($groups) && Group::isFeatureActive() ? implode('', $groups) : ''));
        if (!Cache::isStored($cache_id)) {
            $result = Db::getInstance()->executeS('
				SELECT c.*, cl.`name` as gcat_name, g.*, gl.*, s.name as shop_name
				FROM `' . _DB_PREFIX_ . 'category` c
				INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = "' . (int) $shop_id . '")
				LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON c.`id_category` = cl.`id_category` AND cl.`id_shop` = "' . (int) $shop_id . '"
				LEFT JOIN `' . _DB_PREFIX_ . 'gshoppingflux` g ON g.`id_gcategory` = c.`id_category` AND g.`id_shop` = "' . (int) $shop_id . '"
				LEFT JOIN `' . _DB_PREFIX_ . 'gshoppingflux_lang` gl ON gl.`id_gcategory` = c.`id_category` AND gl.`id_shop` = "' . (int) $shop_id . '"
				LEFT JOIN ' . _DB_PREFIX_ . 'shop s ON s.`id_shop` = "' . (int) $shop_id . '"
				WHERE 1 ' . $sql_filter . ' ' . ($id_lang ? 'AND cl.`id_lang` = ' . (int) $id_lang . ' AND gl.`id_lang` = ' . (int) $id_lang : '')
                . ($active ? ' AND c.`active` = 1' : '')
                . (isset($groups) && Group::isFeatureActive() ? ' AND cg.`id_group` IN (' . implode(',', $groups) . ')' : '')
                . (!$id_lang || (isset($groups) && Group::isFeatureActive()) ? ' GROUP BY c.`id_category`' : '')
                . ($sql_sort != '' ? $sql_sort : ' ORDER BY c.`level_depth` ASC')
                . ($sql_sort == '' && $use_shop_restriction ? ', cs.`position` ASC' : '')
                . ($sql_limit != '' ? $sql_limit : ''));

            $attributes = $this->getShopAttributes($this->context->language->id, $this->context->shop->id);

            foreach ($result as $k => $cat) {
                $result[$k]['gcategory'] = html_entity_decode($result[$k]['gcategory']);
                $gid_colors = [];
                $gid_materials = [];
                $gid_patterns = [];
                $gid_sizes = [];

                if ($result[$k]['level_depth'] > 0) {
                    $tree = ' > ';
                    $str = '';
                    for ($i = 0; $i < $result[$k]['level_depth'] - 1; ++$i) {
                        $str .= $tree;
                    }

                    $result[$k]['gcat_name'] = $str . ' ' . $result[$k]['gcat_name'];

                    $attribute_ids = ArrayHelper::getColumn($attributes, 'id_attribute_group');

                    $result[$k]['color'] = explode(';', $result[$k]['color']);
                    foreach ($result[$k]['color'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_colors[] = $attributes[$key]['name'];
                        }
                    }
                    $result[$k]['material'] = explode(';', $result[$k]['material']);
                    foreach ($result[$k]['material'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_materials[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['pattern'] = explode(';', $result[$k]['pattern']);
                    foreach ($result[$k]['pattern'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_patterns[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['size'] = explode(';', $result[$k]['size']);
                    foreach ($result[$k]['size'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_sizes[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['gid_colors'] = implode(' ; ', $gid_colors);
                    $result[$k]['gid_materials'] = implode(' ; ', $gid_materials);
                    $result[$k]['gid_patterns'] = implode(' ; ', $gid_patterns);
                    $result[$k]['gid_sizes'] = implode(' ; ', $gid_sizes);
                }
            }

            $categories = [];
            $buff = [];

            if (!isset($root_category)) {
                $root_category = 1;
            }

            foreach ($result as $row) {
                $current = &$buff[$row['id_category']];
                $current = $row;

                if ($row['id_category'] == $root_category) {
                    $categories[$row['id_category']] = &$current;
                } else {
                    $buff[$row['id_parent']]['children'][$row['id_category']] = &$current;
                }
            }

            Cache::store($cache_id, $categories);
        }

        return Cache::retrieve($cache_id);
    }

    /**
     * Build category tree structure
     *
     * Recursively builds complete category tree starting from root category.
     * Used to populate category list displays in admin panel.
     * Integrates with customGetNestedCategories for data retrieval.
     *
     * @param int $id_cat Category ID to build tree from (0 = start from root)
     * @param array|int $catlist Accumulator array for recursive calls (default: 0)
     * @return array Complete flattened category tree with all nested relationships
     */
    private function makeCatTree($id_cat = 0, $catlist = 0)
    {
        $id_lang = (int) $this->context->language->id;
        $id_shop = (int) Shop::getContextShopID();
        $sql_filter = '';
        $sql_sort = '';
        $sql_limit = '';

        if ($id_cat == 0 && $catlist == 0) {
            $catlist = [];
            $shop = new Shop($id_shop);
            $id_cat = Category::getRootCategory($id_lang, $shop);
            $id_cat = $id_cat->id_category;
            $sql_limit = ';';
        }

        $category = new Category((int) $id_cat, (int) $id_lang);

        if (Validate::isLoadedObject($category)) {
            $tabcat = $this->customGetNestedCategories($id_shop, $id_cat, $id_lang, true, $this->user_groups, true, $sql_filter, $sql_sort, $sql_limit);
            $catlist = array_merge($catlist, $tabcat);
        }

        foreach ($tabcat as $k => $c) {
            if (!empty($c['children'])) {
                foreach ($c['children'] as $j) {
                    $catlist = $this->makeCatTree($j['id_category'], $catlist);
                }
            }
        }

        return $catlist;
    }
    /**
     * Get Google category values with inheritance
     *
     * Retrieves Google Shopping configuration for all categories with inheritance logic.
     * If a category has no specific value set, inherits from parent category up to root.
     * Also applies module-level default values if category inheritance finds nothing.
     *
     * Populates $this->categories_values array used during XML generation.
     *
     * @param int $id_lang Language ID for configuration retrieval
     * @param int $id_shop Shop ID to scope configuration
     * @return void Populates $this->categories_values property with inherited values
     */
    public function getGCategValues($id_lang, $id_shop)
    {
        // Get categories' export values, or it's parents ones :
        // Matching Google category, condition, availability, gender, age_group...
        $sql = 'SELECT k.*, g.*, gl.*
		FROM ' . _DB_PREFIX_ . 'category k
		LEFT JOIN ' . _DB_PREFIX_ . 'gshoppingflux g ON (g.id_gcategory=k.id_category AND g.id_shop=' . $id_shop . ')
		LEFT JOIN ' . _DB_PREFIX_ . 'gshoppingflux_lang gl ON (gl.id_gcategory=k.id_category AND gl.id_lang = ' . (int) $id_lang . ' AND gl.id_shop=' . (int) $id_shop . ')
		WHERE g.id_shop = ' . (int) $id_shop;

        $ret = Db::getInstance()->executeS($sql);
        $shop = new Shop($id_shop);
        $root = Category::getRootCategory($id_lang, $shop);

        foreach ($ret as $cat) {
            $parent_id = $cat['id_category'];
            $gcategory = $cat['gcategory'];
            $condition = $cat['condition'];
            $availability = $cat['availability'];
            $gender = $cat['gender'];
            $age_group = $cat['age_group'];
            $color = $cat['color'];
            $material = $cat['material'];
            $pattern = $cat['pattern'];
            $size = $cat['size'];

            while ((empty($gcategory) || empty($condition) || empty($availability) || empty($gender) || empty($age_group) || empty($color) || empty($material) || empty($pattern) || empty($size)) && $parent_id >= $root->id_category) {
                $parentsql = $sql . ' AND k.id_category = ' . $parent_id . ';';
                $parentret = Db::getInstance()->executeS($parentsql);

                if (!count($parentret)) {
                    break;
                }

                foreach ($parentret as $parentcat) {
                    $parent_id = $parentcat['id_parent'];
                    if (empty($gcategory)) {
                        $gcategory = $parentcat['gcategory'];
                    }
                    if (empty($condition)) {
                        $condition = $parentcat['condition'];
                    }
                    if (empty($availability)) {
                        $availability = $parentcat['availability'];
                    }
                    if (empty($gender)) {
                        $gender = $parentcat['gender'];
                    }
                    if (empty($age_group)) {
                        $age_group = $parentcat['age_group'];
                    }
                    if (empty($color)) {
                        $color = $parentcat['color'];
                    }
                    if (empty($material)) {
                        $material = $parentcat['material'];
                    }
                    if (empty($pattern)) {
                        $pattern = $parentcat['pattern'];
                    }
                    if (empty($size)) {
                        $size = $parentcat['size'];
                    }
                }
            }

            if (!$color && !empty($this->module_conf['color'])) {
                $color = $this->module_conf['color'];
            }
            if (!$material && !empty($this->module_conf['material'])) {
                $material = $this->module_conf['material'];
            }
            if (!$pattern && !empty($this->module_conf['pattern'])) {
                $pattern = $this->module_conf['pattern'];
            }
            if (!$size && !empty($this->module_conf['size'])) {
                $size = $this->module_conf['size'];
            }

            $this->categories_values[$cat['id_category']]['gcategory'] = html_entity_decode($gcategory);
            $this->categories_values[$cat['id_category']]['gcat_condition'] = $condition;
            $this->categories_values[$cat['id_category']]['gcat_avail'] = $availability;
            $this->categories_values[$cat['id_category']]['gcat_gender'] = $gender;
            $this->categories_values[$cat['id_category']]['gcat_age_group'] = $age_group;
            $this->categories_values[$cat['id_category']]['gcat_color'] = explode(';', $color);
            $this->categories_values[$cat['id_category']]['gcat_material'] = explode(';', $material);
            $this->categories_values[$cat['id_category']]['gcat_pattern'] = explode(';', $pattern);
            $this->categories_values[$cat['id_category']]['gcat_size'] = explode(';', $size);
        }
    }
    /**
     * Get output filename for export file
     *
     * Generates standardized filename for XML feed export files.
     * Filename includes optional prefix, file type, shop ID, language, and currency codes.
     * Format: [prefix_]googleshopping[-variant]-s{shop}[-{lang}][-{curr}].xml
     *
     * @param string $lang Language ISO code (2-char code like 'en', 'fr')
     * @param string $curr Currency ISO code (3-char code like 'USD', 'EUR')
     * @param int $shop Shop ID appended to filename
     * @param bool $local_inventory Include 'local-inventory' in filename (default: false)
     * @param bool $reviews Include 'reviews' in filename (default: false)
     * @return string Generated filename with extension
     */
    private function _getOutputFileName($lang, $curr, $shop, $local_inventory = false, $reviews = false)
    {
        $file_prefix = Configuration::get('GS_FILE_PREFIX', '', $this->context->shop->id_shop_group, $this->context->shop->id);
        if ($file_prefix) {
            return $file_prefix . '_googleshopping' . ($local_inventory ? '-local-inventory' : ($reviews ? '-reviews' : '')) . '-s' . $shop . (!empty($lang) ? '-' . $lang : '') . (!empty($curr) ? '-' . $curr : '') . '.xml';
        }

        return 'googleshopping' . ($local_inventory ? '-local-inventory' : ($reviews ? '-reviews' : '')) . '-s' . $shop . (!empty($lang) ? '-' . $lang : '') . (!empty($curr) ? '-' . $curr : '') . '.xml';
    }
    /**
     * Get shop meta description
     *
     * Retrieves shop meta description from Prestashop meta configuration.
     * Used as shop description in XML feed header when generating product feeds.
     *
     * @param int $id_lang Language ID for description retrieval
     * @param int $id_shop Shop ID to scope description
     * @return string Shop meta description for homepage
     */
    public function getShopDescription($id_lang, $id_shop)
    {
        $ret = Db::getInstance()->executeS('
			SELECT ml.description
			FROM ' . _DB_PREFIX_ . 'meta_lang ml
			LEFT JOIN ' . _DB_PREFIX_ . 'meta m ON (m.id_meta = ml.id_meta)
			WHERE m.page="index"
				AND ml.id_shop = ' . (int) $id_shop . '
				AND ml.id_lang = ' . (int) $id_lang);

        return $ret[0]['description'];
    }

    /**
     * Generate file lists for all shops
     *
     * Triggers XML feed generation for all shops configured in Prestashop.
     * Returns array of generation results for each shop.
     *
     * @return array Array of generation results indexed by shop ID
     */
    public function generateAllShopsFileList()
    {
        // Get all shops
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $i => $shop) {
            $ret[$i] = $this->generateShopFileList($shop);
        }

        return $ret;
    }

    /**
     * Generate file list for specific shop
     *
     * Generates XML feeds for all language-currency pairs in a specific shop.
     * Optionally generates local inventory or reviews feeds instead of standard feeds.
     *
     * @param int $id_shop Shop ID to generate files for
     * @param bool $local_inventory Generate local inventory feed instead (default: false)
     * @param bool $reviews Generate reviews feed instead (default: false)
     * @return array Array of generation results for each language-currency pair
     */
    public function generateShopFileList($id_shop, $local_inventory = false, $reviews = false)
    {
        if ($reviews) {
            return $this->generateReviewsFile($id_shop);
        }
        // Get all shop languages
        $languages = GLangAndCurrency::getAllLangCurrencies(1);
        foreach ($languages as $i => $lang) {
            $currencies = explode(';', $lang['id_currency']);
            foreach ($currencies as $id_curr) {
                $ret[] = $this->generateFile($lang, $id_curr, $id_shop, $local_inventory);
            }
        }

        return $ret;
    }

    /**
     * Generate file list for specific language
     *
     * Generates XML feed for all currencies configured for a specific language in a shop.
     * Useful for regenerating feeds for only one language without regenerating all.
     *
     * @param int $id_lang Language ID to generate files for
     * @param int $id_shop Shop ID for feed scope
     * @param bool $local_inventory Generate local inventory feed instead (default: false)
     * @return array Array of generation results for each currency
     */
    public function generateLangFileList($id_lang, $id_shop, $local_inventory = false)
    {
        // Get all shop languages
        $languages = GLangAndCurrency::getLangCurrencies($id_lang, $id_shop);
        foreach ($languages as $i => $lang) {
            $currencies = explode(';', $lang['id_currency']);
            foreach ($currencies as $id_curr) {
                $ret[] = $this->generateFile($lang, $id_curr, $id_shop, $local_inventory);
            }
        }

        return $ret;
    }

    /**
     * Generate single XML product feed file
     *
     * Main method to generate a single XML feed file for a specific language-currency-shop combination.
     * Handles:
     * - XML structure and header generation
     * - Product filtering and sorting
     * - Attribute combination expansion (if enabled)
     * - Local inventory data (if local_inventory flag set)
     * - UTF-8 BOM addition
     * - File permission setting
     *
     * Iterates through all products and generates item XML using getItemXML() or getLocalInventoryItemXML().
     *
     * @param array $lang Language configuration array with id_lang and iso_code
     * @param int $id_curr Currency ID for pricing conversion
     * @param int $id_shop Shop ID for feed scope
     * @param bool $local_inventory Generate local inventory format instead (default: false)
     * @return array Generation statistics:
     *              - nb_products: Total products exported
     *              - nb_combinations: Total attribute combinations exported
     *              - nb_prod_w_attr: Products with attributes
     *              - non_exported_products: Skipped products (unavailable, etc)
     */
    private function generateFile($lang, $id_curr, $id_shop, $local_inventory = false)
    {
        $id_lang = (int) $lang['id_lang'];
        $curr = new Currency($id_curr);
        $this->shop = new Shop($id_shop);
        $root = Category::getRootCategory($id_lang, $this->shop);
        $this->id_root = $root->id_category;

        // Get module configuration for this shop
        $this->module_conf = array_merge($this->getConfigFieldsValues($id_shop), $this->getConfigLocalInventoryFieldsValues($id_shop));

        // Init categories special attributes :
        // Google's matching category, gender, age_group, color_group, material, pattern, size...
        $this->getGCategValues($id_lang, $id_shop);

        // Init file_path value
        if ($this->module_conf['gen_file_in_root']) {
            $generate_file_path = dirname(__FILE__) . '/../../' . $this->_getOutputFileName($lang['iso_code'], $curr->iso_code, $id_shop, $local_inventory);
        } else {
            $generate_file_path = dirname(__FILE__) . '/export/' . $this->_getOutputFileName($lang['iso_code'], $curr->iso_code, $id_shop, $local_inventory);
        }

        if ($this->shop->name == 'Prestashop') {
            $this->shop->name = Configuration::get('PS_SHOP_NAME');
        }

        // Google Shopping XML
        $xml = '<?xml version="1.0" encoding="' . self::CHARSET . '" ?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n\n";
        $xml .= '<channel>' . "\n";
        // Shop name
        $xml .= '<title><![CDATA[' . $this->shop->name . ']]></title>' . "\n";
        // Shop description
        $xml .= '<description><![CDATA[' . $this->getShopDescription($id_lang, $id_shop) . ']]></description>' . "\n";
        $xml .= '<link href="' . htmlspecialchars($this->uri, self::REPLACE_FLAGS, self::CHARSET, false) . '" rel="alternate" type="text/html"/>' . "\n";
        $xml .= '<image>' . "\n";
        $xml .= '<url>' . htmlspecialchars($this->context->link->getMediaLink(_PS_IMG_ . Configuration::get('PS_LOGO')), self::REPLACE_FLAGS, self::CHARSET, false) . '</url>' . "\n";
        $xml .= '<link>' . htmlspecialchars($this->uri, self::REPLACE_FLAGS, self::CHARSET, false) . '</link>' . "\n";
        $xml .= '</image>' . "\n";
        $xml .= '<modified>' . date('Y-m-d') . ' T01:01:01Z</modified>' . "\n";
        $xml .= '<author>' . "\n" . '<name>' . htmlspecialchars(Configuration::get('PS_SHOP_NAME'), self::REPLACE_FLAGS, self::CHARSET, false) . '</name>' . "\n" . '</author>' . "\n\n";

        $googleshoppingfile = fopen($generate_file_path, 'w');

        // Add UTF-8 byte order mark
        fwrite($googleshoppingfile, pack('CCC', 0xEF, 0xBB, 0xBF));

        // File header
        fwrite($googleshoppingfile, $xml);

        $sql = 'SELECT DISTINCT p.*, pl.*, ps.id_category_default as category_default, gc.export, glc.tax_included, gl.* '
            . 'FROM ' . _DB_PREFIX_ . 'product p '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON pl.id_product = p.id_product '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'category c ON c.id_category = p.id_category_default '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'gshoppingflux gc ON gc.id_gcategory = ps.id_category_default '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'gshoppingflux_lc glc ON glc.`id_glang` = ' . $id_lang . ' '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'gshoppingflux_lang gl ON gl.id_gcategory = ps.id_category_default '
            . 'WHERE `p`.`price` >= 0 AND `c`.`active` = 1 AND `gc`.`export` = 1 '
            . 'AND `pl`.`id_lang` = ' . $id_lang . ' AND `gl`.`id_lang` = ' . $id_lang;

        // Multishops filter
        if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(true, null, true)) > 1) {
            $sql .= ' AND `ps`.`active` = 1 AND `gc`.`id_shop` = ' . $id_shop . ' AND `pl`.`id_shop` = ' . $id_shop . ' AND `ps`.`id_shop` = ' . $id_shop . ' AND `gl`.`id_shop` = ' . $id_shop;
        } else {
            $sql .= ' AND `p`.`active` = 1';
        }

        // Check EAN13/UPC
        if ($this->module_conf['no_gtin'] != 1) {
            $sql .= ' AND ( (`p`.`ean13` != "" AND `p`.`ean13` != 0) OR (`p`.`upc` != "" AND `p`.`upc` != 0) )';
        }

        // Check BRAND
        if ($this->module_conf['no_brand'] != 1) {
            $sql .= ' AND `p`.`id_manufacturer` != "" AND `p`.`id_manufacturer` != 0';
        }

        $sql .= ' GROUP BY `p`.`id_product`;';
        $products = Db::getInstance()->executeS($sql);
        $this->nb_total_products = 0;
        $this->nb_not_exported_products = 0;
        $this->nb_combinations = 0;
        $this->nb_prd_w_attr = [];

        foreach ($products as $product) {
            $p = new Product($product['id_product'], true, $id_lang, $id_shop, $this->context);

            $attributesResume = null;
            if ($this->module_conf['export_attributes'] == 1) {
                $attributesResume = $p->getAttributesResume($id_lang);
            }
            $product['gid'] = $product['id_product'];
            $product['color'] = '';
            $product['material'] = '';
            $product['pattern'] = '';
            $product['size'] = '';
            if ($attributesResume && $this->module_conf['export_attributes'] == 1) {
                $original_product = $product;
                $categories_value = $this->categories_values[$product['id_gcategory']];
                $combinum = 0;

                foreach ($attributesResume as $productCombination) {
                    $product = $original_product;
                    $attributes = $p->getAttributeCombinationsById($productCombination['id_product_attribute'], $id_lang);
                    foreach ($attributes as $a) {
                        if (in_array($a['id_attribute_group'], $categories_value['gcat_color'])) {
                            $product['color'] = $a['attribute_name'];
                        }
                        if (in_array($a['id_attribute_group'], $categories_value['gcat_material'])) {
                            $product['material'] = $a['attribute_name'];
                        }
                        if (in_array($a['id_attribute_group'], $categories_value['gcat_pattern'])) {
                            $product['pattern'] = $a['attribute_name'];
                        }
                        if (in_array($a['id_attribute_group'], $categories_value['gcat_size'])) {
                            $product['size'] = $a['attribute_name'];
                        }
                    }
                    ++$combinum;
                    $product['reference'] = (!empty($a['reference']) ? $a['reference'] : $product['reference']);
                    $product['ean13'] = (!empty($a['ean13']) ? $a['ean13'] : $product['ean13']);
                    $product['upc'] = (!empty($a['upc']) ? $a['upc'] : $product['upc']);
                    $product['supplier_reference'] = (!empty($a['supplier_reference']) ? $a['supplier_reference'] : $product['supplier_reference']);
                    $product['weight'] += $a['weight'];
                    $product['item_group_id'] = $product['id_product'];
                    $product['gid'] = $product['id_product'] . '-' . $productCombination['id_product_attribute'];
                    if ($local_inventory) {
                        $xml_googleshopping = $this->getLocalInventoryItemXML($product, $lang, $id_curr, $id_shop, $productCombination['id_product_attribute']);
                    } else {
                        $xml_googleshopping = $this->getItemXML($product, $lang, $id_curr, $id_shop, $productCombination['id_product_attribute']);
                    }
                    fwrite($googleshoppingfile, $xml_googleshopping);
                }
                unset($original_product);
            } else {
                if ($local_inventory) {
                    $xml_googleshopping = $this->getLocalInventoryItemXML($product, $lang, $id_curr, $id_shop);
                } else {
                    $xml_googleshopping = $this->getItemXML($product, $lang, $id_curr, $id_shop);
                }
                fwrite($googleshoppingfile, $xml_googleshopping);
            }
        }

        $xml = '</channel>' . "\n" . '</rss>';
        fwrite($googleshoppingfile, $xml);
        fclose($googleshoppingfile);

        @chmod($generate_file_path, 0777);

        return [
            'nb_products' => $this->nb_total_products,
            'nb_combinations' => $this->nb_combinations,
            'nb_prod_w_attr' => count($this->nb_prd_w_attr),
            'non_exported_products' => $this->nb_not_exported_products,
        ];
    }

    /**
     * Generate reviews XML file
     *
     * Generates XML feed of approved customer product reviews in Google Shopping Reviews format.
     * Includes reviewer information, rating, review date, product identifiers, and review URL.
     * Only exports reviews that are:
     * - Not deleted
     * - Approved/validated by shop
     *
     * @param int $id_shop Shop ID for reviews scope
     * @return array Generation statistics:
     *              - nb_reviews: Total reviews exported
     */
    private function generateReviewsFile($id_shop)
    {
        $this->shop = new Shop($id_shop);
        $this->module_conf = $this->getConfigFieldsValues($id_shop);

        // Init file_path value
        if ($this->module_conf['gen_file_in_root']) {
            $generate_file_path = dirname(__FILE__) . '/../../' . $this->_getOutputFileName(0, 0, $id_shop, false, true);
        } else {
            $generate_file_path = dirname(__FILE__) . '/export/' . $this->_getOutputFileName(0, 0, $id_shop, false, true);
        }

        if ($this->shop->name == 'Prestashop') {
            $this->shop->name = Configuration::get('PS_SHOP_NAME');
        }

        // Google Shopping XML
        $xml = '<?xml version="1.0" encoding="' . self::CHARSET . '" ?>' . "\n";
        $xml .= '<feed xmlns:vc="http://www.w3.org/2007/XMLSchema-versioning" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.google.com/shopping/reviews/schema/product/2.3/product_reviews.xsd">' . "\n";
        $xml .= '<version>2.3</version>' . "\n";

        // Shop name
        $xml .= '<publisher>' . "\n";
        $xml .= '<name>' . htmlspecialchars(Configuration::get('PS_SHOP_NAME'), self::REPLACE_FLAGS, self::CHARSET, false) . '</name>' . "\n";
        $xml .= '</publisher>' . "\n";

        $googleshoppingfile = fopen($generate_file_path, 'w');

        // Add UTF-8 byte order mark
        fwrite($googleshoppingfile, pack('CCC', 0xEF, 0xBB, 0xBF));

        // File header
        fwrite($googleshoppingfile, $xml);

        $xml = '<reviews>' . "\n";

        $sql = 'SELECT pc.`id_product_comment`, pc.`id_product`, c.id_customer AS customer_id,
                IF(c.id_customer, CONCAT(c.`firstname`, \' \',  c.`lastname`), pc.customer_name) customer_name,
                IF(c.id_customer, 0, 1) anonymous, pc.`title`, pc.`content`, pc.`grade`, pc.`date_add`
            FROM ' . _DB_PREFIX_ . 'product_comment pc
            LEFT JOIN ' . _DB_PREFIX_ . 'customer c ON pc.id_customer = c.id_customer
            WHERE pc.deleted = 0 AND pc.validate = 1';

        $comments = Db::getInstance()->executeS($sql);

        foreach ($comments as $comment) {
            $p = new Product($comment['id_product'], false, null, $id_shop, $this->context);

            $xml .= '<review>' . "\n";
            $xml .= '<review_id>' . $comment['id_product_comment'] . '</review_id>' . "\n";
            $xml .= '<reviewer>' . "\n";
            $xml .= '<name is_anonymous="' . $comment['anonymous'] . '">' . $comment['customer_name'] . '</name>' . "\n";
            $xml .= '</reviewer>' . "\n";
            $date_add = new DateTime($comment['date_add']);
            $xml .= '<review_timestamp>' . $date_add->format(DATE_ATOM) . '</review_timestamp>' . "\n";
            $xml .= '<title>' . $comment['title'] . '</title>' . "\n";
            $xml .= '<content>' . $comment['content'] . '</content>' . "\n";
            $product_link = $this->context->link->getProductLink($comment['id_product'], $p->link_rewrite);
            $xml .= '<review_url type="group">' . $product_link . '</review_url>' . "\n";
            $xml .= '<ratings>' . "\n";
            $xml .= '<overall min="1" max="5">' . $comment['grade'] . '</overall>' . "\n";
            $xml .= '</ratings>' . "\n";
            $xml .= '<products>' . "\n";
            $xml .= '<product>' . "\n";
            $xml .= '<product_ids>' . "\n";
            $xml .= '<gtins>' . "\n";
            $xml .= '<gtin>' . $p->ean13 . '</gtin>' . "\n";
            $xml .= '</gtins>' . "\n";
            $xml .= '<skus>' . "\n";
            $xml .= '<sku>' . $p->reference . '</sku>' . "\n";
            $xml .= '</skus>' . "\n";
            $xml .= '</product_ids>' . "\n";
            $xml .= '<product_url>' . $product_link . '</product_url>' . "\n";
            $xml .= '</product>' . "\n";
            $xml .= '</products>' . "\n";

            $xml .= '</review>' . "\n";
        }

        $xml .= '</reviews>' . "\n";
        $xml .= '</feed>';
        fwrite($googleshoppingfile, $xml);
        fclose($googleshoppingfile);

        @chmod($generate_file_path, 0777);

        return [
            'nb_reviews' => count($comments),
        ];
    }

    /**
     * Generate local inventory item XML
     *
     * Generates single item XML element in local inventory format.
     * Local inventory feeds are used by Google for store-specific inventory data.
     * Includes:
     * - Store code identifier
     * - Product ID/SKU
     * - Quantity in stock
     * - Availability status
     * - Price with currency
     *
     * @param array $product Product data array
     * @param array $lang Language configuration with id_lang
     * @param int $id_curr Currency ID for pricing
     * @param int $id_shop Shop ID for context
     * @param int|bool $combination Product attribute combination ID (false if simple product)
     * @return string Generated XML item element or empty string if skipped
     */
    private function getLocalInventoryItemXML($product, $lang, $id_curr, $id_shop, $combination = false)
    {
        $xml_googleshopping = '';
        $id_lang = (int) $lang['id_lang'];
        $p = new Product($product['id_product'], true, $id_lang, $id_shop, $this->context);
        if (!$combination) {
            $product['quantity'] = StockAvailable::getQuantityAvailableByProduct($product['id_product'], 0, $id_shop);
        } else {
            $product['quantity'] = StockAvailable::getQuantityAvailableByProduct($product['id_product'], $combination, $id_shop);
        }
        $xml_googleshopping .= '<item>' . "\n";
        $xml_googleshopping .= '<g:store_code>' . $this->module_conf['store_code'] . '</g:store_code>' . "\n";
        $xml_googleshopping .= '<g:id>' . $product['gid'] . '</g:id>' . "\n";
        // Product quantity & availability
        if (empty($this->categories_values[$product['category_default']]['gcat_avail'])) {
            if ($this->module_conf['quantity'] == 1 && $this->ps_stock_management) {
                $xml_googleshopping .= '<g:quantity>' . $product['quantity'] . '</g:quantity>' . "\n";
            }
            if ($this->ps_stock_management) {
                if ($product['quantity'] > 0 && $product['available_for_order']) {
                    $xml_googleshopping .= '<g:availability>in stock</g:availability>' . "\n";
                } elseif ($p->isAvailableWhenOutOfStock((int) $p->out_of_stock) && $product['available_for_order']) {
                    $xml_googleshopping .= '<g:availability>preorder</g:availability>' . "\n";
                } else {
                    $xml_googleshopping .= '<g:availability>out of stock</g:availability>' . "\n";
                }
            } else {
                if ($product['available_for_order']) {
                    $xml_googleshopping .= '<g:availability>in stock</g:availability>' . "\n";
                } else {
                    $xml_googleshopping .= '<g:availability>out of stock</g:availability>' . "\n";
                }
            }
        } else {
            if ($this->module_conf['quantity'] == 1 && $product['quantity'] > 0 && $this->ps_stock_management) {
                $xml_googleshopping .= '<g:quantity>' . $product['quantity'] . '</g:quantity>' . "\n";
            }
            $xml_googleshopping .= '<g:availability>' . $this->categories_values[$product['category_default']]['gcat_avail'] . '</g:availability>' . "\n";
        }

        // Price(s)
        $currency = new Currency((int) $id_curr);
        $use_tax = ($product['tax_included'] ? true : false);
        $no_tax = (!$use_tax ? true : false);
        $product['price'] = (float) $p->getPriceStatic($product['id_product'], $use_tax, $combination) * $currency->conversion_rate;
        $product['price_without_reduct'] = (float) $p->getPriceWithoutReduct($no_tax, $combination) * $currency->conversion_rate;
        $product['price'] = Tools::ps_round($product['price'], $this->getPriceDisplayPrecision());
        $product['price_without_reduct'] = Tools::ps_round($product['price_without_reduct'], $this->getPriceDisplayPrecision());
        if ((float) $product['price'] < (float) $product['price_without_reduct']) {
            $xml_googleshopping .= '<g:price>' . $product['price_without_reduct'] . ' ' . $currency->iso_code . '</g:price>' . "\n";
            $xml_googleshopping .= '<g:sale_price>' . $product['price'] . ' ' . $currency->iso_code . '</g:sale_price>' . "\n";
        } else {
            $xml_googleshopping .= '<g:price>' . $product['price'] . ' ' . $currency->iso_code . '</g:price>' . "\n";
        }

        $xml_googleshopping .= '</item>' . "\n\n";

        if ($combination) {
            ++$this->nb_combinations;
            $this->nb_prd_w_attr[$product['id_product']] = 1;
        }
        ++$this->nb_total_products;

        return $xml_googleshopping;
    }

    /**
     * Generate product item XML
     *
     * Generates single complete product item XML element for Google Shopping feed.
     * Handles all Google Shopping product attributes including:
     * - Product identification (ID, GTIN, MPN, brand)
     * - Description and images
     * - Pricing (regular and sale price)
     * - Availability and quantity
     * - Shipping information and costs
     * - Product attributes (gender, age_group, color, material, pattern, size)
     * - Condition and category mapping
     * - Product variants and combinations
     *
     * Applies product filtering based on configuration (minimum price, stock, availability).
     * Handles attribute combinations if export_attributes is enabled.
     * Supports multi-language description sources (short, long, short+long, meta).
     * Calculates shipping costs based on carrier configuration.
     *
     * @param array $product Product data from database query
     * @param array $lang Language configuration with id_lang and iso_code
     * @param int $id_curr Currency ID for pricing conversion
     * @param int $id_shop Shop ID for product scope
     * @param int|bool $combination Product attribute combination ID (false if simple product)
     * @return string Generated XML item element or empty string if product is filtered out
     */
    private function getItemXML($product, $lang, $id_curr, $id_shop, $combination = false)
    {
        $xml_googleshopping = '';
        $id_lang = (int) $lang['id_lang'];
        $title_limit = 70;
        $description_limit = 4990;
        $languages = Language::getLanguages();
        $tailleTabLang = count($languages);
        $this->context->language->id = $id_lang;
        $this->context->shop->id = $id_shop;
        $p = new Product($product['id_product'], true, $id_lang, $id_shop, $this->context);

        // Get module configuration for this shop
        if (!$combination) {
            $product['quantity'] = StockAvailable::getQuantityAvailableByProduct($product['id_product'], 0, $id_shop);
        } else {
            $product['quantity'] = StockAvailable::getQuantityAvailableByProduct($product['id_product'], $combination, $id_shop);
        }

        // Exclude non-available products
        if ($this->module_conf['export_nap'] === 0 && ($product['quantity'] < 1 || $product['available_for_order'] == 0)) {
            ++$this->nb_not_exported_products;

            return;
        }

        // Check minimum product price
        $price = Product::getPriceStatic((int) $product['id_product'], true);
        if ((float) $this->module_conf['export_min_price'] > 0 && (float) $this->module_conf['export_min_price'] > (float) $price) {
            return;
        }

        $cat_link_rew = Category::getLinkRewrite($product['id_gcategory'], $id_lang);
        $product_link = $this->context->link->getProductLink((int) $product['id_product'], $product['link_rewrite'], $cat_link_rew, $product['ean13'], (int) $product['id_lang'], $id_shop, $combination, null, null, true);

        // Product name
        $title_crop = $product['name'];

        //  Product color attribute, if any
        if (!empty($product['color'])) {
            $title_crop .= ' ' . $product['color'];
        }
        if (!empty($product['material'])) {
            $title_crop .= ' ' . $product['material'];
        }
        if (!empty($product['pattern'])) {
            $title_crop .= ' ' . $product['pattern'];
        }
        if (!empty($product['size'])) {
            $title_crop .= ' ' . $product['size'];
        }

        if (Tools::strlen($product['name']) > $title_limit) {
            $title_crop = Tools::substr($title_crop, 0, $title_limit - 1);
            $title_crop = Tools::substr($title_crop, 0, strrpos($title_crop, ' '));
        }

        // Description type
        if ($this->module_conf['description'] == 'long') {
            $description_crop = $product['description'];
        } elseif ($this->module_conf['description'] == 'short') {
            $description_crop = $product['description_short'];
        } elseif ($this->module_conf['description'] == 'short+long') {
            $description_crop = '';
            if (!empty($product['description_short'])) {
                $description_crop = $product['description_short'];
            }
            if (!empty($product['description'])) {
                $description_crop .= (!empty($product['description_short']) ? ' ' : '') . $product['description'];
            }
        } elseif ($this->module_conf['description'] == 'meta') {
            $description_crop = $product['meta_description'];
        }

        $description_crop = $this->rip_tags($description_crop);

        if (Tools::strlen($description_crop) > $description_limit) {
            $description_crop = Tools::substr($description_crop, 0, $description_limit - 1);
            $description_crop = Tools::substr($description_crop, 0, strrpos($description_crop, ' ')) . ' ...';
        }

        $xml_googleshopping .= '<item>' . "\n";
        $xml_googleshopping .= '<g:id>' . $product['gid'] . '</g:id>' . "\n";
        $xml_googleshopping .= '<g:title><![CDATA[' . $title_crop . ']]></g:title>' . "\n";
        $xml_googleshopping .= '<g:description><![CDATA[' . $description_crop . ']]></g:description>' . "\n";
        $xml_googleshopping .= '<g:link><![CDATA[' . $this->linkencode($product_link) . ']]></g:link>' . "\n";

        // Image links
        $images = Image::getImages($lang['id_lang'], $product['id_product'], $combination);
        if (count($images) == 0 && $combination != false) {
            $images = Image::getImages($lang['id_lang'], $product['id_product']);
        }
        $indexTabLang = 0;
        if ($tailleTabLang > 1) {
            while (count($images) < 1 && $indexTabLang < $tailleTabLang) {
                if ($languages[$indexTabLang]['id_lang'] != $lang['id_lang']) {
                    $images = Image::getImages($languages[$indexTabLang]['id_lang'], $product['id_product']);
                }

                ++$indexTabLang;
            }
        }
        $nbimages = 0;
        $image_type = $this->module_conf['img_type'];

        if ($image_type == '') {
            $image_type = 'large_default';
        }
        $cover_key = array_search('1', ArrayHelper::getColumn($images, 'cover'));
        foreach ($images as $im_key => $im) {
            $image = $this->context->link->getImageLink($product['link_rewrite'], $product['id_product'] . '-' . $im['id_image'], $image_type);
            $image = preg_replace('*http:///*', $this->uri, $image);
            if ($im['cover'] == 1 || ($cover_key === false && $im_key == 0)) {
                $xml_googleshopping .= '<g:image_link><![CDATA[' . $image . ']]></g:image_link>' . "\n";
            } else {
                $xml_googleshopping .= '<g:additional_image_link><![CDATA[' . $image . ']]></g:additional_image_link>' . "\n";
            }
            // max images by product
            if (++$nbimages == 10) {
                break;
            }
        }

        // Product condition, or category's condition attribute, or its parent one...
        // Product condition = new, used, refurbished
        if (empty($product['condition'])) {
            $product['condition'] = $this->categories_values[$product['id_gcategory']]['gcat_condition'];
        }

        if (!empty($product['condition'])) {
            $xml_googleshopping .= '<g:condition><![CDATA[' . $product['condition'] . ']]></g:condition>' . "\n";
        }

        // Shop category
        $breadcrumb = GCategories::getPath($product['id_gcategory'], '', $id_lang, $id_shop, $this->id_root);
        $product_type = '';

        if (!empty($this->module_conf['product_type[]'][$id_lang])) {
            $product_type = $this->module_conf['product_type[]'][$id_lang];

            if (!empty($breadcrumb)) {
                $product_type .= ' > ';
            }
        }

        $product_type .= $breadcrumb;
        $xml_googleshopping .= '<g:product_type><![CDATA[' . $product_type . ']]></g:product_type>' . "\n";

        // Matching Google category, or parent categories' one
        $product['gcategory'] = $this->categories_values[$product['category_default']]['gcategory'];
        $xml_googleshopping .= '<g:google_product_category><![CDATA[' . $product['gcategory'] . ']]></g:google_product_category>' . "\n";

        // Product quantity & availability
        if (empty($this->categories_values[$product['category_default']]['gcat_avail'])) {
            if ($this->module_conf['quantity'] == 1 && $this->ps_stock_management) {
                $xml_googleshopping .= '<g:quantity>' . $product['quantity'] . '</g:quantity>' . "\n";
            }
            if ($this->ps_stock_management) {
                if ($product['quantity'] > 0 && $product['available_for_order']) {
                    $xml_googleshopping .= '<g:availability>in stock</g:availability>' . "\n";
                } elseif ($p->isAvailableWhenOutOfStock((int) $p->out_of_stock) && $product['available_for_order']) {
                    $xml_googleshopping .= '<g:availability>preorder</g:availability>' . "\n";
                } else {
                    $xml_googleshopping .= '<g:availability>out of stock</g:availability>' . "\n";
                }
            } else {
                if ($product['available_for_order']) {
                    $xml_googleshopping .= '<g:availability>in stock</g:availability>' . "\n";
                } else {
                    $xml_googleshopping .= '<g:availability>out of stock</g:availability>' . "\n";
                }
            }
        } else {
            if ($this->module_conf['quantity'] == 1 && $product['quantity'] > 0 && $this->ps_stock_management) {
                $xml_googleshopping .= '<g:quantity>' . $product['quantity'] . '</g:quantity>' . "\n";
            }
            $xml_googleshopping .= '<g:availability>' . $this->categories_values[$product['category_default']]['gcat_avail'] . '</g:availability>' . "\n";
        }

        // Price(s)
        $currency = new Currency((int) $id_curr);
        $use_tax = ($product['tax_included'] ? true : false);
        $no_tax = (!$use_tax ? true : false);
        $product['price'] = (float) $p->getPriceStatic($product['id_product'], $use_tax, $combination) * $currency->conversion_rate;
        $product['price_without_reduct'] = (float) $p->getPriceWithoutReduct($no_tax, $combination) * $currency->conversion_rate;
        $product['price'] = Tools::ps_round($product['price'], $this->getPriceDisplayPrecision());
        $product['price_without_reduct'] = Tools::ps_round($product['price_without_reduct'], $this->getPriceDisplayPrecision());
        if ((float) $product['price'] < (float) $product['price_without_reduct']) {
            $xml_googleshopping .= '<g:price>' . $product['price_without_reduct'] . ' ' . $currency->iso_code . '</g:price>' . "\n";
            $xml_googleshopping .= '<g:sale_price>' . $product['price'] . ' ' . $currency->iso_code . '</g:sale_price>' . "\n";
        } else {
            $xml_googleshopping .= '<g:price>' . $product['price'] . ' ' . $currency->iso_code . '</g:price>' . "\n";
        }

        $identifier_exists = 0;
        // GTIN (EAN, UPC, JAN, ISBN)
        if (!empty($product['ean13'])) {
            $xml_googleshopping .= '<g:gtin>' . $product['ean13'] . '</g:gtin>' . "\n";
            ++$identifier_exists;
        } elseif (!empty($product['upc'])) {
            $xml_googleshopping .= '<g:gtin>' . $product['upc'] . '</g:gtin>' . "\n";
            ++$identifier_exists;
        }

        // Brand
        if ($this->module_conf['no_brand'] != 0 && !empty($product['id_manufacturer'])) {
            $xml_googleshopping .= '<g:brand><![CDATA[' . htmlspecialchars(Manufacturer::getNameById((int) $product['id_manufacturer']), self::REPLACE_FLAGS, self::CHARSET, false) . ']]></g:brand>' . "\n";
            ++$identifier_exists;
        }

        // MPN
        if (empty($product['supplier_reference'])) {
            $product['supplier_reference'] = ProductSupplier::getProductSupplierReference($product['id_product'], 0, $product['id_supplier']);
        }

        if ($this->module_conf['mpn_type'] == 'reference' && !empty($product['reference'])) {
            $xml_googleshopping .= '<g:mpn><![CDATA[' . $product['reference'] . ']]></g:mpn>' . "\n";
            ++$identifier_exists;
        } elseif ($this->module_conf['mpn_type'] == 'supplier_reference' && !empty($product['supplier_reference'])) {
            $xml_googleshopping .= '<g:mpn><![CDATA[' . $product['supplier_reference'] . ']]></g:mpn>' . "\n";
            ++$identifier_exists;
        }

        // Tag "identifier_exists"
        if ($this->module_conf['id_exists_tag'] && $identifier_exists < 2) {
            $xml_googleshopping .= '<g:identifier_exists>FALSE</g:identifier_exists>' . "\n";
        }

        // Product gender and age_group attributes association
        $product_features = $this->getProductFeatures($product['id_product'], $id_lang, $id_shop);
        $product['gender'] = $this->categories_values[$product['category_default']]['gcat_gender'];
        $product['age_group'] = $this->categories_values[$product['category_default']]['gcat_age_group'];
        foreach ($product_features as $feature) {
            switch ($feature['id_feature']) {
                case $this->module_conf['gender']:
                    $product['gender'] = $feature['value'];
                    continue 2;
                case $this->module_conf['age_group']:
                    $product['age_group'] = $feature['value'];
                    continue 2;
            }

            if (!$product['color']) {
                foreach ($this->module_conf['color[]'] as $id => $v) {
                    if ($v == $feature['id_feature']) {
                        $product['color'] = $feature['value'];
                    }
                }
            }
            if (!$product['material']) {
                foreach ($this->module_conf['material[]'] as $id => $v) {
                    if ($v == $feature['id_feature']) {
                        $product['material'] = $feature['value'];
                    }
                }
            }
            if (!$product['pattern']) {
                foreach ($this->module_conf['pattern[]'] as $id => $v) {
                    if ($v == $feature['id_feature']) {
                        $product['pattern'] = $feature['value'];
                    }
                }
            }
            if (!$product['size']) {
                foreach ($this->module_conf['size[]'] as $id => $v) {
                    if ($v == $feature['id_feature']) {
                        $product['size'] = $feature['value'];
                    }
                }
            }
        }

        //  Product gender attribute, or category gender attribute, or parent's one
        if (!empty($product['gender'])) {
            $xml_googleshopping .= '<g:gender><![CDATA[' . $product['gender'] . ']]></g:gender>' . "\n";
        }

        // Product age_group attribute, or category age_group attribute, or parent's one
        if (!empty($product['age_group'])) {
            $xml_googleshopping .= '<g:age_group><![CDATA[' . $product['age_group'] . ']]></g:age_group>' . "\n";
        }

        // Product attributes combination groups
        if ($combination && !empty($product['item_group_id'])) {
            $xml_googleshopping .= '<g:item_group_id>' . $product['item_group_id'] . '</g:item_group_id>' . "\n";
        }

        // Product color attribute, or category color attribute, or parent's one
        if (!empty($product['color'])) {
            $xml_googleshopping .= '<g:color><![CDATA[' . $product['color'] . ']]></g:color>' . "\n";
        }

        // Product material attribute, or category material attribute, or parent's one
        if (!empty($product['material'])) {
            $xml_googleshopping .= '<g:material><![CDATA[' . $product['material'] . ']]></g:material>' . "\n";
        }

        // Product pattern attribute, or category pattern attribute, or parent's one
        if (!empty($product['pattern'])) {
            $xml_googleshopping .= '<g:pattern><![CDATA[' . $product['pattern'] . ']]></g:pattern>' . "\n";
        }

        // Product size attribute, or category size attribute, or parent's one
        if (!empty($product['size'])) {
            $xml_googleshopping .= '<g:size><![CDATA[' . $product['size'] . ']]></g:size>' . "\n";
        }

        // Featured products
        if ($this->module_conf['featured_products'] == 1 && $product['on_sale'] != '0') {
            $xml_googleshopping .= '<g:featured_product>true</g:featured_product>' . "\n";
        }

        // Shipping
        if ($product['is_virtual']) {
            $xml_googleshopping .= '<g:shipping>' . "\n";
            $xml_googleshopping .= "\t" . '<g:country>' . $this->module_conf['shipping_country'] . '</g:country>' . "\n";
            $xml_googleshopping .= "\t" . '<g:service>Standard</g:service>' . "\n";
            $xml_googleshopping .= "\t" . '<g:price>' . Tools::convertPriceFull(0, null, $currency) . ' ' . $currency->iso_code . '</g:price>' . "\n";
            $xml_googleshopping .= '</g:shipping>' . "\n";
        } elseif ($this->module_conf['shipping_mode'] == 'fixed') {
            $xml_googleshopping .= '<g:shipping>' . "\n";
            $xml_googleshopping .= "\t" . '<g:country>' . $this->module_conf['shipping_country'] . '</g:country>' . "\n";
            $xml_googleshopping .= "\t" . '<g:service>Standard</g:service>' . "\n";
            $xml_googleshopping .= "\t" . '<g:price>' . Tools::convertPriceFull($this->module_conf['shipping_price'], null, $currency) . ' ' . $currency->iso_code . '</g:price>' . "\n";
            $xml_googleshopping .= '</g:shipping>' . "\n";
        } elseif ($this->module_conf['shipping_mode'] == 'full' && count($this->module_conf['shipping_countries[]'])) {
            $this->id_address_delivery = 0;
            $countries = [];
            if (in_array('all', $this->module_conf['shipping_countries[]'])) {
                $countries = Country::getCountries($this->context->language->id, true);
            } else {
                foreach ($this->module_conf['shipping_countries[]'] as $id_country) {
                    $countries[] = (new Country((int) $id_country))->getFields();
                }
            }

            // optimize performance by grouping by zone
            $zones = [];
            foreach ($countries as $country) {
                $zones[$country['id_zone']][] = $country;
            }
            unset($countries);

            $shipping_free_price = $this->free_shipping['PS_SHIPPING_FREE_PRICE'];
            $shipping_free_weight = isset($this->free_shipping['PS_SHIPPING_FREE_WEIGHT']) ? $this->free_shipping['PS_SHIPPING_FREE_WEIGHT'] : 0;

            foreach ($zones as $id_zone => $countries) {
                $carriers = Carrier::getCarriers($this->context->language->id, true, false, $id_zone, null, 5);
                $carriers_excluded = $this->module_conf['carriers_excluded[]'];
                $carriers_product = $p->getCarriers();

                if (!empty($carriers_product)) {
                    foreach ($carriers as $index => $carrier) {
                        if (!in_array($carrier['id_carrier'], ArrayHelper::getColumn($carriers_product, 'id_carrier'))) {
                            unset($carriers[$index]);
                        }
                    }
                }

                if (!empty($carriers_excluded) && !in_array('no', $carriers_excluded)) {
                    $carriers = array_filter($carriers, function ($carrier) use ($carriers_excluded) {
                        return !in_array($carrier['id_carrier'], $carriers_excluded);
                    });
                }
                foreach ($carriers as $index => $carrier) {
                    $carrier = is_object($carrier) ? $carrier : new Carrier($carrier['id_carrier']);
                    $carrier_tax = Tax::getCarrierTaxRate((int) $carrier->id);
                    $shipping = (float) 0;

                    if ($carrier->max_width > 0 || $carrier->max_height > 0 || $carrier->max_depth > 0 || $carrier->max_weight > 0) {
                        $carrierSizes = [(int) $carrier->max_width, (int) $carrier->max_height, (int) $carrier->max_depth];
                        $productSizes = [(int) $product['width'], (int) $product['height'], (int) $product['depth']];
                        rsort($carrierSizes, SORT_NUMERIC);
                        rsort($productSizes, SORT_NUMERIC);
                        if (($carrierSizes[0] > 0 && $carrierSizes[0] < $productSizes[0])
                            || ($carrierSizes[1] > 0 && $carrierSizes[1] < $productSizes[1])
                            || ($carrierSizes[2] > 0 && $carrierSizes[2] < $productSizes[2])
                        ) {
                            unset($carriers[$index]);
                            break;
                        }
                        if ($carrier->max_weight > 0 && $carrier->max_weight < $product['weight']) {
                            unset($carriers[$index]);
                            break;
                        }
                    }
                    if (
                        !(((float) $shipping_free_price > 0) && ($product['price'] >= (float) $shipping_free_price))
                        && !(((float) $shipping_free_weight > 0) && ($product['weight'] >= (float) $shipping_free_weight))
                    ) {
                        if (isset($this->ps_shipping_handling) && $carrier->shipping_handling) {
                            $shipping = (float) $this->ps_shipping_handling;
                        }
                        if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT) {
                            $shipping += $carrier->getDeliveryPriceByWeight($product['weight'], $id_zone);
                        } else {
                            $shipping += $carrier->getDeliveryPriceByPrice($product['price'], $id_zone);
                        }
                        $shipping += $p->additional_shipping_cost;

                        $shipping *= 1 + ($carrier_tax / 100);
                    }

                    $carriers[$index]['price'] = $shipping;
                }

                $shipping = array_reduce($carriers, function ($a, $b) {
                    if ($a === null) {
                        return $b;
                    } else {
                        return ($a['price'] > $b['price']) ? $b : $a;
                    }
                });

                foreach ($countries as $country) {
                    $xml_googleshopping .= '<g:shipping>' . "\n";
                    $xml_googleshopping .= "\t" . '<g:country>' . $country['iso_code'] . '</g:country>' . "\n";
                    $xml_googleshopping .= "\t" . '<g:service>' . $shipping['delay'] . '</g:service>' . "\n";
                    $xml_googleshopping .= "\t" . '<g:price>' . Tools::convertPriceFull($shipping['price'], null, $currency) . ' ' . $currency->iso_code . '</g:price>' . "\n";
                    $xml_googleshopping .= '</g:shipping>' . "\n";
                }
            }
        }

        // Shipping weight
        if ($product['weight'] != '0') {
            $xml_googleshopping .= '<g:shipping_weight>' . number_format($product['weight'], 2, '.', '') . ' ' . strtolower(Configuration::get('PS_WEIGHT_UNIT')) . '</g:shipping_weight>' . "\n";
        }
        if ($this->module_conf['shipping_dimension'] == 1 && ($product['width'] != 0 && $product['height'] != 0 && $product['depth'] != 0)) {
            $xml_googleshopping .= '<g:shipping_length>' . number_format($product['depth'], 2, '.', '') . ' ' . Configuration::get('PS_DIMENSION_UNIT') . '</g:shipping_length>' . "\n";
            $xml_googleshopping .= '<g:shipping_width>' . number_format($product['depth'], 2, '.', '') . ' ' . Configuration::get('PS_DIMENSION_UNIT') . '</g:shipping_width>' . "\n";
            $xml_googleshopping .= '<g:shipping_height>' . number_format($product['height'], 2, '.', '') . ' ' . Configuration::get('PS_DIMENSION_UNIT') . '</g:shipping_height>' . "\n";
        }
        $xml_googleshopping .= '<g:unit_pricing_measure>1 ct</g:unit_pricing_measure>' . "\n";
        $xml_googleshopping .= '</item>' . "\n\n";

        if ($combination) {
            ++$this->nb_combinations;
            $this->nb_prd_w_attr[$product['id_product']] = 1;
        }
        ++$this->nb_total_products;

        return $xml_googleshopping;
    }
}
