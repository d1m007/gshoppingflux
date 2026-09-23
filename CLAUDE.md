# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PrestaShop module (`gshoppingflux`) that exports store products as an XML feed for Google Merchant Center. The same feed format is also consumed by Shopalike, Pricerunner, and Partner-Ads. It supports PrestaShop 1.5 through 9.x, multi-shop, multi-language, and multi-currency setups.

There is no release pipeline in this repo — it is plain PHP dropped into a PrestaShop `modules/` directory and only runs inside a PrestaShop installation (nothing here executes standalone). Composer manages the PSR-4 autoloader used at runtime and, as a dev dependency, PHPUnit for the unit test suite under `tests/`; there are no third-party runtime dependencies.

## Running and testing changes

There's no PrestaShop instance to run the module against in this environment, and no database — so most of the module's own behavior (admin screens, actual feed generation against real products) can only be exercised inside a real PrestaShop install, the same way as before:

```bash
# symlink or copy the module directory into a PrestaShop install
ln -s /path/to/gshoppingflux/gshoppingflux /path/to/prestashop/modules/gshoppingflux
```

Then install/configure it from the PrestaShop back office (Modules > Google Shopping Flux), or trigger feed generation directly:

```bash
# regenerate the feed for the shop's default context (invoked by PrestaShop's own cron in production)
php modules/gshoppingflux/cron.php
php modules/gshoppingflux/cron.php?local=1    # local inventory feed
php modules/gshoppingflux/cron.php?reviews=1  # product reviews feed
```

`cron.php` bootstraps PrestaShop's `config.inc.php`/`init.php` and calls `GShoppingFlux::generateShopFileList()`. It must be run from inside a working PrestaShop tree — there's no mock or harness for it.

For a quick syntax check without a full install:

```bash
php -l gshoppingflux/gshoppingflux.php
for f in gshoppingflux/src/*.php gshoppingflux/src/Traits/*.php; do php -l "$f"; done
```

### Unit tests

