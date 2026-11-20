<?php
/**
 * The three-value blocking scope, under the default host fallback (`website`).
 *
 * Each surface is exercised as a REQUEST SHAPE, never as a literal path: `is_admin()` for the
 * dashboard and a path derived from `wp_login_url()` for the login form.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE               = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST           = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES     = 'test_proxies';
const WP_AWESOME_OPTION_PUBLIC_AJAX_LIST    = 'test_public_ajax_list';
// The pair the version with an enable checkbox stored. Read only while the list above has never
// been saved, so an upgrading install keeps exactly the exemptions it already had.
const WP_AWESOME_OPTION_PUBLIC_AJAX_ENABLED = 'test_public_ajax_legacy_enabled';
const WP_AWESOME_OPTION_PUBLIC_AJAX_ACTIONS = 'test_public_ajax_legacy_actions';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param 'frontend'|'admin'|'login'|'ajax' $surface
 * @param mixed $list the steady-state allowed-AJAX list, or null to leave that row absent
 * @param mixed $legacyEnabled the obsolete enable flag an upgrading install may still hold, or null
 * @param mixed $legacyList the obsolete action list that flag once switched on, or null
 */
function wpaw_test_request(string $scope, string $surface, string $allowlist = '198.51.100.0/24', string $remote = '203.0.113.9', mixed $action = null, mixed $list = null, mixed $legacyEnabled = null, mixed $legacyList = null): bool {
	$options = ['test_scope' => $scope, 'test_allowlist' => $allowlist, 'test_proxies' => ''];

	if (null !== $list) {
		$options['test_public_ajax_list'] = $list;
	}

	if (null !== $legacyEnabled) {
		$options['test_public_ajax_legacy_enabled'] = $legacyEnabled;
	}

	if (null !== $legacyList) {
		$options['test_public_ajax_legacy_actions'] = $legacyList;
	}

	wpaw_test_reset($options);

	$_SERVER['REMOTE_ADDR'] = $remote;
	$_SERVER['REQUEST_URI'] = '/about-us/';
	$_GET = null === $action ? [] : ['action' => $action];
	$_POST = [];
	$_REQUEST = $_GET;

	if ('admin' === $surface || 'ajax' === $surface) {
		wpaw_test_set('is_admin', true);
		$_SERVER['REQUEST_URI'] = '/wp-admin/index.php';
	}

	if ('ajax' === $surface) {
		wpaw_test_set('doing_ajax', true);
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
	}

	if ('login' === $surface) {
		$_SERVER['REQUEST_URI'] = '/wp-login.php?redirect_to=%2Fwp-admin%2F';
	}

	return wpaw_gate_should_deny();
}

// --- website: the whole HTTP surface --------------------------------------

wpaw_assert_true(wpaw_test_request('website', 'frontend'), 'website scope denies the frontend');
wpaw_assert_true(wpaw_test_request('website', 'admin'), 'website scope denies the dashboard');
wpaw_assert_true(wpaw_test_request('website', 'login'), 'website scope denies the login form');
wpaw_assert_true(wpaw_test_request('website', 'ajax'), 'website scope has no AJAX hole and must never grow one');
wpaw_assert_true(
	wpaw_test_request('website', 'ajax', action: 'get_campuses', list: 'get_campuses'),
	'website scope gates a listed action too; the list belongs to admin scope alone'
);

// --- admin: dashboard and login only --------------------------------------

wpaw_assert_false(wpaw_test_request('admin', 'frontend'), 'admin scope leaves the public frontend public');
wpaw_assert_true(wpaw_test_request('admin', 'admin'), 'admin scope denies the dashboard');
wpaw_assert_true(wpaw_test_request('admin', 'login'), 'admin scope denies the login form');

// --- admin: AJAX is enumerated, never exempt wholesale ---------------------
//
// `admin-ajax.php` is shared by the public frontend and the dashboard, so `admin` scope used to
// exempt it wholesale. That also exempted every privileged handler core, plugins and the theme
// register. The exemption is now an enumerated list of action names, editable only by an
// administrator, and THE LIST IS THE WHOLE SETTING: there is no second switch, so a
// stored-yet-inert state cannot exist.

wpaw_assert_true(wpaw_test_request('admin', 'ajax'), 'admin scope denies AJAX when no action is listed');
wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: 'heartbeat'), 'admin scope denies privileged AJAX actions');
wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: ['get_campuses']), 'a non-scalar action is never exempt');

