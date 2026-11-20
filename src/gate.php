<?php
/**
 * The gate itself.
 *
 * WHERE IT RUNS, AND WHY THAT IS NOT NEGOTIABLE. Enforcement happens at mu-plugin load — the
 * earliest point where `get_option()` works, because the database handle is set up before mu-plugins
 * are included — and NOT on a hook. That single position sits ahead of REST, XML-RPC, feeds, login
 * and the dashboard at once. Moving it to `admin_init`, or to any other hook, trades one check for a
 * list of entry points that has to be kept complete forever; the first one anybody forgets is a hole
 * nobody sees.
 *
 * WHAT IT PROTECTS, STATED HONESTLY. A denied request has already crossed the network, reached the
 * interpreter and booted WordPress. This protects CONTENT, not INFRASTRUCTURE: it does not blunt
 * brute-force or scanner load the way an edge allowlist does. Do not describe it as edge-grade.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Which runtime exemption applies, or an empty string for an ordinary HTTP request.
 *
 * WP-CLI is the documented recovery path from a bad allowlist, so gating it would make a lockout
 * unrecoverable without database access. Cron runs as an HTTP request on many hosts and would break
 * silently the moment an allowlist was populated.
 *
 * Returns WHICH exemption matched rather than a boolean, so a test can prove WP-CLI is exempt in its
 * own right and not merely because the test itself happens to run on the CLI.
 */
function wpaw_gate_runtime_exemption(): string {
	if (defined('WP_CLI') && WP_CLI) {
		return 'wp-cli';
	}

	if (defined('DOING_CRON') && DOING_CRON) {
		return 'cron';
	}

	if ('cli' === PHP_SAPI) {
		return 'cli-sapi';
	}

	return '';
}

/**
 * FAIL-SAFE: an empty or unparseable allowlist counts as "not restricting", never as "deny
 * everyone". A corrupt option must not brick a machine whose only route in is the site itself. The
 * settings screen surfaces the state as a warning instead.
 */
function wpaw_gate_restricting(): bool {
	return ! empty(wpaw_parse_list((string) get_option(wpaw_option_key('allowlist'), '')));
}

function wpaw_gate_ip_allowed(string $ip): bool {
	if ('' === $ip) {
		return false;
	}

	foreach (wpaw_parse_list((string) get_option(wpaw_option_key('allowlist'), '')) as $entry) {
		if (wpaw_ip_in_range($ip, $entry)) {
			return true;
		}
	}

	return false;
}

/**
 * Reduces a URL path to a comparable form: no query, no trailing slash, always leading slash.
 */
function wpaw_gate_normalize_path(string $path): string {
	$path = (string) parse_url($path, PHP_URL_PATH);
	$path = rtrim($path, '/');

	return '' === $path ? '/' : ($path[0] === '/' ? $path : '/' . $path);
}

/**
 * Whether this request is for the site's login form.
 *
 * The path is DERIVED from `wp_login_url()`, never matched against a literal `/wp-login.php`,
 * because the serving path is filterable and can change at any time.
 *
 * A host that KNOWS its login form has moved can say so through
 * `WP_AWESOME_GATE_LOGIN_PATHS`, which names a callable returning extra paths to treat as login
 * surface. See `wpaw_gate_extra_login_paths()`. That closes the limit below for hosts that opt in;
 * it cannot be closed generically, because the knowledge lives in a plugin that has not loaded yet.
 *
 * KNOWN LIMIT for hosts that do NOT opt in, and it is narrower than it first sounds. `wp_login_url()`
 * is filterable, but a plugin that relocates the login form registers its filter as an ordinary plugin, which loads AFTER
 * mu-plugins — so at enforcement time this derives the default path. On such a host the relocated
 * login FORM stays reachable from an address that is not on the list. Nothing else changes: the
 * dashboard is matched with `is_admin()`, which core decides before WordPress loads and no plugin
 * can move, so every authenticated request after that login is still denied. A consuming project
 * that uses a hide-login plugin should verify this surface itself.
 */
function wpaw_gate_request_is_login(): bool {
	$request = wpaw_gate_normalize_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
	$login   = wpaw_gate_normalize_path(wp_login_url());

	if ('/' !== $login && $request === $login) {
		return true;
	}

	return in_array($request, wpaw_gate_extra_login_paths(), true);
}

