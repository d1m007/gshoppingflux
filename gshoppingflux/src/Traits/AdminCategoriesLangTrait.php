<?php

namespace GShoppingFlux\Traits;

use Cache;
use Category;
use Configuration;
use Currency;
use Db;
use GShoppingFlux\ArrayHelper;
use GShoppingFlux\GCategories;
use GShoppingFlux\GLangAndCurrency;
use Group;
use HelperForm;
use HelperList;
use Language;
use Shop;
use Tools;
use Validate;

/**
 * GShoppingFlux admin screens for the Google category mapping and the
 * language/currency configuration, plus the small data helpers
 * (features, attributes, category tree) they depend on.
 *
 * @package GShoppingFlux
 * @copyright 2014-2025 Google Shopping Flux Contributors
 * @license Apache License 2.0
 */
trait AdminCategoriesLangTrait
{
    /**
     * Get shop features for attribute selection
     *
     * Retrieves all product features available in a specific shop and language.
     * Used to populate feature selection dropdowns in admin forms.
     *
     * @param int $id_lang Language ID for feature names
     * @param int $id_shop Shop ID to scope features
     * @return array Array of feature objects with id_feature and name
     */
    public function getShopFeatures($id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT fl.* FROM ' . _DB_PREFIX_ . 'feature f
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (fl.id_feature = f.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_shop fs ON (fs.id_feature = f.id_feature)
			WHERE fl.id_lang = ' . (int) $id_lang . ' AND fs.id_shop = ' . (int) $id_shop . '
			ORDER BY f.id_feature ASC');
    }

    /**
     * Get shop attributes for attribute group selection
     *
     * Retrieves all attribute groups available in a specific shop and language.
     * Used to populate attribute selection dropdowns in admin forms.
     *
     * @param int $id_lang Language ID for attribute names
     * @param int $id_shop Shop ID to scope attributes
     * @return array Array of attribute group objects
     */
    public function getShopAttributes($id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT agl.* FROM ' . _DB_PREFIX_ . 'attribute_group_lang agl
			LEFT JOIN ' . _DB_PREFIX_ . 'attribute_group_shop ags ON (ags.id_attribute_group = agl.id_attribute_group)
			WHERE agl.id_lang = ' . (int) $id_lang . ' AND ags.id_shop = ' . (int) $id_shop . '
			ORDER BY ags.id_attribute_group ASC');
    }

    /**
     * Get product features for a specific product
     *
     * Retrieves all feature values associated with a product in a specific language and shop.
     * Used when generating product XML to retrieve gender, age_group, color, etc.
     *
     * @param int $id_product Product ID
     * @param int $id_lang Language ID for feature values
     * @param int $id_shop Shop ID
     * @return array Array of product features with values
     */
    public function getProductFeatures($id_product, $id_lang, $id_shop)
    {
        return Db::getInstance()->executeS('
			SELECT fl.*, fv.value FROM ' . _DB_PREFIX_ . 'feature_product fp
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl ON (fl.id_feature = fp.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_shop fs ON (fs.id_feature = fp.id_feature)
			LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fv ON (fv.id_feature_value = fp.id_feature_value AND fv.id_lang = fl.id_lang)
			WHERE fp.id_product = ' . (int) $id_product . ' AND fl.id_lang = ' . (int) $id_lang . ' AND fs.id_shop = ' . (int) $id_shop . '
			ORDER BY fp.id_feature ASC');
    }
    /**
     * Render category mapping edit form
     *
     * Generates admin form for editing a category's Google Shopping category mapping,
     * condition, availability, and product attributes (gender, age_group, color, etc.).
     *
     * @return string Generated HTML form
     */
    public function renderCategForm()
    {
        // Initialize helper
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $back_url = $helper->currentIndex . '&token=' . $helper->token;
        $helper->fields_value = $this->getGCategFieldsValues();
        $helper->languages = $this->context->controller->getLanguages();
        $helper->tpl_vars = [
            'back_url' => $back_url,
            'show_cancel_button' => true,
        ];
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        // Build condition options
        $conditions = [
            [
                'id_cond' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id_cond' => 'new',
                'name' => $this->l('Category\'s products are new'),
            ],
            [
                'id_cond' => 'used',
                'name' => $this->l('Category\'s products are used'),
            ],
            [
                'id_cond' => 'refurbished',
                'name' => $this->l('Category\'s products are refurbished'),
            ],
        ];

        // Build availability options
        $avail_modes = [
            [
                'id_mode' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id_mode' => 'in stock',
                'name' => $this->l('Category\'s products are in stock'),
            ],
            [
                'id_mode' => 'preorder',
                'name' => $this->l('Category\'s products avail. on preorder'),
            ],
        ];

        // Build gender options
        $gender_modes = [
            [
                'id' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id' => 'male',
                'name' => $this->l('Category\'s products are for men'),
            ],
            [
                'id' => 'female',
                'name' => $this->l('Category\'s products are for women'),
            ],
            [
                'id' => 'unisex',
                'name' => $this->l('Category\'s products are unisex'),
            ],
        ];

        // Build age group options
        $age_modes = [
            [
                'id' => '',
                'name' => $this->l('Default'),
            ],
            [
                'id' => 'newborn',
                'name' => $this->l('Newborn'),
            ],
            [
                'id' => 'infant',
                'name' => $this->l('Infant'),
            ],
            [
                'id' => 'toddler',
                'name' => $this->l('Toddler'),
            ],
            [
                'id' => 'kids',
                'name' => $this->l('Kids'),
            ],
            [
                'id' => 'adult',
                'name' => $this->l('Adult'),
            ],
        ];

        // Get available attributes for selection
        $attributes = [
            [
                'id_attribute_group' => '',
                'name' => $this->l('Products attribute doesn\'t exist'),
            ],
        ];
        $attributes = array_merge($attributes, $this->getShopAttributes($id_lang, $id_shop));

        // Form descriptions
        $gcat_desc = '<a href="http://www.google.com/support/merchants/bin/answer.py?answer=160081&query=product_type" target="_blank">' . $this->l('See Google Categories') . '</a> ';
        $form_desc = html_entity_decode($this->l('Default: System tries to get the value of the product attribute. If not found, system tries to get the category\'s attribute value. <br> If not found, it tries to get the parent category\'s attribute, and so till the root category. At last, if empty, value is not exported.'));

        // Build form fields array
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !ArrayHelper::getValue('updategshoppingflux')) ? $this->l('Update the matching Google category') : $this->l('Add a new Google category'),
                    'icon' => 'icon-link',
                ],
                'input' => [
                    // Category breadcrumb display (read-only)
                    [
                        'type' => 'free',
                        'label' => $this->l('Category'),
                        'name' => 'breadcrumb',
                    ],
                    // Google category name (multi-language)
                    [
                        'type' => 'text',
                        'label' => $this->l('Matching Google category'),
                        'name' => 'gcategory',
                        'lang' => true,
                        'desc' => $gcat_desc,
                    ],
                    // Export toggle
                    $this->boolSwitchField('export', $this->l('Export products from this category')),
                    // Product condition selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Condition'),
                        'name' => 'condition',
                        'default_value' => $helper->fields_value['condition'],
                        'options' => [
                            'query' => $conditions,
                            'id' => 'id_cond',
                            'name' => 'name',
                        ],
                    ],
                    // Product availability selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Products\' availability'),
                        'name' => 'availability',
                        'default_value' => $helper->fields_value['availability'],
                        'options' => [
                            'query' => $avail_modes,
                            'id' => 'id_mode',
                            'name' => 'name',
                        ],
                    ],
                    // Gender attribute selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Gender attribute'),
                        'name' => 'gender',
                        'default_value' => $helper->fields_value['gender'],
                        'options' => [
                            'query' => $gender_modes,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    // Age group selector
                    [
                        'type' => 'select',
                        'label' => $this->l('Age group'),
                        'name' => 'age_group',
                        'default_value' => $helper->fields_value['age_group'],
                        'options' => [
                            'query' => $age_modes,
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    // Color attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products color attribute'),
                        'name' => 'color[]',
                        'default_value' => $helper->fields_value['color[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple color attributes.'),
                    ],
                    // Material attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products material attribute'),
                        'name' => 'material[]',
                        'default_value' => $helper->fields_value['material[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple material attributes.'),
                    ],
                    // Pattern attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products pattern attribute'),
                        'name' => 'pattern[]',
                        'default_value' => $helper->fields_value['pattern[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple pattern attributes.'),
                    ],
                    // Size attribute multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Products size attribute'),
                        'name' => 'size[]',
                        'default_value' => $helper->fields_value['size[]'],
                        'options' => [
                            'query' => $attributes,
                            'id' => 'id_attribute_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple size attributes.'),
                    ],
                ],
                'description' => $form_desc,
                'submit' => [
                    'name' => 'submitCategory',
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        // Update button instead of save when editing
        if ((Tools::getIsset('updategshoppingflux') || Tools::getIsset('statusgshoppingflux')) && !ArrayHelper::getValue('updategshoppingflux')) {
            $fields_form['form']['submit'] = [
                'name' => 'updateCategory',
                'title' => $this->l('Update'),
            ];
        }

        // Add hidden fields for update operations
        if (Tools::isSubmit('updategshoppingflux') || Tools::isSubmit('statusgshoppingflux')) {
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'updatecateg',
            ];
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'id_gcategory',
            ];
            $helper->fields_value['updatecateg'] = '';
        }

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Get Google category form field values
     *
     * Retrieves existing Google category configuration for form population.
     * Used when editing an existing category mapping.
     *
     * @return array Array of form field values
     */
    public function getGCategFieldsValues()
    {
        $gcatexport_active = '';
        $gcatcondition_edit = '';
        $gcatavail_edit = '';
        $gcatgender_edit = '';
        $gcatage_edit = '';
        $gcatcolor_edit = '';
        $gcatmaterial_edit = '';
        $gcatpattern_edit = '';
        $gcatsize_edit = '';
        $gcategory_edit = '';
        $gcatlabel_edit = '';

        // Load existing values if editing
        if (Tools::isSubmit('updategshoppingflux') || Tools::isSubmit('statusgshoppingflux')) {
            $id_lang = $this->context->cookie->id_lang;
            $gcateg = GCategories::getCategLang(ArrayHelper::getValue('id_gcategory'), (int) Shop::getContextShopID(), $id_lang);

            // Decode HTML entities in category names
            foreach ($gcateg['gcategory'] as $key => $categ) {
                $gcateg['gcategory'][$key] = Tools::htmlentitiesDecodeUTF8($categ);
            }

            // Extract values from loaded data
            $gcatexport_active = $gcateg['export'];
            $gcatcondition_edit = $gcateg['condition'];
            $gcatavail_edit = $gcateg['availability'];
            $gcatgender_edit = $gcateg['gender'];
            $gcatage_edit = $gcateg['age_group'];
            $gcatcolor_edit = $gcateg['color'];
            $gcatmaterial_edit = $gcateg['material'];
            $gcatpattern_edit = $gcateg['pattern'];
            $gcatsize_edit = $gcateg['size'];
            $gcategory_edit = $gcateg['gcategory'];
            $gcatlabel_edit = $gcateg['breadcrumb'];
        }

        // Build return array with field values
        $fields_values = [
            'id_gcategory' => ArrayHelper::getValue('id_gcategory'),
            'breadcrumb' => (isset($gcatlabel_edit) ? $gcatlabel_edit : ''),
            'export' => ArrayHelper::getValue('export', isset($gcatexport_active) ? $gcatexport_active : ''),
            'condition' => ArrayHelper::getValue('condition', isset($gcatcondition_edit) ? $gcatcondition_edit : ''),
            'availability' => ArrayHelper::getValue('availability', isset($gcatavail_edit) ? $gcatavail_edit : ''),
            'gender' => ArrayHelper::getValue('gender', isset($gcatgender_edit) ? $gcatgender_edit : ''),
            'age_group' => ArrayHelper::getValue('age_group', isset($gcatage_edit) ? $gcatage_edit : ''),
            'color[]' => ArrayHelper::safeExplode(ArrayHelper::getValue('color[]', isset($gcatcolor_edit) ? $gcatcolor_edit : '')),
            'material[]' => ArrayHelper::safeExplode(ArrayHelper::getValue('material[]', isset($gcatmaterial_edit) ? $gcatmaterial_edit : '')),
            'pattern[]' => ArrayHelper::safeExplode(ArrayHelper::getValue('pattern[]', isset($gcatpattern_edit) ? $gcatpattern_edit : '')),
            'size[]' => ArrayHelper::safeExplode(ArrayHelper::getValue('size[]', isset($gcatsize_edit) ? $gcatsize_edit : '')),
        ];

        // Initialize Google category names for all languages
        if (ArrayHelper::getValue('submitAddmodule')) {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['gcategory'][$lang['id_lang']] = '';
            }
        } else {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['gcategory'][$lang['id_lang']] = ArrayHelper::getValue('gcategory_' . (int) $lang['id_lang'], isset($gcategory_edit[$lang['id_lang']]) ? html_entity_decode($gcategory_edit[$lang['id_lang']]) : '');
            }
        }

        return $fields_values;
    }

    /**
     * Get language configuration form field values
     *
     * Retrieves language and currency configuration for form population.
     * Used when editing language-currency mappings.
     *
     * @return array Array of language configuration field values
     */
    public function getGLangFieldsValues()
    {
        // Initialize variables
        $glangcurrency_edit = '';
        $glangtax_included = '';
        $glangexport_active = '';

        // Load existing values if editing
        if (Tools::isSubmit('updategshoppingflux_lc') || Tools::isSubmit('statusgshoppingflux_lc')) {
            $glang = GLangAndCurrency::getLangCurrencies(ArrayHelper::getValue('id_glang'), (int) Shop::getContextShopID());
            $glangcurrency_edit = explode(';', $glang[0]['id_currency']);
            $glangtax_included = $glang[0]['tax_included'];
            $glangexport_active = $glang[0]['active'];
        }

        // Get language data
        $language = Language::getLanguage(ArrayHelper::getValue('id_glang'));

        // Build return array
        $fields_values = [
            'id_glang' => ArrayHelper::getValue('id_glang'),
            'name' => $language['name'],
            'iso_code' => $language['iso_code'],
            'language_code' => $language['language_code'],
            'currencies[]' => ArrayHelper::getValue('currencies[]', $glangcurrency_edit),
            'tax_included' => ArrayHelper::getValue('tax_included', $glangtax_included),
            'active' => ArrayHelper::getValue('active', $glangexport_active),
        ];

        return $fields_values;
    }

    /**
     * Render language-currency configuration form
     *
     * Generates admin form for editing language-currency pair configuration.
     * Allows setting currency and tax inclusion for each language.
     *
     * @return string Generated HTML form
     */
    public function renderLangForm()
    {
        // Initialize form helper
        $this->fields_form = [];
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->table = 'gshoppingflux_lc';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $back_url = $helper->currentIndex . '&token=' . $helper->token;
        $helper->fields_value = $this->getGLangFieldsValues();
        $helper->tpl_vars = [
            'back_url' => $back_url,
            'show_cancel_button' => true,
        ];

        // Get available currencies
        $currencies = Currency::getCurrencies();

        // Form description
        $form_desc = html_entity_decode($this->l('Select currency to export with this language.'));

        // Build form fields
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Language export settings'),
                    'icon' => 'icon-globe',
                ],
                'input' => [
                    // Language name display (read-only)
                    [
                        'type' => 'free',
                        'label' => $this->l('Language'),
                        'name' => 'name',
                    ],
                    // Language code display (read-only)
                    [
                        'type' => 'free',
                        'label' => $this->l('Language code'),
                        'name' => 'language_code',
                    ],
                    // Active toggle (disabled)
                    $this->boolSwitchField('active', $this->l('Enabled'), null, ['disabled' => true]),
                    // Currency multi-selector
                    [
                        'type' => 'select',
                        'multiple' => true,
                        'label' => $this->l('Currencies'),
                        'name' => 'currencies[]',
                        'default_value' => $helper->fields_value['currencies[]'],
                        'options' => [
                            'query' => $currencies,
                            'id' => 'id_currency',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Hold [Ctrl] key pressed to select multiple currencies.'),
                    ],
                    // Tax inclusion toggle
                    $this->boolSwitchField(
                        'tax_included',
                        $this->l('Prices exported tax included'),
                        $this->l('If disabled, prices are exported ex tax.'),
                        ['on_id' => 'inc_tax', 'off_id' => 'ex_tax']
                    ),
                ],
                'description' => $form_desc,
                'submit' => [
                    'name' => 'submitCategory',
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        // Update button instead of 'Save' when editing
        if ((Tools::getIsset('updategshoppingflux_lc') || Tools::getIsset('statusgshoppingflux_lc')) && !ArrayHelper::getValue('updategshoppingflux_lc')) {
            $fields_form['form']['submit'] = [
                'name' => 'updateLanguage',
                'title' => $this->l('Update'),
            ];
        }

        // Add hidden fields for update operations
        if (Tools::isSubmit('updategshoppingflux_lc') || Tools::isSubmit('statusgshoppingflux_lc')) {
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'updatelang',
            ];
            $fields_form['form']['input'][] = [
                'type' => 'hidden',
                'name' => 'id_glang',
            ];
            $helper->fields_value['updatelang'] = '';
        }

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Render language list table
     *
     * Generates a list view table of all language-currency pairs configured
     * for the current shop. Shows language names, codes, currencies, and status.
     *
     * @return string Generated HTML list table
     */
    public function renderLangList()
    {
        // Define table columns
        $fields_list = [
            'id_glang' => [
                'title' => $this->l('ID'),
            ],
            'flag' => [
                'title' => $this->l('Flag'),
                'image' => 'l',
            ],
            'name' => [
                'title' => $this->l('Language'),
            ],
            'language_code' => [
                'title' => $this->l('Language code'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'currency' => [
                'title' => $this->l('Currency'),
            ],
            'tax_included' => [
                'title' => $this->l('Tax'),
            ],
            'active' => [
                'title' => $this->l('Enabled'),
                'align' => 'center',
                'active' => 'status',
                'type' => 'bool',
                'class' => 'fixed-width-sm',
            ],
        ];

        // Add shop name column if multishop is active
        if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(true, null, true)) > 1) {
            $fields_list = array_merge($fields_list, [
                'shop_name' => [
                    'title' => $this->l('Shop name'),
                    'width' => '15%',
                ],
            ]);
        }

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->show_toolbar = false;
        $helper->simple_header = true;
        $helper->identifier = 'id_glang';
        $helper->imageType = 'jpg';
        $helper->table = 'gshoppingflux_lc';
        $helper->actions = [
            'edit',
        ];
        $helper->title = $this->l('Export languages and currencies');
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->tpl_vars = [
            'languages' => $this->context->controller->getLanguages(),
        ];
        $glangflux = GLangAndCurrency::getAllLangCurrencies(0);
        foreach ($glangflux as $k => $v) {
            $currencies = explode(';', $glangflux[$k]['id_currency']);
            $arrCurr = [];
            foreach ($currencies as $idc) {
                $currency = new Currency($idc);
                $arrCurr[] = $currency->iso_code;
            }
            if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE')) {
                $shop = Shop::getShop($glangflux[$k]['id_shop']);
                $glangflux[$k]['shop_name'] = $shop['name'];
            }
            $glangflux[$k]['currency'] = implode(' - ', $arrCurr);
            if ($glangflux[$k]['tax_included'] == 1) {
                $glangflux[$k]['tax_included'] = $this->l('Inc Tax ');
            } else {
                $glangflux[$k]['tax_included'] = $this->l('Ex Tax');
            }
        }

        return $helper->generateList($glangflux, $fields_list);
    }

    /**
     * Render category list table
     *
     * Generates tree-view list table of all categories with Google Shopping mappings.
     * Displays category hierarchy, Google category names, condition, availability,
     * and attribute mappings (gender, age_group, color, material, pattern, size).
     * Shows export status for each category.
     *
     * @return string Generated HTML list table using HelperList
     */
    public function renderCategList()
    {
        $gcategories = $this->makeCatTree();

        $fields_list = [
            'id_gcategory' => [
                'title' => $this->l('ID'),
            ],
        ];

        if (Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(true, null, true)) > 1) {
            $fields_list = array_merge($fields_list, [
                'shop_name' => [
                    'title' => $this->l('Shop name'),
                    'width' => '15%',
                ],
            ]);
        }

        $fields_list = array_merge($fields_list, [
            'gcat_name' => [
                'title' => $this->l('Category'),
                'width' => '30%',
            ],
            'gcategory' => [
                'title' => $this->l('Matching Google category'),
                'width' => '70%',
            ],
            'condition' => [
                'title' => $this->l('Condit.'),
            ],
            'availability' => [
                'title' => $this->l('Avail.'),
            ],
            'gender' => [
                'title' => $this->l('Gender'),
            ],
            'age_group' => [
                'title' => $this->l('Age'),
            ],
            'gid_colors' => [
                'title' => $this->l('Color'),
            ],
            'gid_materials' => [
                'title' => $this->l('Material'),
            ],
            'gid_patterns' => [
                'title' => $this->l('Pattern'),
            ],
            'gid_sizes' => [
                'title' => $this->l('Size'),
            ],
            'export' => [
                'title' => $this->l('Export'),
                'align' => 'center',
                'is_bool' => true,
                'active' => 'status',
            ],
        ]);

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_gcategory';
        $helper->table = 'gshoppingflux';
        $helper->actions = [
            'edit',
        ];
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->title = $this->l('Google categories');
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

        return $helper->generateList($gcategories, $fields_list);
    }

    /**
     * Render module information panel
     *
     * Generates information display panel showing:
     * - Generated feed file URLs for all languages/currencies
     * - Local inventory feed URLs
     * - Reviews feed URL
     * - CRON task URLs for automatic feed generation
     * - Help links and forum information
     *
     * Useful for admins to copy feed URLs into Google Merchant Center account
     * and set up automated exports via CRON jobs.
     *
     * @return string Generated HTML information panel using HelperForm
     */
    public function renderInfo()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->languages = $this->context->controller->getLanguages();
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Get active langs on shop
        $languages = GLangAndCurrency::getAllLangCurrencies(1);
        $shops = Shop::getShops(true, null, true);
        $output = '';

        foreach ($languages as $lang) {
            $currencies = explode(';', $lang['id_currency']);
            foreach ($currencies as $curr) {
                $currency = new Currency($curr);
                if (Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $this->context->shop->id_shop_group, $this->context->shop->id) == 1) {
                    $get_file_url = $this->uri . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id);
                    $get_local_file_url = $this->uri . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id, true);
                } else {
                    $get_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id);
                    $get_local_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName($lang['iso_code'], $currency->iso_code, $this->context->shop->id, true);
                }
                $output .= '<a href="' . $get_file_url . '">' . $get_file_url . '</a> <br /> ';
                $output .= '<a href="' . $get_local_file_url . '">' . $get_local_file_url . '</a> <br /> ';
            }
        }
        if (Configuration::get('GS_GEN_FILE_IN_ROOT', 0, $this->context->shop->id_shop_group, $this->context->shop->id) == 1) {
            $get_reviews_file_url = $this->uri . $this->_getOutputFileName('', '', $this->context->shop->id, false, true);
        } else {
            $get_reviews_file_url = $this->uri . 'modules/' . $this->name . '/export/' . $this->_getOutputFileName('', '', $this->context->shop->id, false, true);
        }
        $output .= '<a href="' . $get_reviews_file_url . '">' . $get_reviews_file_url . '</a> <br /> ';

        $info_cron = '<a href="' . $this->uri . 'modules/' . $this->name . '/cron.php" target="_blank">' . $this->uri . 'modules/' . $this->name . '/cron.php</a>';
        $info_cron .= '<br/><a href="' . $this->uri . 'modules/' . $this->name . '/cron.php?local=true" target="_blank">' . $this->uri . 'modules/' . $this->name . '/cron.php?local=true</a>';
        $info_cron .= '<br/><a href="' . $this->uri . 'modules/' . $this->name . '/cron.php?reviews=true" target="_blank">' . $this->uri . 'modules/' . $this->name . '/cron.php?reviews=true</a>';

        if (count($languages) > 1) {
            $files_desc = $this->l('Configure these URLs in your Google Merchant Center account.');
        } else {
            $files_desc = $this->l('Configure this URL in your Google Merchant Center account.');
        }

        $cron_desc = $this->l('Install a CRON task to update the feed frequently.');

        if (count($shops) > 1) {
            $cron_desc .= ' ' . $this->l('Please note that as multishop feature is active, you\'ll have to install several CRON tasks, one for each shop.');
        }

        $form_desc = $this->l('Report bugs and find help on forum: <a href="https://www.prestashop.com/forums/topic/661366-free-module-google-shopping-flux/" target="_blank">https://www.prestashop.com/forums/topic/661366-free-module-google-shopping-flux/</a>');
        $helper->fields_value = [
            'info_files' => $output,
            'info_cron' => $info_cron,
        ];

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Files information'),
                    'icon' => 'icon-info',
                ],
                'input' => [
                    [
                        'type' => 'free',
                        'label' => $this->l('Generated files links:'),
                        'name' => 'info_files',
                        'desc' => $files_desc,
                    ],
                    [
                        'type' => 'free',
                        'label' => $this->l('Automatic files generation:'),
                        'name' => 'info_cron',
                        'desc' => $cron_desc,
                    ],
                ],
                'description' => html_entity_decode($form_desc, self::REPLACE_FLAGS, self::CHARSET),
            ],
        ];

        return $helper->generateForm([
            $fields_form,
        ]);
    }

    /**
     * Get custom nested categories tree
     *
     * Retrieves hierarchical category tree with Google Shopping mapping information.
     * Handles category nesting, breadcrumb generation, and attribute mapping display.
     * Optimized with caching to improve performance on large category trees.
     *
     * @param int $shop_id Shop ID to scope categories
     * @param int|null $root_category Root category ID to start tree from (optional)
     * @param int $id_lang Language ID for category names
     * @param bool $active Filter only active categories (default: true)
     * @param array|null $groups Group IDs to filter by (optional, if groups enabled)
     * @param bool $use_shop_restriction Apply shop restrictions in query (default: true)
     * @param string $sql_filter Additional SQL WHERE conditions (default: empty)
     * @param string $sql_sort Custom SQL ORDER BY clause (default: empty)
     * @param string $sql_limit Custom SQL LIMIT clause (default: empty)
     * @return array Nested category tree with Google Shopping configuration and attributes
     */
    public function customGetNestedCategories($shop_id, $root_category = null, $id_lang = false, $active = true, $groups = null, $use_shop_restriction = true, $sql_filter = '', $sql_sort = '', $sql_limit = '')
    {
        if (isset($root_category) && !Validate::isInt($root_category)) {
            exit(Tools::displayError());
        }

        if (!Validate::isBool($active)) {
            exit(Tools::displayError());
        }

        if (isset($groups) && Group::isFeatureActive() && !is_array($groups)) {
            $groups = (array) $groups;
        }

        $cache_id = 'Category::getNestedCategories_' . md5((int) $shop_id . (int) $root_category . (int) $id_lang . (int) $active . (int) $active . (isset($groups) && Group::isFeatureActive() ? implode('', $groups) : ''));
        if (!Cache::isStored($cache_id)) {
            $result = Db::getInstance()->executeS('
				SELECT c.*, cl.`name` as gcat_name, g.*, gl.*, s.name as shop_name
				FROM `' . _DB_PREFIX_ . 'category` c
				INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = "' . (int) $shop_id . '")
				LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON c.`id_category` = cl.`id_category` AND cl.`id_shop` = "' . (int) $shop_id . '"
				LEFT JOIN `' . _DB_PREFIX_ . 'gshoppingflux` g ON g.`id_gcategory` = c.`id_category` AND g.`id_shop` = "' . (int) $shop_id . '"
				LEFT JOIN `' . _DB_PREFIX_ . 'gshoppingflux_lang` gl ON gl.`id_gcategory` = c.`id_category` AND gl.`id_shop` = "' . (int) $shop_id . '"
				LEFT JOIN ' . _DB_PREFIX_ . 'shop s ON s.`id_shop` = "' . (int) $shop_id . '"
				WHERE 1 ' . $sql_filter . ' ' . ($id_lang ? 'AND cl.`id_lang` = ' . (int) $id_lang . ' AND gl.`id_lang` = ' . (int) $id_lang : '')
                . ($active ? ' AND c.`active` = 1' : '')
                . (isset($groups) && Group::isFeatureActive() ? ' AND cg.`id_group` IN (' . implode(',', $groups) . ')' : '')
                . (!$id_lang || (isset($groups) && Group::isFeatureActive()) ? ' GROUP BY c.`id_category`' : '')
                . ($sql_sort != '' ? $sql_sort : ' ORDER BY c.`level_depth` ASC')
                . ($sql_sort == '' && $use_shop_restriction ? ', cs.`position` ASC' : '')
                . ($sql_limit != '' ? $sql_limit : ''));

            $attributes = $this->getShopAttributes($this->context->language->id, $this->context->shop->id);

            foreach ($result as $k => $cat) {
                $result[$k]['gcategory'] = html_entity_decode($result[$k]['gcategory']);
                $gid_colors = [];
                $gid_materials = [];
                $gid_patterns = [];
                $gid_sizes = [];

                if ($result[$k]['level_depth'] > 0) {
                    $tree = ' > ';
                    $str = '';
                    for ($i = 0; $i < $result[$k]['level_depth'] - 1; ++$i) {
                        $str .= $tree;
                    }

                    $result[$k]['gcat_name'] = $str . ' ' . $result[$k]['gcat_name'];

                    $attribute_ids = ArrayHelper::getColumn($attributes, 'id_attribute_group');

                    $result[$k]['color'] = explode(';', $result[$k]['color']);
                    foreach ($result[$k]['color'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_colors[] = $attributes[$key]['name'];
                        }
                    }
                    $result[$k]['material'] = explode(';', $result[$k]['material']);
                    foreach ($result[$k]['material'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_materials[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['pattern'] = explode(';', $result[$k]['pattern']);
                    foreach ($result[$k]['pattern'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_patterns[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['size'] = explode(';', $result[$k]['size']);
                    foreach ($result[$k]['size'] as $a => $v) {
                        if (in_array($v, $attribute_ids)) {
                            $gid_sizes[] = $attributes[$key]['name'];
                        }
                    }

                    $result[$k]['gid_colors'] = implode(' ; ', $gid_colors);
                    $result[$k]['gid_materials'] = implode(' ; ', $gid_materials);
                    $result[$k]['gid_patterns'] = implode(' ; ', $gid_patterns);
                    $result[$k]['gid_sizes'] = implode(' ; ', $gid_sizes);
                }
            }

            $categories = [];
            $buff = [];

            if (!isset($root_category)) {
                $root_category = 1;
            }

            foreach ($result as $row) {
                $current = &$buff[$row['id_category']];
                $current = $row;

                if ($row['id_category'] == $root_category) {
                    $categories[$row['id_category']] = &$current;
                } else {
                    $buff[$row['id_parent']]['children'][$row['id_category']] = &$current;
                }
            }

            Cache::store($cache_id, $categories);
        }

        return Cache::retrieve($cache_id);
    }

    /**
     * Build category tree structure
     *
     * Recursively builds complete category tree starting from root category.
     * Used to populate category list displays in admin panel.
     * Integrates with customGetNestedCategories for data retrieval.
     *
     * @param int $id_cat Category ID to build tree from (0 = start from root)
     * @param array|int $catlist Accumulator array for recursive calls (default: 0)
     * @return array Complete flattened category tree with all nested relationships
     */
    private function makeCatTree($id_cat = 0, $catlist = 0)
    {
        $id_lang = (int) $this->context->language->id;
        $id_shop = (int) Shop::getContextShopID();
        $sql_filter = '';
        $sql_sort = '';
        $sql_limit = '';

        if ($id_cat == 0 && $catlist == 0) {
            $catlist = [];
            $shop = new Shop($id_shop);
            $id_cat = Category::getRootCategory($id_lang, $shop);
            $id_cat = $id_cat->id_category;
            $sql_limit = ';';
        }

        $category = new Category((int) $id_cat, (int) $id_lang);

        if (Validate::isLoadedObject($category)) {
            $tabcat = $this->customGetNestedCategories($id_shop, $id_cat, $id_lang, true, $this->user_groups, true, $sql_filter, $sql_sort, $sql_limit);
            $catlist = array_merge($catlist, $tabcat);
        }

        foreach ($tabcat as $k => $c) {
            if (!empty($c['children'])) {
                foreach ($c['children'] as $j) {
                    $catlist = $this->makeCatTree($j['id_category'], $catlist);
                }
            }
        }

        return $catlist;
    }
}
