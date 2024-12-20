<?php
/*
Licensed to the Apache Software Foundation (ASF) under one
or more contributor license agreements.  See the NOTICE file
distributed with this work for additional information
regarding copyright ownership.  The ASF licenses this file
to you under the Apache License, Version 2.0 (the
"License"); you may not use this file except in compliance
with the License.  You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing,
software distributed under the License is distributed on an
"AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
KIND, either express or implied.  See the License for the
specific language governing permissions and limitations
under the License.
*/

require dirname(__FILE__) . '/gcategories.class.php';
require dirname(__FILE__) . '/glangandcurrency.class.php';
include_once dirname(__FILE__) . '/array_column.php';

class GShoppingFlux extends Module
{
    private $_html = '';
    private $user_groups;

    const CHARSET = 'UTF-8';
    const REPLACE_FLAGS = ENT_COMPAT;

    public function __construct()
    {
        $this->name = 'gshoppingflux';
        $this->tab = 'smart_shopping';
        $this->version = '1.7.4';
        $this->author = 'Dim00z';

        $this->bootstrap = true;
        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Google Shopping Flux');
        $this->description = $this->l('Export your products to Google Merchant Center, easily.');

        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.5.0.0', 'max' => _PS_VERSION_];
        $this->uri = ToolsCore::getCurrentUrlProtocolPrefix() . $this->context->shop->domain_ssl . $this->context->shop->physical_uri;
        if (empty($this->context->shop->domain_ssl)) {
            $this->uri = ToolsCore::getCurrentUrlProtocolPrefix() . $this->context->shop->domain . $this->context->shop->physical_uri;
        }
        $this->categories_values = [];

        $this->ps_stock_management = Configuration::get('PS_STOCK_MANAGEMENT');
        $this->ps_shipping_handling = (float) Configuration::get('PS_SHIPPING_HANDLING');
        $this->free_shipping = Configuration::getMultiple(['PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT']);
    }

    public function install($delete_params = true)
    {
        if (!parent::install()
            || !$this->registerHook('actionObjectCategoryAddAfter')
            || !$this->registerHook('actionObjectCategoryDeleteAfter')
            || !$this->registerHook('actionShopDataDuplication')
            || !$this->registerHook('actionCarrierUpdate')
            || !$this->installDb()) {
            return false;
        }

        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $shop_id) {
            $shop_group_id = Shop::getGroupFromShop($shop_id);

            if (!$this->initDb((int) $shop_id)) {
                return false;
            }

            if ($delete_params) {
                if (!Configuration::updateValue('GS_PRODUCT_TYPE', '', true, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_DESCRIPTION', 'short', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SHIPPING_MODE', 'fixed', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SHIPPING_PRICE_FIXED', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SHIPPING_PRICE', '0.00', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SHIPPING_COUNTRY', 'UK', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SHIPPING_COUNTRIES', '0', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_CARRIERS_EXCLUDED', '0', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_IMG_TYPE', 'large_default', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_MPN_TYPE', 'reference', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_GENDER', '', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_AGE_GROUP', '', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_ATTRIBUTES', '0', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_COLOR', '', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_MATERIAL', '', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_PATTERN', '', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SIZE', '', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_EXPORT_MIN_PRICE', '0.00', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_NO_GTIN', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_SHIPPING_DIMENSION', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_NO_BRAND', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_ID_EXISTS_TAG', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_EXPORT_NAP', '0', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_QUANTITY', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_FEATURED_PRODUCTS', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_GEN_FILE_IN_ROOT', '1', false, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_FILE_PREFIX', '', true, (int) $shop_group_id, (int) $shop_id)
                    || !Configuration::updateValue('GS_LOCAL_SHOP_CODE', '', true, (int) $shop_group_id, (int) $shop_id)) {
                    return false;
                }
            }
        }

        // Get generation file route
        if (!is_dir(dirname(__FILE__) . '/export')) {
            @mkdir(dirname(__FILE__) . '/export', 0755, true);
        }

        @chmod(dirname(__FILE__) . '/export', 0755);

