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
const WP_AWESOME_OPTION_PUBLIC_AJAX_ENABLED = 'test_public_ajax_enabled';
const WP_AWESOME_OPTION_PUBLIC_AJAX_ACTIONS = 'test_public_ajax';

require_once dirname(__DIR__) . '/src/load.php';

/**
 * @param 'frontend'|'admin'|'login'|'ajax' $surface
 * @param mixed $public the stored public-AJAX-action list, or null to leave the option absent
 * @param mixed $enabled the public-AJAX enable toggle, or null to leave the option absent
 */
function wpaw_test_request(string $scope, string $surface, string $allowlist = '198.51.100.0/24', string $remote = '203.0.113.9', mixed $action = null, mixed $public = null, mixed $enabled = null): bool {
	$options = ['test_scope' => $scope, 'test_allowlist' => $allowlist, 'test_proxies' => ''];

	if (null !== $public) {
		$options['test_public_ajax'] = $public;
	}

	if (null !== $enabled) {
		$options['test_public_ajax_enabled'] = $enabled;
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
	wpaw_test_request('website', 'ajax', action: 'get_campuses', public: "get_campuses", enabled: '1'),
	'website scope gates a configured public action too, even with the exemption section enabled; it belongs to admin scope alone'
);

// --- admin: dashboard and login only --------------------------------------

wpaw_assert_false(wpaw_test_request('admin', 'frontend'), 'admin scope leaves the public frontend public');
wpaw_assert_true(wpaw_test_request('admin', 'admin'), 'admin scope denies the dashboard');
wpaw_assert_true(wpaw_test_request('admin', 'login'), 'admin scope denies the login form');

// --- admin: AJAX is enumerated, never exempt wholesale ---------------------
//
// `admin-ajax.php` is shared by the public frontend and the dashboard, so `admin` scope used to
// exempt it wholesale. That also exempted every privileged handler core, plugins and the theme
// register. The exemption is now an optional section, OFF by default, holding an empty list by
// default, editable only by an administrator.

wpaw_assert_true(wpaw_test_request('admin', 'ajax'), 'admin scope denies AJAX when no public action is configured');
wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: 'heartbeat'), 'admin scope denies privileged AJAX actions');
wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: ['get_campuses']), 'a non-scalar action is never exempt');

// OFF BY DEFAULT. A stored list is preserved while the section is off, but it is not applied.
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: 'get_campuses'),
	'an absent enable setting is treated as disabled, even when a list is stored'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: 'get_campuses', enabled: '0'),
	'a stored list is not applied while the section is explicitly disabled'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: '', enabled: '1'),
	'an enabled section with an empty list exempts nothing'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: "# public endpoints\n\n", enabled: '1'),
	'an enabled section with a comment-only list exempts nothing'
);

$public = "get_campuses\nget_news";

wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: $public, enabled: '1'),
	'an enabled section exempts a configured public action in admin scope'
);
wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'get_news', public: $public, enabled: '1'),
	'every configured action is exempt, not only the first'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'heartbeat', public: $public, enabled: '1'),
	'an unlisted action stays gated even when an enabled list exists'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'admin', action: 'get_campuses', public: $public, enabled: '1'),
	'the exemption is scoped to AJAX requests; a dashboard request carrying the same action parameter is still gated'
);

// The gate never consults the current user. An authenticated administrator on a blocked address is
// gated like anyone else; the exemption is a property of the action name, not of the session.

wpaw_test_reset(['test_scope' => 'admin', 'test_allowlist' => '198.51.100.0/24', 'test_public_ajax_enabled' => '1']);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
wpaw_test_set('can_manage', true);
$_GET = ['action' => 'heartbeat'];
$_POST = [];
$_REQUEST = $_GET;
wpaw_assert_true(wpaw_gate_should_deny(), 'admin scope gates a privileged AJAX action even for a signed-in administrator');

