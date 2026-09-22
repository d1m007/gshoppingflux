<?php

namespace GShoppingFlux\Traits;

use Carrier;
use Category;
use Configuration;
use Country;
use Currency;
use Db;
use GShoppingFlux\ArrayHelper;
use GShoppingFlux\GCategories;
use GShoppingFlux\GLangAndCurrency;
use Image;
use Language;
use Manufacturer;
use Product;
use ProductSupplier;
use Shop;
use StockAvailable;
use Tax;
use Tools;

/**
 * GShoppingFlux feed generation engine: builds the standard and local
 * inventory Google Shopping XML feeds, one <item> at a time.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */
trait FeedGeneratorTrait
{
    private function getPriceDisplayPrecision()
    {
        if (defined('PS_PRICE_DISPLAY_PRECISION')) {
            return (int) PS_PRICE_DISPLAY_PRECISION;
        }
        // Note: Configuration::get signature is (key, id_lang, id_shop_group, id_shop, default)
        // The 5th argument is the default, not the 2nd — so we must check the result
        // explicitly and fall back to 2 (standard currency precision) when unset.
        $value = Configuration::get('PS_PRICE_DISPLAY_PRECISION');
        if ($value === false || $value === null || $value === '') {
            return 2;
        }
        return (int) $value;
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
        } elseif (empty($components['port'])) {
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

    /**
     * Escape a string so it cannot prematurely close a CDATA section.
     *
     * A literal "]]>" inside product/review data would otherwise terminate
     * the enclosing <![CDATA[ ... ]]> block early and let raw markup leak
     * into the feed, so every "]]>" sequence is split across two CDATA
     * sections using the standard XML escaping trick.
     */
    private function cdataSafe($string)
    {
        return str_replace(']]>', ']]]]><![CDATA[>', (string) $string);
    }

    private function generateXMLFiles($lang_id, $shop_id, $shop_group_id, $local_inventory = false, $reviews = false)
    {
        if (isset($lang_id) && $lang_id != 0) {
            $count = $this->generateLangFileList($lang_id, $shop_id, $local_inventory);
            $languages = GLangAndCurrency::getLangCurrencies($lang_id, $shop_id);
        } else {
            $count = $this->generateShopFileList($shop_id, $local_inventory, $reviews);
            $languages = GLangAndCurrency::getAllLangCurrencies(1, (int) $shop_id);
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
            $currencies = ArrayHelper::safeExplode($lang['id_currency']);
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

        return ($file_prefix ? $file_prefix . '_' : '') . 'googleshopping'
            . ($local_inventory ? '-local-inventory' : ($reviews ? '-reviews' : ''))
            . '-s' . $shop
            . (!empty($lang) ? '-' . $lang : '')
            . (!empty($curr) ? '-' . $curr : '')
            . '.xml';
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
        $languages = GLangAndCurrency::getAllLangCurrencies(1, (int) $id_shop);
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

    /**
     * Build the RSS <channel> header (shop title/description/link/image/author)
     * shared by every <item> in the standard and local inventory feeds.
     */
    private function buildFeedHeaderXml($id_lang, $id_shop)
    {
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

        return $xml;
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

        $xml = $this->buildFeedHeaderXml($id_lang, $id_shop);

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
     * Build the <g:quantity>/<g:availability> XML for a product.
     *
     * Shared between the standard and local inventory feeds: uses the
     * mapped category's Google availability override when set, otherwise
     * derives it from PrestaShop's own stock/availability status.
     */
    private function buildAvailabilityXml($product, Product $p)
    {
        $xml = '';

        if (empty($this->categories_values[$product['category_default']]['gcat_avail'])) {
            if ($this->module_conf['quantity'] == 1 && $this->ps_stock_management) {
                $xml .= '<g:quantity>' . $product['quantity'] . '</g:quantity>' . "\n";
            }
            if ($this->ps_stock_management) {
                if ($product['quantity'] > 0 && $product['available_for_order']) {
                    $xml .= '<g:availability>in stock</g:availability>' . "\n";
                } elseif ($p->isAvailableWhenOutOfStock((int) $p->out_of_stock) && $product['available_for_order']) {
                    $xml .= '<g:availability>preorder</g:availability>' . "\n";
                } else {
                    $xml .= '<g:availability>out of stock</g:availability>' . "\n";
                }
            } else {
                if ($product['available_for_order']) {
                    $xml .= '<g:availability>in stock</g:availability>' . "\n";
                } else {
                    $xml .= '<g:availability>out of stock</g:availability>' . "\n";
                }
            }
        } else {
            if ($this->module_conf['quantity'] == 1 && $product['quantity'] > 0 && $this->ps_stock_management) {
                $xml .= '<g:quantity>' . $product['quantity'] . '</g:quantity>' . "\n";
            }
            $xml .= '<g:availability>' . $this->categories_values[$product['category_default']]['gcat_avail'] . '</g:availability>' . "\n";
        }

        return $xml;
    }

    /**
     * Build the <g:price>/<g:sale_price> XML for a product.
     *
     * Shared between the standard and local inventory feeds. Also updates
     * $product['price']/$product['price_without_reduct'] in place, since
     * getItemXML() reuses those computed values for shipping costs.
     */
    private function buildPriceXml(array &$product, Product $p, Currency $currency, $combination)
    {
        $use_tax = ($product['tax_included'] ? true : false);
        $no_tax = (!$use_tax ? true : false);
        $product['price'] = (float) $p->getPriceStatic($product['id_product'], $use_tax, $combination) * $currency->conversion_rate;
        $product['price_without_reduct'] = (float) $p->getPriceWithoutReduct($no_tax, $combination) * $currency->conversion_rate;
        $product['price'] = Tools::ps_round($product['price'], $this->getPriceDisplayPrecision());
        $product['price_without_reduct'] = Tools::ps_round($product['price_without_reduct'], $this->getPriceDisplayPrecision());

        $xml = '';
        if ((float) $product['price'] < (float) $product['price_without_reduct']) {
            $xml .= '<g:price>' . $product['price_without_reduct'] . ' ' . $currency->iso_code . '</g:price>' . "\n";
            $xml .= '<g:sale_price>' . $product['price'] . ' ' . $currency->iso_code . '</g:sale_price>' . "\n";
        } else {
            $xml .= '<g:price>' . $product['price'] . ' ' . $currency->iso_code . '</g:price>' . "\n";
        }

        return $xml;
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
        $xml_googleshopping .= $this->buildAvailabilityXml($product, $p);

        // Price(s)
        $currency = new Currency((int) $id_curr);
        $xml_googleshopping .= $this->buildPriceXml($product, $p, $currency, $combination);

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
        $title_limit = self::TITLE_MAX_LENGTH;
        $short_title_limit = self::SHORT_TITLE_MAX_LENGTH;
        $description_limit = self::DESCRIPTION_MAX_LENGTH;
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
            $title_crop_pos = strrpos($title_crop, ' ');
            if ($title_crop_pos !== false) {
                $title_crop = Tools::substr($title_crop, 0, $title_crop_pos);
            }
        }

        if (Tools::strlen($short_title_crop) > $short_title_limit) {
            $short_title_crop = Tools::substr($short_title_crop, 0, $short_title_limit - 1);
            $short_title_crop_pos = strrpos($short_title_crop, ' ');
            if ($short_title_crop_pos !== false) {
                $short_title_crop = Tools::substr($short_title_crop, 0, $short_title_crop_pos);
            }
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
            $description_crop_pos = strrpos($description_crop, ' ');
            if ($description_crop_pos !== false) {
                $description_crop = Tools::substr($description_crop, 0, $description_crop_pos);
            }
            $description_crop .= ' ...';
        }

        $xml_googleshopping .= '<item>' . "\n";
        $xml_googleshopping .= '<g:id>' . $product['gid'] . '</g:id>' . "\n";
        $xml_googleshopping .= '<g:title><![CDATA[' . $this->cdataSafe($title_crop) . ']]></g:title>' . "\n";
        $xml_googleshopping .= '<g:short_title><![CDATA[' . $this->cdataSafe($short_title_crop) . ']]></g:short_title>' . "\n";
        $xml_googleshopping .= '<g:description><![CDATA[' . $this->cdataSafe($description_crop) . ']]></g:description>' . "\n";
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
            if (++$nbimages == self::MAX_PRODUCT_IMAGES) {
                break;
            }
        }

        // Product condition, or category's condition attribute, or its parent one...
        // Product condition = new, used, refurbished
        if (empty($product['condition'])) {
            $product['condition'] = $this->categories_values[$product['id_gcategory']]['gcat_condition'];
        }

        if (!empty($product['condition'])) {
            $xml_googleshopping .= '<g:condition><![CDATA[' . $this->cdataSafe($product['condition']) . ']]></g:condition>' . "\n";
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
        $xml_googleshopping .= '<g:product_type><![CDATA[' . $this->cdataSafe($product_type) . ']]></g:product_type>' . "\n";

        // Matching Google category, or parent categories' one
        $product['gcategory'] = $this->categories_values[$product['category_default']]['gcategory'];
        $xml_googleshopping .= '<g:google_product_category><![CDATA[' . $this->cdataSafe($product['gcategory']) . ']]></g:google_product_category>' . "\n";

        // Product quantity & availability
        $xml_googleshopping .= $this->buildAvailabilityXml($product, $p);

        // Price(s)
        $currency = new Currency((int) $id_curr);
        $xml_googleshopping .= $this->buildPriceXml($product, $p, $currency, $combination);

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
            $xml_googleshopping .= '<g:gender><![CDATA[' . $this->cdataSafe($product['gender']) . ']]></g:gender>' . "\n";
        }

        // Product age_group attribute, or category age_group attribute, or parent's one
        if (!empty($product['age_group'])) {
            $xml_googleshopping .= '<g:age_group><![CDATA[' . $this->cdataSafe($product['age_group']) . ']]></g:age_group>' . "\n";
        }

        // Product attributes combination groups
        if ($combination && !empty($product['item_group_id'])) {
            $xml_googleshopping .= '<g:item_group_id>' . $product['item_group_id'] . '</g:item_group_id>' . "\n";
        }

        // Product color attribute, or category color attribute, or parent's one
        if (!empty($product['color'])) {
            $xml_googleshopping .= '<g:color><![CDATA[' . $this->cdataSafe($product['color']) . ']]></g:color>' . "\n";
        }

        // Product material attribute, or category material attribute, or parent's one
        if (!empty($product['material'])) {
            $xml_googleshopping .= '<g:material><![CDATA[' . $this->cdataSafe($product['material']) . ']]></g:material>' . "\n";
        }

        // Product pattern attribute, or category pattern attribute, or parent's one
        if (!empty($product['pattern'])) {
            $xml_googleshopping .= '<g:pattern><![CDATA[' . $this->cdataSafe($product['pattern']) . ']]></g:pattern>' . "\n";
        }

        // Product size attribute, or category size attribute, or parent's one
        if (!empty($product['size'])) {
            $xml_googleshopping .= '<g:size><![CDATA[' . $this->cdataSafe($product['size']) . ']]></g:size>' . "\n";
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

                if (!empty($carriers)) {
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
        }

        // Shipping weight
        if ($product['weight'] != '0') {
            $xml_googleshopping .= '<g:shipping_weight>' . number_format($product['weight'], 2, '.', '') . ' ' . strtolower(Configuration::get('PS_WEIGHT_UNIT')) . '</g:shipping_weight>' . "\n";
        }
        if ($this->module_conf['shipping_dimension'] == 1 && ($product['width'] != 0 && $product['height'] != 0 && $product['depth'] != 0)) {
            $xml_googleshopping .= '<g:shipping_length>' . number_format($product['depth'], 2, '.', '') . ' ' . Configuration::get('PS_DIMENSION_UNIT') . '</g:shipping_length>' . "\n";
            $xml_googleshopping .= '<g:shipping_width>' . number_format($product['width'], 2, '.', '') . ' ' . Configuration::get('PS_DIMENSION_UNIT') . '</g:shipping_width>' . "\n";
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
