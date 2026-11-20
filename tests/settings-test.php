<?php
/**
 * The single settings screen: what it saves, what it refuses to save, and what it renders.
 *
 * The refusals matter more than the saves. Every one of them is a way an administrator could lock
 * themselves out of a machine they have no console access to.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const WP_AWESOME_OPTION_SCOPE               = 'test_scope';
const WP_AWESOME_OPTION_ALLOWLIST           = 'test_allowlist';
const WP_AWESOME_OPTION_TRUSTED_PROXIES     = 'test_proxies';
const WP_AWESOME_OPTION_MAINTENANCE         = 'test_maintenance';
const WP_AWESOME_OPTION_PUBLIC_AJAX_LIST    = 'test_public_ajax_list';
// The obsolete pair: read only while the list above has never been saved, and never registered, so
// no control can write it back.
const WP_AWESOME_OPTION_PUBLIC_AJAX_ENABLED = 'test_public_ajax_legacy_enabled';
const WP_AWESOME_OPTION_PUBLIC_AJAX_ACTIONS = 'test_public_ajax_legacy_actions';

$log = tempnam(sys_get_temp_dir(), 'wpaw-log-');
file_put_contents($log, sprintf(
	"198.51.100.77 - - [%s] \"GET / HTTP/1.1\" 403 153 \"-\" \"Mozilla/5.0\"\n",
	gmdate('d/M/Y:H:i:s O', time() - 3600)
));
define('WP_AWESOME_GATE_ACCESS_LOG_PATH', $log);

require_once dirname(__DIR__) . '/src/load.php';

// --- registration ---------------------------------------------------------

wpaw_test_reset([]);
wpaw_settings_register();

$registered = wpaw_test_get('settings')['options'];
wpaw_assert_same(
	['test_scope', 'test_allowlist', 'test_proxies', 'test_maintenance', 'test_public_ajax_list'],
	array_keys($registered),
	'all five controls are registered, under the host\'s own option keys'
);
wpaw_assert_same('', $registered['test_public_ajax_list']['args']['default'], 'the allowed AJAX list registers with an empty default');
wpaw_assert_same('wpaw_sanitize_public_ajax_actions', $registered['test_public_ajax_list']['args']['sanitize_callback'], 'the list is validated when saved');
wpaw_assert_true(
	! isset($registered['test_public_ajax_legacy_enabled']) && ! isset($registered['test_public_ajax_legacy_actions']),
	'the obsolete enable flag and its list are not registered, so no control can write them and nothing reads them on a normal save'
);
wpaw_assert_same(
	1,
	count(array_unique(array_column($registered, 'group'))),
	'every control belongs to the same settings group, so one Save button saves the screen'
);

wpaw_settings_menu();
wpaw_assert_same('manage_options', wpaw_test_get('settings')['page']['capability'], 'the screen requires manage_options');
wpaw_assert_same('Site Access', wpaw_test_get('settings')['page']['menu_title'], 'the descriptive existing menu name is retained');
wpaw_assert_same('Site Access', wpaw_test_get('settings')['page']['page_title'], 'and the page title matches the menu item');

// --- the scope control ----------------------------------------------------

foreach (['disabled', 'website', 'admin'] as $scope) {
	wpaw_test_reset(['test_scope' => 'website']);
	wpaw_assert_same($scope, wpaw_sanitize_scope($scope), sprintf('%s is an accepted scope', $scope));
}

wpaw_test_reset(['test_scope' => 'website']);
wpaw_assert_same('website', wpaw_sanitize_scope('nonsense'), 'a value outside the three is refused and the stored scope is kept');
wpaw_assert_same(1, count(wpaw_test_get('settings_errors')), 'and the refusal is reported to the administrator');

wpaw_test_reset(['test_scope' => 'corrupt']);
wpaw_assert_same('website', wpaw_sanitize_scope('nonsense'), 'refusing a save on an already-corrupt option keeps the resolved fallback, not the corruption');

// --- the maintenance control ----------------------------------------------

wpaw_test_reset([]);
wpaw_assert_same('1', wpaw_sanitize_toggle('1'), 'the maintenance checkbox stores 1 when ticked');
wpaw_assert_same('0', wpaw_sanitize_toggle('on'), 'anything else stores 0');
wpaw_assert_same('0', wpaw_sanitize_toggle(''), 'an unticked checkbox stores 0');

// --- the allowlist --------------------------------------------------------

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'website'];
wpaw_assert_same('203.0.113.9/32', wpaw_sanitize_allowlist('198.51.100.0/24'), 'a save that would lock the administrator out is refused');
wpaw_assert_same('lockout', wpaw_test_get('settings_errors')[0]['code'], 'and the refusal names the reason');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'admin'];
wpaw_assert_same('203.0.113.9/32', wpaw_sanitize_allowlist('198.51.100.0/24'), 'the lockout guard applies in admin scope too — losing the dashboard is the same lockout');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'website', 'wp_awesome_force_save' => '1'];
wpaw_assert_same('198.51.100.0/24', wpaw_sanitize_allowlist('198.51.100.0/24'), 'the override saves a list the administrator is not on, deliberately');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_POST                  = ['test_scope' => 'disabled'];
wpaw_assert_same('198.51.100.0/24', wpaw_sanitize_allowlist('198.51.100.0/24'), 'no lockout is possible while the scope is disabled, so the guard does not fire');

wpaw_test_reset(['test_allowlist' => '203.0.113.9/32']);
$_POST = ['test_scope' => 'website'];
wpaw_assert_same('203.0.113.9/32', wpaw_sanitize_allowlist("203.0.113.9/32\nnot-an-ip"), 'one invalid entry refuses the whole save');
wpaw_assert_same('bad_entry', wpaw_test_get('settings_errors')[0]['code'], 'and says which entry');

wpaw_test_reset([]);
$_POST = ['test_scope' => 'disabled'];
wpaw_assert_same(
	"# Office\n203.0.113.3/32 # office inline\n# Client\n203.0.113.20 # client inline\n# Trailing note",
	wpaw_sanitize_allowlist("# Client\\n203.0.113.20 # client inline\n# Office\n203.0.113.3/32 # office inline\n\n# Trailing note"),
	'a save normalises literal newlines, sorts entries, and keeps attached and inline comments'
);

// --- allowed AJAX actions -------------------------------------------------
//
// This list replaced a blanket `admin`-scope AJAX exemption, and the checkbox that once switched it
// on is gone: THE LIST IS THE SETTING. Every refusal below is the safe direction — an action that
// cannot be validated is not saved, and a save is one list or none.

wpaw_test_reset([]);
wpaw_assert_same('', wpaw_sanitize_public_ajax_actions(''), 'an empty list is a valid save and stores no exemptions');

wpaw_test_reset([]);
wpaw_assert_same(
	"get_campuses\nget_news",
	wpaw_sanitize_public_ajax_actions("get_news\nget_campuses"),
	'a valid list saves one action per line, in a stable order'
);

wpaw_test_reset([]);
wpaw_assert_same(
	"get_campuses\nget_news",
	wpaw_sanitize_public_ajax_actions('get_campuses\\nget_news'),
	'literal newline escapes pasted by automation are normalised to line breaks'
);

wpaw_test_reset([]);
wpaw_assert_same(
	"# Campus map\nget_campuses",
	wpaw_sanitize_public_ajax_actions("# Campus map\nget_campuses\n\n"),
	'comments and blank lines are kept and blank lines dropped'
);

wpaw_test_reset(['test_public_ajax_list' => 'get_news']);
wpaw_assert_same(
	'get_news',
	wpaw_sanitize_public_ajax_actions("get_campuses\ninvalid\x00action"),
	'one invalid action name refuses the whole save and keeps the stored list'
);
wpaw_assert_same('bad_ajax_action', wpaw_test_get('settings_errors')[0]['code'], 'and names the reason');

wpaw_test_reset(['test_public_ajax_list' => 'get_news']);
wpaw_assert_same(
	"acf/fields/relationship/query\npublic action#1",
	wpaw_sanitize_public_ajax_actions("acf/fields/relationship/query\npublic action#1"),
	'slash and other printable characters are retained in exact action names'
);
foreach (["get_campuses\ninvalid\taction", "get_campuses\ninvalid\xff"] as $bad) {
	wpaw_assert_same('get_news', wpaw_sanitize_public_ajax_actions($bad), 'control characters and invalid UTF-8 refuse the whole save');
}

wpaw_test_reset(['test_public_ajax_list' => 'get_news']);
wpaw_assert_same('get_news', wpaw_sanitize_public_ajax_actions(['future_privileged_action']), 'a malformed submitted list cannot become an action through string conversion');
wpaw_assert_same('bad_ajax_action', wpaw_test_get('settings_errors')[0]['code'], 'a non-string submission is refused');

// --- upgrading from the version that also stored an enable flag -----------
//
// A save carries the whole permission state with it, so a request that does not deliberately set
// the list must not change what is exempt. `options.php` posts the textarea whenever this screen is
// saved; the cases below are the ones where the field is absent or unusable, and none of them may
// promote a row the older version left switched off.

wpaw_test_reset(['test_public_ajax_legacy_enabled' => '1', 'test_public_ajax_legacy_actions' => "get_news\nget_campuses"]);
wpaw_assert_same(
	"get_news\nget_campuses",
	wpaw_sanitize_public_ajax_actions(null),
	'a missing submission keeps the legacy list exactly as it was already in force'
);

wpaw_test_reset(['test_public_ajax_legacy_enabled' => '0', 'test_public_ajax_legacy_actions' => 'get_campuses']);
wpaw_assert_same('', wpaw_sanitize_public_ajax_actions(null), 'a missing submission cannot adopt a legacy list that was switched off');
wpaw_assert_same('', wpaw_sanitize_public_ajax_actions("get_news\ninvalid\x00action"), 'an invalid submission cannot adopt it either');
wpaw_assert_same('get_campuses', get_option('test_public_ajax_legacy_actions'), 'and the parked names are neither adopted nor destroyed');

wpaw_test_reset([]);
wpaw_assert_same('', wpaw_sanitize_public_ajax_actions(null), 'a fresh install has nothing to preserve when the field is missing');

// An empty save is a deliberate revocation, and it must survive the upgrade fallback.
wpaw_test_reset([
	'test_scope'                      => 'admin',
	'test_allowlist'                  => '198.51.100.0/24',
	'test_public_ajax_legacy_enabled' => '1',
	'test_public_ajax_legacy_actions' => 'get_campuses',
]);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
$_GET = ['action' => 'get_campuses'];
$_POST = [];
wpaw_assert_false(wpaw_gate_should_deny(), 'the legacy list is in force before anybody saves');
update_option('test_public_ajax_list', wpaw_sanitize_public_ajax_actions(''));
wpaw_assert_true(wpaw_gate_should_deny(), 'saving an empty list revokes it');
wpaw_assert_same('get_campuses', get_option('test_public_ajax_legacy_actions'), 'the old row is preserved and stays inert');

// A deliberate save of a valid list is the one path that adopts names, including names that were
// parked: the screen renders those as absent, so adopting them takes typing them.
wpaw_test_reset([
	'test_scope'                      => 'admin',
	'test_allowlist'                  => '198.51.100.0/24',
	'test_public_ajax_legacy_enabled' => '0',
	'test_public_ajax_legacy_actions' => 'get_campuses',
]);
update_option('test_public_ajax_list', wpaw_sanitize_public_ajax_actions("get_campuses\nget_news"));
wpaw_assert_same("get_campuses\nget_news", get_option('test_public_ajax_list'), 'the deliberate save stores the typed list');
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_GET                    = ['action' => 'get_campuses'];
$_POST                   = [];
$_REQUEST                = $_GET;
wpaw_assert_false(wpaw_gate_should_deny(), 'and the saved action is exempt from the admin-scope gate');
$_GET = ['action' => 'heartbeat'];
$_REQUEST = $_GET;
wpaw_assert_true(wpaw_gate_should_deny(), 'while an unlisted action stays gated');
$_GET = ['action' => 'get_campuses'];
$_POST = ['action' => 'future_privileged_action'];
wpaw_assert_true(wpaw_gate_should_deny(), 'and a POST action overrides the listed GET action');

// The saved string is exactly what the gate consumes, and a second sanitization pass — `add_option`
// performs one on the first save of a row — must not change it.

wpaw_test_reset([]);
$once = wpaw_sanitize_public_ajax_actions("# UTF-8\r\naąb");
wpaw_assert_same("# UTF-8\naąb", $once, 'UTF-8 and comments survive the round trip');
wpaw_assert_same($once, wpaw_sanitize_public_ajax_actions($once), 'a second sanitization pass changes nothing');
wpaw_assert_same(['aąb'], wpaw_parse_public_ajax_actions($once), 'the saved UTF-8 action parses exactly');

wpaw_test_reset([
	'test_scope'            => 'admin',
	'test_allowlist'        => '198.51.100.0/24',
	'test_public_ajax_list' => $once,
]);
wpaw_test_set('is_admin', true);
wpaw_test_set('doing_ajax', true);
$_GET = ['action' => 'aąb'];
$_POST = [];
wpaw_assert_false(wpaw_gate_should_deny(), 'the saved UTF-8 action matches the gate exactly');
$_GET = ['action' => 'aąb-extra'];
wpaw_assert_true(wpaw_gate_should_deny(), 'a near-match remains gated after the round trip');

// --- trusted proxies ------------------------------------------------------

wpaw_test_reset(['test_proxies' => '10.0.0.0/24']);
wpaw_assert_same('10.0.0.0/24', wpaw_sanitize_proxies('nonsense'), 'an invalid trusted proxy refuses the save');
wpaw_assert_same('bad_proxy', wpaw_test_get('settings_errors')[0]['code'], 'and says so');

wpaw_test_reset(['test_proxies' => '']);
wpaw_assert_same('10.0.0.0/24', wpaw_sanitize_proxies('10.0.0.0/24'), 'a valid trusted proxy saves');

// --- the screen -----------------------------------------------------------

wpaw_test_reset(['test_scope' => 'website', 'test_allowlist' => '203.0.113.0/24']);

ob_start();
wpaw_settings_render_page();
wpaw_assert_same('', (string) ob_get_clean(), 'a user without manage_options renders nothing at all');

wpaw_test_set('can_manage', true);

ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();

wpaw_assert_contains('name="test_scope"', $screen, 'the scope control posts under the host\'s option key');
wpaw_assert_contains('value="disabled"', $screen, 'the scope control offers disabled');
wpaw_assert_contains('value="website"', $screen, 'the scope control offers website');
wpaw_assert_contains('value="admin"', $screen, 'the scope control offers admin');
wpaw_assert_contains('name="test_maintenance"', $screen, 'the maintenance checkbox is on the same screen');
wpaw_assert_contains('name="test_allowlist"', $screen, 'the allowlist textarea is on the same screen');
wpaw_assert_contains('name="test_proxies"', $screen, 'the trusted-proxy textarea is on the same screen');
wpaw_assert_contains('name="test_public_ajax_list"', $screen, 'the allowed AJAX action list is on the same screen');
wpaw_assert_not_contains('name="test_public_ajax_legacy_enabled"', $screen, 'the obsolete enable checkbox is gone from the screen');
wpaw_assert_contains('exempts nothing', $screen, 'the screen states that an empty list exempts nothing');
wpaw_assert_contains('/wp-admin/admin-ajax.php', $screen, 'the help names the endpoint the list applies to');
wpaw_assert_contains('signed in or not', $screen, 'the screen states that one entry covers both handlers, so privileged action names are a risk');
wpaw_assert_contains('Site Access', $screen, 'the page heading matches the menu item');

// THE LEFT LABEL BELONGS TO THE TEXTAREA: `for` names its `id`, so the label is a real label and
// not a caption next to a control it does not own.
wpaw_assert_contains('<label for="wp-awesome-public-ajax">Allowed AJAX actions</label>', $screen, 'the row is labelled Allowed AJAX actions');
wpaw_assert_contains('id="wp-awesome-public-ajax"', $screen, 'and that label points at the textarea\'s own id');

// The list sits DIRECTLY below the IP allowlist and above the trusted-proxy control, and it is the
// only control in that row: no checkbox, so no stored-yet-inert state.
$allowlist_pos = strpos($screen, 'name="test_allowlist"');
$actions_pos   = strpos($screen, 'name="test_public_ajax_list"');
$proxies_pos   = strpos($screen, 'name="test_proxies"');

wpaw_assert_true(false !== $allowlist_pos && false !== $actions_pos && false !== $proxies_pos, 'all three controls render');
wpaw_assert_true($allowlist_pos < $actions_pos, 'the allowed AJAX list begins directly below the IP allowlist');
wpaw_assert_true($actions_pos < $proxies_pos, 'and ends before the trusted-proxy control');
wpaw_assert_same(1, substr_count($screen, 'name="test_public_ajax_list"'), 'the list is posted once, by the textarea alone');
wpaw_assert_contains('name="wp_awesome_force_save"', $screen, 'the self-lockout override is on the same screen');
wpaw_assert_contains('198.51.100.77', $screen, 'a recently blocked address is listed');
wpaw_assert_contains('wpawAddAddress(\'198.51.100.77\')', $screen, 'and can be added to the allowlist with one click');
wpaw_assert_not_contains('Allow public access', $screen, 'the old public-access checkbox is gone, replaced by the scope control');

wpaw_test_reset(['test_scope' => 'corrupt-value', 'test_allowlist' => '203.0.113.0/24']);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_contains('corrupt-value', $screen, 'an unrecognised stored scope is surfaced rather than hidden behind the fallback');

// A list that is being ignored must not render as a list that is in force.
wpaw_test_reset([
	'test_scope'            => 'admin',
	'test_allowlist'        => '203.0.113.0/24',
	'test_public_ajax_list' => "get_campuses\nbroken\x00entry",
]);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_contains('cannot be used', $screen, 'a malformed stored AJAX list is surfaced as being ignored');
wpaw_assert_not_contains('bypass the IP gate', $screen, 'and no bypass is claimed for it');

wpaw_test_reset([
	'test_scope'            => 'admin',
	'test_allowlist'        => '203.0.113.0/24',
	'test_public_ajax_list' => "get_campuses\nget_news",
]);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_contains('2 allowed AJAX actions bypass the IP gate', $screen, 'the screen states how many actions bypass the gate and that the exemption is not authentication-aware');
wpaw_assert_contains('signed in or not', $screen, 'and repeats the anonymous-caller risk next to the count');
wpaw_assert_contains(">get_campuses\nget_news</textarea>", $screen, 'and the list in force is the one the textarea shows');

// `website` scope ignores the list, so the screen must not claim the actions bypass anything.
wpaw_test_reset([
	'test_scope'            => 'website',
	'test_allowlist'        => '203.0.113.0/24',
	'test_public_ajax_list' => "get_campuses\nget_news",
]);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_not_contains('bypass the IP gate', $screen, 'website scope does not claim any action bypasses the gate');
wpaw_assert_contains('get_campuses', $screen, 'but the list is still visible for editing');

// UPGRADING: an enabled legacy list is still in force, so it is what the textarea shows.
wpaw_test_reset([
	'test_scope'                      => 'admin',
	'test_allowlist'                  => '203.0.113.0/24',
	'test_public_ajax_legacy_enabled' => '1',
	'test_public_ajax_legacy_actions' => 'get_campuses',
]);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_contains('>get_campuses</textarea>', $screen, 'a legacy list that is still in force is shown, so saving the screen does not lose it');
wpaw_assert_not_contains('preserved but not in force', $screen, 'and it is not described as parked');

// A legacy list that was switched off is PRESERVED BUT NOT SHOWN. Pre-filling it would adopt it on
// the next save of any field on this screen, which is exactly the silent activation an upgrade must
// not perform.
wpaw_test_reset([
	'test_scope'                      => 'admin',
	'test_allowlist'                  => '203.0.113.0/24',
	'test_public_ajax_legacy_enabled' => '0',
	'test_public_ajax_legacy_actions' => 'get_campuses',
]);
wpaw_test_set('can_manage', true);
ob_start();
wpaw_settings_render_page();
$screen = (string) ob_get_clean();
wpaw_assert_contains('preserved but not in force', $screen, 'a legacy list that was switched off is explained');
wpaw_assert_contains('test_public_ajax_legacy_actions', $screen, 'and the notice names where the names are kept');
wpaw_assert_not_contains('>get_campuses</textarea>', $screen, 'but the names are not pre-filled into the form');

unlink($log);

wpaw_test_done('settings-test');