`tests/` (PHPUnit, `GShoppingFlux\Tests\` PSR-4) covers the pieces of `src/` that don't need a live PrestaShop: `ArrayHelper`, the pure-string helpers in `FeedGeneratorTrait` (`cdataSafe()`, `truncateAtWordBoundary()`), and `GCategories::getPath()`'s breadcrumb recursion. It stubs the handful of PrestaShop core classes those touch (`tests/Stubs/Tools.php`, `Validate.php`, `Category.php`) rather than depending on a real install — these are not behavioral clones of core, only what the tested code actually calls, so extend them if a new test needs more of a stubbed class's surface.

```bash
cd gshoppingflux
composer install          # pulls in phpunit (dev-only; vendor/ is gitignored)
composer test              # or: vendor/bin/phpunit
```

Nothing under `AdminOptionsTrait`, `AdminCategoriesLangTrait`, `LifecycleTrait`, or the DB-querying/HTTP-facing parts of `FeedGeneratorTrait`/`ReviewsFeedTrait` is unit-tested — those need PrestaShop's `Db`, `HelperForm`, `Context`, and a real admin request to exercise meaningfully, which is out of reach without a PrestaShop instance. Keep testing new pure logic the same way (extract it to a small private method with no `Db`/`Context` dependency, like `truncateAtWordBoundary()`) rather than trying to stub your way into the framework-heavy methods.

## Architecture

### Entry point and file layout

- `gshoppingflux/gshoppingflux.php` — the `GShoppingFlux extends Module` class PrestaShop instantiates by name. It's deliberately thin: class constants (`CONFIG_DEFAULTS`, the `VALID_*` whitelists), instance properties, `__construct()`, and five `use SomeTrait;` statements that compose in everything else. This file must stay in the **global namespace** — PrestaShop's module loader expects the bare class name `GShoppingFlux`.
- `gshoppingflux/src/` — PSR-4 autoloaded, namespace `GShoppingFlux\`:
  - `GCategories.php` — static data-access class for the PrestaShop-category → Google-category mapping table, including breadcrumb path building.
  - `GLangAndCurrency.php` — static data-access class for the language/currency pairs a feed is generated for.
  - `ArrayHelper.php` — small array utilities used throughout (form value extraction, `safeImplode()`/`safeExplode()`, an `array_column` polyfill).
  - `Traits/` (namespace `GShoppingFlux\Traits`), one trait per functional area, each `use`'d into `GShoppingFlux` and sharing its `$this`:
    - `LifecycleTrait` — install/uninstall/reset and the four hooks.
    - `AdminOptionsTrait` — `getContent()`'s dispatcher, the `save*()` handlers, and the main options/local inventory/reviews forms.
    - `AdminCategoriesLangTrait` — the category-mapping and language/currency admin screens, plus the feature/attribute/category-tree data helpers they use.
    - `FeedGeneratorTrait` — the standard and local inventory feed generation engine (`generateAllShopsFileList()` down to `getItemXML()`).
    - `ReviewsFeedTrait` — `generateReviewsFile()`, a separate XML schema from the shopping feed.
- `gshoppingflux/composer.json` — PSR-4 autoload mapping (`"GShoppingFlux\\": "src/"`) plus the `phpunit/phpunit` dev dependency for `tests/`. `vendor/` is gitignored (it now pulls in phpunit and its transitive packages, not just our own autoloader) and generated with `composer install`. `gshoppingflux.php` requires `vendor/autoload.php` when present, and falls back to explicit `require_once` calls for the same `src/` files when it's absent — so the module works unmodified for a merchant who copies the folder as-is with no `vendor/` and no Composer step.
- `gshoppingflux/tests/` — PHPUnit unit tests (`GShoppingFlux\Tests\` PSR-4), `tests/Stubs/` for the PrestaShop core class stand-ins they need. See "Unit tests" above.
- `gshoppingflux/cron.php` — standalone bootstrap script that PrestaShop's task scheduler (or an external cron) hits to regenerate feeds.
- `gshoppingflux/.htaccess` — re-allows direct access to `cron.php` specifically. Some prestashop versions ship a hardened `modules/.htaccess` that denies every `.php` file under `modules/` by default; without this override, hitting the cron URL 404s (or 403s, depending on the host's error-document mapping) before PHP ever runs, with nothing in `cron.php` itself able to explain it. Keep this file if you ever restructure the module's file layout.
- `gshoppingflux/export/` — default output directory for generated XML files (can be overridden to the PrestaShop webroot via the `gen_file_in_root` setting).
- `gshoppingflux/views/templates/admin/_configure/helpers/form/form.tpl` — Smarty template wrapping the admin config form.

Every core PrestaShop class a `src/` file uses (`Db`, `Shop`, `Tools`, `Product`, ...) needs an explicit `use ClassName;` at the top of that file, since those classes live in the global namespace and `src/` files don't. When adding a new core-class call to a trait, check whether it's already imported before assuming it resolves.

### Database

Three custom tables, created in `installDb()` and dropped in `uninstallDb()`:

- `gshoppingflux` — one row per PrestaShop category per shop: export flag, plus Google attributes (`condition`, `availability`, `gender`, `age_group`, `color`, `material`, `pattern`, `size`).
- `gshoppingflux_lang` — the Google category name for that mapping, per language.
- `gshoppingflux_lc` — language/currency pairs configured for feed generation, per shop, with a tax-included flag.

All three are scoped by `id_shop`, with `id_shop = 0` used as a "global/all shops" row (see the `IN (0, id_shop)` pattern in every query in `GCategories` and `GLangAndCurrency`). Any new query against these tables should follow that same fallback convention.

Module settings (feed formatting options — description source, shipping mode, image type, MPN/GTIN handling, excluded carriers, min export price, etc.) are stored via PrestaShop's standard `Configuration` table under `GS_*` keys (e.g. `GS_SHIPPING_MODE`, `GS_IMG_TYPE`, `GS_EXPORT_MIN_PRICE`), read/written through `getConfigFieldsValues()` / `saveFluxOptions()` in `AdminOptionsTrait`. The full default value for every `GS_*` key lives in `GShoppingFlux::CONFIG_DEFAULTS`, seeded on install and removed on full uninstall from that same list.

### Hooks

Registered in `install()`:

- `actionObjectCategoryAddAfter` / `actionObjectCategoryDeleteAfter` — keep the `gshoppingflux`/`gshoppingflux_lang` rows in sync when categories are added/removed.
- `actionShopDataDuplication` — replicates the module's per-category and language/currency config when a shop is duplicated (multi-shop).
- `actionCarrierUpdate` — reacts to carrier changes (used by shipping cost/carrier-exclusion logic).

### Feed generation flow

All of this lives in `FeedGeneratorTrait` (`generateReviewsFile()` is the one exception, in `ReviewsFeedTrait`). Entry points are `generateAllShopsFileList()` → `generateShopFileList($id_shop, $local_inventory, $reviews)` → `generateLangFileList()` → `generateFile()` (private), which is called once per language/currency pair configured in `gshoppingflux_lc`. `generateFile()`:

1. Loads shop, root category, and merged module config (`getConfigFieldsValues()` + `getConfigLocalInventoryFieldsValues()`).
2. Loads the Google category mapping for the current language/shop via `getGCategValues()`.
3. Resolves the output path via `_getOutputFileName()`, either under `export/` or the PrestaShop webroot.
4. Iterates products, building each `<item>` with `getItemXML()` (standard feed) — combinations/attributes are expanded into separate items when `export_attributes` is enabled.
5. Writes UTF-8 (with BOM) XML and chmods the file.

Two parallel formats reuse most of this machinery:

- Local inventory feed (`local_inventory = true`) — uses `getLocalInventoryItemXML()` instead of `getItemXML()`.
- Product reviews feed (`reviews = true`) — a separate code path, `generateReviewsFile()`, producing the Google product-reviews XML schema instead of the shopping feed schema.

### Admin UI

`getContent()` (`AdminOptionsTrait`) is the single dispatcher for the configuration screen: it delegates to `processFormSubmissions()` for POST handling (`saveFluxOptions`, `saveLocalInventoryOptions` in `AdminOptionsTrait`; `saveCategory`, `saveLanguage` in `AdminCategoriesLangTrait`) and to `renderAdminContent()` for output, which stitches together several `render*Form()`/`render*List()` methods (main options, local inventory, reviews — `AdminOptionsTrait`; category mapping, language/currency list, info panel — `AdminCategoriesLangTrait`) into one page.

## Gotchas

- **Version is declared in several places and they must be bumped together**: the `@version` docblock at the top of `gshoppingflux.php`, `$this->version` in the constructor, and every `<version>` tag in `gshoppingflux/config.xml`, `config_fr.xml`, `config_es.xml`, `config_de.xml`, `config_it.xml` (plus the changelog in `README.md`). These have drifted out of sync before (e.g. the docblock/`config.xml` say `1.7.8` while `$this->version` still said `1.7.7`) — check all of them when releasing, and don't assume one is authoritative.
- PrestaShop 9 dropped several old APIs this module used to depend on (`ToolsCore`, uppercase `Db` methods, `_PS_PRICE_DISPLAY_PRECISION_`); the compatibility shims for that are in `getPriceDisplayPrecision()` (`FeedGeneratorTrait`) and scattered `Tools::`/`Db::` call sites — when touching pricing or DB calls, keep both the PS 1.5 and PS 9 code paths working, per `ps_versions_compliancy` (`1.5.0.0` to `9.99.99`).
- `id_shop = 0` rows are shared/global fallbacks in the three custom tables, not a literal shop with ID 0 — deleting or filtering these tables without accounting for that will break single-shop installs too.
- `gshoppingflux.php` stays unnamespaced on purpose (see "Entry point and file layout"); don't add a `namespace` declaration to it or PrestaShop will fail to find the `GShoppingFlux` class. Everything under `src/` is namespaced `GShoppingFlux\` (or `GShoppingFlux\Traits\`) and needs `use` imports for both PrestaShop core classes and this module's own `GCategories`/`GLangAndCurrency`/`ArrayHelper`.
- `vendor/` is gitignored — don't commit it. After adding, removing, or renaming a file under `src/`, run `composer dump-autoload -o` from `gshoppingflux/` locally so your own `vendor/` stays in sync; there's nothing to commit for it. Do commit `composer.lock` when `composer.json`'s dependencies change, so `composer install` stays reproducible.
- The five traits share one flat method namespace once composed into `GShoppingFlux` (PHP fatal-errors on a name collision between two `use`'d traits) — before adding a method to a trait, check the others don't already declare a method with that name.
- **`GS_CRON_TOKEN`** (empty by default) optionally gates `cron.php`: when an employee sets it in the main options form, `cron.php` requires a matching `?token=...` (checked with `hash_equals()`) and 403s otherwise. Empty stays the historical open-URL behavior, so this never breaks an existing cron job. If you touch `_getOutputFileName()`, `cron.php`, or `renderInfo()`'s `$info_cron` block, keep the token check/display in sync across all three.
- **`GCategories`/`FeedGeneratorTrait` cache Category/Carrier lookups within a single run** (`GCategories::$categoryCache`, `GShoppingFlux::$carriersByZoneCache`) to avoid re-querying the same category/carrier-zone data once per exported product. Both are reset at the start of the relevant top-level call (`GCategories::resetCache()`, or the top of `generateFile()`) — if you add a new per-run cache, reset it the same way rather than letting it leak between `generateFile()` calls or (in tests) between test cases.
- **PHP 8.2+ deprecates undeclared dynamic properties** on `Module` subclasses (no `#[AllowDynamicProperties]` on PrestaShop's `Module` core class, verified directly against `PrestaShop/PrestaShop`'s `classes/module/Module.php`). Any new `$this->someProperty = ...` in a trait needs a matching declaration in `gshoppingflux.php`'s `CLASS PROPERTIES` section — it won't be caught by `php -l`, only by running on PHP 8.2+ with error reporting on.
- **PHP 8.1+ deprecates passing `null` to a non-nullable parameter of an internal function** (`preg_replace()`, `str_replace()`, `trim()`, `htmlspecialchars()`, etc.). Several DB columns this module reads can legitimately be `NULL` (`meta_description`, review `title`) — cast `(string)` before handing such a value to a core PHP function, the way `cdataSafe()`/`rip_tags()` already do, rather than assuming the column is always a string.
- Verified against PrestaShop 9.1.5: no breaking change beyond what PS9.0 already introduced (and this module already shims) affects a classic Db/Configuration/Tools/HelperForm/`Module::l()` module — nothing PS9.1-specific to shim here.
