<?php

/**
 * Minimal stand-in for PrestaShop's Language class: getLanguages(),
 * used by getConfigFieldsValues() for the multi-language product type.
 */
class Language
{
    /** @var array Languages returned by getLanguages() */
    public static $languages = [];

    public static function getLanguages($active = true, $id_shop = false, $ids_only = false)
    {
        return self::$languages;
    }
}
