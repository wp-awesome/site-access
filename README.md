# site-access

An IP gate and a maintenance screen for WordPress, sharing one settings page, configured entirely by
constants the host defines.

One of the **wp-awesome** plugins. Each is its own repository so a project adopts only what it wants:

| plugin | repository | what it answers |
| --- | --- | --- |
| `site-access` | `wp-awesome/site-access` | May this address reach this site at all? |
| `user-obscure` | `wp-awesome/user-obscure` | What may an allowed visitor learn about its accounts? |

They share no code and no options. Adopt either, both, or neither, and keep your own project
plugins in the same `mu-plugins/` directory alongside them.

```sh
git submodule add https://github.com/wp-awesome/site-access \
    wp-content/mu-plugins/site-access
```

A submodule must point at a whole repository — `git submodule add <repository> <path>` takes a path
to put it, not a path to take from it. That is why these are separate repositories rather than
directories inside one: a monorepo cannot be adopted a folder at a time.

Two capabilities, one screen at **Settings → Site Access**:

- **Maintenance mode** — a checkbox. Everyone except a logged-in administrator receives a themeless
  503 screen.
- **Blocking scope** — `disabled`, `website` or `admin`. How much of the site the allowlist applies
  to.
- **Allowed IPs** — the allowlist, a list of who was recently denied with one-click adding, an
  optional trusted-proxy list, and a self-lockout override.

## What it protects, stated honestly

A denied request has already crossed the network, reached the interpreter and booted WordPress. This
protects **content**, not **infrastructure**: it does not blunt brute-force or scanner load the way
an allowlist at the proxy or CDN does. Do not describe it to anyone as edge-grade.

It lives in the application on purpose. The same protection then works on a host with no proxy at
all, one mechanism covers every topology, and the list is editable by the people who maintain the
site rather than by whoever has shell access.

## Installing it — the constraint that will otherwise waste your afternoon

**WordPress does not load must-use plugins from subdirectories.** `wp_get_mu_plugins()` has no
recursion in it: only top-level `.php` files in `wp-content/mu-plugins/` are executed. A directory
placed there — a submodule, a Composer package, a copied folder — is completely invisible to
WordPress.

So this package **cannot** be dropped into `mu-plugins/` and left to work. It needs a one-file stub
at the top level that defines the host's constants and requires the entry point. **The failure mode
of forgetting the stub is silent**: no error, no warning, no admin notice — the gate and the
maintenance screen simply never run, and the site is ungated while looking entirely normal.

Write a test in the consuming project that asserts both features are **loaded and enforcing**, not
merely present on disk. `wpaw_boot()` returns a memoized report for exactly that purpose:

```php
$report = wpaw_boot();
// ['scope' => 'website', 'gate' => 'allow'|'deny'|'exempt-runtime',
//  'maintenance' => true, 'settings' => true]
```

A host stub looks like this:

```php
<?php
// wp-content/mu-plugins/my-site-access.php   ← top level, not in a subdirectory
declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

const WP_AWESOME_OPTION_SCOPE          = 'my_existing_public_option';
const WP_AWESOME_OPTION_ALLOWLIST      = 'my_existing_allowlist_option';
const WP_AWESOME_OPTION_MAINTENANCE    = 'my_existing_maintenance_option';
const WP_AWESOME_GATE_FALLBACK_SCOPE   = 'website';

require_once __DIR__ . '/wp-awesome/site-access.php';
```

## Blocking scope

| Value      | Frontend | Dashboard | Login form | `admin-ajax.php` |
| ---------- | -------- | --------- | ---------- | ---------------- |
| `disabled` | open     | open      | open       | open             |
| `website`  | gated    | gated     | gated      | **gated**        |
| `admin`    | open     | gated     | gated      | **exempt**       |

`admin` exempts AJAX because the public frontend shares `admin-ajax.php` — gating it would break the
public site the scope exists to leave public. `website` has no such hole today and must never grow
one: it is the scope for a site that should not be reachable at all.

Neither surface is matched by URL. The dashboard is matched with `is_admin()`, because `WP_ADMIN` is
defined by the dashboard entry script before WordPress is loaded, which makes it true wherever the
dashboard is served from. The login form's path is derived from `wp_login_url()`. **Never match a
literal `/wp-admin/` or `/wp-login.php`** — the serving path is configurable and can change at any
time.

