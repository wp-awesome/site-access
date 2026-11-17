<?php
/**
 * One screen: maintenance mode, blocking scope, allowed addresses, and an optional section listing
 * the AJAX actions the public frontend may use.
 *
 * The scope is a stored setting rather than a constant because a three-value control cannot be
 * mistyped the way free text can, and because the person who needs to change it is the site owner,
 * not a developer with shell access. What a controlled input cannot protect against is a value that
 * never came from the control at all — a restore, a downgrade, a hand-edited row — which is why the
 * resolution in `scope.php` falls back rather than trusting whatever is stored.
 *
 * Every refusal below exists because of the same failure: an administrator locking themselves out of
 * a machine they have no console access to.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WPAW_SETTINGS_GROUP = 'wp_awesome_site_access';
const WPAW_SETTINGS_SLUG  = 'wp-awesome-site-access';
const WPAW_SETTINGS_TITLE = 'Site Access';
const WPAW_FORCE_FIELD    = 'wp_awesome_force_save';

function wpaw_settings_bootstrap(): bool {
	add_action('admin_menu', 'wpaw_settings_menu');
	add_action('admin_init', 'wpaw_settings_register');

	return true;
}

function wpaw_settings_menu(): void {
	add_options_page(
		WPAW_SETTINGS_TITLE,
		WPAW_SETTINGS_TITLE,
		'manage_options',
		WPAW_SETTINGS_SLUG,
		'wpaw_settings_render_page'
	);
}

function wpaw_settings_register(): void {
	register_setting(WPAW_SETTINGS_GROUP, wpaw_option_key('scope'), [
		'type'              => 'string',
		'sanitize_callback' => 'wpaw_sanitize_scope',
		'default'           => wpaw_fallback_scope(),
	]);

	register_setting(WPAW_SETTINGS_GROUP, wpaw_option_key('allowlist'), [
		'type'              => 'string',
		'sanitize_callback' => 'wpaw_sanitize_allowlist',
		'default'           => '',
	]);

	register_setting(WPAW_SETTINGS_GROUP, wpaw_option_key('trusted_proxies'), [
		'type'              => 'string',
		'sanitize_callback' => 'wpaw_sanitize_proxies',
		'default'           => '',
	]);

	register_setting(WPAW_SETTINGS_GROUP, wpaw_option_key('maintenance'), [
		'type'              => 'string',
		'sanitize_callback' => 'wpaw_sanitize_toggle',
		'default'           => '0',
	]);

	register_setting(WPAW_SETTINGS_GROUP, wpaw_option_key('public_ajax_enabled'), [
		'type'              => 'string',
		'sanitize_callback' => 'wpaw_sanitize_public_ajax_enabled',
		'default'           => '0',
	]);

	register_setting(WPAW_SETTINGS_GROUP, wpaw_option_key('public_ajax_actions'), [
		'type'              => 'string',
		'sanitize_callback' => 'wpaw_sanitize_public_ajax_actions',
		'default'           => '',
	]);
}

/**
 * A value outside the three is refused rather than stored. The control cannot produce one, so a
 * value that arrives here came from somewhere else.
 */
function wpaw_sanitize_scope(mixed $value): string {
	$value = (string) $value;

	if (wpaw_is_scope($value)) {
		return $value;
	}

	add_settings_error(
		WPAW_SETTINGS_GROUP,
		'bad_scope',
		sprintf('Blocking scope: "%s" is not one of disabled, website or admin. Nothing was changed.', esc_html($value))
	);

	return wpaw_scope();
}

function wpaw_sanitize_toggle(mixed $value): string {
	return '1' === $value ? '1' : '0';
}

