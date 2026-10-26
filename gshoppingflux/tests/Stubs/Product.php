<?php

/**
 * Minimal stand-in for PrestaShop's Product class: only what
 * FeedGeneratorTrait::buildAvailabilityXml() calls on it.
 */
class Product
{
    public $out_of_stock = 0;

    /** Whether the product can be ordered when out of stock */
    public $orderableWhenOutOfStock = false;

    public function isAvailableWhenOutOfStock($out_of_stock)
    {
        return $this->orderableWhenOutOfStock;
    }
}