### The rule that keeps this safe: unrecognised never means open

A stored value this package does not recognise — from a restore, a downgrade, or a hand-edited row —
is **never** treated as `disabled`. It resolves to the host's declared
`WP_AWESOME_GATE_FALLBACK_SCOPE`, so each deployment fails in its own safe direction: a preview never
leaks unreleased content, a public production site never goes dark. No deployment can silently end up
ungated.

A **missing** value resolves the same way, deliberately. Two rules where one suffices is how a
security control drifts, and the guarantee that "missing means open" used to provide — you cannot
lock yourself out of a fresh install — is actually provided by the empty-allowlist fail-safe below,
which still holds.

If the host constant is itself unusable, the fallback is `website` — the most restrictive value,
never `disabled`. A dark site is loud and fixed in minutes; a silently ungated site leaks for as long
as nobody looks. For a security control, prefer the loud failure.

## Fail-safes, and why each exists

- **An empty or unparseable allowlist restricts nothing.** A corrupt option must not brick a remote
  machine whose only route in is the site itself. The settings screen shows this state as a warning.
- **WP-CLI, cron and the CLI SAPI are never gated.** WP-CLI is the documented recovery path from a
  bad allowlist; gating it would make a lockout unrecoverable without database access.
- **A save that would lock the current administrator out is refused**, unless they tick the override —
  which exists for the legitimate case of granting access to somebody else from an address you are
  not on.
- **Recovery from a lockout**, without HTTP access:

  ```sh
  wp option update <your scope option> disabled   # open the site to everyone
  wp option get    <your allowlist option>        # inspect
  wp option delete <your allowlist option>        # empty list == not restricting
  ```

## Client identity is security critical

`REMOTE_ADDR` is trusted, and nothing else. `X-Forwarded-For` is consulted **only** when
`REMOTE_ADDR` is itself one of the configured trusted proxies, and then the **rightmost** entry that
is not a trusted proxy is taken — the rightmost entries were appended by infrastructure the site
owner controls, the leftmost are whatever the client sent.

Leave the trusted-proxy list empty unless the server genuinely sees a proxy address instead of the
real client. Reading a forwarded header unconditionally is a one-line bypass: anybody could send
`X-Forwarded-For: <an allowed address>`.

## Where enforcement runs, and why it must stay there

At **mu-plugin load**, not on a hook. That is the earliest point where `get_option()` works — the
database handle is set up before must-use plugins are included — and it sits ahead of REST, XML-RPC,
feeds, the login form and the dashboard at once. Moving it to `admin_init` or any other hook trades
one check for a list of entry points that has to be kept complete forever, and the first one anybody
forgets is a hole nobody sees.

Maintenance mode is the deliberate exception: it runs on `template_redirect` at priority 1, because
its administrator exemption needs `current_user_can()`, which needs the current user resolved. That
hook is also skipped for the dashboard and the login form, so an administrator can always reach the
toggle to switch it back off.

## Host constants

All optional. Defaults are neutral, so a host that defines nothing still gets a working, safe plugin
under generic option keys.

| Constant                            | Default                   | Purpose                                                        |
| ----------------------------------- | ------------------------- | -------------------------------------------------------------- |
| `WP_AWESOME_OPTION_SCOPE`           | `wp_awesome_scope`        | Option key holding the blocking scope.                          |
| `WP_AWESOME_OPTION_ALLOWLIST`       | `wp_awesome_allowlist`    | Option key holding the allowlist.                               |
| `WP_AWESOME_OPTION_TRUSTED_PROXIES` | `wp_awesome_trusted_proxies` | Option key holding the trusted-proxy list.                   |
| `WP_AWESOME_OPTION_MAINTENANCE`     | `wp_awesome_maintenance`  | Option key holding the maintenance toggle.                      |
| `WP_AWESOME_OPTION_LEGACY_ENABLED`  | _(empty)_                 | Legacy "gate enabled" key to migrate from. Empty means none.    |
| `WP_AWESOME_GATE_FALLBACK_SCOPE`    | `website`                 | Scope used when the stored value is missing or unrecognised.    |
| `WP_AWESOME_GATE_ACCESS_LOG_PATH`   | _(empty)_                 | Access log to read the recently-denied report from.             |
| `WP_AWESOME_MAINTENANCE_RETRY_AFTER`| `3600`                    | Seconds in the `Retry-After` header.                            |
| `WP_AWESOME_MAINTENANCE_LANGUAGE`   | `en`                      | `lang` attribute of the maintenance screen.                     |
| `WP_AWESOME_MAINTENANCE_TITLE`      | _generic_                 | Document title.                                                 |
| `WP_AWESOME_MAINTENANCE_HEADING`    | _generic_                 | Heading.                                                        |
| `WP_AWESOME_MAINTENANCE_BODY`       | _generic_                 | Body copy.                                                      |
| `WP_AWESOME_MAINTENANCE_FOOTNOTE`   | _(empty)_                 | Closing line.                                                   |
| `WP_AWESOME_MAINTENANCE_ILLUSTRATION` | _(empty)_               | Absolute path to an SVG inlined into the screen.                |
| `WP_AWESOME_MAINTENANCE_BACKGROUND` / `_TEXT_COLOUR` / `_HEADING_COLOUR` | dark neutral | Palette. |