// A FRESH INSTALL AND A DELIBERATE REVOCATION ARE DIFFERENT ROWS WITH THE SAME EFFECT. Both exempt
// nothing; a saved empty list must also stay revoked when an older row is still in the database.
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: ''),
	'a saved empty list exempts nothing'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: "# public endpoints\n\n"),
	'a comment-only list exempts nothing'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: "\t"),
	'a control-only list is malformed and exempts nothing'
);

$public = "get_campuses\nget_news";

wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: $public),
	'a listed action is exempt in admin scope'
);
wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_news', list: $public),
	'every listed action is exempt, not only the first'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'heartbeat', list: $public),
	'an unlisted action stays gated even when a list exists'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'admin', action: 'get_campuses', list: $public),
	'the exemption is scoped to AJAX requests; a dashboard request carrying the same action parameter is still gated'
);

// The gate never consults the current user. An authenticated administrator on a blocked address is
// gated like anyone else; the exemption is a property of the action name, not of the session.

wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24']);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
wpaw_test_set('can_manage', true);
$_GET = ['action' => 'heartbeat'];
$_POST = [];
$_REQUEST = $_GET;
wpaw_assert_true(wpaw_gate_should_deny(), 'admin scope gates a privileged AJAX action even for a signed-in administrator');

wpaw_test_reset([
	'test_scope'            => 'admin',
	'test_allowlist'        => '198.51.100.0/24',
	'test_public_ajax_list' => 'get_campuses',
]);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
wpaw_test_set('can_manage', true);
$_GET = ['action' => 'get_campuses'];
$_POST = [];
$_REQUEST = $_GET;
wpaw_assert_false(wpaw_gate_should_deny(), 'a listed action is exempt for an authenticated request too, because one name covers both handlers');
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'GET_CAMPUSES', list: $public),
	'action matching is exact and case-sensitive'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses_extra', list: $public),
	'action matching is exact, never a prefix'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: ' get_campuses', list: $public),
	'action matching does not trim surrounding whitespace'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: ['get_campuses'], list: $public),
	'an array action is not exempt even when its value is on the list'
);

// PHP request_order may differ from WordPress's eventual GET-then-POST merge.
wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: 'get_campuses');
$_POST = ['action' => 'future_privileged_action'];
wpaw_assert_true(wpaw_gate_should_deny(), 'POST action overrides a listed GET action regardless of the early REQUEST value');
$_GET = ['action' => 'future_privileged_action'];
$_POST = [];
$_REQUEST = ['action' => 'get_campuses'];
wpaw_assert_true(wpaw_gate_should_deny(), 'a cookie or stale REQUEST action cannot exempt a different dispatched action');
$_GET = ['action' => 'get_campuses'];
$_POST = ['action' => ['get_campuses']];
wpaw_assert_true(wpaw_gate_should_deny(), 'a malformed POST action overrides a listed GET action and fails closed');
wpaw_test_set('is_admin', false);
wpaw_assert_true(wpaw_gate_should_deny(), 'an AJAX request remains gated even if is_admin is false');

// Malformed stored configuration fails CLOSED, as a whole. A row that mixes a typo into the list is
// evidence the list is not what its author believed; exempting the readable part would grant
// exactly the access nobody configured.

wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: ['get_campuses']),
	'a non-string stored list exempts nothing'
);
foreach (["get_campuses\ninvalid\x00action", "get_campuses\ninvalid\taction", "get_campuses\ninvalid\xff"] as $malformed) {
	wpaw_assert_true(
		wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: $malformed),
		'a control character or invalid UTF-8 voids the entire list'
	);
}
wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'acf/fields/relationship/query', list: 'acf/fields/relationship/query'),
	'a slash-containing action can be exempted exactly'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'acf/fields/relationship/query_extra', list: 'acf/fields/relationship/query'),
	'a slash-containing action does not match a longer name'
);
wpaw_test_request('admin', 'ajax', action: 'acf/fields/relationship/query', list: 'acf/fields/relationship/query');
$_POST = ['action' => 'future_privileged_action'];
wpaw_assert_true(wpaw_gate_should_deny(), 'POST privileged action overrides the listed slash-containing GET action');
$_GET = ['action' => 'future_privileged_action'];
$_POST = ['action' => 'acf/fields/relationship/query'];
wpaw_assert_false(wpaw_gate_should_deny(), 'a listed slash-containing POST action overrides GET');
wpaw_assert_false(wpaw_test_request('admin', 'ajax', action: 'public action#1', list: 'public action#1'), 'printable names including spaces and hash match literally');
wpaw_assert_false(wpaw_test_request('admin', 'ajax', action: '0', list: '0'), 'the literal action 0 is not dropped by list parsing');
wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: "a'b", list: "a'b"), 'raw quotes cannot exempt a different action after WordPress adds slashes');
wpaw_assert_false(wpaw_test_request('admin', 'ajax', action: "a'b", list: "a\\'b"), 'matching uses the exact action WordPress dispatches after adding slashes');

