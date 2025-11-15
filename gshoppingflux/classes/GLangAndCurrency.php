<?php

/**
 * GLangAndCurrency
 *
 * Manages language and currency configurations for Google Shopping feed generation.
 * Provides CRUD operations for language-currency pairs used in multi-language,
 * multi-currency store environments.
 *
 * The class supports multi-shop installations through proper filtering and
 * scoping of language-currency configurations.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GLangAndCurrency
{
    /**
     * Retrieve language and currency configurations for a specific language and shop
     *
     * Fetches all language-currency combinations available in a shop, joined with
     * language information from Prestashop's language table. Results include tax
     * inclusion settings and currency identifiers needed for feed generation.
     *
     * This method supports Prestashop's global (shop_id=0) and shop-specific configurations,
     * returning both global and shop-specific entries.
     *
     * @param int $id_lang Language ID to filter configurations
     * @param int $id_shop Shop ID to scope results to a specific shop
     *
     * @return array Array of language-currency configurations with fields:
     *              - id_glang: Language-currency configuration ID
     *              - id_lang: Language identifier
     *              - id_currency: Currency identifier for this language
     *              - tax_included: Flag indicating if prices include tax (0 or 1)
     *              - id_shop: Shop ID (0 for global, >0 for shop-specific)
     *              - iso_code: ISO language code (e.g., 'en', 'fr')
     *              - name: Language display name
     *              - active: Language active status
     *
     * @see getAllLangCurrencies() For retrieving all configurations across shops
     */
    public static function getLangCurrencies($id_lang, $id_shop)
    {
        // Execute JOIN query to fetch language-currency pairs with language metadata
        $ret = Db::getInstance()->executeS('SELECT glc.*, l.* '
            . 'FROM ' . _DB_PREFIX_ . 'gshoppingflux_lc glc '
            . 'INNER JOIN `' . _DB_PREFIX_ . 'lang` l ON (l.id_lang = glc.id_glang)'
            . 'WHERE glc.id_glang IN (0, ' . (int) $id_lang . ') '
            . 'AND glc.id_shop IN (0, ' . (int) $id_shop . ');');

        return $ret;
    }

    /**
     * Retrieve all language and currency configurations across all shops
     *
     * Fetches all available language-currency combinations for the current shop context.
     * Respects Prestashop's shop restrictions through Shop::addSqlRestriction().
     *
     * Optionally filters to return only active languages, useful for feed generation
     * where inactive languages should be skipped.
     *
     * @param bool $active Optional flag to filter only active languages (default: false)
     *
     * @return array Array of language-currency configurations (see getLangCurrencies() for structure)
     *
     * @see getLangCurrencies() For shop-specific filtering
     */
    public static function getAllLangCurrencies($active = false)
    {
        // Build SQL query with shop restriction and optional active filter
        $ret = Db::getInstance()->executeS('SELECT glc.*, l.* FROM ' . _DB_PREFIX_ . 'gshoppingflux_lc glc '
            . 'INNER JOIN ' . _DB_PREFIX_ . 'lang l ON (glc.id_glang = l.id_lang)'
            . 'WHERE 1 ' . Shop::addSqlRestriction()
            . ($active ? ' AND l.`active` = 1' : ''));

        return $ret;
    }

    /**
     * Create a new language-currency configuration
     *
     * Inserts a new language-currency pair with tax inclusion setting
     * into the Google Shopping Flux configuration table.
     *
     * This method is typically used when setting up a new language/currency
     * combination for feed generation in a multi-language store.
     *
     * @param int $id_lang Language ID (from Prestashop languages table)
     * @param int $id_currency Currency ID (from Prestashop currencies table)
     * @param int $tax_included Tax inclusion flag:
     *                          - 0: Prices exclude tax (tax-exclusive)
     *                          - 1: Prices include tax (tax-inclusive)
     * @param int $id_shop Shop ID for multi-shop support:
     *                     - 0: Global configuration
     *                     - >0: Shop-specific configuration
     *
     * @return bool|null True on success (implicit return), false on validation failure
     *
     * @see update() For modifying existing language-currency configurations
     * @see remove() For deleting language-currency configurations
     */
    public static function add($id_lang, $id_currency, $tax_included, $id_shop)
    {
        // Validation: ensure required language and shop IDs are provided
        if (empty($id_lang) || empty($id_shop)) {
            return false;
        }

        // Insert new language-currency configuration record
        Db::getInstance()->insert(
            'gshoppingflux_lc',
            [
                'id_glang' => (int) $id_lang,
                'id_currency' => $id_currency,
                'tax_included' => $tax_included,
                'id_shop' => (int) $id_shop,
            ]
        );
    }

    /**
     * Update an existing language-currency configuration
     *
     * Modifies the currency and tax inclusion settings for a specific
     * language-shop combination. Scoped updates ensure data isolation
     * in multi-shop environments.
     *
     * @param int $id_lang Language ID to identify the configuration
     * @param int $id_currency Updated currency ID
     * @param int $tax_included Updated tax inclusion flag:
     *                          - 0: Prices exclude tax (tax-exclusive)
     *                          - 1: Prices include tax (tax-inclusive)
     * @param int $id_shop Shop ID for scoped update
     *
     * @return bool False on validation failure, true on success (implicit return)
     *
     * @see add() For creating new language-currency configurations
     */
    public static function update($id_lang, $id_currency, $tax_included, $id_shop)
    {
        // Validation: ensure required language and shop IDs are provided
        if (empty($id_lang) || empty($id_shop)) {
            return false;
        }

        // Update configuration with new currency and tax inclusion setting
        Db::getInstance()->update(
            'gshoppingflux_lc',
            [
                'id_currency' => $id_currency, // Updated currency ID
                'tax_included' => $tax_included, // Updated tax inclusion setting
            ],
            // WHERE clause: scope update to specific language and shop
            'id_glang = ' . (int) $id_lang . ' AND id_shop=' . (int) $id_shop
        );
    }

    /**
     * Remove a language-currency configuration
     *
     * Deletes a language-currency pair from the Google Shopping Flux configuration.
     * Operation is scoped to both language and shop IDs for safety in multi-shop environments.
     *
     * After deletion, feeds for that language-currency combination will no longer be generated.
     *
     * @param int $id_lang Language ID to identify the configuration
     * @param int $id_shop Shop ID for scoped deletion
     *
     * @return void
     *
     * @see add() For creating new configurations
     */
    public static function remove($id_lang, $id_shop)
    {
        // Delete configuration record scoped to specific language and shop

        Db::getInstance()->delete(
            'gshoppingflux_lc',
            // WHERE clause: ensure both language and shop match for safety
            'id_glang = ' . (int) $id_lang . ' AND id_shop = ' . (int) $id_shop
        );
    }
}