/** Do not activate a retained list when the replacement submitted with the toggle is invalid. */
function wpaw_sanitize_public_ajax_enabled(mixed $value): string {
	if ('1' !== $value) {
		return '0';
	}

	if (wpaw_gate_public_ajax_enabled()) {
		return '1';
	}

	// options.php processes the toggle first; it trims and unslashes the list before saving it.
	// Inspect the same submitted value now, including on add_option's second sanitization.
	$submitted = $_POST[wpaw_option_key('public_ajax_actions')] ?? null;
	$submitted = is_string($submitted) ? stripslashes(trim($submitted)) : null;
	if (null === wpaw_parse_public_ajax_actions($submitted)) {
		add_settings_error(WPAW_SETTINGS_GROUP, 'bad_ajax_enable', 'Public AJAX exemptions were not enabled because the submitted action list is missing or invalid.');
		return '0';
	}

	return '1';
}

/** Normalize validated AJAX lines without byte-mode Unicode line splitting. */
function wpaw_normalize_public_ajax_lines(string $raw): array {
	$raw = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $raw);
	$groups = [];
	$comments = [];
	foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
		$line = trim($line, ' ');
		if ('' === $line) {
			continue;
		}
		if ('#' === $line[0]) {
			$comments[] = $line;
			continue;
		}
		$groups[] = ['name' => $line, 'lines' => [...$comments, $line]];
		$comments = [];
	}
	usort($groups, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
	$lines = [];
	foreach ($groups as $group) {
		array_push($lines, ...$group['lines']);
	}
	return [...$lines, ...$comments];
}

/**
 * Validates the public AJAX action list, then stores it verbatim (normalised).
 *
 * REFUSES THE WHOLE SAVE ON ONE BAD ENTRY. Dropping the offending line and saving the rest would
 * look like success while leaving the administrator with a list they did not type and cannot see
 * the difference in — and the direction of that surprise is an exemption they did not intend. The
 * stored list is returned untouched instead, and the error names the entry.
 *
 * An EMPTY value is a valid save: it removes every exemption, which is the fail-closed default.
 */
function wpaw_sanitize_public_ajax_actions(mixed $value): string {
	$stored   = get_option(wpaw_option_key('public_ajax_actions'), '');
	$previous = is_string($stored) ? $stored : '';

	if (! is_string($value)) {
		add_settings_error(WPAW_SETTINGS_GROUP, 'bad_ajax_action', 'Public AJAX actions must be a text list. The action list was not saved.');
		return $previous;
	}

	if (null === wpaw_parse_public_ajax_actions($value)) {
		add_settings_error(
			WPAW_SETTINGS_GROUP,
			'bad_ajax_action',
			'Public AJAX actions must be printable UTF-8 without control characters. The action list was not saved.'
		);
		return $previous;
	}

	return implode(PHP_EOL, wpaw_normalize_public_ajax_lines($value));
}

function wpaw_sanitize_proxies(mixed $value): string {
	$value = (string) $value;

	foreach (wpaw_parse_list($value) as $entry) {
		if (! wpaw_is_valid_entry($entry)) {
			add_settings_error(
				WPAW_SETTINGS_GROUP,
				'bad_proxy',
				sprintf('Trusted proxies: "%s" is not a valid IP or CIDR. Nothing was saved.', esc_html($entry))
			);

			return (string) get_option(wpaw_option_key('trusted_proxies'), '');
		}
	}

	return $value;
}

/**
 * Validates every entry, then refuses any save that would lock the current administrator out.
 *
 * The scope being saved in the SAME request decides whether a lockout is even possible, so it is
 * read from the submitted values rather than from the database, which still holds the old one. The
 * override exists for the legitimate case: granting access to somebody else, from an address you are
 * not currently on.
 */
