<?php
/**
 * One screen, three controls: maintenance mode, blocking scope, allowed addresses.
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
const WPAW_FORCE_FIELD    = 'wp_awesome_force_save';

function wpaw_settings_bootstrap(): bool {
	add_action('admin_menu', 'wpaw_settings_menu');
	add_action('admin_init', 'wpaw_settings_register');

	return true;
}

function wpaw_settings_menu(): void {
	add_options_page(
		'Site Access',
		'Site Access',
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
	return '1' === (string) $value ? '1' : '0';
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

	$ip          = wpaw_client_ip();
	$scope       = wpaw_scope();
	$stored      = wpaw_stored_scope();
	$allowlist   = (string) get_option(wpaw_option_key('allowlist'), '');
	$proxies     = (string) get_option(wpaw_option_key('trusted_proxies'), '');
	$maintenance = wpaw_maintenance_enabled();
	$restricting = wpaw_gate_restricting();

	echo '<div class="wrap"><h1>Site Access</h1>';

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
