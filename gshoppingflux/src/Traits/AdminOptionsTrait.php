<?php

namespace GShoppingFlux\Traits;

use Carrier;
use Configuration;
use Country;
use GShoppingFlux\ArrayHelper;
use GShoppingFlux\GCategories;
use GShoppingFlux\GLangAndCurrency;
use HelperForm;
use ImageType;
use Language;
use Shop;
use Tools;

/**
 * GShoppingFlux admin configuration screen: the dispatcher, the
 * save*() handlers, and the main options / local inventory / reviews
 * forms.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */
trait AdminOptionsTrait
{
    public function getContent()
    {
        $id_lang = $this->context->language->id;
        $languages = $this->context->controller->getLanguages();
        $shops = Shop::getShops(true, null, true);
        $shop_id = $this->context->shop->id;
        $shop_group_id = Shop::getGroupFromShop($shop_id);

        // Check for multishop restrictions
        if (count($shops) > 1 && Shop::getContext() != 1) {
            $this->_html .= $this->getWarningMultishopHtml();
            return $this->_html;
        }

        // Display current shop info
        if (Shop::isFeatureActive()) {
            $this->_html .= $this->getCurrentShopInfoMsg();
        }

        // Handle form submissions
        $this->processFormSubmissions($languages, $shop_id, $shop_group_id);

        // Render forms and lists
        $this->renderAdminContent();

        return $this->_html;
    }

    /**
     * Process admin form submissions
     *
     * @param array $languages Array of available languages
     * @param int $shop_id Current shop ID
     * @param int $shop_group_id Current shop group ID
     * @return void
     */
    private function processFormSubmissions($languages, $shop_id, $shop_group_id)
    {
        // Process main flux options
        if (Tools::isSubmit('submitFluxOptions')) {
            $this->saveFluxOptions($languages, $shop_id, $shop_group_id);
        }
        // Process local inventory options
        elseif (Tools::isSubmit('submitLocalInventoryFluxOptions')) {
            $this->saveLocalInventoryOptions($shop_id, $shop_group_id);
        }
        // Process reviews export
        elseif (Tools::isSubmit('submitReviewsFluxOptions')) {
            $this->confirm = $this->l('The settings have been updated.');
            $this->generateXMLFiles(0, $shop_id, $shop_group_id, false, true);
        }
        // Process category update
        elseif (Tools::isSubmit('updateCategory')) {
            $this->saveCategory($shop_group_id, $shop_id);
        }
        // Process language update
        elseif (Tools::isSubmit('updateLanguage')) {
            $this->saveLanguage($shop_id, $shop_group_id);
        }
    }

    /**
     * Save flux (main export) options
     *
     * @param array $languages Available languages
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return void
     */

    /**
     * Report the outcome of a Configuration::updateValue() batch: an error
     * message on failure, or a confirmation plus a feed regeneration on
     * success. Shared by saveFluxOptions() and saveLocalInventoryOptions().
     */
    private function reportSaveResult($updated, $shop_id, $shop_group_id, $local_inventory = false)
    {
        if (!$updated) {
            $shop = new Shop($shop_id);
            $this->_html .= $this->displayError(sprintf($this->l('Unable to update settings for shop: %s'), $shop->name));

            return;
        }

        $this->confirm = $this->l('The settings have been updated.');
        $this->generateXMLFiles(0, $shop_id, $shop_group_id, $local_inventory);
        $this->_html .= $this->displayConfirmation($this->confirm);
    }