function wpaw_sanitize_allowlist(mixed $value): string {
	$value    = (string) $value;
	$previous = (string) get_option(wpaw_option_key('allowlist'), '');
	$entries  = wpaw_parse_list($value);

	foreach ($entries as $entry) {
		if (! wpaw_is_valid_entry($entry)) {
			add_settings_error(
				WPAW_SETTINGS_GROUP,
				'bad_entry',
				sprintf('Allowed IPs: "%s" is not a valid IP or CIDR. Nothing was saved.', esc_html($entry))
			);

			return $previous;
		}
	}

	$submitted_scope = (string) ($_POST[wpaw_option_key('scope')] ?? '');
	$will_enforce    = wpaw_is_scope($submitted_scope)
		? WPAW_SCOPE_DISABLED !== $submitted_scope
		: WPAW_SCOPE_DISABLED !== wpaw_scope();

	$forced      = ! empty($_POST[WPAW_FORCE_FIELD]);
	$current_ip  = wpaw_client_ip();
	$would_allow = false;

	foreach ($entries as $entry) {
		if ('' !== $current_ip && wpaw_ip_in_range($current_ip, $entry)) {
			$would_allow = true;
			break;
		}
	}

	if ($will_enforce && ! empty($entries) && ! $would_allow && ! $forced) {
		add_settings_error(
			WPAW_SETTINGS_GROUP,
			'lockout',
			sprintf(
				'Refused: your current IP (%s) is not in that list, so saving it would lock you out. Add your IP, or tick "save anyway" if you meant to.',
				esc_html('' !== $current_ip ? $current_ip : 'unknown')
			)
		);

		return $previous;
	}

	return implode(PHP_EOL, wpaw_normalize_lines($value));
}

