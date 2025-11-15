<?php

/**
 * Array Helper
 *
 * Utility functions for array operations
 *
 * @author Dim00z
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Helper class for array operations
 */
class ArrayHelper
{
    /**
     * Get array value from POST/GET data
     *
     * @param string $key Array key
     * @param mixed $defaultValue Default value if not found
     * @return string Imploded array or empty string
     */
    public static function getArrayValue($key, $defaultValue = false)
    {
        $array = Tools::getValue($key, $defaultValue);
        return is_array($array) ? implode(';', $array) : '';
    }

    /**
     * Get value from POST/GET data
     *
     * @param string $key Value key
     * @param mixed $defaultValue Default value if not found
     * @return mixed Value
     */
    public static function getValue($key, $defaultValue = false)
    {
        return Tools::getValue($key, $defaultValue);
    }

    /**
     * Explode string to array, filter empty values
     *
     * @param string $string String to explode
     * @param string $delimiter Delimiter
     * @return array Filtered array
     */
    public static function explodeAndFilter($string, $delimiter = ';')
    {
        if (empty($string)) {
            return [];
        }

        $array = explode($delimiter, $string);
        return array_filter($array, function ($value) {
            return !empty($value);
        });
    }

    /**
     * Safely implode array
     *
     * @param array $array Array to implode
     * @param string $delimiter Delimiter
     * @return string Imploded string
     */
    public static function safeImplode($array, $delimiter = ';')
    {
        if (!is_array($array)) {
            return '';
        }

        $filtered = array_filter($array, function ($value) {
            return $value !== '' && $value !== null;
        });

        return implode($delimiter, $filtered);
    }

    /**
     * Get array column (polyfill for PHP < 5.5)
     *
     * @param array $array Input array
     * @param mixed $columnKey Column key to extract
     * @param mixed $indexKey Optional index key
     * @return array Column values
     */
    public static function getColumn($array, $columnKey, $indexKey = null)
    {
        if (function_exists('array_column')) {
            return array_column($array, $columnKey, $indexKey);
        }

        // Polyfill for older PHP versions
        $result = [];

        foreach ($array as $row) {
            $key = $value = null;
            $keySet = $valueSet = false;

            if ($indexKey !== null && array_key_exists($indexKey, $row)) {
                $keySet = true;
                $key = (string)$row[$indexKey];
            }

            if ($columnKey === null) {
                $valueSet = true;
                $value = $row;
            } elseif (is_array($row) && array_key_exists($columnKey, $row)) {
                $valueSet = true;
                $value = $row[$columnKey];
            }

            if ($valueSet) {
                if ($keySet) {
                    $result[$key] = $value;
                } else {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Check if array is associative
     *
     * @param array $array Array to check
     * @return bool Is associative
     */
    public static function isAssociative($array)
    {
        if (!is_array($array) || empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $array Array to search
     * @param string $key Dot-notated key
     * @param mixed $default Default value
     * @return mixed Value or default
     */
    public static function getNestedValue($array, $key, $default = null)
    {
        if (!is_array($array)) {
            return $default;
        }

        // Direct key access (optimization)
        if (isset($array[$key])) {
            return $array[$key];
        }

        // Nested key access using dot notation
        $segments = explode('.', $key);

        foreach ($segments as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array ?? $default;
    }
}