/**
 * Extra paths a host declares to be login surface.
 *
 * WHY A HOST CALLABLE RATHER THAN A FILTER. The knowledge of where a login form has moved lives in a
 * plugin that has not loaded when this runs, so `apply_filters('login_url', ...)` returns the
 * unfiltered default here. That is the same root cause as the limit documented above, so closing it
 * with a filter is circular. The host reads its own configuration directly — raw `get_option()`, no
 * filters — and hands back paths. Do NOT "improve" this into `apply_filters()`; it silently stops
 * working the moment you do.
 *
 * GATE THE REDIRECTOR, NOT ONLY THE FORM. Some relocation plugins 302 from the custom path to
 * `wp-login.php` rather than rendering there, in which case the render target is already covered and
 * the custom path leaks nothing. Declare it anyway: our boundary should not depend on the downstream
 * behaviour of a plugin we do not control, and a plugin that renders directly at the custom path is
 * exactly the case this exists for.
 *
 * FAILS CLOSED, MEANING IT CONTRIBUTES NOTHING. This runs before WordPress exists, so a throw here
 * takes the site down rather than denying a request. Anything that is not a callable returning a
 * list of strings yields no extra paths and leaves current behaviour unchanged — never a fatal, and
 * never a wildcard.
 *
 * @return list<string>
 */
function wpaw_gate_extra_login_paths(): array {
	if (! defined('WP_AWESOME_GATE_LOGIN_PATHS')) {
		return [];
	}

	$source = constant('WP_AWESOME_GATE_LOGIN_PATHS');

	if (! is_callable($source)) {
		return [];
	}

	try {
		$declared = $source();
	} catch (Throwable $error) {
		return [];
	}

	if (! is_array($declared)) {
		return [];
	}

	$paths = [];

	foreach ($declared as $path) {
		if (! is_string($path) || '' === trim($path)) {
			continue;
		}

		$normalized = wpaw_gate_normalize_path($path);

		if ('/' === $normalized) {
			continue;
		}

		$paths[] = $normalized;
	}

	return array_values(array_unique($paths));
}

/**
 * Whether this request targets an administrative surface.
 *
 * `is_admin()` is the path-independent signal: `WP_ADMIN` is defined by the dashboard entry script
 * before WordPress is loaded at all, so it is true wherever the dashboard is served from and cannot
 * be moved by configuration.
 */
function wpaw_gate_request_is_admin_surface(): bool {
	return is_admin() || wpaw_gate_request_is_login();
}

/** Whether an action is nonempty printable UTF-8, not an identifier or pattern. */
function wpaw_is_ajax_action_name(string $action): bool {
	return '' !== $action && 1 === preg_match('/\A[^\p{C}\p{Zl}\p{Zp}]+\z/u', $action);
}

/**
 * Parses a stored public-AJAX list, or null when it is not usable at all.
 *
 * Null is a REFUSAL, not an error to repair. A value that is not a string, or that contains one
 * entry which is not a plain action name, has no usable reading: the list's author cannot have meant
 * the readable remainder, because they also typed something that is not an action name. Exempting
 * the remainder would grant exactly the access nobody asked for.
 *
 * @return list<string>|null
 */
function wpaw_parse_public_ajax_actions(mixed $raw): ?array {
	if (! is_string($raw)) {
		return null;
	}

	// Only actual line breaks delimit entries. Reject controls before trimming, including in
	// comments, so malformed input cannot be silently repaired into a different exemption.
	$raw = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $raw);
	if (1 !== preg_match('/\A[^\p{C}\p{Zl}\p{Zp}\r\n]*(?:[\r\n][^\p{C}\p{Zl}\p{Zp}\r\n]*)*\z/u', $raw)) {
		return null;
	}

	$actions = [];
	foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
		$entry = trim($line, ' ');
		if ('' === $entry || '#' === $entry[0]) {
			continue;
		}
		if (! wpaw_is_ajax_action_name($entry)) {
			return null;
		}
		$actions[] = $entry;
	}

	return array_values(array_unique($actions));
}

