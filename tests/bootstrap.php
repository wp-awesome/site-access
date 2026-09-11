<?php
/**
 * A WordPress-shaped test harness with no WordPress in it.
 *
 * The package under test is deliberately loadable this early — enforcement happens at mu-plugin
 * load, before most of core exists — so the surface it may touch is small enough to stub honestly
 * here. Anything the package calls that this file does not define is a fatal error, which is the
 * point: it is how "the maintenance payload needs no theme" is proved rather than asserted.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

if (! defined('WEEK_IN_SECONDS')) {
	define('WEEK_IN_SECONDS', 604800);
}

/**
 * @param array<string, mixed> $options
 */
function wpaw_test_reset(array $options = []): void {
	$GLOBALS['wpaw_test'] = [
		'options'         => $options,
		'is_admin'        => false,
		'doing_ajax'      => false,
		'login_url'       => 'https://example.test/wp-login.php',
		'environment'     => 'staging',
		'can_manage'      => false,
		'actions'         => [],
		'settings'        => [],
		'settings_errors' => [],
		'option_guard'    => false,
	];

	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	$_SERVER['REQUEST_URI'] = '/';
	unset($_SERVER['HTTP_X_FORWARDED_FOR']);
	$_POST = [];
}

function wpaw_test_set(string $key, mixed $value): void {
	$GLOBALS['wpaw_test'][$key] = $value;
}

function wpaw_test_get(string $key): mixed {
	return $GLOBALS['wpaw_test'][$key];
}

wpaw_test_reset();

// ---------------------------------------------------------------------------
// WordPress surface
// ---------------------------------------------------------------------------

function get_option(string $name, mixed $default = false): mixed {
	if (true === $GLOBALS['wpaw_test']['option_guard']) {
		throw new RuntimeException(sprintf('get_option(%s) was called while the database was fenced off', $name));
	}

	return $GLOBALS['wpaw_test']['options'][$name] ?? $default;
}

function update_option(string $name, mixed $value): bool {
	if (true === $GLOBALS['wpaw_test']['option_guard']) {
		throw new RuntimeException(sprintf('update_option(%s) was called while the database was fenced off', $name));
	}

	$GLOBALS['wpaw_test']['options'][$name] = $value;

	return true;
}

function is_admin(): bool {
	return (bool) $GLOBALS['wpaw_test']['is_admin'];
}

function wp_doing_ajax(): bool {
	return (bool) $GLOBALS['wpaw_test']['doing_ajax'];
}

function wp_login_url(string $redirect = ''): string {
	return (string) $GLOBALS['wpaw_test']['login_url'];
}

function wp_get_environment_type(): string {
	return (string) $GLOBALS['wpaw_test']['environment'];
}

function current_user_can(string $capability): bool {
	return 'manage_options' === $capability && true === $GLOBALS['wpaw_test']['can_manage'];
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
	$GLOBALS['wpaw_test']['actions'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
}

function add_options_page(string $page_title, string $menu_title, string $capability, string $slug, callable $callback): void {
	$GLOBALS['wpaw_test']['settings']['page'] = compact('page_title', 'menu_title', 'capability', 'slug');
}

/** @param array<string, mixed> $args */
function register_setting(string $group, string $option, array $args = []): void {
	$GLOBALS['wpaw_test']['settings']['options'][$option] = ['group' => $group, 'args' => $args];
}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void {
	$GLOBALS['wpaw_test']['settings_errors'][] = ['setting' => $setting, 'code' => $code, 'message' => $message];
}

function settings_errors(string $setting = ''): void {
}

function settings_fields(string $group): void {
}

function submit_button(): void {
	echo '<button type="submit">Save</button>';
}

function checked(mixed $checked, mixed $current = true, bool $display = true): string {
	return $checked === $current ? " checked='checked'" : '';
}

function selected(mixed $selected, mixed $current = true, bool $display = true): string {
	return $selected === $current ? " selected='selected'" : '';
}

function esc_html(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_js(string $text): string {
	return addslashes($text);
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

function wpaw_assert_same(mixed $expected, mixed $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException(sprintf(
			"%s\n    expected: %s\n    actual:   %s",
			$message,
			var_export($expected, true),
			var_export($actual, true)
		));
	}
}

function wpaw_assert_true(bool $actual, string $message): void {
	wpaw_assert_same(true, $actual, $message);
}

function wpaw_assert_false(bool $actual, string $message): void {
	wpaw_assert_same(false, $actual, $message);
}

function wpaw_assert_contains(string $needle, string $haystack, string $message): void {
	if (! str_contains($haystack, $needle)) {
		throw new RuntimeException(sprintf("%s\n    missing: %s", $message, $needle));
	}
}

function wpaw_assert_not_contains(string $needle, string $haystack, string $message): void {
	if (str_contains($haystack, $needle)) {
		throw new RuntimeException(sprintf("%s\n    unexpectedly present: %s", $message, $needle));
	}
}

function wpaw_test_done(string $name): void {
	echo $name . ": ok\n";
}