        return true;
    }

    public function installDb()
    {
        return Db::getInstance()->execute('
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
		  	) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;')

            && Db::getInstance()->execute('
				CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gshoppingflux_lc` (
					`id_glang` INT(11) UNSIGNED NOT NULL,
					`id_currency` VARCHAR(255) NOT NULL,
					`tax_included` TINYINT(1) NOT NULL,
					`id_shop` INT(11) UNSIGNED NOT NULL,
			  INDEX (`id_glang`, `id_shop`)
			) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;')

            && Db::getInstance()->execute('
				CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'gshoppingflux_lang` (
					`id_gcategory` INT(11) UNSIGNED NOT NULL,
					`id_lang` INT(11) UNSIGNED NOT NULL,
					`id_shop` INT(11) UNSIGNED NOT NULL,
					`gcategory` VARCHAR( 255 ) NOT NULL,
			  INDEX (`id_gcategory`, `id_lang`, `id_shop`)
			) ENGINE = ' . _MYSQL_ENGINE_ . ' CHARACTER SET utf8 COLLATE utf8_general_ci;');
    }

    public function initDb($id_shop)
    {
        $languages = Language::getLanguages(true, $id_shop);
        $id_lang = $this->context->language->id;
        $str = [];

        $shop = new Shop($id_shop);
        $root = Category::getRootCategory($id_lang, $shop);

        $categs = Db::getInstance()->executeS('
			SELECT c.id_category, c.id_parent, c.active
			FROM ' . _DB_PREFIX_ . 'category c
			INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.id_category=c.id_category AND cs.id_shop=' . (int) $id_shop . ')
			ORDER BY c.id_category ASC, c.level_depth ASC, cs.position ASC;');

        foreach ($categs as $kc => $cat) {
            foreach ($languages as $key => $lang) {
                $str[$lang['id_lang']] = '';
            }

            $condition = '';
            $availability = '';
            $gender = '';
            $age_group = '';
            $color = '';
            $material = '';
            $pattern = '';
            $size = '';

            $cat_exists = GCategories::get($cat['id_category'], $id_lang, $id_shop);
            if ((!count($cat_exists) || $cat_exists === false) && ($cat['id_category'] > 0)) {
                if ($root->id_category == $cat['id_category']) {
                    foreach ($languages as $key => $lang) {
                        $str[$lang['id_lang']] = $this->l('Google Category Example > Google Sub-Category Example');
                    }

                    $condition = 'new';
                    $availability = 'in stock';
                }
                GCategories::add($cat['id_category'], $str, $cat['active'], $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $id_shop);
            }
        }

        foreach ($languages as $lang) {
            if (!count(GLangAndCurrency::getLangCurrencies($lang['id_lang'], $id_shop))) {
                GLangAndCurrency::add($lang['id_lang'], $this->context->currency->id, 1, $id_shop);
            }
        }

        return true;
    }

    public function uninstall($delete_params = true)
    {
        if (!parent::uninstall()) {
            return false;
        }

        if ($delete_params) {
            if (!$this->uninstallDB() || !Configuration::deleteByName('GS_PRODUCT_TYPE') || !Configuration::deleteByName('GS_DESCRIPTION') || !Configuration::deleteByName('GS_SHIPPING_MODE') || !Configuration::deleteByName('GS_SHIPPING_PRICE') || !Configuration::deleteByName('GS_SHIPPING_COUNTRY') || !Configuration::deleteByName('GS_SHIPPING_COUNTRIES') || !Configuration::deleteByName('GS_CARRIERS_EXCLUDED') || !Configuration::deleteByName('GS_IMG_TYPE') || !Configuration::deleteByName('GS_MPN_TYPE') || !Configuration::deleteByName('GS_GENDER') || !Configuration::deleteByName('GS_AGE_GROUP') || !Configuration::deleteByName('GS_ATTRIBUTES') || !Configuration::deleteByName('GS_COLOR') || !Configuration::deleteByName('GS_MATERIAL') || !Configuration::deleteByName('GS_PATTERN') || !Configuration::deleteByName('GS_SIZE') || !Configuration::deleteByName('GS_EXPORT_MIN_PRICE') || !Configuration::deleteByName('GS_NO_GTIN') || !Configuration::deleteByName('GS_SHIPPING_DIMENSION') || !Configuration::deleteByName('GS_NO_BRAND') || !Configuration::deleteByName('GS_ID_EXISTS_TAG') || !Configuration::deleteByName('GS_EXPORT_NAP') || !Configuration::deleteByName('GS_QUANTITY') || !Configuration::deleteByName('GS_FEATURED_PRODUCTS') || !Configuration::deleteByName('GS_GEN_FILE_IN_ROOT') || !Configuration::deleteByName('GS_FILE_PREFIX') || !Configuration::deleteByName('GS_LOCAL_SHOP_CODE')) {
                return false;
            }
        }

        return true;
    }

    private function uninstallDb()
    {
        Db::getInstance()->execute('DROP TABLE `' . _DB_PREFIX_ . 'gshoppingflux`');
        Db::getInstance()->execute('DROP TABLE `' . _DB_PREFIX_ . 'gshoppingflux_lc`');
        Db::getInstance()->execute('DROP TABLE `' . _DB_PREFIX_ . 'gshoppingflux_lang`');

        return true;
    }

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

    public function hookActionObjectCategoryAddAfter($params)
    {
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $id_shop) {
            $this->initDb($id_shop);
        }
    }

    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $id_shop) {
            $this->initDb($id_shop);
        }
    }

    public function hookActionShopDataDuplication($params)
    {
        $gcategories = Db::getInstance()->executeS('
			SELECT *
			FROM ' . _DB_PREFIX_ . 'gshoppingflux
			WHERE id_shop = ' . (int) $params['old_id_shop']);

        foreach ($gcategories as $id => $gcateg) {
            Db::getInstance()->insert('gshoppingflux', [
                'id_gcategory' => null,
                'id_shop' => (int) $params['new_id_shop'],
            ]);

            $gcategories[$id]['new_id_gcategory'] = Db::getInstance()->Insert_ID();
        }

        foreach ($gcategories as $id => $gcateg) {
            $lang = Db::getInstance()->executeS('
					SELECT id_lang, ' . (int) $params['new_id_shop'] . ', gcategory
					FROM ' . _DB_PREFIX_ . 'gshoppingflux_lang
					WHERE id_gcategory = ' . (int) $gcateg['id_gcategory'] . ' AND id_shop = ' . (int) $params['old_id_shop']);

            foreach ($lang as $l) {
                Db::getInstance()->insert('gshoppingflux_lang', [
                    'id_gcategory' => (int) $gcateg['new_id_gcategory'],
                    'id_lang' => (int) $l['id_lang'],
                    'id_shop' => (int) $params['new_id_shop'],
                    'gcategory' => (int) $l['gcategory'],
                ]);
            }
        }
    }

    public function hookActionCarrierUpdate($params)
    {
        $shop_id = $this->context->shop->id;
        $shop_group_id = Shop::getGroupFromShop($shop_id);
        $id_carrier_old = (int) $params['id_carrier'];
        $id_carrier_new = (int) $params['carrier']->id;
        $carriers_excluded = explode(';', Configuration::get('GS_CARRIERS_EXCLUDED', 0, $shop_group_id, $shop_id));
        if ($key = array_search($id_carrier_old, $carriers_excluded) !== false) {
            unset($carriers_excluded[$key]);
            array_push($carriers_excluded, $id_carrier_new);
            Configuration::updateValue('GS_CARRIERS_EXCLUDED', implode(';', $carriers_excluded), false, (int) $shop_group_id, (int) $shop_id);
        }
    }

    public function getContent()
    {
        $id_lang = $this->context->language->id;
        $languages = $this->context->controller->getLanguages();
        $shops = Shop::getShops(true, null, true);
        $shop_id = $this->context->shop->id;
        $shop_group_id = Shop::getGroupFromShop($shop_id);

        $gcategories = Tools::getValue('gcategory') ? array_filter(Tools::getValue('gcategory'), 'strlen') : [];
        if (count($shops) > 1 && Shop::getContext() != 1) {
            $this->_html .= $this->getWarningMultishopHtml();

            return $this->_html;
        }

        if (Shop::isFeatureActive()) {
            $this->_html .= $this->getCurrentShopInfoMsg();
        }

        if (Tools::isSubmit('submitFluxOptions')) {
            $errors_update_shops = [];
            $updated = true;
            $product_type_lang = Tools::getValue('product_type');
            foreach ($languages as $k => $lang) {
                $product_type[$lang['id_lang']] = $product_type_lang[$k];
            }

            $updated &= Configuration::updateValue('GS_PRODUCT_TYPE', $product_type, false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_DESCRIPTION', Tools::getValue('description'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_SHIPPING_MODE', Tools::getValue('shipping_mode'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_SHIPPING_PRICE', (float) Tools::getValue('shipping_price'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_SHIPPING_COUNTRY', Tools::getValue('shipping_country'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_SHIPPING_COUNTRIES', implode(';', Tools::getValue('shipping_countries')), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_CARRIERS_EXCLUDED', implode(';', Tools::getValue('carriers_excluded')), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_IMG_TYPE', Tools::getValue('img_type'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_MPN_TYPE', Tools::getValue('mpn_type'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_GENDER', Tools::getValue('gender'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_AGE_GROUP', Tools::getValue('age_group'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_ATTRIBUTES', Tools::getValue('export_attributes'), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_COLOR', implode(';', Tools::getValue('color')), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_MATERIAL', implode(';', Tools::getValue('material')), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_PATTERN', implode(';', Tools::getValue('pattern')), false, (int) $shop_group_id, (int) $shop_id);
            $updated &= Configuration::updateValue('GS_SIZE', implode(';', Tools::getValue('size')), false, (int) $shop_group_id, (int) $shop_id);
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
                $errors_update_shops[] = $shop->name;
            }

            if (!count($errors_update_shops)) {
                $this->confirm = $this->l('The settings have been updated.');
                $this->generateXMLFiles(0, $shop_id, $shop_group_id);
            } else {
                $this->_html .= $this->displayError(sprintf($this->l('Unable to update settings for the following shop: %s'), implode(', ', $errors_update_shops)));
            }
        } elseif (Tools::isSubmit('submitLocalInventoryFluxOptions')) {
            $errors_update_shops = [];
            $updated = true;
            $updated &= Configuration::updateValue('GS_LOCAL_SHOP_CODE', Tools::getValue('store_code'), false, (int) $shop_group_id, (int) $shop_id);
            if (!$updated) {
                $shop = new Shop($shop_id);
                $errors_update_shops[] = $shop->name;
            }

            if (!count($errors_update_shops)) {
                $this->confirm = $this->l('The settings have been updated.');
                $this->generateXMLFiles(0, $shop_id, $shop_group_id, true);
            } else {
                $this->_html .= $this->displayError(sprintf($this->l('Unable to update settings for the following shop: %s'), implode(', ', $errors_update_shops)));
            }
        } elseif (Tools::isSubmit('submitReviewsFluxOptions')) {
            $this->confirm = $this->l('The settings have been updated.');
            $this->generateXMLFiles(0, $shop_id, $shop_group_id, false, true);
        } elseif (Tools::isSubmit('updateCategory')) {
            $id_gcategory = (int) Tools::getValue('id_gcategory', 0);
            $export = (int) Tools::getValue('export', 0);
            $condition = Tools::getValue('condition');
            $availability = Tools::getValue('availability');
            $gender = Tools::getValue('gender');
            $age_group = Tools::getValue('age_group');
            $color = implode(';', Tools::getValue('color'));
            $material = implode(';', Tools::getValue('material'));
            $pattern = implode(';', Tools::getValue('pattern'));
            $size = implode(';', Tools::getValue('size'));
            $id_shop = (int) Shop::getContextShopID();

            if (Tools::isSubmit('updatecateg')) {
                $gcateg = [];
                foreach (Language::getLanguages(false) as $lang) {
                    $gcateg[$lang['id_lang']] = Tools::getValue('gcategory_' . (int) $lang['id_lang']);
                }

                GCategories::update($id_gcategory, $gcateg, $export, $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $id_shop);
                $this->confirm = $this->l('Google category has been updated.');
            }
            if (Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id) == 1) {
                $this->generateXMLFiles(0, $shop_id, $shop_group_id);
            }
        } elseif (Tools::isSubmit('updateLanguage')) {
            $id_glang = (int) Tools::getValue('id_glang', 0);
            $currencies = implode(';', Tools::getValue('currencies'));
            $tax_included = (int) Tools::getValue('tax_included', 0);
            $export = (int) Tools::getValue('active', 0);
            if (Tools::isSubmit('updatelang')) {
                GLangAndCurrency::update($id_glang, $currencies, $tax_included, (int) Shop::getContextShopID());
                if (count(Tools::getValue('currencies')) > 1) {
                    $this->confirm = $this->l('Selected currencies for this language have been saved.');
                } else {
                    $this->confirm = $this->l('Selected currency for this language has been saved.');
                }
            }
            if ($export && Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id) == 1) {
                $this->generateXMLFiles($id_glang, $shop_id, $shop_group_id);
            } else {
                $this->_html .= $this->displayConfirmation(html_entity_decode($this->confirm));
            }
        }

        $gcategories = GCategories::gets((int) $id_lang, null, (int) $shop_id);
        if (!count($gcategories)) {
            return $this->_html;
        }

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

        return $this->_html;
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
            $currencies = explode(';', $lang['id_currency']);
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

    private function getWarningMultishopHtml()
    {
        return '<p class="alert alert-warning">' . $this->l('You cannot manage Google categories from a "All Shops" or a "Group Shop" context, select directly the shop you want to edit') . '</p>';
    }

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

    public function getShopFeatures($id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT fl.* FROM ' . _DB_PREFIX_ . 'feature f
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (fl.id_feature = f.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_shop fs ON (fs.id_feature = f.id_feature)
			WHERE fl.id_lang = ' . (int) $id_lang . ' AND fs.id_shop = ' . (int) $id_shop . '
			ORDER BY f.id_feature ASC');
    }

    public function getShopAttributes($id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT agl.* FROM ' . _DB_PREFIX_ . 'attribute_group_lang agl
			LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group_shop ags ON (ags.id_attribute_group = agl.id_attribute_group)
			WHERE agl.id_lang = ' . (int) $id_lang . ' AND ags.id_shop = ' . (int) $id_shop . '
			ORDER BY ags.id_attribute_group ASC');
    }

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

    public function renderForm()
    {
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

        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;
        $img_types = ImageType::getImagesTypes('products');

        $features = [
            [
                'id_feature' => '',
                'name' => $this->l('Product feature doesn\'t exist'),
            ],
        ];
        $features = array_merge($features, $this->getShopFeatures($id_lang, $id_shop));
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
        $form_desc = html_entity_decode($this->l('Please visit and read the <a href="http://support.google.com/merchants/answer/188494" target="_blank">Google Shopping Products Feed Specification</a> if you don\'t know how to configure these options. <br/> If all your shop products match the same Google Shopping category, you can attach it to your home category in the table below, sub-categories will automatically get the same setting. No need to fill each Google category field. <br/> Products in categories with no Google category specified are exported in the Google Shopping category linked to the nearest parent.'));

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Parameters'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Default product type'),
                        'name' => 'product_type[]',
                        // 'class' => 'fixed-width-xl',
                        'lang' => true,
                        'desc' => $this->l('Your shop\'s default product type, ie: if you sell pants and shirts, and your main categories are "Men", "Women", "Kids", enter "Clothing" here. That will be exported as your shop main category. This setting is optional and can be left empty. Besides the module requires that at least main category of your shop is correctly linked to a Google product category.'),
                    ],
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
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping price'),
                        'name' => 'shipping_price',
                        'class' => 'fixed-width-xs',
                        'prefix' => $this->context->currency->sign,
                        'desc' => $this->l('This field is used for "Price fixed".'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping country'),
                        'name' => 'shipping_country',
                        'class' => 'fixed-width-xs',
                        // 'suffix' => strtoupper($this->context->language->iso_code),
                        'desc' => $this->l('This field is used for "Price fixed".'),
                    ],
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
                    [
                        'type' => 'text',
                        'label' => $this->l('Minimum product price'),
                        'name' => 'export_min_price',
                        'class' => 'fixed-width-xs',
                        'prefix' => $this->context->currency->sign,
                        'desc' => $this->l('Products at lower price are not exported. Enter 0.00 for no use.'),
                        'required' => true,
                    ],
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
                    [
                        'type' => 'text',
                        'label' => $this->l('prefix for output filename'),
                        'name' => 'file_prefix',
                        'class' => 'fixed-width-lg',
                        'desc' => $this->l('Allows you to prefix feed filename. Makes it a little harder for other to guess your feed names'),
                    ],
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

        return $helper->generateForm([
            $fields_form,
        ]);
    }

    public function renderLocalInventoryForm()
    {
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

        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

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
                    ]],
                'submit' => [
                    'name' => 'submitLocalInventoryFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([
            $fields_form,
        ]);
    }

    public function renderReviewsForm()
    {
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

        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

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

        return $helper->generateForm([
            $fields_form,
        ]);
    }

    public function getConfigFieldsValues($shop_id)
    {
        $shop_group_id = Shop::getGroupFromShop($shop_id);
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

        foreach (Language::getLanguages(false) as $lang) {
            $product_type[$lang['id_lang']] = Configuration::get('GS_PRODUCT_TYPE', $lang['id_lang'], $shop_group_id, $shop_id);
        }

        $description = Configuration::get('GS_DESCRIPTION', 0, $shop_group_id, $shop_id);
        $shipping_mode = Configuration::get('GS_SHIPPING_MODE', 0, $shop_group_id, $shop_id);
        $shipping_price_fixed &= (bool) Configuration::get('GS_SHIPPING_PRICE_FIXED', 0, $shop_group_id, $shop_id);
        $shipping_price = (float) Configuration::get('GS_SHIPPING_PRICE', 0, $shop_group_id, $shop_id);
        $shipping_country = Configuration::get('GS_SHIPPING_COUNTRY', 0, $shop_group_id, $shop_id);
        $shipping_countries = explode(';', Configuration::get('GS_SHIPPING_COUNTRIES', 0, $shop_group_id, $shop_id));
        $carriers_excluded = explode(';', Configuration::get('GS_CARRIERS_EXCLUDED', 0, $shop_group_id, $shop_id));
        $img_type = Configuration::get('GS_IMG_TYPE', 0, $shop_group_id, $shop_id);
        $mpn_type = Configuration::get('GS_MPN_TYPE', 0, $shop_group_id, $shop_id);
        $gender = Configuration::get('GS_GENDER', 0, $shop_group_id, $shop_id);
        $age_group = Configuration::get('GS_AGE_GROUP', 0, $shop_group_id, $shop_id);
        $export_attributes = Configuration::get('GS_ATTRIBUTES', 0, $shop_group_id, $shop_id);
        $color = explode(';', Configuration::get('GS_COLOR', 0, $shop_group_id, $shop_id));
        $material = explode(';', Configuration::get('GS_MATERIAL', 0, $shop_group_id, $shop_id));
        $pattern = explode(';', Configuration::get('GS_PATTERN', 0, $shop_group_id, $shop_id));
        $size = explode(';', Configuration::get('GS_SIZE', 0, $shop_group_id, $shop_id));
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

    public function getConfigLocalInventoryFieldsValues($shop_id)
    {
        $shop_group_id = Shop::getGroupFromShop($shop_id);
        $store_code = Configuration::get('GS_LOCAL_SHOP_CODE', 0, $shop_group_id, $shop_id);

        return [
            'store_code' => $store_code,
        ];
    }

    public function renderCategForm()
    {
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
        $attributes = [
            [
                'id_attribute_group' => '',
                'name' => $this->l('Products attribute doesn\'t exist'),
            ],
        ];
        $attributes = array_merge($attributes, $this->getShopAttributes($id_lang, $id_shop));
        $gcat_desc = '<a href="http://www.google.com/support/merchants/bin/answer.py?answer=160081&query=product_type" target="_blank">' . $this->l('See Google Categories') . '</a> ';
        $form_desc = html_entity_decode($this->l('Default: System tries to get the value of the product attribute. If not found, system tries to get the category\'s attribute value. <br> If not found, it tries to get the parent category\'s attribute, and so till the root category. At last, if empty, value is not exported.'));

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !Tools::getValue('updategshoppingflux')) ? $this->l('Update the matching Google category') : $this->l('Add a new Google category'),
                    'icon' => 'icon-link',
                ],
                'input' => [
                    [
                        'type' => 'free',
                        'label' => $this->l('Category'),
                        'name' => 'breadcrumb',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Matching Google category'),
                        'name' => 'gcategory',
                        'lang' => true,
                        'desc' => $gcat_desc,
                    ],
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

        if ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !Tools::getValue('updategshoppingflux')) {
            $fields_form['form']['submit'] = [
                'name' => 'updateCategory',
                'title' => $this->l('Update'),
            ];
        }

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

        return $helper->generateForm(
            [
                $fields_form,
            ]
        );
    }

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

        if (Tools::isSubmit('updategshoppingflux') || Tools::isSubmit('statusgshoppingflux')) {
            $id_lang = $this->context->cookie->id_lang;
            $gcateg = GCategories::getCategLang(Tools::getValue('id_gcategory'), (int) Shop::getContextShopID(), $id_lang);

            foreach ($gcateg['gcategory'] as $key => $categ) {
                $gcateg['gcategory'][$key] = Tools::htmlentitiesDecodeUTF8($categ);
            }

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

        $fields_values = [
            'id_gcategory' => Tools::getValue('id_gcategory'),
            'breadcrumb' => (isset($gcatlabel_edit) ? $gcatlabel_edit : ''),
            'export' => Tools::getValue('export', isset($gcatexport_active) ? $gcatexport_active : ''),
            'condition' => Tools::getValue('condition', isset($gcatcondition_edit) ? $gcatcondition_edit : ''),
            'availability' => Tools::getValue('availability', isset($gcatavail_edit) ? $gcatavail_edit : ''),
            'gender' => Tools::getValue('gender', isset($gcatgender_edit) ? $gcatgender_edit : ''),
            'age_group' => Tools::getValue('age_group', isset($gcatage_edit) ? $gcatage_edit : ''),
            'color[]' => explode(';', Tools::getValue('color[]', isset($gcatcolor_edit) ? $gcatcolor_edit : '')),
            'material[]' => explode(';', Tools::getValue('material[]', isset($gcatmaterial_edit) ? $gcatmaterial_edit : '')),
            'pattern[]' => explode(';', Tools::getValue('pattern[]', isset($gcatpattern_edit) ? $gcatpattern_edit : '')),
            'size[]' => explode(';', Tools::getValue('size[]', isset($gcatsize_edit) ? $gcatsize_edit : '')),
        ];

        if (Tools::getValue('submitAddmodule')) {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['gcategory'][$lang['id_lang']] = '';
            }
        } else {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['gcategory'][$lang['id_lang']] = Tools::getValue('gcategory_' . (int) $lang['id_lang'], isset($gcategory_edit[$lang['id_lang']]) ? html_entity_decode($gcategory_edit[$lang['id_lang']]) : '');
            }
        }

        return $fields_values;
    }

    public function getGLangFieldsValues()
    {
        $glangcurrency_edit = '';
        $glangexport_active = '';

        if (Tools::isSubmit('updategshoppingflux_lc') || Tools::isSubmit('statusgshoppingflux_lc')) {
            $glang = GLangAndCurrency::getLangCurrencies(Tools::getValue('id_glang'), (int) Shop::getContextShopID());
            $glangcurrency_edit = explode(';', $glang[0]['id_currency']);
            $glangtax_included = $glang[0]['tax_included'];
            $glangexport_active = $glang[0]['active'];
        }
        $language = Language::getLanguage(Tools::getValue('id_glang'));
        $fields_values = [
            'id_glang' => Tools::getValue('id_glang'),
            'name' => $language['name'],
            'iso_code' => $language['iso_code'],
            'language_code' => $language['language_code'],
            'currencies[]' => Tools::getValue('currencies[]', $glangcurrency_edit),
            'tax_included' => Tools::getValue('tax_included', $glangtax_included),
            'active' => Tools::getValue('active', $glangexport_active),
        ];

        return $fields_values;
    }

    public function renderLangForm()
    {
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
        $currencies = Currency::getCurrencies();

        $form_desc = html_entity_decode($this->l('Select currency to export with this language.'));

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Language export settings'),
                    'icon' => 'icon-globe',
                ],
                'input' => [
                    [
                        'type' => 'free',
                        'label' => $this->l('Language'),
                        'name' => 'name',
                    ],
                    [
                        'type' => 'free',
                        'label' => $this->l('Language code'),
                        'name' => 'language_code',
                    ],
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

        if ((Tools::getIsset('updategshoppingflux_lc') || Tools::getIsset('statusgshoppingflux_lc')) && !Tools::getValue('updategshoppingflux_lc')) {
            $fields_form['form']['submit'] = [
                'name' => 'updateLanguage',
                'title' => $this->l('Update'),
            ];
        }

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

        return $helper->generateForm(
            [
                $fields_form,
            ]
        );
    }

    public function renderLangList()
    {
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

        if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(true, null, true)) > 1) {
            $fields_list = array_merge($fields_list, [
                'shop_name' => [
                    'title' => $this->l('Shop name'),
                    'width' => '15%',
                ],
            ]);
        }

        $form_desc = 'Export currencies you need with the language you want.';

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

        $form_desc = 'If all your products match the same Google category, you can attach it to your home category, sub-categories will automatically get the same setting. Products in categories with no Google category specified are exported in the same place than the one from parent categories.';

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
                    $shop_group_id = Shop::getGroupFromShop($shop_id);

                    $result[$k]['color'] = explode(';', $result[$k]['color']);
                    foreach ($result[$k]['color'] as $a => $v) {
                        $key = array_search($v, array_column($attributes, 'id_attribute_group'));
                        if ($key !== false) {
                            $gid_colors[] = $attributes[$key]['name'];
                        }
                    }
                    $result[$k]['material'] = explode(';', $result[$k]['material']);
                    foreach ($result[$k]['material'] as $a => $v) {
                        $key = array_search($v, array_column($attributes, 'id_attribute_group'));
                        if ($key !== false) {
                            $gid_materials[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['pattern'] = explode(';', $result[$k]['pattern']);
                    foreach ($result[$k]['pattern'] as $a => $v) {
                        $key = array_search($v, array_column($attributes, 'id_attribute_group'));
                        if ($key !== false) {
                            $gid_patterns[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['size'] = explode(';', $result[$k]['size']);
                    foreach ($result[$k]['size'] as $a => $v) {
                        $key = array_search($v, array_column($attributes, 'id_attribute_group'));
                        if ($key !== false) {
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

    private function rip_tags($string)
    {
        // ----- remove HTML TAGs -----
        $string = preg_replace('/<[^>]*>/', ' ', $string);

        // ----- remove control characters -----
        $string = str_replace("\r", '', $string); // --- replace with empty space
        $string = str_replace("\n", ' ', $string); // --- replace with space
        $string = str_replace("\t", ' ', $string); // --- replace with space

        // ----- remove multiple spaces -----
        $string = trim(preg_replace('/ {2,}/', ' ', $string));

        return $string;
    }

    private function _getOutputFileName($lang, $curr, $shop, $local_inventory = false, $reviews = false)
    {
        $file_prefix = Configuration::get('GS_FILE_PREFIX', '', $this->context->shop->id_shop_group, $this->context->shop->id);
        if ($file_prefix) {
            return $file_prefix . '_googleshopping' . ($local_inventory ? '-local-inventory' : ($reviews ? '-reviews' : '')) . '-s' . $shop . (!empty($lang) ? '-' . $lang : '') . (!empty($curr) ? '-' . $curr : '') . '.xml';
        }

        return 'googleshopping' . ($local_inventory ? '-local-inventory' : ($reviews ? '-reviews' : '')) . '-s' . $shop . (!empty($lang) ? '-' . $lang : '') . (!empty($curr) ? '-' . $curr : '') . '.xml';
    }

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

    public function generateAllShopsFileList()
    {
        // Get all shops
        $shops = Shop::getShops(true, null, true);
        foreach ($shops as $i => $shop) {
            $ret[$i] = $this->generateShopFileList($shop);
        }

        return $ret;
    }

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
        $products = Db::getInstance()->ExecuteS($sql);
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

        $comments = Db::getInstance()->ExecuteS($sql);

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
        $product['price'] = Tools::ps_round($product['price'], _PS_PRICE_DISPLAY_PRECISION_);
        $product['price_without_reduct'] = Tools::ps_round($product['price_without_reduct'], _PS_PRICE_DISPLAY_PRECISION_);
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

    private function getItemXML($product, $lang, $id_curr, $id_shop, $combination = false)
    {
        $xml_googleshopping = '';
        $id_lang = (int) $lang['id_lang'];
        $title_limit = 150;
        $short_title_limit = 65;
        $description_limit = 4990;
        $languages = Language::getLanguages();
        $tailleTabLang = sizeof($languages);
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
        $short_title_crop = $product['name'];

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

        if (Tools::strlen($title_crop) > $title_limit) {
            $title_crop = Tools::substr($title_crop, 0, $title_limit - 1);
            $title_crop = Tools::substr($title_crop, 0, strrpos($title_crop, ' '));
        }

        if (Tools::strlen($short_title_crop) > $short_title_limit) {
            $short_title_crop = Tools::substr($short_title_crop, 0, $short_title_limit - 1);
            $short_title_crop = Tools::substr($short_title_crop, 0, strrpos($short_title_crop, ' '));
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
        $xml_googleshopping .= '<g:short_title><![CDATA[' . $short_title_crop . ']]></g:short_title>' . "\n";
        $xml_googleshopping .= '<g:description><![CDATA[' . $description_crop . ']]></g:description>' . "\n";
        $xml_googleshopping .= '<g:link><![CDATA[' . $this->linkencode($product_link) . ']]></g:link>' . "\n";

        // Image links
        $images = Image::getImages($lang['id_lang'], $product['id_product'], $combination);
        if (count($images) == 0 && $combination != false) {
            $images = Image::getImages($lang['id_lang'], $product['id_product']);
        }
        $indexTabLang = 0;
        if ($tailleTabLang > 1) {
            while (sizeof($images) < 1 && $indexTabLang < $tailleTabLang) {
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
        $cover_key = array_search('1', array_column($images, 'cover'));
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
        $product['price'] = Tools::ps_round($product['price'], _PS_PRICE_DISPLAY_PRECISION_);
        $product['price_without_reduct'] = Tools::ps_round($product['price_without_reduct'], _PS_PRICE_DISPLAY_PRECISION_);
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
                        if (false === array_search($carrier['id_carrier'], array_column($carriers_product, 'id_carrier'), true)) {
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
                    if (!(((float) $shipping_free_price > 0) && ($product['price'] >= (float) $shipping_free_price))
                    && !(((float) $shipping_free_weight > 0) && ($product['weight'] >= (float) $shipping_free_weight))) {
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

    public function linkencode($p_url)
    {
        $ta = parse_url($p_url);
        if (!empty($ta['scheme'])) {
            $ta['scheme'] .= '://';
        }
        if (!empty($ta['pass']) and !empty($ta['user'])) {
            $ta['user'] .= ':';
            $ta['pass'] = rawurlencode($ta['pass']) . '@';
        } elseif (!empty($ta['user'])) {
            $ta['user'] .= '@';
        } else {
            $ta['user'] = '';
            $ta['pass'] = '';
        }
        if (!empty($ta['port']) and !empty($ta['host'])) {
            $ta['host'] = '' . $ta['host'] . ':';
        } elseif (!empty($ta['host'])) {
            $ta['host'] = $ta['host'];
            $ta['port'] = '';
        }
        if (!empty($ta['path'])) {
            $tu = '';
            $tok = strtok($ta['path'], '\\/');
            while (strlen($tok)) {
                $tu .= rawurlencode($tok) . '/';
                $tok = strtok('\\/');
            }
            $ta['path'] = '/' . trim($tu, '/');
        }
        if (!empty($ta['query'])) {
            $ta['query'] = '?' . $ta['query'];
        } else {
            $ta['query'] = '';
        }
        if (!empty($ta['fragment'])) {
            $ta['fragment'] = '#' . $ta['fragment'];
        } else {
            $ta['fragment'] = '';
        }

        return implode('', [$ta['scheme'], $ta['user'], $ta['pass'], $ta['host'], $ta['port'], $ta['path'], $ta['query'], $ta['fragment']]);
    }
}