/**
 * Whether this installation has ever saved the steady-state action list.
 *
 * The existence of the row — including a deliberately saved EMPTY string — is what separates a list
 * the administrator owns from one an older version left behind. `get_option()` returns the default
 * for a row that does not exist and the stored value otherwise, so a default of `false` distinguishes
 * the two without writing anything.
 */
function wpaw_public_ajax_list_stored(): bool {
	$key = wpaw_option_key('public_ajax_list');

	return '' !== $key && false !== get_option($key, false);
}

/**
 * The action list the version with an enable checkbox was exempting, or an empty string.
 *
 * ISOLATED ON PURPOSE. This pair is obsolete and is read in exactly one place, the fallback in
 * `wpaw_public_ajax_list_value()`, which runs only while the steady-state row has never been saved.
 * Nothing else may consult the old switch; a steady-state path that still asked it would make every
 * later save conditional on a value the administrator can no longer see.
 *
 * ONLY THE LITERAL `'1'` WAS "ON", which is what makes the upgrade safe in both directions:
 *
 *   - `'1'` with a usable list keeps exempting exactly what it exempted before, so an upgrade does
 *     not break a working public fetch.
 *   - `'0'`, an absent row, or anything unrecognisable leaves the stored names INERT. They were not
 *     in force before the upgrade and must not come into force because a checkbox disappeared. The
 *     names stay where they are, so nothing is destroyed and a rollback still finds them.
 *
 * A non-string row reads as "nothing in force", never as a list to repair.
 */
function wpaw_legacy_public_ajax_list(): string {
	$enabled_key = wpaw_option_key('legacy_public_ajax_enabled');
	$actions_key = wpaw_option_key('legacy_public_ajax_actions');

	if ('' === $enabled_key || '' === $actions_key) {
		return '';
	}

	if ('1' !== get_option($enabled_key, '0')) {
		return '';
	}

	$stored = get_option($actions_key, '');

	return is_string($stored) ? $stored : '';
}

/**
 * The action list text in force, from the steady-state row or, on an installation that has never
 * saved one, from the isolated legacy pair.
 *
 * ONE KEY, ONE MEANING. The list IS the setting: there is no separate switch, so "stored but off"
 * cannot exist and cannot be mistaken for "on". A saved empty string is a deliberate revocation and
 * is itself a saved row, which is why the legacy fallback cannot resurrect a list after it.
 *
 * Returned unparsed and unconverted, so a corrupt row reaches `wpaw_parse_public_ajax_actions()`
 * intact and is refused there rather than being silently cleaned up here.
 */
function wpaw_public_ajax_list_value(): mixed {
	$key = wpaw_option_key('public_ajax_list');

	if ('' !== $key && false !== get_option($key, false)) {
		return get_option($key, '');
	}

	return wpaw_legacy_public_ajax_list();
}

/**
 * The AJAX actions an administrator has declared public, or none.
 *
 * WHY A STORED SETTING RATHER THAN A CONSTANT. The set is site-specific and changes when the theme
 * or a plugin adds a public fetch — a content operation an administrator performs, not a redeploy.
 * The constant it replaced was hardcoded in the host stub, which made every new endpoint a code
 * change and quietly granted whatever the list happened to contain at build time.
 *
 * EMPTY MEANS NO EXEMPTION, AND SO DOES UNREADABLE. A missing, empty or comment-only list exempts
 * nothing; malformed configuration fails closed as a whole through
 * `wpaw_parse_public_ajax_actions()`, never as its readable remainder.
 *
 * @return list<string>
 */
function wpaw_gate_public_ajax_actions(): array {
	return wpaw_parse_public_ajax_actions(wpaw_public_ajax_list_value()) ?? [];
}

/**
 * Whether the list in force is usable, empty, or malformed.
 *
 * The settings screen reports `malformed` explicitly. Failing closed silently is correct for the
 * request, but an administrator looking at a list that is being ignored needs to be told that it is
 * being ignored — otherwise the screen shows a configuration that is not in force.
 *
 * @return 'usable'|'empty'|'malformed'
 */
function wpaw_gate_public_ajax_actions_state(): string {
	$raw = wpaw_public_ajax_list_value();

	if (! is_string($raw)) {
		return 'malformed';
	}

	if ('' === $raw) {
		return 'empty';
	}

	$actions = wpaw_parse_public_ajax_actions($raw);

	if (null === $actions) {
		return 'malformed';
	}

	return [] === $actions ? 'empty' : 'usable';
}

