<?php

/**
 * PHPUnit bootstrap.
 *
 * This module has no PrestaShop install to test against (see
 * CLAUDE.md), so unit tests target the small, mostly-pure-PHP pieces
 * of src/ directly, using minimal stand-ins (tests/Stubs/) for the
 * handful of PrestaShop core classes those pieces touch. These are not
 * behavioral clones of PrestaShop core — only what each tested method
 * actually calls.
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '9.0.0');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/Stubs/Tools.php';
require_once __DIR__ . '/Stubs/Validate.php';
require_once __DIR__ . '/Stubs/Category.php';
require_once __DIR__ . '/Stubs/Configuration.php';
require_once __DIR__ . '/Stubs/Currency.php';
require_once __DIR__ . '/Stubs/Product.php';
require_once __DIR__ . '/Stubs/Shop.php';
require_once __DIR__ . '/Stubs/Language.php';
require_once __DIR__ . '/Stubs/Module.php';

// The module class itself (global namespace, not PSR-4 autoloaded)
require_once __DIR__ . '/../gshoppingflux.php';

// Feed dates are formatted with the PHP default timezone (PrestaShop sets
// it from PS_TIMEZONE); pin it so date assertions don't depend on the host.
date_default_timezone_set('Europe/Paris');