// --- upgrading from the version that also stored an enable flag -----------
//
// The effective permission state must survive the upgrade. The flag was the only thing that made a
// stored list live, so an upgrade that ignored it would activate every list a previous administrator
// had deliberately switched off; an upgrade that broke on it would take a working public fetch
// dark. Both directions are asserted below, and a list the flag did not make live stays inert until
// somebody deliberately saves a list on the screen.

wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', legacyEnabled: '1', legacyList: 'get_campuses'),
	'an enabled legacy list keeps working after the upgrade, without a save'
);
wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_news', legacyEnabled: '1', legacyList: "get_campuses\nget_news"),
	'and it keeps exempting every name it always did'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'heartbeat', legacyEnabled: '1', legacyList: 'get_campuses'),
	'and nothing more than it always did'
);
wpaw_assert_true(
	wpaw_test_request('website', 'ajax', action: 'get_campuses', legacyEnabled: '1', legacyList: 'get_campuses'),
	'website scope still ignores the list after the upgrade'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', legacyList: 'get_campuses'),
	'a legacy list with no enable flag at all never comes into force'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', legacyEnabled: '0', legacyList: 'get_campuses'),
	'a legacy list the flag switched off never comes into force'
);
foreach (['0', '', 'yes', 'on', 1, true, ['1']] as $flag) {
	wpaw_assert_true(
		wpaw_test_request('admin', 'ajax', '198.51.100.0/24', '203.0.113.9', 'get_campuses', null, $flag, 'get_campuses'),
		sprintf('a legacy enable flag of %s is not "on", so its list stays inert', var_export($flag, true))
	);
}
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', legacyEnabled: '1', legacyList: ['get_campuses']),
	'a non-string legacy list exempts nothing'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', legacyEnabled: '1', legacyList: "get_campuses\ninvalid\x00action"),
	'a malformed legacy list fails closed as a whole'
);

// A SAVED STEADY-STATE ROW WINS OUTRIGHT, including the deliberately empty one. This is what stops
// an empty save from being undone by a row the upgrade left behind.
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: '', legacyEnabled: '1', legacyList: 'get_campuses'),
	'an empty save revokes the exemption and the legacy list cannot resurrect it'
);
wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_news', list: 'get_news', legacyEnabled: '1', legacyList: 'get_campuses'),
	'a saved list replaces the legacy one entirely'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', list: 'get_news', legacyEnabled: '0', legacyList: 'get_campuses'),
	'and a saved list is applied even when the obsolete flag it once belonged to said off'
);

// The state report exists so the administrator is told when a stored list is being ignored.

wpaw_test_reset([]);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'a fresh install reports an empty list');

wpaw_test_reset(['test_public_ajax_list' => '']);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'a saved empty list reports empty');

wpaw_test_reset(['test_public_ajax_list' => "# public endpoints\n\n"]);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'a comment-only list reports empty');

wpaw_test_reset(['test_public_ajax_list' => "get_campuses\nget_news"]);
wpaw_assert_same('usable', wpaw_gate_public_ajax_actions_state(), 'a valid list is reported as usable');

wpaw_test_reset(['test_public_ajax_list' => "get_campuses\nbroken\x00entry"]);
wpaw_assert_same('malformed', wpaw_gate_public_ajax_actions_state(), 'one invalid entry makes the whole list malformed');

wpaw_test_reset(['test_public_ajax_list' => ['get_campuses']]);
wpaw_assert_same('malformed', wpaw_gate_public_ajax_actions_state(), 'a non-string row is malformed, not absent');

wpaw_test_reset(['test_public_ajax_list' => "\t"]);
wpaw_assert_same('malformed', wpaw_gate_public_ajax_actions_state(), 'a control-only list is malformed, not empty');