wpaw_test_reset([
	'test_scope'                => 'admin',
	'test_allowlist'            => '198.51.100.0/24',
	'test_public_ajax'          => 'get_campuses',
	'test_public_ajax_enabled'  => '1',
]);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
wpaw_test_set('can_manage', true);
$_GET = ['action' => 'get_campuses'];
$_POST = [];
$_REQUEST = $_GET;
wpaw_assert_false(wpaw_gate_should_deny(), 'a configured action is exempt for an authenticated request too, because one name covers both handlers');
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'GET_CAMPUSES', public: $public, enabled: '1'),
	'action matching is exact and case-sensitive'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses_extra', public: $public, enabled: '1'),
	'action matching is exact, never a prefix'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: ' get_campuses', public: $public, enabled: '1'),
	'action matching does not trim surrounding whitespace'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: ['get_campuses'], public: $public, enabled: '1'),
	'an array action is not exempt even when its value is on the list'
);

// PHP request_order may differ from WordPress's eventual GET-then-POST merge.
wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: 'get_campuses', enabled: '1');
$_POST = ['action' => 'future_privileged_action'];
wpaw_assert_true(wpaw_gate_should_deny(), 'POST action overrides a public GET action regardless of the early REQUEST value');
$_GET = ['action' => 'future_privileged_action'];
$_POST = [];
$_REQUEST = ['action' => 'get_campuses'];
wpaw_assert_true(wpaw_gate_should_deny(), 'a cookie or stale REQUEST action cannot exempt a different dispatched action');
$_GET = ['action' => 'get_campuses'];
$_POST = ['action' => ['get_campuses']];
wpaw_assert_true(wpaw_gate_should_deny(), 'a malformed POST action overrides a public GET action and fails closed');
wpaw_test_set('is_admin', false);
wpaw_assert_true(wpaw_gate_should_deny(), 'an AJAX request remains gated even if is_admin is false');

foreach ([true, 1, ['1'], '01'] as $bad_toggle) {
	wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: 'get_campuses', enabled: $bad_toggle), 'a malformed enable setting never grants an exemption');
}

// Malformed stored configuration fails CLOSED, as a whole. A row that mixes a typo into the list is
// evidence the list is not what its author believed; exempting the readable part would grant
// exactly the access nobody configured.

wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: ['get_campuses'], enabled: '1'),
	'a non-string stored list exempts nothing'
);
foreach (["get_campuses\ninvalid\x00action", "get_campuses\ninvalid\taction", "get_campuses\ninvalid\xff"] as $malformed) {
	wpaw_assert_true(
		wpaw_test_request('admin', 'ajax', action: 'get_campuses', public: $malformed, enabled: '1'),
		'a control character or invalid UTF-8 voids the entire list'
	);
}
wpaw_assert_false(
	wpaw_test_request('admin', 'ajax', action: 'acf/fields/relationship/query', public: 'acf/fields/relationship/query', enabled: '1'),
	'a slash-containing action can be exempted exactly'
);
wpaw_assert_true(
	wpaw_test_request('admin', 'ajax', action: 'acf/fields/relationship/query_extra', public: 'acf/fields/relationship/query', enabled: '1'),
	'a slash-containing action does not match a longer name'
);
wpaw_test_request('admin', 'ajax', action: 'acf/fields/relationship/query', public: 'acf/fields/relationship/query', enabled: '1');
$_POST = ['action' => 'future_privileged_action'];
wpaw_assert_true(wpaw_gate_should_deny(), 'POST privileged action overrides the listed slash-containing GET action');
$_GET = ['action' => 'future_privileged_action'];
$_POST = ['action' => 'acf/fields/relationship/query'];
wpaw_assert_false(wpaw_gate_should_deny(), 'a listed slash-containing POST action overrides GET');
wpaw_assert_false(wpaw_test_request('admin', 'ajax', action: 'public action#1', public: 'public action#1', enabled: '1'), 'printable names including spaces and hash match literally');
wpaw_assert_false(wpaw_test_request('admin', 'ajax', action: '0', public: '0', enabled: '1'), 'the literal action 0 is not dropped by list parsing');
wpaw_assert_true(wpaw_test_request('admin', 'ajax', action: "a'b", public: "a'b", enabled: '1'), 'raw quotes cannot exempt a different action after WordPress adds slashes');
wpaw_assert_false(wpaw_test_request('admin', 'ajax', action: "a'b", public: "a\\'b", enabled: '1'), 'matching uses the exact action WordPress dispatches after adding slashes');