function wpaw_settings_render_page(): void {
	if (! current_user_can('manage_options')) {
		return;
	}

	$ip             = wpaw_client_ip();
	$scope          = wpaw_scope();
	$stored         = wpaw_stored_scope();
	$allowlist      = (string) get_option(wpaw_option_key('allowlist'), '');
	$proxies        = (string) get_option(wpaw_option_key('trusted_proxies'), '');
	$maintenance    = wpaw_maintenance_enabled();
	$restricting    = wpaw_gate_restricting();
	$public_ajax    = wpaw_gate_public_ajax_actions_state();
	$public_enabled = wpaw_gate_public_ajax_enabled();
	$public_actions = wpaw_gate_public_ajax_actions();
	$public_stored  = get_option(wpaw_option_key('public_ajax_actions'), '');
	// A non-string row cannot be rendered as text; the error notice above explains why it is ignored.
	$public_display = is_string($public_stored) ? $public_stored : '';
	$public_count   = count($public_actions);

	echo '<div class="wrap"><h1>' . esc_html(WPAW_SETTINGS_TITLE) . '</h1>';

	settings_errors(WPAW_SETTINGS_GROUP);

	printf(
		'<p><strong>Your current IP: <code>%s</code></strong></p>',
		esc_html('' !== $ip ? $ip : 'could not be determined')
	);

	if ($maintenance) {
		echo '<div class="notice notice-warning inline"><p><strong>Maintenance mode is ON.</strong> '
			. 'Everyone except a logged-in administrator receives a maintenance screen.</p></div>';
	}

	if ('' !== $stored && ! wpaw_is_scope($stored)) {
		printf(
			'<div class="notice notice-error inline"><p><strong>The stored blocking scope is not a value this plugin recognises (<code>%s</code>).</strong> '
			. 'That usually means a restore from an older version or a hand-edited row. Until it is saved again, this site is being treated as <code>%s</code>.</p></div>',
			esc_html($stored),
			esc_html($scope)
		);
	} elseif (WPAW_SCOPE_DISABLED === $scope) {
		echo '<div class="notice notice-warning inline"><p><strong>Blocking is off — anyone with the URL can reach this site.</strong> '
			. 'The list below is being ignored.</p></div>';
	} elseif (! $restricting) {
		echo '<div class="notice notice-warning inline"><p><strong>The list is empty, so nothing is being restricted.</strong> '
			. 'An empty list is deliberately treated as open rather than closed, so that a bad save cannot lock everyone out. '
			. 'Add at least one entry.</p></div>';
	}

	if ('malformed' === $public_ajax) {
		echo '<div class="notice notice-error inline"><p><strong>The stored public AJAX action list cannot be used, so no action is exempt.</strong> '
			. 'It is not a string, or it contains invalid UTF-8 or control characters. The gate fails closed rather than '
			. 'exempting the readable part. Correct the list below and save it.</p></div>';
	} elseif ('usable' === $public_ajax && WPAW_SCOPE_ADMIN === $scope) {
		printf(
			'<div class="notice notice-info inline"><p><strong>%d public AJAX action%s bypass the IP gate.</strong> '
			. 'They are reachable from any address, signed in or not. Review the list below against what those handlers do.</p></div>',
			$public_count,
			1 === $public_count ? '' : 's'
		);
	}

	echo '<form method="post" action="options.php">';

	settings_fields(WPAW_SETTINGS_GROUP);

	echo '<table class="form-table" role="presentation"><tbody>';

	printf(
		'<tr><th scope="row">Maintenance mode</th><td><label><input type="checkbox" name="%s" value="1"%s> '
		. '<strong>Show a maintenance screen instead of the site</strong></label>'
		. '<p class="description">Visitors receive a 503 response with a maintenance screen. Administrators logged in to '
		. 'the dashboard are unaffected, so switching this on never locks you out of switching it back off.</p></td></tr>',
		esc_attr(wpaw_option_key('maintenance')),
		checked($maintenance, true, false)
	);

	$scope_labels = [
		WPAW_SCOPE_DISABLED => ['No blocking', 'The list below is ignored and anyone with the URL can reach the whole site.'],
		WPAW_SCOPE_WEBSITE  => ['Whole website', 'Every request is checked against the list below — pages, dashboard and login alike.'],
		WPAW_SCOPE_ADMIN    => ['Dashboard and login only', 'The public site stays public; only the dashboard and the login form are restricted to the list below.'],
	];

	echo '<tr><th scope="row">Blocking scope</th><td>';

	foreach ($scope_labels as $value => [$label, $description]) {
		printf(
			'<p><label><input type="radio" name="%s" value="%s"%s> <strong>%s</strong></label>'
			. '<br><span class="description">%s</span></p>',
			esc_attr(wpaw_option_key('scope')),
			esc_attr($value),
			checked($scope, $value, false),
			esc_html($label),
			esc_html($description)
		);
	}

	echo '</td></tr>';

	printf(
		'<tr><th scope="row"><label for="wp-awesome-allowlist">Allowed IPs</label></th><td>'
		. '<textarea id="wp-awesome-allowlist" name="%s" rows="10" cols="50" class="large-text code" '
		. 'placeholder="203.0.113.4&#10;198.51.100.0/24&#10;2001:db8::/32">%s</textarea>'
		. '<p class="description">One IP or CIDR per line. IPv4 and IPv6. <code>#</code> starts a comment. '
		. 'Literal <code>\\n</code> separators pasted by automation are normalised to line breaks.</p>'
		. '<p><button type="button" class="button" onclick="wpawAddAddress(\'%s\')">Add my current IP</button></p>'
		. '</td></tr>',
		esc_attr(wpaw_option_key('allowlist')),
		esc_textarea($allowlist),
		esc_js($ip)
	);

	// DIRECTLY BELOW THE ALLOWLIST, because it is the same question one level down: which requests
	// may reach the site. The section is optional and off by default; the list is preserved while it
	// is off, so switching it back on never means retyping it.
	printf(
		'<tr><th scope="row">Public AJAX exemptions</th><td>'
		. '<label><input type="checkbox" name="%s" value="1"%s> '
		. '<strong>Exempt the listed AJAX actions from the IP gate</strong></label>'
		. '<p class="description"><strong>Off by default.</strong> While off, every AJAX request in '
		. '<em>Dashboard and login only</em> scope is gated, even if the list below is populated. Disabling '
		. 'keeps the list, so it can be switched back on without retyping it.</p>'
		. '<p><label for="wp-awesome-public-ajax">Public AJAX actions</label><br>'
		. '<textarea id="wp-awesome-public-ajax" name="%s" rows="6" cols="50" class="large-text code" '
		. 'placeholder="get_news&#10;get_campuses">%s</textarea></p>'
		. '<p class="description">One exact action name per line; slash and other printable characters are allowed. Lines beginning with # are comments. An enabled section with an empty list '
		. 'exempts nothing. The list only matters in <em>Dashboard and login only</em> scope: the '
		. 'whole-website scope gates every request. An entry exempts that action for everyone, signed in or not, '
		. 'because WordPress registers both AJAX handlers under one name. Only list read-only endpoints that are '
		. 'safe for an anonymous visitor, and never list a name that a privileged handler shares — the '
		. 'exemption cannot tell the two apart.</p></td></tr>',
		esc_attr(wpaw_option_key('public_ajax_enabled')),
		checked($public_enabled, true, false),
		esc_attr(wpaw_option_key('public_ajax_actions')),
		esc_textarea($public_display)
	);

	printf(
		'<tr><th scope="row"><label for="wp-awesome-proxies">Trusted proxies</label></th><td>'
		. '<textarea id="wp-awesome-proxies" name="%s" rows="4" cols="50" class="large-text code">%s</textarea>'
		. '<p class="description"><strong>Leave empty unless you know you need it.</strong> Only set this if the server sees a '
		. 'proxy address instead of the real client IP. When empty, only the connecting address is trusted, which cannot be '
		. 'spoofed.</p></td></tr>',
		esc_attr(wpaw_option_key('trusted_proxies')),
		esc_textarea($proxies)
	);

	printf(
		'<tr><th scope="row">Override</th><td><label><input type="checkbox" name="%s" value="1"> '
		. 'Save even if my own IP is not on the list</label>'
		. '<p class="description">Without this, a save that would lock you out is refused.</p></td></tr>',
		esc_attr(WPAW_FORCE_FIELD)
	);

	echo '</tbody></table>';

	submit_button();

	echo '</form>';

	wpaw_settings_render_blocked();

	// Inline rather than enqueued, so the screen has no asset to ship, register or cache-bust.
	echo '<script>function wpawAddAddress(address){var field=document.getElementById("wp-awesome-allowlist");'
		. 'if(!field||!address){return;}var lines=field.value.split(/\r?\n/).map(function(line){return line.trim();});'
		. 'if(lines.indexOf(address)===-1){field.value=(field.value.replace(/\s+$/,"")+"\n"+address).replace(/^\s+/,"");}'
		. 'field.focus();}</script>';

	echo '</div>';
}

