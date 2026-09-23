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