// Disabling is explicit and non-destructive: the list stays in the database and takes effect again
// when the section is re-enabled. Nothing in the settings path deletes it.

wpaw_test_reset([
	'test_scope'               => 'admin',
	'test_allowlist'           => '198.51.100.0/24',
	'test_public_ajax'         => 'get_campuses',
	'test_public_ajax_enabled' => '1',
]);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
$_GET = ['action' => 'get_campuses'];
$_POST = [];
$_REQUEST = $_GET;
wpaw_assert_false(wpaw_gate_should_deny(), 'the enabled section exempts the stored action');

update_option('test_public_ajax_enabled', '0');
wpaw_assert_true(wpaw_gate_should_deny(), 'disabling the section gates the action again');
wpaw_assert_same('get_campuses', get_option('test_public_ajax'), 'disabling the section preserves the stored list');

update_option('test_public_ajax_enabled', '1');
wpaw_assert_false(wpaw_gate_should_deny(), 're-enabling restores the exemption without re-entering the list');

// The state report exists so the administrator is told when a stored list is being ignored.

wpaw_test_reset([]);
wpaw_assert_same('disabled', wpaw_gate_public_ajax_actions_state(), 'the exemption section is reported as disabled by default');

wpaw_test_reset(['test_public_ajax' => 'get_campuses']);
wpaw_assert_same('disabled', wpaw_gate_public_ajax_actions_state(), 'an absent enable setting reports disabled even when a list is stored');

wpaw_test_reset(['test_public_ajax' => 'get_campuses', 'test_public_ajax_enabled' => '0']);
wpaw_assert_same('disabled', wpaw_gate_public_ajax_actions_state(), 'an explicitly disabled section is reported as disabled');

wpaw_test_reset(['test_public_ajax_enabled' => '1']);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'an enabled section with no list is reported as empty');

wpaw_test_reset(['test_public_ajax' => '', 'test_public_ajax_enabled' => '1']);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'an enabled section with an empty list is reported as empty');

wpaw_test_reset(['test_public_ajax' => "# public endpoints\n\n", 'test_public_ajax_enabled' => '1']);
wpaw_assert_same('empty', wpaw_gate_public_ajax_actions_state(), 'an enabled section with a comment-only list is reported as empty');

wpaw_test_reset(['test_public_ajax' => "get_campuses\nget_news", 'test_public_ajax_enabled' => '1']);
wpaw_assert_same('usable', wpaw_gate_public_ajax_actions_state(), 'an enabled section with a valid list is reported as usable');

wpaw_test_reset(['test_public_ajax' => "get_campuses\nbroken\x00entry", 'test_public_ajax_enabled' => '1']);
wpaw_assert_same('malformed', wpaw_gate_public_ajax_actions_state(), 'one invalid entry makes the whole enabled list malformed');

wpaw_test_reset(['test_public_ajax' => ['get_campuses'], 'test_public_ajax_enabled' => '1']);
wpaw_assert_same('malformed', wpaw_gate_public_ajax_actions_state(), 'a non-string list is reported as malformed while enabled');

wpaw_test_reset(['test_public_ajax' => "\t", 'test_public_ajax_enabled' => '1']);
wpaw_assert_same('malformed', wpaw_gate_public_ajax_actions_state(), 'a control-only list is malformed, not empty');

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
