<?php

namespace GShoppingFlux\Traits;

use Category;
use Configuration;
use Db;
use GShoppingFlux\ArrayHelper;
use GShoppingFlux\GCategories;
use GShoppingFlux\GLangAndCurrency;
use Language;
use Shop;

/**
 * GShoppingFlux module lifecycle: install/uninstall/reset and the hooks
 * that keep the module's own tables in sync with category/shop changes.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */
trait LifecycleTrait
{
    /**
     * Install module and create database tables
     *
     * Registers hooks, creates database tables, and initializes
     * configuration values for each shop.
     *
     * @param bool $delete_params Whether to delete existing parameters
     * @return bool True on success, false on failure
     */
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
        foreach (self::CONFIG_DEFAULTS as $key => $value) {
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
            $config_keys = array_keys(self::CONFIG_DEFAULTS);

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
}
