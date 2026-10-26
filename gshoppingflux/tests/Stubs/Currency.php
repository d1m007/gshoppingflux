<?php

/**
 * Minimal stand-in for PrestaShop's Currency class: only the two public
 * properties the feed reads (conversion_rate, iso_code).
 */
class Currency
{
    public $conversion_rate = 1;
    public $iso_code = 'EUR';

    public function __construct($id = null, $iso_code = 'EUR', $conversion_rate = 1)
    {
        $this->iso_code = $iso_code;
        $this->conversion_rate = $conversion_rate;
    }
}
