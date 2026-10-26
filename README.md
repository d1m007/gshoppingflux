# Google Shopping Flux

Prestashop Module for Google Merchant center. Generates a XML feed, so that you can use Google Merchant Center to advertise for your products.

This feed is also accepted by:

- [Shopalike](https://visual-meta.com/en/about-us/online-shopping-portals)
- [Pricerunner](https://www.pricerunner.com/)
- [Partner-Ads](https://www.partner-ads.com/)

## Getting Started

Follow these steps to get up and running with your product feeds fast.

### Prestashop tested version

- 1.6.0.6
- 1.6.1.17
- 1.7.2.4
- 1.7.x
- 8.x
- 9.0.1
- **9.1.5** ✨ (New!)

### Installing

```bash
# Download archive file
# On back office > Modules > Add new Module > Upload archive
```

Or via command line:

```bash
cd modules/
git clone https://github.com/dim00z/gshoppingflux.git
```

### Configuration of module

Depending on your store and what you want to send to google, you would need to change some of the configuration of the module.

## Features

- ✅ Full Google Shopping Feed XML generation
- ✅ Local Inventory Feed support
- ✅ Product Reviews Feed support
- ✅ Multi-shop support
- ✅ Multi-language & multi-currency support
- ✅ Automatic CRON generation
- ✅ Product attributes/combinations export
- ✅ Customizable category mapping
- ✅ Shipping cost calculation
- ✅ PrestaShop 9 compatible
- ✅ Additional Google attributes (all optional, disabled by default): ISBN as GTIN, native MPN field, backorder, availability date, sale price effective dates, cost of goods sold, bundles, item group title, variant options, product details, product dimensions/weight, PDF documents, adult, energy classes and EPREL certification, size system/type, shipping/handling attributes, excluded destinations, custom labels, shipping and return policy labels

## Changelog

### Version 1.9.0 (2026)

New "Additional Google attributes" panel, following the latest [Google product data specification](https://support.google.com/merchants/answer/7052112). Every new option is disabled (or empty) by default, so an existing feed stays exactly the same until you enable them:

- `<g:gtin>` from the ISBN for products with no EAN13/UPC, ISBN-10 converted to ISBN-13 (PrestaShop 1.7+)
- `<g:mpn>` from the native MPN field, as a new "Manufacturers References type" choice (PrestaShop 1.7.7+)
- Option to send `backorder` instead of `preorder` for out of stock products that can still be ordered (default: `preorder`), and new "backorder" availability for categories
- `<g:availability_date>` from the product/combination availability date, for preorder/backorder products
- `<g:sale_price_effective_date>` from the specific price start/end dates
- `<g:cost_of_goods_sold>` from the wholesale price
- Option to stop sending `<g:unit_pricing_measure>1 ct</g:unit_pricing_measure>` (default: still sent)
- `<g:is_bundle>` for packs
- `<g:item_group_title>` and `<g:variant_option>` for attributes combinations
- `<g:product_detail>` from the product features not already mapped to another attribute
- `<g:product_length>`, `<g:product_width>`, `<g:product_height>`, `<g:product_weight>`, converted to the units accepted by Google
- `<g:document_link>` from the product PDF attachments
- `<g:adult>`
- `<g:energy_efficiency_class>`, `<g:min_energy_efficiency_class>`, `<g:max_energy_efficiency_class>` and `<g:certification>` (EPREL) from product features
- `<g:size_system>` and `<g:size_type>`
- `<g:ships_from_country>`, `<g:min_handling_time>`, `<g:max_handling_time>`, `<g:free_shipping_threshold>`, `<g:minimum_order_value>`
- `<g:excluded_destination>`
- `<g:custom_label_0>` to `<g:custom_label_4>`, `<g:shipping_label>` and `<g:return_policy_label>`, each filled from the brand, supplier, default category, "on sale" flag, condition, reference or any product feature
- French translations for the new options
- Unit tests for the new attributes, including a check that a shop upgraded without the new settings keeps the same feed

### Version 1.8.3 (2026)

- New feature : Option to capitalize each word of the title and short title (Thank you @eballetbaz)

### Version 1.8.2 (2026)

- New feature : Option to append Brand to the title (Thank you @eballetbaz)

### Version 1.8.1 (2026)

- Fix XML feed no longer generated at the shop root when "Generate the files to the root of the site" is enabled, a regression from 1.8.0 (Thank you @eballetbaz)
- Fix XML feed written under `src/Traits/export/` instead of the module's `export/` directory when "Generate the files to the root of the site" is disabled, a regression from 1.8.0
- Fix additional images from other shops wrongly included in multi-shop feeds (Thank you @eballetbaz)

### Version 1.8.0 (2026)

- Fix `<g:shipping_width>` reporting product depth instead of width
- Fix title/short_title/description truncation producing an empty value when no word boundary was found near the length limit
- Escape `]]>` in CDATA sections (title, description, category, attributes) and in product review fields, preventing malformed or injected XML in the feed
- Fix a PHP warning and incorrect shipping price when no carrier matches a product's size or weight
- Fix the `GS_SHIPPING_PRICE_FIXED` setting being left behind after a full uninstall
- Fix language/currency selection not following the shop being exported on multi-shop CRON runs
- Fix category name and breadcrumb missing for global (all-shops) category mappings
- Fix 404 error when editing a category or the language/currency list on PrestaShop 9 (Thank you @oxess)
- Fix color/material/pattern/size, excluded shipping countries/carriers, and currency selects resetting on save due to broken `safeImplode()` calls (Thank you @rodriciru)
- Add a `<g:short_title>` element to the feed (max 65 characters)
- Increase `<g:title>` max length to 150 characters (was 70)
- Restructure the module into a PSR-4 `src/` layout with Composer autoloading and one trait per functional area, following PrestaShop 9 conventions
- Add a PHPUnit unit test suite (`gshoppingflux/tests/`)
- Whitelist admin form values (condition, availability, gender, shipping mode, etc.) against their known option lists instead of storing raw input
- Remove duplicated code across the feed generation and admin form code paths

### Version 1.7.7 (2026)

- Fix price precision falling back to 0 on PS9 (causes 0 EUR rounding) (Thank you @icemansparks)

### Version 1.7.6 (2025)

- Add comments everywhere
- Reorganize code files
- Small improvements
- Safe implode to fix implode errors on non array value got from Tools

### Version 1.7.5 (2025)

- ✨ **PrestaShop 9.0.1 compatibility**
- 🔧 Fixed `_PS_PRICE_DISPLAY_PRECISION_` undefined constant issue
- 🔧 Replaced deprecated `ToolsCore` with `Tools`
- 🔧 Updated all `Db` method calls to lowercase (PS9 requirement)
- 📝 Added comprehensive method documentation
- 🧹 Code cleanup and modernization
- ⚡ Maintained backward compatibility with PS 1.5+

### Version 1.7.4

- Previous stable version

## Contributing

You are welcome to help making this module better.

### How to contribute

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/dim00z/gshoppingflux/tags).

## Authors

- **Jordi Martin** - *Initial work* - [jmartin82](https://github.com/jmartin82)
- **d1m007** - *A lot of improvements* - [d1m007](https://github.com/d1m007)
- **Casper Olsen** - *A lot of improvements* - [casper-o](https://github.com/casper-o)
- **Vincent Charpentier** - *Current maintainer* - [zenzen279](https://github.com/zenzen279)

See also the list of [contributors](https://github.com/d1m007/gshoppingflux/contributors) who participated in this project.

## Support

- 📖 [Google Shopping Feed Specification](https://support.google.com/merchants/answer/7052112)
- 🐛 [Issue Tracker](https://github.com/dim00z/gshoppingflux/issues)

## License

This project is licensed under the Apache License 2.0 - see the LICENSE file for details.

## Acknowledgments

- PrestaShop community for continuous support
- All contributors who have helped improve this module
- Google Merchant Center team for comprehensive documentation

---

Made with ❤️ for PrestaShop