// An upgrading install reports what its obsolete pair left in force, and says so when it left names
// behind that are not in force.
wpaw_test_reset(['test_public_ajax_legacy_actions' => 'get_campuses']);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'a legacy list left switched off reports empty');
wpaw_assert_true(wpaw_public_ajax_legacy_parked(), 'and is reported as parked so the screen can explain it');

wpaw_test_reset(['test_public_ajax_legacy_enabled' => '0', 'test_public_ajax_legacy_actions' => "# note\nget_campuses"]);
wpaw_assert_true(wpaw_public_ajax_legacy_parked(), 'comment lines in a parked list do not hide it');

wpaw_test_reset(['test_public_ajax_legacy_enabled' => '1', 'test_public_ajax_legacy_actions' => 'get_campuses']);
wpaw_assert_same('usable', wpaw_gate_public_ajax_actions_state(), 'an enabled legacy list is still in force and reports usable');
wpaw_assert_false(wpaw_public_ajax_legacy_parked(), 'and is not reported as parked');

wpaw_test_reset(['test_public_ajax_legacy_enabled' => '0', 'test_public_ajax_legacy_actions' => 'get_campuses', 'test_public_ajax_list' => '']);
wpaw_assert_false(wpaw_public_ajax_legacy_parked(), 'once a steady-state row exists the legacy rows are no longer reported');

// --- disabled: nothing at all ---------------------------------------------

wpaw_assert_false(wpaw_test_request('disabled', 'frontend'), 'disabled scope denies nothing on the frontend');
wpaw_assert_false(wpaw_test_request('disabled', 'admin'), 'disabled scope denies nothing in the dashboard');
wpaw_assert_false(wpaw_test_request('disabled', 'login'), 'disabled scope denies nothing at login');
wpaw_assert_false(
	wpaw_test_request('disabled', 'ajax', action: 'heartbeat'),
	'disabled scope denies nothing on AJAX either, configured list or not'
);

// --- an allowlisted client passes everywhere ------------------------------

foreach (['website', 'admin', 'disabled'] as $scope) {
	foreach (['frontend', 'admin', 'login', 'ajax'] as $surface) {
		wpaw_assert_false(
			wpaw_test_request($scope, $surface, '203.0.113.0/24'),
			sprintf('an allowlisted client passes %s scope on the %s surface', $scope, $surface)
		);
	}
}

// --- the fail-safe --------------------------------------------------------

foreach (['website', 'admin'] as $scope) {
	foreach (['frontend', 'admin', 'login'] as $surface) {
		wpaw_assert_false(
			wpaw_test_request($scope, $surface, ''),
			sprintf('an empty allowlist restricts nothing in %s scope on the %s surface', $scope, $surface)
		);
		wpaw_assert_false(
			wpaw_test_request($scope, $surface, "# only a comment\n\n"),
			sprintf('an allowlist of comments and blanks restricts nothing in %s scope on the %s surface', $scope, $surface)
		);
	}
}

// --- the login path is derived, never hardcoded ---------------------------

wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24']);
wpaw_test_set('login_url', 'https://example.test/not-the-usual-login/');
$_SERVER['REQUEST_URI'] = '/not-the-usual-login/';
wpaw_assert_true(wpaw_gate_should_deny(), 'a relocated login form is denied because the path comes from wp_login_url()');

$_SERVER['REQUEST_URI'] = '/wp-login.php';
wpaw_assert_false(wpaw_gate_should_deny(), 'a path that is not the site login form is treated as frontend');

wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24']);
wpaw_test_set('login_url', 'https://example.test/wordpress/wp-login.php');
$_SERVER['REQUEST_URI'] = '/wordpress/wp-login.php';
wpaw_assert_true(wpaw_gate_should_deny(), 'a subdirectory install\'s login path is matched with its prefix');

// --- an unrecognised or absent stored value resolves to the host fallback ---

wpaw_test_reset(['test_scope' => 'nonsense-from-a-restore', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'an unrecognised stored scope resolves to the host fallback, never to disabled');
wpaw_assert_true(wpaw_gate_should_deny(), 'an unrecognised stored scope still gates the frontend under a website fallback');

wpaw_test_reset(['test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'an absent stored scope resolves to the host fallback');

wpaw_test_reset(['test_scope' => '', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'an empty stored scope resolves to the host fallback');

wpaw_test_reset(['test_scope' => 'WEBSITE', 'test_allowlist' => '198.51.100.0/24']);
wpaw_assert_same('website', wpaw_scope(), 'scope values are compared exactly; a near-miss is treated as corruption');

wpaw_test_done('scope-test');
