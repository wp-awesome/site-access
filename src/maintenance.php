<?php
/**
 * Maintenance mode: a 503 screen for everyone except a logged-in administrator.
 *
 * WHY AN ADMIN TOGGLE, NOT A WEB-SERVER FLAG FILE. A flag file is more robust — it works even when
 * WordPress or the database is down — but it needs someone with shell access every single time,
 * which defeats the point of a self-service switch. The payload below can be lifted into a web-server
 * location block unchanged if a true database-down maintenance mode is ever needed, precisely because
 * it has no theme or database dependency of its own.
 *
 * WHY 503, NEVER 200. A 200 tells a crawler this is the real page, and on a live site an indexed
 * maintenance screen competes with the real content in search results long after the work is done.
 * `Retry-After` tells a well-behaved crawler and browser this is temporary, not a replacement.
 *
 * WHY THE PAYLOAD IS INLINE. Reaching into the theme — a template part, an enqueued stylesheet —
 * would make this screen fail exactly when a theme problem is WHY maintenance mode was switched on.
 * Every rule it needs travels in one string, and the only file it reads is an illustration the host
 * points at by absolute path.
 *
 * WHY `template_redirect`, NOT MU-PLUGIN LOAD LIKE THE GATE. The administrator exemption needs
 * `current_user_can()`, which needs the current user resolved — not yet true at mu-plugin load.
 * `template_redirect` is also skipped entirely for the dashboard and the login form, so an
 * administrator can always reach the toggle to switch this back off without any code here having to
 * special-case those URLs.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Whether the toggle is on. Anything other than the literal '1' is off.
 */
function wpaw_maintenance_enabled(): bool {
	return '1' === (string) get_option(wpaw_option_key('maintenance'), '0');
}

/**
 * Whether the CURRENT request must receive the screen. Kept separate from the response below so the
 * policy — on, and not an administrator — is testable without headers, buffering and `exit()`.
 */
function wpaw_maintenance_should_serve(): bool {
	return wpaw_maintenance_enabled() && ! current_user_can('manage_options');
}

/**
 * @return string[]
 */
function wpaw_maintenance_headers(): array {
	$retry = (int) wpaw_setting('WP_AWESOME_MAINTENANCE_RETRY_AFTER', '3600');

	return [
		'HTTP/1.1 503 Service Unavailable',
		'Retry-After: ' . ($retry > 0 ? $retry : 3600),
		'Content-Type: text/html; charset=utf-8',
		'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
	];
}

/**
 * Reads the host's illustration. A missing or unreadable file degrades to no illustration, never a
 * fatal error on a screen whose whole job is to stay up when something else is broken.
 */
function wpaw_maintenance_illustration(?string $path = null): string {
	$path ??= wpaw_setting('WP_AWESOME_MAINTENANCE_ILLUSTRATION', '');

	if ('' === $path || ! is_readable($path)) {
		return '';
	}

	return (string) file_get_contents($path);
}

/**
 * Constrains a host-supplied colour to something that cannot escape the style block. These are code
 * constants rather than user input, so this is a guard rail, not a trust boundary.
 */
function wpaw_maintenance_colour(string $constant, string $default): string {
	$value = trim(wpaw_setting($constant, $default));

	return 1 === preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\([0-9,.\s%]+\)$/', $value) ? $value : $default;
}

/**
 * Renders the self-contained payload.
 *
 * No enqueued stylesheet, no template part, no block markup, and — deliberately — no WordPress
 * escaping helpers or option reads either, so this function keeps working on a site whose database
 * or theme is the reason maintenance mode is on.
 */
function wpaw_maintenance_render(): string {
	$escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

	$language     = $escape(wpaw_setting('WP_AWESOME_MAINTENANCE_LANGUAGE', 'en'));
	$title        = $escape(wpaw_setting('WP_AWESOME_MAINTENANCE_TITLE', 'Website Under Maintenance'));
	$heading      = $escape(wpaw_setting('WP_AWESOME_MAINTENANCE_HEADING', 'Website Under Maintenance'));
	$body         = $escape(wpaw_setting('WP_AWESOME_MAINTENANCE_BODY', 'This website is temporarily unavailable while scheduled maintenance is carried out. Please try again shortly.'));
	$footnote     = $escape(wpaw_setting('WP_AWESOME_MAINTENANCE_FOOTNOTE', ''));
	$background   = wpaw_maintenance_colour('WP_AWESOME_MAINTENANCE_BACKGROUND', '#1f2124');
	$foreground   = wpaw_maintenance_colour('WP_AWESOME_MAINTENANCE_TEXT_COLOUR', '#d5d7da');
	$accent       = wpaw_maintenance_colour('WP_AWESOME_MAINTENANCE_HEADING_COLOUR', '#ffffff');
	$illustration = wpaw_maintenance_illustration();

	return <<<HTML
		<!DOCTYPE html>
		<html lang="{$language}">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>{$title}</title>
		<style>
			html, body { margin: 0; padding: 0; }
			body {
				display: flex;
				align-items: center;
				justify-content: center;
				min-height: 100vh;
				background: {$background};
				font-family: Verdana, Arial, sans-serif;
				color: {$foreground};
				text-align: center;
			}
			.wp-awesome-maintenance { max-width: 700px; padding: 0 24px; }
			.wp-awesome-maintenance svg { max-width: 280px; height: auto; margin-bottom: 40px; }
			.wp-awesome-maintenance h1 { color: {$accent}; font-size: 32px; margin: 0 0 24px; }
			.wp-awesome-maintenance p { font-size: 16px; line-height: 1.4; margin: 0 0 24px; }
			.wp-awesome-maintenance strong { display: block; font-size: 16px; }
		</style>
		</head>
		<body>
			<div class="wp-awesome-maintenance">
				{$illustration}
				<h1>{$heading}</h1>
				<p>{$body}</p>
				<strong>{$footnote}</strong>
			</div>
		</body>
		</html>

		HTML;
}

/**
 * Ends the request with the maintenance screen.
 */
function wpaw_maintenance_serve(): never {
	if (! headers_sent()) {
		foreach (wpaw_maintenance_headers() as $header) {
			header($header);
		}
	}

	echo wpaw_maintenance_render();
	exit;
}

/**
 * Priority 1 so this runs ahead of core's canonical redirect and anything else on the same hook,
 * none of which should get a chance to issue a different response while maintenance mode is on.
 */
function wpaw_maintenance_bootstrap(): bool {
	add_action('template_redirect', static function (): void {
		if (! wpaw_maintenance_should_serve()) {
			return;
		}

		wpaw_maintenance_serve();
	}, 1);

	return true;
}