    private function saveFluxOptions($languages, $shop_id, $shop_group_id)
    {
        $updated = true;

        // Build product type array
        $product_type = [];
        $product_type_lang = Tools::getValue('product_type');
        foreach ($languages as $k => $lang) {
            $product_type[$lang['id_lang']] = $product_type_lang[$k];
        }

        // Whitelist incoming values backed by a fixed option list
        $description = Tools::getValue('description');
        if (!in_array($description, self::VALID_DESCRIPTIONS, true)) {
            $description = 'short';
        }
        $shipping_mode = Tools::getValue('shipping_mode');
        if (!in_array($shipping_mode, self::VALID_SHIPPING_MODES, true)) {
            $shipping_mode = 'none';
        }
        $mpn_type = Tools::getValue('mpn_type');
        if (!in_array($mpn_type, self::VALID_MPN_TYPES, true)) {
            $mpn_type = 'reference';
        }
        $gender = Tools::getValue('gender');
        if (!in_array($gender, self::VALID_GENDERS, true)) {
            $gender = '';
        }
        $age_group = Tools::getValue('age_group');
        if (!in_array($age_group, self::VALID_AGE_GROUPS, true)) {
            $age_group = '';
        }

        // Update all configuration values
        $updated &= Configuration::updateValue('GS_PRODUCT_TYPE', $product_type, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_DESCRIPTION', $description, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_MODE', $shipping_mode, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_PRICE', (float) Tools::getValue('shipping_price'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_COUNTRY', Tools::getValue('shipping_country'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_COUNTRIES', ArrayHelper::safeImplode((array) Tools::getValue('shipping_countries')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_CARRIERS_EXCLUDED', ArrayHelper::safeImplode((array) Tools::getValue('carriers_excluded')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_IMG_TYPE', Tools::getValue('img_type'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_MPN_TYPE', $mpn_type, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_GENDER', $gender, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_AGE_GROUP', $age_group, false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_ATTRIBUTES', Tools::getValue('export_attributes'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_COLOR', ArrayHelper::safeImplode((array) Tools::getValue('color')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_MATERIAL', ArrayHelper::safeImplode((array) Tools::getValue('material')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_PATTERN', ArrayHelper::safeImplode((array) Tools::getValue('pattern')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SIZE', ArrayHelper::safeImplode((array) Tools::getValue('size')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_EXPORT_MIN_PRICE', (float) Tools::getValue('export_min_price'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_NO_GTIN', (bool) Tools::getValue('no_gtin'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_SHIPPING_DIMENSION', (bool) Tools::getValue('shipping_dimension'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_NO_BRAND', (bool) Tools::getValue('no_brand'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_ID_EXISTS_TAG', (bool) Tools::getValue('id_exists_tag'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_EXPORT_NAP', (bool) Tools::getValue('export_nap'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_QUANTITY', (bool) Tools::getValue('quantity'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_FEATURED_PRODUCTS', (bool) Tools::getValue('featured_products'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_GEN_FILE_IN_ROOT', (bool) Tools::getValue('gen_file_in_root'), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_FILE_PREFIX', trim(Tools::getValue('file_prefix')), false, (int) $shop_group_id, (int) $shop_id);
        $updated &= Configuration::updateValue('GS_AUTOEXPORT_ON_SAVE', (bool) Tools::getValue('autoexport_on_save'), false, (int) $shop_group_id, (int) $shop_id);

        $this->reportSaveResult($updated, $shop_id, $shop_group_id);
    }

    /**
     * Save local inventory options
     *
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return void
     */
    private function saveLocalInventoryOptions($shop_id, $shop_group_id)
    {
        $updated = Configuration::updateValue('GS_LOCAL_SHOP_CODE', Tools::getValue('store_code'), false, (int) $shop_group_id, (int) $shop_id);

        $this->reportSaveResult($updated, $shop_id, $shop_group_id, true);
    }

    /**
     * Save category mapping update
     *
     * @param int $shop_group_id Shop group ID
     * @param int $shop_id Shop ID
     * @return void
     */
    private function saveCategory($shop_group_id, $shop_id)
    {
        $id_gcategory = (int) Tools::getValue('id_gcategory', 0);
        $export = (int) Tools::getValue('export', 0);
        $condition = Tools::getValue('condition');
        if (!in_array($condition, self::VALID_CONDITIONS, true)) {
            $condition = '';
        }
        $availability = Tools::getValue('availability');
        if (!in_array($availability, self::VALID_AVAILABILITY, true)) {
            $availability = '';
        }
        $gender = Tools::getValue('gender');
        if (!in_array($gender, self::VALID_GENDERS, true)) {
            $gender = '';
        }
        $age_group = Tools::getValue('age_group');
        if (!in_array($age_group, self::VALID_AGE_GROUPS, true)) {
            $age_group = '';
        }
        $color = ArrayHelper::safeImplode((array) Tools::getValue('color'));
        $material = ArrayHelper::safeImplode((array) Tools::getValue('material'));
        $pattern = ArrayHelper::safeImplode((array) Tools::getValue('pattern'));
        $size = ArrayHelper::safeImplode((array) Tools::getValue('size'));

        if (Tools::isSubmit('updatecateg')) {
            $gcateg = [];
            foreach (Language::getLanguages(false) as $lang) {
                $gcateg[$lang['id_lang']] = Tools::getValue('gcategory_' . (int) $lang['id_lang']);
            }

            GCategories::update($id_gcategory, $gcateg, $export, $condition, $availability, $gender, $age_group, $color, $material, $pattern, $size, $shop_id);
            $this->confirm = $this->l('Google category has been updated.');
        }

        // Auto-export if enabled
        if (Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id) == 1) {
            $this->generateXMLFiles(0, $shop_id, $shop_group_id);
        }

        $this->_html .= $this->displayConfirmation($this->confirm);
    }

    /**
     * Save language-currency configuration
     *
     * @param int $shop_id Shop ID
     * @param int $shop_group_id Shop group ID
     * @return void
     */
    private function saveLanguage($shop_id, $shop_group_id)
    {
        $id_glang = (int) Tools::getValue('id_glang', 0);
        $currencies = ArrayHelper::safeImplode((array) Tools::getValue('currencies'));
        $tax_included = (int) Tools::getValue('tax_included', 0);
        $export = (int) Tools::getValue('active', 0);

        if (Tools::isSubmit('updatelang')) {
            GLangAndCurrency::update($id_glang, $currencies, $tax_included, (int) Shop::getContextShopID());
            $this->confirm = $this->l('Language configuration has been saved.');
        }

        if ($export && Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id) == 1) {
            $this->generateXMLFiles($id_glang, $shop_id, $shop_group_id);
        } else {
            $this->_html .= $this->displayConfirmation($this->confirm);
        }
    }

    /**
     * Render admin panel content
     *
     * @return void
     */
    private function renderAdminContent()
    {
        $id_lang = $this->context->language->id;
        $shop_id = $this->context->shop->id;

        // Check if categories exist
        $categories = GCategories::gets((int) $id_lang, null, (int) $shop_id);
        if (!count($categories)) {
            return;
        }

        // Determine which forms/lists to display
        if ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !Tools::getValue('updategshoppingflux')) {
            $this->_html .= $this->renderCategForm();
            $this->_html .= $this->renderCategList();
        } elseif ((Tools::getIsset('updategshoppingflux_lc') || Tools::getIsset('statusgshoppingflux_lc')) && !Tools::getValue('updategshoppingflux_lc')) {
            $this->_html .= $this->renderLangForm();
            $this->_html .= $this->renderLangList();
        } else {
            $this->_html .= $this->renderForm();
            $this->_html .= $this->renderLocalInventoryForm();
            $this->_html .= $this->renderReviewsForm();
            $this->_html .= $this->renderCategList();
            $this->_html .= $this->renderLangList();
            $this->_html .= $this->renderInfo();
        }
    }

    // ============================================================
    // HELPER METHODS - FORM RENDERING
    // ============================================================

    /**
     * Display warning for multishop context
     *
     * @return string HTML warning message
     */
    private function getWarningMultishopHtml()
    {
        return '<p class="alert alert-warning">' . $this->l('You cannot manage Google categories from a "All Shops" or a "Group Shop" context, select directly the shop you want to edit') . '</p>';
    }

    /**
     * Display current shop info message
     *
     * @return string HTML info message
     */
    private function getCurrentShopInfoMsg()
    {
        $shop_info = null;

        if (Shop::getContext() == Shop::CONTEXT_SHOP) {
            $shop_info = sprintf($this->l('The modifications will be applied to shop: %s'), $this->context->shop->name);
        } elseif (Shop::getContext() == Shop::CONTEXT_GROUP) {
            $shop_info = sprintf($this->l('The modifications will be applied to this group: %s'), Shop::getContextShopGroup()->name);
        } else {
            $shop_info = $this->l('The modifications will be applied to all shops');
        }

        return '<div class="alert alert-info">' . $shop_info . '</div>';
    }
    /**
     * Build a HelperForm 'switch' (Enabled/Disabled) field definition.
     *
     * Collapses the boilerplate repeated across every boolean option in
     * renderForm()/renderCategForm()/renderLangForm() into one place.
     *
     * @param string $name Field name
     * @param string $label Field label
     * @param string|null $desc Optional field description
     * @param array $options Optional overrides: 'on_id'/'off_id' for the
     *                       'values' ids (default active_on/active_off),
     *                       'disabled' => true to render it read-only
     * @return array HelperForm field definition
     */
    private function boolSwitchField($name, $label, $desc = null, array $options = [])
    {
        $onId = isset($options['on_id']) ? $options['on_id'] : 'active_on';
        $offId = isset($options['off_id']) ? $options['off_id'] : 'active_off';

        $field = [
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'is_bool' => true,
            'values' => [
                [
                    'id' => $onId,
                    'value' => 1,
                    'label' => $this->l('Enabled'),
                ],
                [
                    'id' => $offId,
                    'value' => 0,
                    'label' => $this->l('Disabled'),
                ],
            ],
        ];

        if ($desc !== null) {
            $field['desc'] = $desc;
        }

        if (!empty($options['disabled'])) {
            $field['disabled'] = true;
        }

        return $field;
    }

    /**
     * Render main configuration form
     *
     * Generates the main admin panel form with all module configuration options
     * including product type, shipping, image type, attributes, and export settings.
     *
     * @return string Generated HTML form
     */
    public function renderForm()
    {
        // Initialize form helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues($this->context->shop->id),
            'id_language' => $this->context->language->id,
            'languages' => $this->context->controller->getLanguages(),
        ];

        // Get shop data for form options
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;
        $img_types = ImageType::getImagesTypes('products');

        // Build feature selection options
        $features = [
            [
                'id_feature' => '',
                'name' => $this->l('Product feature doesn\'t exist'),
            ],
        ];
        $features = array_merge($features, $this->getShopFeatures($id_lang, $id_shop));

        // Build description type options
        $descriptions = [
            [
                'id_desc' => 'short',
                'name' => $this->l('Short description'),
            ],
            [
                'id_desc' => 'long',
                'name' => $this->l('Long description'),
            ],
            [
                'id_desc' => 'short+long',
                'name' => $this->l('Short and long description'),
            ],
            [
                'id_desc' => 'meta',
                'name' => $this->l('Meta description'),
            ],
        ];

        // Build MPN type options
        $mpn_types = [
            [
                'id_mpn' => 'reference',
                'name' => $this->l('Reference'),
            ],
            [
                'id_mpn' => 'supplier_reference',
                'name' => $this->l('Supplier reference'),
            ],
        ];

        // Form description with helpful links and instructions
        $form_desc = html_entity_decode($this->l('Please visit and read the <a href="http://support.google.com/merchants/answer/188494" target="_blank">Google Shopping Products Feed Specification</a> if you don\'t know how to configure these options. <br/> If all your shop products match the same Google Shopping category, you can attach it to your home category in the table below, sub-categories will automatically get the same setting. No need to fill each Google category field. <br/> Products in categories with no Google category specified are exported in the Google Shopping category linked to the nearest parent.'));


        // Build form fields array
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Parameters'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    // Product type field
                    [
                        'type' => 'text',
                        'label' => $this->l('Default product type'),
                        'name' => 'product_type[]',
                        // 'class' => 'fixed-width-xl',
                        'lang' => true,
                        'desc' => $this->l('Your shop\'s default product type, ie: if you sell pants and shirts, and your main categories are "Men", "Women", "Kids", enter "Clothing" here. That will be exported as your shop main category. This setting is optional and can be left empty. Besides the module requires that at least main category of your shop is correctly linked to a Google product category.'),
                    ],
                    // Description type selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Description type'),
                        'name' => 'description',
                        'default_value' => $helper->tpl_vars['fields_value']['description'],
                        'options' => [
                            // 'default' => array('value' => 0, 'label' => $this->l('Choose description type')),
                            'query' => $descriptions,
                            'id' => 'id_desc',
                            'name' => 'name',
                        ],
                    ],
                    // Shipping method selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Shipping Methods'),
                        'name' => 'shipping_mode',
                        'options' => [
                            'query' => [
                                [
                                    'id_mode' => 'none',
                                    'name' => $this->l('No shipping method'),
                                ],
                                [
                                    'id_mode' => 'fixed',
                                    'name' => $this->l('Price fixed'),
                                ],
                                [
                                    'id_mode' => 'full',
                                    'name' => $this->l('Generate shipping costs in several countries [EXPERIMENTAL]'),
                                ],
                            ],
                            'id' => 'id_mode',
                            'name' => 'name',
                        ],
                    ],
                    // Fixed shipping price
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping price'),
                        'name' => 'shipping_price',
                        'class' => 'fixed-width-xs',
                        'prefix' => $this->context->currency->sign,
                        'desc' => $this->l('This field is used for "Price fixed".'),
                    ],
                    // Shipping country field
                    [
                        'type' => 'text',
                        'label' => $this->l('Shipping country'),
                        'name' => 'shipping_country',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->l('This field is used for "Price fixed".'),
                    ],
                    // Multi-country shipping selection
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Shipping countries'),
                        'name' => 'shipping_countries[]',
                        'options' => [
                            'query' => array_merge([
                                [
                                    'id_country' => 'all',
                                    'name' => $this->l('All'),
                                ],
                            ], Country::getCountries($this->context->language->id, true)),
                            'id' => 'id_country',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('This field is used for "Generate shipping costs in several countries". Hold [Ctrl] key pressed to select multiple country.'),
                    ],
                    // Carrier exclusion selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Carriers to exclude'),
                        'name' => 'carriers_excluded[]',
                        'options' => [
                            'query' => array_merge([
                                [
                                    'id_carrier' => 'no',
                                    'name' => $this->l('No'),
                                ],
                            ], Carrier::getCarriers($this->context->language->id, false, false, null, null, Carrier::ALL_CARRIERS)),
                            'id' => 'id_carrier',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('This field is used for "Generate shipping costs in several countries". Hold [Ctrl] key pressed to select multiple carriers.'),
                    ],
                    // Image type selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Images type'),
                        'name' => 'img_type',
                        'default_value' => $helper->tpl_vars['fields_value']['img_type'],
                        'options' => [
                            // 'default' => array('value' => 0, 'label' => $this->l('Choose image type')),
                            'query' => $img_types,
                            'id' => 'name',
                            'name' => 'name',
                        ],
                    ],
                    // MPN type selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Manufacturers References type (MPN)'),
                        'name' => 'mpn_type',
                        'default_value' => $helper->tpl_vars['fields_value']['mpn_type'],
                        'options' => [
                            'query' => $mpn_types,
                            'id' => 'id_mpn',
                            'name' => 'name',
                        ],
                    ],
                    // Minimum price filter
                    [
                        'type' => 'text',
                        'label' => $this->l('Minimum product price'),
                        'name' => 'export_min_price',
                        'class' => 'fixed-width-xs',
                        'prefix' => $this->context->currency->sign,
                        'desc' => $this->l('Products at lower price are not exported. Enter 0.00 for no use.'),
                        'required' => true,
                    ],
                    // Gender feature selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Products gender feature'),
                        'name' => 'gender',
                        'default_value' => $helper->tpl_vars['fields_value']['gender'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                    ],
                    // Age group feature selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Products age group feature'),
                        'name' => 'age_group',
                        'default_value' => $helper->tpl_vars['fields_value']['age_group'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                    ],
                    // Color feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products color feature'),
                        'name' => 'color[]',
                        'default_value' => $helper->tpl_vars['fields_value']['color[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple color features.'),
                    ],
                    // Material feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products material feature'),
                        'name' => 'material[]',
                        'default_value' => $helper->tpl_vars['fields_value']['material[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple material features.'),
                    ],
                    // Pattern feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products pattern feature'),
                        'name' => 'pattern[]',
                        'default_value' => $helper->tpl_vars['fields_value']['pattern[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple pattern features.'),
                    ],
                    // Size feature multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products size feature'),
                        'name' => 'size[]',
                        'default_value' => $helper->tpl_vars['fields_value']['size[]'],
                        'options' => [
                            'query' => $features,
                            'id' => 'id_feature',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple size features.'),
                    ],
                    // Export attributes toggle
                    $this->boolSwitchField(
                        'export_attributes',
                        $this->l('Export attributes combinations'),
                        $this->l('If checked, one product is exported for each attributes combination. Products should have at least one attribute filled in order to be exported as combinations.')
                    ),
                    // GTIN export toggle
                    $this->boolSwitchField(
                        'no_gtin',
                        $this->l('Export products with no GTIN code'),
                        $this->l('Allow export of products, that no not have a GTIN code (EAN13/UPC)')
                    ),
                    // Shipping dimensions export toggle
                    $this->boolSwitchField(
                        'shipping_dimension',
                        $this->l('Export products shipping dimensions'),
                        $this->l('Allow export of dimension for each products, if typed in product details')
                    ),
                    // No brand products toggle
                    $this->boolSwitchField(
                        'no_brand',
                        $this->l('Export products with no brand'),
                        $this->l('Allow export of products, that no not have a brand (Manufacturer)')
                    ),
                    // Identifier exists tag toggle
                    $this->boolSwitchField(
                        'id_exists_tag',
                        $this->l('Set <identifier_exists> tag to FALSE'),
                        $this->l('If your product is new (which you submit through the condition attribute) and it doesn’t have a gtin and brand or mpn and brand.') . ' <a href="https://support.google.com/merchants/answer/6324478?hl=en" target="_blank">' . $this->l('identifier_exists: Definition') . '</a>'
                    ),
                    // Non-available products export toggle
                    $this->boolSwitchField('export_nap', $this->l('Export non-available products')),
                    // Quantity export toggle
                    $this->boolSwitchField('quantity', $this->l('Export product quantity')),
                    // On sale indicator export toggle
                    $this->boolSwitchField('featured_products', $this->l('Export "On Sale" indication')),
                    // File generation location toggle
                    $this->boolSwitchField('gen_file_in_root', $this->l('Generate the files to the root of the site')),
                    // File prefix field
                    [
                        'type' => 'text',
                        'label' => $this->l('prefix for output filename'),
                        'name' => 'file_prefix',
                        'class' => 'fixed-width-lg',
                        'desc' => $this->l('Allows you to prefix feed filename. Makes it a little harder for other to guess your feed names'),
                    ],
                    // Auto-export toggle
                    $this->boolSwitchField(
                        'autoexport_on_save',
                        $this->l('Automatic export on saves?'),
                        $this->l('When disabled, you have to "Save & Export" manually or run the CRON job, to generate new files.')
                    ),
                ],
                'description' => $form_desc,
                'submit' => [
                    'name' => 'submitFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Render local inventory form
     *
     * Generates admin form for local inventory feed configuration.
     * Allows setting the store code for local inventory exports.
     *
     * @return string Generated HTML form
     */
    public function renderLocalInventoryForm()
    {
        // Initialize form helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigLocalInventoryFieldsValues($this->context->shop->id),
            'id_language' => $this->context->language->id,
            'languages' => $this->context->controller->getLanguages(),
        ];

        // Build form fields
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Local Inventory Parameters'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Your store code'),
                        'name' => 'store_code',
                        'desc' => $this->l('Your store code'),
                    ]
                ],
                'submit' => [
                    'name' => 'submitLocalInventoryFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Render reviews export form
     *
     * Generates admin form for customer reviews export configuration.
     * Provides option to export product reviews to Google Shopping.
     *
     * @return string Generated HTML form
     */
    public function renderReviewsForm()
    {
        // Initialize form helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [],
            'id_language' => $this->context->language->id,
            'languages' => $this->context->controller->getLanguages(),
        ];

        // Build form fields - simple form with just export button
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Reviews'),
                    'icon' => 'icon-cogs',
                ],
                'submit' => [
                    'name' => 'submitReviewsFluxOptions',
                    'title' => $this->l('Save & Export'),
                ],
            ],
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Get configuration field values for main flux form
     *
     * Retrieves all stored configuration values for the module and formats them
     * for display in admin forms. Handles multi-language values and arrays.
     *
     * @param int $shop_id Shop ID to retrieve config for
     * @return array Associative array of configuration field values
     */
    public function getConfigFieldsValues($shop_id)
    {
        $shop_group_id = Shop::getGroupFromShop($shop_id);

        // Initialize all variables with default values
        $product_type = [];
        $description = 'short';
        $shipping_price_fixed = true;
        $shipping_mode = 'fixed';
        $shipping_price = 0;
        $shipping_country = 'UK';
        $shipping_countries = 'all';
        $img_type = 'large_default';
        $mpn_type = '';
        $gender = '';
        $age_group = '';
        $export_attributes = '';
        $color = [];
        $material = [];
        $pattern = [];
        $size = [];
        $export_min_price = 0;
        $no_gtin = true;
        $shipping_dimension = true;
        $no_brand = true;
        $id_exists_tag = true;
        $export_nap = true;
        $quantity = true;
        $featured_products = true;
        $gen_file_in_root = true;
        $autoexport_on_save = true;
        $file_prefix = '';

        // Retrieve multi-language product types
        foreach (Language::getLanguages(false) as $lang) {
            $product_type[$lang['id_lang']] = Configuration::get('GS_PRODUCT_TYPE', $lang['id_lang'], $shop_group_id, $shop_id);
        }

        // Retrieve all configuration values
        $description = Configuration::get('GS_DESCRIPTION', 0, $shop_group_id, $shop_id);
        $shipping_mode = Configuration::get('GS_SHIPPING_MODE', 0, $shop_group_id, $shop_id);
        $shipping_price_fixed &= (bool) Configuration::get('GS_SHIPPING_PRICE_FIXED', 0, $shop_group_id, $shop_id);
        $shipping_price = (float) Configuration::get('GS_SHIPPING_PRICE', 0, $shop_group_id, $shop_id);
        $shipping_country = Configuration::get('GS_SHIPPING_COUNTRY', 0, $shop_group_id, $shop_id);
        $shipping_countries = ArrayHelper::safeExplode(Configuration::get('GS_SHIPPING_COUNTRIES', 0, $shop_group_id, $shop_id));
        $carriers_excluded = ArrayHelper::safeExplode(Configuration::get('GS_CARRIERS_EXCLUDED', 0, $shop_group_id, $shop_id));
        $img_type = Configuration::get('GS_IMG_TYPE', 0, $shop_group_id, $shop_id);
        $mpn_type = Configuration::get('GS_MPN_TYPE', 0, $shop_group_id, $shop_id);
        $gender = Configuration::get('GS_GENDER', 0, $shop_group_id, $shop_id);
        $age_group = Configuration::get('GS_AGE_GROUP', 0, $shop_group_id, $shop_id);
        $export_attributes = Configuration::get('GS_ATTRIBUTES', 0, $shop_group_id, $shop_id);
        $color = ArrayHelper::safeExplode(Configuration::get('GS_COLOR', 0, $shop_group_id, $shop_id));
        $material = ArrayHelper::safeExplode(Configuration::get('GS_MATERIAL', 0, $shop_group_id, $shop_id));
        $pattern = ArrayHelper::safeExplode(Configuration::get('GS_PATTERN', 0, $shop_group_id, $shop_id));
        $size = ArrayHelper::safeExplode(Configuration::get('GS_SIZE', 0, $shop_group_id, $shop_id));
        $export_min_price = (float) Configuration::get('GS_EXPORT_MIN_PRICE', 0, $shop_group_id, $shop_id);
        $no_gtin &= (bool) Configuration::get('GS_NO_GTIN', 0, $shop_group_id, $shop_id);
        $shipping_dimension &= (bool) Configuration::get('GS_SHIPPING_DIMENSION', 0, $shop_group_id, $shop_id);
        $no_brand &= (bool) Configuration::get('GS_NO_BRAND', 0, $shop_group_id, $shop_id);
        $id_exists_tag &= (bool) Configuration::get('GS_ID_EXISTS_TAG', 0, $shop_group_id, $shop_id);
        $export_nap &= (bool) Configuration::get('GS_EXPORT_NAP', 0, $shop_group_id, $shop_id);
        $quantity &= (bool) Configuration::get('GS_QUANTITY', 0, $shop_group_id, $shop_id);
        $featured_products &= (bool) Configuration::get('GS_FEATURED_PRODUCTS', 0, $shop_group_id, $shop_id);
        $gen_file_in_root &= (bool) Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $shop_group_id, $shop_id);
        $autoexport_on_save &= (bool) Configuration::get('GS_AUTOEXPORT_ON_SAVE', 0, $shop_group_id, $shop_id);
        $file_prefix = Configuration::get('GS_FILE_PREFIX', 0, $shop_group_id, $shop_id);

        // Return formatted array for form display
        return [
            'product_type[]' => $product_type,
            'description' => $description,
            'shipping_mode' => $shipping_mode,
            'shipping_price_fixed' => (int) $shipping_price_fixed,
            'shipping_price' => (float) $shipping_price,
            'shipping_country' => $shipping_country,
            'shipping_countries[]' => $shipping_countries,
            'carriers_excluded[]' => $carriers_excluded,
            'img_type' => $img_type,
            'mpn_type' => $mpn_type,
            'gender' => $gender,
            'age_group' => $age_group,
            'export_attributes' => (int) $export_attributes,
            'color[]' => $color,
            'material[]' => $material,
            'pattern[]' => $pattern,
            'size[]' => $size,
            'export_min_price' => (float) $export_min_price,
            'no_gtin' => (int) $no_gtin,
            'shipping_dimension' => (int) $shipping_dimension,
            'no_brand' => (int) $no_brand,
            'id_exists_tag' => (int) $id_exists_tag,
            'export_nap' => (int) $export_nap,
            'quantity' => (int) $quantity,
            'featured_products' => (int) $featured_products,
            'gen_file_in_root' => (int) $gen_file_in_root,
            'file_prefix' => $file_prefix,
            'autoexport_on_save' => (int) $autoexport_on_save,
        ];
    }

    /**
     * Get local inventory configuration field values
     *
     * Retrieves stored local inventory configuration values for form display.
     *
     * @param int $shop_id Shop ID
     * @return array Array with store_code configuration value
     */
    public function getConfigLocalInventoryFieldsValues($shop_id)
    {
        $shop_group_id = Shop::getGroupFromShop($shop_id);
        $store_code = Configuration::get('GS_LOCAL_SHOP_CODE', 0, $shop_group_id, $shop_id);

        return [
            'store_code' => $store_code,
        ];
    }
}
