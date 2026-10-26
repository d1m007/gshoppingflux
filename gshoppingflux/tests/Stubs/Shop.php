<?php

/**
 * Minimal stand-in for PrestaShop's Shop class: only
 * getGroupFromShop(), used by getConfigFieldsValues().
 */
class Shop
{
    public static function getGroupFromShop($id_shop, $as_id = true)
    {
        return 1;
    }
}
