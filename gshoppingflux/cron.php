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
    $shop_group_id = Shop::getGroupFromShop($shop_id);
    $local_inventory = gshoppingfluxCronBoolParam('local');
    $reviews = gshoppingfluxCronBoolParam('reviews');

    // GS_CRON_TOKEN is empty by default (unchanged behavior for existing
    // installs/cron jobs). When an employee sets one in the module's
    // settings, cron.php requires a matching ?token=... to run.
    $cron_token = Configuration::get('GS_CRON_TOKEN', 0, $shop_group_id, $shop_id);
    if ($cron_token !== false && $cron_token !== '' && !hash_equals((string) $cron_token, (string) Tools::getValue('token', ''))) {
        http_response_code(403);
        exit('KO, invalid or missing token.');
    }

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
