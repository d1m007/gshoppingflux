<?php

namespace GShoppingFlux\Traits;

use Configuration;
use DateTime;
use Db;
use Product;
use Shop;

/**
 * GShoppingFlux product reviews feed: a separate XML schema
 * (Google Shopping product reviews) from the standard shopping feed.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */
trait ReviewsFeedTrait
{
    /**
     * Generate reviews XML file
     *
     * Generates XML feed of approved customer product reviews in Google Shopping Reviews format.
     * Includes reviewer information, rating, review date, product identifiers, and review URL.
     * Only exports reviews that are:
     * - Not deleted
     * - Approved/validated by shop
     *
     * @param int $id_shop Shop ID for reviews scope
     * @return array Generation statistics:
     *              - nb_reviews: Total reviews exported
     */
    private function generateReviewsFile($id_shop)
    {
        $this->shop = new Shop($id_shop);
        $this->module_conf = $this->getConfigFieldsValues($id_shop);

        // Init file_path value
        if ($this->module_conf['gen_file_in_root']) {
            $generate_file_path = dirname(__FILE__) . '/../../' . $this->_getOutputFileName(0, 0, $id_shop, false, true);
        } else {
            $generate_file_path = dirname(__FILE__) . '/export/' . $this->_getOutputFileName(0, 0, $id_shop, false, true);
        }

        if ($this->shop->name == 'Prestashop') {
            $this->shop->name = Configuration::get('PS_SHOP_NAME');
        }

        // Google Shopping XML
        $xml = '<?xml version="1.0" encoding="' . self::CHARSET . '" ?>' . "\n";
        $xml .= '<feed xmlns:vc="http://www.w3.org/2007/XMLSchema-versioning" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.google.com/shopping/reviews/schema/product/2.3/product_reviews.xsd">' . "\n";
        $xml .= '<version>2.3</version>' . "\n";

        // Shop name
        $xml .= '<publisher>' . "\n";
        $xml .= '<name>' . htmlspecialchars(Configuration::get('PS_SHOP_NAME'), self::REPLACE_FLAGS, self::CHARSET, false) . '</name>' . "\n";
        $xml .= '</publisher>' . "\n";

        $googleshoppingfile = fopen($generate_file_path, 'w');

        // Add UTF-8 byte order mark
        fwrite($googleshoppingfile, pack('CCC', 0xEF, 0xBB, 0xBF));

        // File header
        fwrite($googleshoppingfile, $xml);

        $xml = '<reviews>' . "\n";

        $sql = 'SELECT pc.`id_product_comment`, pc.`id_product`, c.id_customer AS customer_id,
                IF(c.id_customer, CONCAT(c.`firstname`, \' \',  c.`lastname`), pc.customer_name) customer_name,
                IF(c.id_customer, 0, 1) anonymous, pc.`title`, pc.`content`, pc.`grade`, pc.`date_add`
            FROM ' . _DB_PREFIX_ . 'product_comment pc
            LEFT JOIN ' . _DB_PREFIX_ . 'customer c ON pc.id_customer = c.id_customer
            WHERE pc.deleted = 0 AND pc.validate = 1';

        $comments = Db::getInstance()->executeS($sql);

        foreach ($comments as $comment) {
            $p = new Product($comment['id_product'], false, null, $id_shop, $this->context);

            $xml .= '<review>' . "\n";
            $xml .= '<review_id>' . $comment['id_product_comment'] . '</review_id>' . "\n";
            $xml .= '<reviewer>' . "\n";
            $xml .= '<name is_anonymous="' . $comment['anonymous'] . '">' . htmlspecialchars($comment['customer_name'], self::REPLACE_FLAGS, self::CHARSET, false) . '</name>' . "\n";
            $xml .= '</reviewer>' . "\n";
            $date_add = new DateTime($comment['date_add']);
            $xml .= '<review_timestamp>' . $date_add->format(DATE_ATOM) . '</review_timestamp>' . "\n";
            $xml .= '<title>' . htmlspecialchars($comment['title'], self::REPLACE_FLAGS, self::CHARSET, false) . '</title>' . "\n";
            $xml .= '<content>' . htmlspecialchars($comment['content'], self::REPLACE_FLAGS, self::CHARSET, false) . '</content>' . "\n";
            $product_link = $this->context->link->getProductLink($comment['id_product'], $p->link_rewrite);
            $xml .= '<review_url type="group">' . $product_link . '</review_url>' . "\n";
            $xml .= '<ratings>' . "\n";
            $xml .= '<overall min="1" max="5">' . $comment['grade'] . '</overall>' . "\n";
            $xml .= '</ratings>' . "\n";
            $xml .= '<products>' . "\n";
            $xml .= '<product>' . "\n";
            $xml .= '<product_ids>' . "\n";
            $xml .= '<gtins>' . "\n";
            $xml .= '<gtin>' . $p->ean13 . '</gtin>' . "\n";
            $xml .= '</gtins>' . "\n";
            $xml .= '<skus>' . "\n";
            $xml .= '<sku>' . $p->reference . '</sku>' . "\n";
            $xml .= '</skus>' . "\n";
            $xml .= '</product_ids>' . "\n";
            $xml .= '<product_url>' . $product_link . '</product_url>' . "\n";
            $xml .= '</product>' . "\n";
            $xml .= '</products>' . "\n";

            $xml .= '</review>' . "\n";
        }

        $xml .= '</reviews>' . "\n";
        $xml .= '</feed>';
        fwrite($googleshoppingfile, $xml);
        fclose($googleshoppingfile);

        @chmod($generate_file_path, 0777);

        return [
            'nb_reviews' => count($comments),
        ];
    }
}
