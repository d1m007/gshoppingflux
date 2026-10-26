<?php

/**
 * Minimal stand-in for PrestaShop's Configuration class: get() reads from
 * an in-memory array a test fills via self::$values (or updateValue()),
 * and returns false for a key that was never stored, like the real one.
 */
class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    public static function get($key, $id_lang = null, $id_shop_group = null, $id_shop = null, $default = false)
    {
        $value = array_key_exists($key, self::$values) ? self::$values[$key] : $default;

        return is_array($value) ? (isset($value[$id_lang]) ? $value[$id_lang] : $default) : $value;
    }

    /** Stores the value the way PrestaShop does: as a string (false => '') */
    public static function updateValue($key, $values, $html = false, $id_shop_group = null, $id_shop = null)
    {
        self::$values[$key] = is_array($values) ? $values : (string) $values;

        return true;
    }
}
