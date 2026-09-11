<?php
/**
 * Runs the denial path for real, in its own process, because it ends in `exit()`.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/load.php';

wpaw_gate_deny();

echo "unreachable\n";
