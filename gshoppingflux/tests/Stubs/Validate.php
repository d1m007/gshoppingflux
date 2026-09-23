<?php

/**
 * Minimal stand-in for PrestaShop's Validate class: only
 * isLoadedObject(), used by GCategories::getPath() to detect a Category
 * that failed to load (unknown id).
 */
class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && isset($object->id) && $object->id > 0;
    }
}