### There is no environment override, deliberately

A `WP_AWESOME_GATE_PRODUCTION_NOOP` constant used to make the gate inert whenever
`wp_get_environment_type()` returned `production`. It was **removed**, and should not be reintroduced.

The settings page offers three modes. It must mean three modes. An administrator selecting `website`
on a production site and silently receiving no gating is the product lying to its operator — and
surfacing a notice does not fix it, because the operator cannot change a constant. They would read
the notice and still need a developer.

The risk it guarded was real but belongs elsewhere: a database restored across an environment
boundary carries `scope` with it, so a preview dump landing on production could take the site dark.
That is a **deploy-time** check. It fails loudly, at the moment of the mistake, in the pipeline that
made it — rather than disabling the control forever and invisibly on every request.

`tests/enforcement-test.php` asserts the neutrality directly: `website` gates a blocked address on
`production`, `staging`, `development` and `local` alike, and `disabled` gates nothing on any of them.
Reintroducing an environment exemption fails that matrix by name.

Maintenance mode was never affected by the old constant either: it runs in every environment, because
suppressing it on production would disable it exactly where it is needed.

## Migrating an existing installation

Option **keys** are injectable so that an existing site keeps its own. They hold live settings — the
allowlist gating a public site among them — and renaming a key means writing another migration and
getting it right on a security control, for no functional gain.

Only the stored **value** is reinterpreted, in place, idempotently, on every load:

| Stored                                        | Becomes    |
| --------------------------------------------- | ---------- |
| `'1'` in the scope option (old "allow public") | `disabled` |
| `'0'` in the scope option (old "allow public") | `website`  |
| `'1'` in `WP_AWESOME_OPTION_LEGACY_ENABLED`    | `website`  |
| `'0'` in `WP_AWESOME_OPTION_LEGACY_ENABLED`    | `disabled` |
| anything unrecognised                          | left alone |

The chain runs newest schema first, so an install on the oldest one still lands correctly. An
unrecognised value is left in the database rather than overwritten: it is the only evidence that
something restored the wrong data, and resolution already fails safe without needing to write.

## The access-log report

The host points `WP_AWESOME_GATE_ACCESS_LOG_PATH` at one access log written in this format:

```
[$time_local] host=$host request="$request" status=$status client=$remote_addr
```

**The leading bracket is required, not decorative.** This package's parser regex and that format
string must be verified together against real server output; pin both in the consuming project's own
deployment test. A sibling project's otherwise near-identical pair does not match anything, because
its format string omits the bracket its own parser requires.

The same lesson has a second half, found while writing this package: `client=` is the last field, so
a line read from a file ends with a newline right after the address. A capture that swallows it makes
`filter_var()` reject every real line, and the report stays permanently empty while looking healthy.
Fixtures without line endings do not catch that. The tests here read a real file.

**An empty table and a broken report are different states and must never render the same.** A
configured log can be unreadable for reasons that have nothing to do with this code — most commonly
the web server writes it into a volume that is not mounted into the container the interpreter runs
in, so from here the file simply does not exist. `wpaw_access_log_state()` returns
`not-configured`, `unreadable` or `readable`, and the screen says *"this report is unavailable"* and
names the path rather than showing an empty table that reads as a clean bill of health. That
indistinguishability is the root reason a broken report can survive for months, so it is treated as a
feature requirement, not a nicety.

