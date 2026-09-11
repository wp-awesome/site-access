<?php
/**
 * Loads the package without running it.
 *
 * Separate from `wp-awesome.php` so a test can exercise one decision at a time without booting, and
 * so a host that needs an unusual load order has somewhere to hook in.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ip.php';
require_once __DIR__ . '/scope.php';
require_once __DIR__ . '/access-log.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/settings.php';