/**
 * The read-only report of who was recently denied, with a one-click add for each address.
 *
 * Rendered after the form on purpose: it describes consequences of the settings above, and an
 * administrator reading it is deciding whether to change them.
 */
function wpaw_settings_render_blocked(): void {
	$state = wpaw_access_log_state();

	if ('not-configured' === $state) {
		return;
	}

	echo '<h2>Blocked in the last 7 days</h2>';

	// Never fall through to an empty table here. An empty table reads as "nobody has been denied",
	// which is the wrong conclusion and the reason a broken report can survive for months.
	if ('unreadable' === $state) {
		printf(
			'<div class="notice notice-warning inline"><p><strong>This report is unavailable.</strong> '
			. 'The access log at <code>%s</code> cannot be read from here, so this is <em>not</em> the same as '
			. 'nobody having been blocked. Check that the file exists and is readable by the web application '
			. '— on a containerised stack it is usually written by the web server into a volume that is not '
			. 'mounted where the interpreter runs.</p></div>',
			esc_html(wpaw_access_log_path())
		);

		return;
	}

	$blocked = wpaw_recent_blocked_ips();

	if ([] === $blocked) {
		echo '<p>No requests were denied in the last 7 days.</p>';

		return;
	}

	echo ''
		. '<p>Unique client addresses that received a 403 response. Review an address before adding it — '
		. 'a blocked address is not necessarily one you want to let in.</p>'
		. '<table class="widefat striped"><thead><tr><th>IP address</th><th>Blocked requests</th>'
		. '<th>Last seen (UTC)</th><th>Action</th></tr></thead><tbody>';

	foreach ($blocked as $ip => $details) {
		printf(
			'<tr><td><code>%s</code></td><td>%d</td><td><code>%s</code></td>'
			. '<td><button type="button" class="button" onclick="wpawAddAddress(\'%s\')">Add to allowed IPs</button></td></tr>',
			esc_html($ip),
			(int) $details['count'],
			esc_html($details['last_seen']),
			esc_js($ip)
		);
	}

	echo '</tbody></table>';
}
