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
- **9.0.1** ✨ (New!)

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

## Changelog

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
- **d1m007** - *Continue working* - [d1m007](https://github.com/d1m007)
- **Casper Olsen** - *Current maintainer* - [casper-o](https://github.com/casper-o)
- **Vincent Charpentier** - *PrestaShop 9 compatibility* - [zenzen279](https://github.com/zenzen279)

See also the list of [contributors](https://github.com/dim00z/gshoppingflux/contributors) who participated in this project.

## Support

- 📖 [Google Shopping Feed Specification](http://support.google.com/merchants/answer/188494)
- 💬 [PrestaShop Forum Thread](https://www.prestashop.com/forums/topic/661366-free-module-google-shopping-flux/)
- 🐛 [Issue Tracker](https://github.com/dim00z/gshoppingflux/issues)

## License

This project is licensed under the Apache License 2.0 - see the LICENSE file for details.

## Acknowledgments

- PrestaShop community for continuous support
- All contributors who have helped improve this module
- Google Merchant Center team for comprehensive documentation

---

Made with ❤️ for PrestaShop
