<?php
/**
 * The blocking scope: how much of the site the allowlist applies to.
 *
 * THE RULE THAT MATTERS: a value this file does not recognise is never treated as `disabled`.
 * An unreadable value means corruption or a restore from an older schema, not a decision somebody
 * made, and resolving it to "no gating at all" would silently un-protect a site nobody was looking
 * at. It resolves to the HOST's declared fallback instead, so each deployment fails in its own safe
 * direction: a preview never leaks content, a public production site never goes dark.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WPAW_SCOPE_DISABLED = 'disabled';
const WPAW_SCOPE_WEBSITE  = 'website';
const WPAW_SCOPE_ADMIN    = 'admin';

/**
 * @return string[]
 */
function wpaw_scopes(): array {
	return [WPAW_SCOPE_DISABLED, WPAW_SCOPE_WEBSITE, WPAW_SCOPE_ADMIN];
}

function wpaw_is_scope(string $value): bool {
	return in_array($value, wpaw_scopes(), true);
}

/**
 * The host's declared fallback.
 *
 * A host that declares nothing gets `website`, and so does a host whose constant is itself
 * unusable. Never `disabled`, at either step. A dark site is loud and fixed in minutes; a silently
 * ungated site leaks for as long as nobody looks, so the loud failure is the right one for a
 * security control.
 */
function wpaw_fallback_scope(): string {
	$declared = wpaw_setting('WP_AWESOME_GATE_FALLBACK_SCOPE', WPAW_SCOPE_WEBSITE);

	return wpaw_is_scope($declared) ? $declared : WPAW_SCOPE_WEBSITE;
}

/**
 * The scope in force. Missing and unrecognised are the same case on purpose — two rules where one
 * suffices is how a security control drifts. Neither can lock anybody out, because an empty
 * allowlist still restricts nothing (see `wpaw_gate_restricting()`).
 */
function wpaw_scope(): string {
	$stored = (string) get_option(wpaw_option_key('scope'), '');

	return wpaw_is_scope($stored) ? $stored : wpaw_fallback_scope();
}

/**
 * The raw stored value, so the settings screen can show an administrator what is actually in the
 * database rather than hiding corruption behind the fallback.
 */
function wpaw_stored_scope(): string {
	return (string) get_option(wpaw_option_key('scope'), '');
}

/**
 * Migrates older schemas onto the three-value scope, in place, under the same option key.
 *
 * Two schemas exist to come from, and they chain:
 *
 *   - the boolean "allow public access" flag: '1' meant open, so `disabled`; '0' meant gated, so
 *     `website`, which is what that flag has always enforced.
 *   - the older "gate enabled" flag, whose sense is inverted: '1' meant gate ON, so `website`.
 *
 * Idempotent: a value that is already a scope is left alone, so this can run on every request. An
 * unrecognised value is ALSO left alone — overwriting it would destroy the only evidence that
 * something restored the wrong data, and resolution already fails safe without needing to write.
 */
function wpaw_migrate_scope(): void {
	$key = wpaw_option_key('scope');

	if ('' === $key) {
		return;
	}

	$stored = get_option($key, false);

	if (false !== $stored) {
		if ('1' === (string) $stored) {
			update_option($key, WPAW_SCOPE_DISABLED);
		} elseif ('0' === (string) $stored) {
			update_option($key, WPAW_SCOPE_WEBSITE);
		}

		return;
	}

	$legacy_key = wpaw_option_key('legacy_enabled');

	if ('' === $legacy_key) {
		return;
	}

	$legacy = get_option($legacy_key, false);

	if (false === $legacy) {
		return;
	}

	// The legacy option is left in place rather than deleted, so a rollback to an older plugin
	// version still finds what it expects.
	update_option($key, '1' === (string) $legacy ? WPAW_SCOPE_WEBSITE : WPAW_SCOPE_DISABLED);
}