When a report is empty, verify **both** preconditions before concluding anything: that the parser
matches the format, and that the interpreter can actually read the file. Either one alone produces
the identical empty result, so fixing one and re-checking the screen proves nothing about the other.

## Known limit: a relocated login form

`wp_login_url()` is filterable, but a plugin that relocates the login form registers its filter as an
ordinary plugin — which loads *after* must-use plugins. At enforcement time this package therefore
derives the default login path.

The consequence is narrower than it sounds: on such a host the relocated login **form** stays
reachable from an address that is not on the list, and nothing else changes. The dashboard is matched
with `is_admin()`, which core decides before WordPress loads and no plugin can move, so every
authenticated request after that login is still denied. The protected surface stays protected; only
the form is exposed.

It is not closed by hardcoding a path, and not by moving enforcement to a hook — that trade would
cost the single-check property the whole package exists for.

**A host that knows its login form has moved can close it: `WP_AWESOME_GATE_LOGIN_PATHS`.**

```php
function acme_login_paths(): array {
	// A RAW option read. Deliberately not apply_filters() — the relocating plugin's filter is not
	// attached at mu-plugin load, which is the very reason this mechanism exists.
	if ('custom' !== get_option('sg_security_login_type')) {
		return [];
	}

	return ['/' . trim((string) get_option('sg_security_login_url'), '/')];
}

const WP_AWESOME_GATE_LOGIN_PATHS = 'acme_login_paths';
```

The constant names a callable returning extra paths to treat as login surface. Undefined means
unchanged behaviour. It applies in `admin` scope only, because `website` scope denies everything
already and has no surface to add.

**Declare the redirector as well as the form.** Some relocation plugins — SiteGround Security's
Custom Login URL among them — 302 from the custom path to `wp-login.php` rather than rendering there.
In that shape the render target is already covered, because path normalisation strips the query
string, so the custom path leaks nothing. Declare it anyway: the boundary should not depend on the
downstream behaviour of a plugin this package does not control, and a plugin that renders *directly*
at the custom path is exactly the case this exists for.

**It fails closed by contributing nothing.** This runs before WordPress exists, so a throw here takes
the site down rather than denying a request. A missing constant, a non-callable, a callable that
throws, a non-array return, non-string entries, empty strings, and `/` itself all yield no extra
paths. `/` is refused specifically: accepting it would turn the whole frontend into login surface and
silently convert `admin` scope into `website` scope through a typo.

A project using a hide-login plugin should still verify this surface itself.

### Do not add a second layer that rewrites login URLs

Reported from production, and worth more than the coverage gap above. A relocating plugin typically
builds its own redirect target through `site_url()` and guards against looping by checking that both
the current and target URLs still contain `wp-login.php`. Add your own filter on `site_url` or
`login_url` to move the form, and **neither URL contains it any more, so the loop guard never fires**.
The result is a redirect loop with no error message, on the login form, in production. It was caught
only because a rate limiter killed the eleventh redirect.

So: relocation belongs to exactly one layer. If a hide-login plugin owns it, do not also filter login
URLs — and if you are removing that plugin in favour of your own alias, sequence the alias to land
*after* the removal, never alongside it.

### "Verified on preview" is worth less than it feels

The same incident passed a full browser round-trip on preview — login, dashboard, logout, all green —
and broke production anyway, because the relocating plugin was active on production and absent from
preview. The environments differed in precisely the one way that mattered, and nothing in the process
surfaced it before deploy.

This package ships to several projects with different plugin sets, and login is where plugins collide
most. Before shipping anything touching authentication, diff the **active plugin list** between the
environment you verified in and the one you are deploying to. A green twin proves the twin works.

## Tests

```sh
php tests/run.php
```

No WordPress, no database, no network. `tests/bootstrap.php` stubs the small WordPress surface this
package touches, and anything it does not define is a fatal error — which is how "the maintenance
payload needs no theme" is proved rather than asserted.

Each file runs in its **own process**, because the host fallback is a constant and a constant cannot
be redefined. Proving "an unrecognised value resolves to the host fallback" for more than one
fallback needs more than one process.
