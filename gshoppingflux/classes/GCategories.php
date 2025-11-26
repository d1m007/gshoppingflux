<?php

/**
 * GCategories
 *
 * Manages Google Shopping categories mapping and associations with Prestashop categories.
 * Handles CRUD operations for Google Shopping category configurations across multiple shops and languages.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GCategories
{

    /**
     * Retrieve all Google Shopping categories with related data
     *
     * Fetches Google Shopping categories with their associated Prestashop categories,
     * shop information, and localized category names. Returns breadcrumb paths for
     * visual navigation hierarchy.
     *
     * @param int $id_lang Language ID for translations and category names
     * @param int|null $id_gcategory Optional specific Google category ID to filter results
     * @param int $id_shop Shop ID to scope the query to a specific shop
     *
     * @return array Array of Google Shopping categories with:
     *               - id_gcategory: Google category identifier
     *               - gcategory: Localized Google category name
     *               - shop_name: Associated shop name
     *               - cat_name: Associated Prestashop category name
     *               - breadcrumb: Full category path (e.g., "Home > Electronics > Phones")
     */
    public static function gets($id_lang, $id_gcategory = null, $id_shop)
    {
        // Build SQL query with LEFT JOINs to fetch category data across multiple tables
        $ret = Db::getInstance()->executeS('SELECT g.*, gl.gcategory, s.name as shop_name, cl.name as cat_name '
            . 'FROM ' . _DB_PREFIX_ . 'gshoppingflux g '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'category c ON (c.id_category=g.id_gcategory AND c.id_shop_default=g.id_shop) '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'category_shop cs ON (cs.id_category=g.id_gcategory AND cs.id_shop=g.id_shop) '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'gshoppingflux_lang gl ON (gl.id_gcategory=g.id_gcategory AND gl.id_lang=' . (int) $id_lang . ' AND gl.id_shop=g.id_shop) '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'shop s ON (s.id_shop=g.id_shop) '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON (cl.id_category=g.id_gcategory AND cl.id_lang=' . (int) $id_lang . ' AND cl.id_shop=g.id_shop) '
            . 'WHERE ' . ((!is_null($id_gcategory)) ? ' g.id_gcategory="' . (int) $id_gcategory . '" AND ' : '')
            . 'g.id_shop IN (0, ' . (int) $id_shop . ');');

        // Get shop's root category for breadcrumb generation
        $shop = new Shop($id_shop);
        $root = Category::getRootCategory($id_lang, $shop);

        // Build breadcrumb paths for each category
        foreach ($ret as $k => $v) {
            $ret[$k]['breadcrumb'] = self::getPath($v['id_gcategory'], '', (int) $id_lang, (int) $id_shop, (int) $root->id_category);

            // Fallback to category name if breadcrumb is empty
            if (empty($ret[$k]['breadcrumb'])) {
                $ret[$k]['breadcrumb'] = $v['cat_name'];
            }
        }

        return $ret;
    }

    /**
     * Retrieve a single Google Shopping category by ID
     *
     * Convenience wrapper around gets() for fetching a specific category.
     *
     * @param int $id_gcategory Google category ID
     * @param int $id_lang Language ID for translations
     * @param int $id_shop Shop ID
     *
     * @return array Category data (see gets() for structure)
     */
    public static function get($id_gcategory, $id_lang, $id_shop)
    {
        return self::gets($id_lang, $id_gcategory, $id_shop);
    }

    /**
     * Retrieve Google Shopping category translations across all languages
     *
     * Fetches a specific Google category with its multilingual Google Shopping category names.
     * Used to populate language-specific forms and configurations.
     *
     * @param int $id_gcategory Google category ID
     * @param int $id_shop Shop ID
     * @param int $id_lang Primary language ID for breadcrumb generation
     *
     * @return array Category configuration with:
     *              - breadcrumb: Category navigation path
     *              - gcategory: Array of Google category names keyed by language ID
     *              - export: Export status flag
     *              - condition: Product condition (new/used/refurbished)
     *              - availability: Product availability status
     *              - gender: Gender attribute (male/female/unisex)
     *              - age_group: Age group attribute
     *              - color: Color attribute mapping
     *              - material: Material attribute mapping
     *              - pattern: Pattern attribute mapping
     *              - size: Size attribute mapping
     */
    public static function getCategLang($id_gcategory, $id_shop, $id_lang)
    {
        // Query to retrieve category data with all language-specific translations
        $ret = Db::getInstance()->executeS(
            '
			SELECT g.*, gl.gcategory, gl.id_lang, cl.name as gcat_name
			FROM ' . _DB_PREFIX_ . 'gshoppingflux g
			LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON (cl.id_category = g.id_gcategory AND cl.id_shop=' . (int) $id_shop . ')
			LEFT JOIN ' . _DB_PREFIX_ . 'gshoppingflux_lang gl ON (gl.id_gcategory = g.id_gcategory AND gl.id_shop=' . (int) $id_shop . ')
			WHERE 1	' . ((!is_null($id_gcategory)) ? ' AND g.id_gcategory = "' . (int) $id_gcategory . '"' : '') . '
			AND g.id_shop IN (0, ' . (int) $id_shop . ');'
        );

        // Build language-keyed array of Google category translations
        $gcateg = [];

        foreach ($ret as $l => $line) {
            $gcateg[$line['id_lang']] = Tools::safeOutput($line['gcategory']);
        }

        // Generate breadcrumb path for category hierarchy visualization
        $shop = new Shop($id_shop);
        $root = Category::getRootCategory($id_lang, $shop);
        $ret[0]['breadcrumb'] = self::getPath((int) $id_gcategory, '', $id_lang, $id_shop, $root->id_category);

        // Fallback to category name if breadcrumb generation fails
        if (empty($ret[0]['breadcrumb']) || $ret[0]['breadcrumb'] == ' > ') {
            $ret[0]['breadcrumb'] = $ret[0]['gcat_name'];
        }

        // Return structured configuration data
        return [
            'breadcrumb' => $ret[0]['breadcrumb'],
            'gcategory' => $gcateg,
            'export' => $ret[0]['export'],
            'condition' => $ret[0]['condition'],
            'availability' => $ret[0]['availability'],
            'gender' => $ret[0]['gender'],
            'age_group' => $ret[0]['age_group'],
            'color' => $ret[0]['color'],
            'material' => $ret[0]['material'],
            'pattern' => $ret[0]['pattern'],
            'size' => $ret[0]['size'],
        ];
    }

    /**
     * Create a new Google Shopping category mapping
     *
     * Inserts a new Google Shopping category configuration linked to a Prestashop category.
     * Creates both the category mapping and multilingual translations in a single operation.
     *
     * @param int $id_category Prestashop category ID
     * @param array $gcateg Language-keyed array of Google Shopping category names
     *                       Example: [1 => 'Electronics', 2 => 'Électronique']
     * @param int $export Export status flag (0 = disabled, 1 = enabled)
     * @param string $condition Product condition (e.g., 'new', 'used', 'refurbished')
     * @param string $availability Product availability (e.g., 'in stock', 'out of stock')
     * @param string $gender Target gender (e.g., 'male', 'female', 'unisex')
     * @param string $age_group Target age group (e.g., 'adult', 'teen', 'kids')
     * @param string $color Color attribute value
     * @param string $material Material attribute value
     * @param string $pattern Pattern attribute value
     * @param string $size Size attribute value
     * @param int $id_shop Shop ID for multi-shop support
     *
     * @return bool True on success, false if validation fails
     */
    public static function add($id_category, $gcateg, $export, $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $id_shop)
    {
        // Validation: ensure category ID is provided
        if (empty($id_category)) {
            return false;
        }
        // Validation: ensure Google categories array is provided
        if (!is_array($gcateg)) {
            return false;
        }

        // Insert main category configuration record
        Db::getInstance()->insert(
            'gshoppingflux',
            [
                'id_gcategory' => (int) $id_category,
                'export' => (int) $export,
                'condition' => $condition,
                'availability' => $availability,
                'gender' => $gender,
                'age_group' => $age_group,
                'color' => $color,
                'material' => $material,
                'pattern' => $pattern,
                'size' => $size,
                'id_shop' => (int) $id_shop,
            ]
        );

        // Insert language-specific translations for Google category names
        foreach ($gcateg as $id_lang => $categ) {
            Db::getInstance()->insert(
                'gshoppingflux_lang',
                [
                    'id_gcategory' => (int) $id_category,
                    'id_lang' => (int) $id_lang,
                    'id_shop' => (int) $id_shop,
                    'gcategory' => pSQL($categ),
                ]
            );
        }
        return true;
    }

    /**
     * Update an existing Google Shopping category mapping
     *
     * Modifies both the category configuration and multilingual translations.
     * Updates are scoped by category ID and shop to support multi-shop installations.
     *
     * @param int $id_category Prestashop category ID to update
     * @param array $gcateg Language-keyed array of updated Google Shopping category names
     * @param int $export Updated export status flag
     * @param string $condition Updated product condition
     * @param string $availability Updated product availability
     * @param string $gender Updated gender attribute
     * @param string $age_group Updated age group attribute
     * @param string $color Updated color attribute
     * @param string $material Updated material attribute
     * @param string $pattern Updated pattern attribute
     * @param string $size Updated size attribute
     * @param int $id_shop Shop ID for scoped updates
     *
     * @return bool True on success, false if validation fails
     */
    public static function update($id_category, $gcateg, $export, $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $id_shop)
    {
        // Validation: ensure category ID is provided
        if (empty($id_category)) {
            return false;
        }

        // Validation: ensure Google categories array is provided
        if (!is_array($gcateg)) {
            return false;
        }

        // Update main category configuration record
        Db::getInstance()->update(
            'gshoppingflux',
            [
                'export' => (int) $export,
                'condition' => $condition,
                'availability' => $availability,
                'gender' => $gender,
                'age_group' => $age_group,
                'color' => $color,
                'material' => $material,
                'pattern' => $pattern,
                'size' => $size,
            ],
            'id_gcategory = ' . (int) $id_category . ' AND id_shop=' . (int) $id_shop
        );

        // Update language-specific translations for each language
        foreach ($gcateg as $id_lang => $categ) {
            Db::getInstance()->update(
                'gshoppingflux_lang',
                [
                    'gcategory' => pSQL($categ),
                ],
                'id_gcategory = ' . (int) $id_category . ' AND id_lang = ' . (int) $id_lang . ' AND id_shop=' . (int) $id_shop
            );
        }

        return true;
    }

    /**
     * Update export status for a category
     *
     * Toggles the export flag without modifying other category attributes.
     * Useful for quick enable/disable operations in admin interfaces.
     *
     * @param int $id_category Category ID to update
     * @param int $id_shop Shop ID for scoped update
     * @param int $export Export status (0 = disabled, 1 = enabled)
     *
     * @return void
     */
    public static function updateStatus($id_category, $id_shop, $export)
    {
        Db::getInstance()->update(
            'gshoppingflux',
            [
                'export' => (int) $export,
            ],
            'id_gcategory = ' . (int) $id_category . ' AND id_shop=' . (int) $id_shop
        );
    }

    /**
     * Remove a Google Shopping category mapping
     *
     * Deletes a category configuration and all associated language translations.
     * Operation is scoped by both category and shop IDs for data isolation.
     *
     * @param int $id_gcategory Google category ID to remove
     * @param int $id_shop Shop ID for scoped deletion
     *
     * @return void
     */
    public static function remove($id_gcategory, $id_shop)
    {
        // Delete main category configuration record
        Db::getInstance()->delete('gshoppingflux', 'id_gcategory = ' . (int) $id_gcategory . ' AND id_shop = ' . (int) $id_shop);

        // Delete all language-specific translations for this category
        Db::getInstance()->delete('gshoppingflux_lang', 'id_gcategory = ' . (int) $id_gcategory);
    }

    /**
     * Generate breadcrumb path for category hierarchy
     *
     * Recursively traverses the category tree from the target category up to the root.
     * Builds a human-readable path representation (e.g., "Home > Electronics > Phones").
     * Stops recursion at the root category or inactive categories.
     *
     * @param int $id_category Category ID to start breadcrumb generation from
     * @param string $path Accumulator string for recursive calls (leave empty on initial call)
     * @param int $id_lang Language ID for category name translation
     * @param int $id_shop Shop ID for category scope
     * @param int $id_root Root category ID where recursion should stop
     *
     * @return string Formatted breadcrumb path (e.g., "Electronics > Phones")
     *                Returns empty string if category is root or inactive
     */
    public static function getPath($id_category, $path = '', $id_lang, $id_shop, $id_root)
    {
        // Load category object with language and shop context
        $category = new Category((int) $id_category, (int) $id_lang, (int) $id_shop);

        // Stop recursion if: category is invalid, is root, or is inactive
        if (!Validate::isLoadedObject($category) || $category->id_category == $id_root || $category->active == 0) {
            return $path;
        }

        // Define breadcrumb separator
        $pipe = ' > ';

        // Remove numeric prefixes from category names (Prestashop stores them as "123.Category Name")
        $category_name = preg_replace('/^[0-9]+\./', '', $category->name);

        // Build breadcrumb by prepending current category name
        if ($path != $category_name) {
            $path = $category_name . ($path != '' ? $pipe . $path : '');
        }

        // Recursive call: traverse to parent category and continue building path
        return self::getPath((int) $category->id_parent, $path, (int) $id_lang, (int) $id_shop, (int) $id_root);
    }
}