/**
 * Whether an older version left action names stored while they were not in force.
 *
 * Reported on the screen and nowhere else. The names are preserved rather than deleted — they are
 * the only record of what a previous administrator configured, and deleting a security setting to
 * tidy a form is how a rollback finds nothing — but they are also NOT adopted: adopting them would
 * grant access nobody chose under the current screen. An administrator who wants them back types
 * them into the list and saves, which is the one path that activates anything.
 */
function wpaw_public_ajax_legacy_parked(): bool {
	if (wpaw_public_ajax_list_stored()) {
		return false;
	}

	if ('' !== wpaw_legacy_public_ajax_list()) {
		return false;
	}

	$actions_key = wpaw_option_key('legacy_public_ajax_actions');

	if ('' === $actions_key) {
		return false;
	}

	$stored = get_option($actions_key, '');

	return is_string($stored) && '' !== trim($stored);
}

/**
 * Whether the current request must be denied.
 *
 * A deterministic policy boundary, deliberately free of headers and `exit()`, so the whole decision
 * is covered by regression tests before anything acts on it.
 */
function wpaw_gate_should_deny(): bool {
	$scope = wpaw_scope();

	if (WPAW_SCOPE_DISABLED === $scope) {
		return false;
	}

	if (! wpaw_gate_restricting()) {
		return false;
	}

	if (WPAW_SCOPE_ADMIN === $scope) {
		// THE ONE SHARED SURFACE. `admin-ajax.php` serves the public frontend's fetch endpoints and
		// every privileged handler core, plugins and the theme register, under one URL. It is NOT
		// exempted wholesale: only action names an administrator listed bypass the IP check, and the
		// list starts empty. The list is the whole setting — there is no separate switch that could
		// leave stored names looking active. An action is a string matched EXACTLY; an array, a
		// near-miss, or a differently-cased name is not an exemption and falls through to the gate.
		//
		// The list is per ACTION NAME, not per authentication state: WordPress wires `wp_ajax_` and
		// `wp_ajax_nopriv_` for the same name, so listing a name exempts both. Only names that are
		// safe to run for an anonymous visitor belong here, and a name shared with a privileged
		// handler must never be listed. See the README before configuring this.
		// Core rebuilds $_REQUEST from GET then POST after mu-plugins load. Do not trust PHP's
		// request_order (which can include cookies or omit POST) at this earlier point: core must
		// dispatch the exact action that was checked here. POST takes precedence, even when null.
		// Core also applies add_magic_quotes (addslashes) before dispatch, so compare that value.
		$action = array_key_exists('action', $_POST) ? $_POST['action'] : ($_GET['action'] ?? null);
		$ajax = wp_doing_ajax();

		if ($ajax && is_string($action) && in_array(addslashes($action), wpaw_gate_public_ajax_actions(), true)) {
			return false;
		}

		if (! $ajax && ! wpaw_gate_request_is_admin_surface()) {
			return false;
		}
	}

	return ! wpaw_gate_ip_allowed(wpaw_client_ip());
}

/**
 * @return string[]
 */
function wpaw_gate_deny_headers(): array {
	return [
		'HTTP/1.1 403 Forbidden',
		'Content-Type: text/plain; charset=utf-8',
		'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
	];
}

/**
 * Ends the request. Raw `header()` rather than `wp_die()`: at this point the theme, localisation and
 * most of core are not loaded, and `wp_die()`'s HTML path would reach for more than is safely there.
 */
function wpaw_gate_deny(): never {
	if (! headers_sent()) {
		foreach (wpaw_gate_deny_headers() as $header) {
			header($header);
		}
	}

	exit("403 Forbidden\n");
}

/**
 * Applies the gate to the current request and reports what it decided.
 *
 * Returns rather than staying silent so a consuming project can assert that the gate actually RAN.
 * The alternative failure — a host stub that never reaches this call — is invisible: no error, no
 * warning, just an ungated site.
 */
function wpaw_gate_enforce(): string {
	if ('' !== wpaw_gate_runtime_exemption()) {
		return 'exempt-runtime';
	}

	if (! wpaw_gate_should_deny()) {
		return 'allow';
	}

	wpaw_gate_deny();
}
