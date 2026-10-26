<?php

/**
 * Minimal stand-in for PrestaShop's Tools class, covering only the
 * static methods this module's unit-tested code calls. Not a behavioral
 * clone of PrestaShop core — just enough to exercise our own logic
 * without a full PrestaShop install.
 */
class Tools
{
    public static function strlen($string, $encoding = 'UTF-8')
    {
        return mb_strlen((string) $string, $encoding);
    }

    public static function substr($string, $start, $length = null, $encoding = 'UTF-8')
    {
        if ($length === null) {
            return mb_substr((string) $string, $start, null, $encoding);
        }

        return mb_substr((string) $string, $start, $length, $encoding);
    }

    public static function strtolower($string)
    {
        return mb_strtolower((string) $string, 'UTF-8');
    }

    public static function strtoupper($string)
    {
        return mb_strtoupper((string) $string, 'UTF-8');
    }

    /** Converts from the default currency: only $currency_to's conversion_rate is applied */
    public static function convertPriceFull($amount, $currency_from = null, $currency_to = null)
    {
        return (float) $amount * ($currency_to ? $currency_to->conversion_rate : 1);
    }

    public static function getValue($key, $defaultValue = false)
    {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $defaultValue;
    }
}
