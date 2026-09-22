<?php

include dirname(__FILE__) . '/../../config/config.inc.php';
include dirname(__FILE__) . '/../../init.php';
include dirname(__FILE__) . '/gshoppingflux.php';

/**
 * Strict boolean parsing for query-string flags: only "1"/"true"/"yes"
 * (case-insensitive) are true, everything else (including "0", "false",
 * "no", or an empty value) is false.
 */
function gshoppingfluxCronBoolParam($name)
{
    if (!Tools::getIsset($name)) {
        return false;
    }

    return in_array(strtolower((string) Tools::getValue($name)), ['1', 'true', 'yes'], true);
}

$start = (float) array_sum(explode(' ', microtime()));

try {
    $module = new GShoppingFlux();
    $shop_id = Shop::getContextShopID();
    $local_inventory = gshoppingfluxCronBoolParam('local');
    $reviews = gshoppingfluxCronBoolParam('reviews');

    $result = $module->generateShopFileList($shop_id, $local_inventory, $reviews);
} catch (Exception $e) {
    http_response_code(500);
    exit('KO, export failed: ' . $e->getMessage());
}

if (empty($result)) {
    http_response_code(500);
    exit('KO, export completed with no file generated. Check the language/currency configuration for this shop.');
}

$end = (float) array_sum(explode(' ', microtime()));
exit('OK, export completed successfully. ' . ($end - $start) . 'sec');
