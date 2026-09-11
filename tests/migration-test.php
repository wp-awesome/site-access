<?php
/**
 * Option migration. This is the part that gates a live site, so it is tested from every schema the
 * host can be sitting on, including the oldest.
 *
 * The KEY never changes — it holds the live allowlist of a public site and renaming it would mean
 * another migration on a security control. Only the VALUE is reinterpreted.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE          = 'ykps_uat_gate_public';
const WP_AWESOME_OPTION_ALLOWLIST      = 'ykps_uat_gate_allowlist';
const WP_AWESOME_OPTION_LEGACY_ENABLED = 'ykps_uat_gate_enabled';

require_once dirname(__DIR__) . '/src/load.php';

/** @param array<string, mixed> $options @return array<string, mixed> */
function wpaw_test_migrate(array $options): array {
	wpaw_test_reset($options);
	wpaw_migrate_scope();

	return wpaw_test_get('options');
}

// --- the current schema ---------------------------------------------------

$after = wpaw_test_migrate(['ykps_uat_gate_public' => '1']);
wpaw_assert_same('disabled', $after['ykps_uat_gate_public'], "public='1' meant open, and becomes disabled");

$after = wpaw_test_migrate(['ykps_uat_gate_public' => '0']);
wpaw_assert_same('website', $after['ykps_uat_gate_public'], "public='0' meant gated, and becomes website");

// --- the oldest schema, chained through ------------------------------------

$after = wpaw_test_migrate(['ykps_uat_gate_enabled' => '1']);
wpaw_assert_same('website', $after['ykps_uat_gate_public'], "the oldest schema's gate ON lands on website");
wpaw_assert_same('1', $after['ykps_uat_gate_enabled'], 'the legacy option is left in place so a rollback still finds it');

$after = wpaw_test_migrate(['ykps_uat_gate_enabled' => '0']);
wpaw_assert_same('disabled', $after['ykps_uat_gate_public'], "the oldest schema's gate OFF lands on disabled");

// The newer option wins when both exist; that is the whole point of the chain's order.
$after = wpaw_test_migrate(['ykps_uat_gate_public' => '0', 'ykps_uat_gate_enabled' => '0']);
wpaw_assert_same('website', $after['ykps_uat_gate_public'], 'the newer option is authoritative when both schemas are present');

// --- idempotence ----------------------------------------------------------

wpaw_test_reset(['ykps_uat_gate_public' => '0']);
wpaw_migrate_scope();
wpaw_migrate_scope();
wpaw_migrate_scope();
wpaw_assert_same('website', wpaw_test_get('options')['ykps_uat_gate_public'], 'migrating repeatedly is stable');

foreach (['disabled', 'website', 'admin'] as $scope) {
	$after = wpaw_test_migrate(['ykps_uat_gate_public' => $scope]);
	wpaw_assert_same($scope, $after['ykps_uat_gate_public'], sprintf('an already-migrated %s value is left alone', $scope));
}

// --- a fresh install writes nothing ---------------------------------------

$after = wpaw_test_migrate([]);
wpaw_assert_same([], $after, 'a fresh install with no stored options is not given any');

// --- corruption is preserved, not overwritten ------------------------------

$after = wpaw_test_migrate(['ykps_uat_gate_public' => 'nonsense-from-a-restore']);
wpaw_assert_same(
	'nonsense-from-a-restore',
	$after['ykps_uat_gate_public'],
	'an unrecognised value is left intact for a human to see; resolution falls back instead of silently rewriting it'
);
wpaw_assert_same('website', wpaw_scope(), 'and the unrecognised value still resolves to the host fallback');

wpaw_test_done('migration-test');
