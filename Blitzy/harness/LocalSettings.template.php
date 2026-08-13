<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @license GPL-2.0-or-later
 */

// =============================================================================
// Blitzy skin — verification-instance site configuration template
// =============================================================================
//
// WHAT THIS FILE IS
//
// harness/provision.sh renders this file into the MediaWiki CONTAINER's
// $IP/LocalSettings.php, where $IP is /var/www/html. It is the site
// configuration of the verification instance and the ONLY place the Blitzy skin
// is registered.
//
// It is NOT a set of shipped defaults. skin.json declares what a wiki gets by
// merely installing the skin, and it ships BlitzyAnnounceEnable as false and the
// other seven options as empty strings — so an ordinary installation renders
// neither an announcement bar nor a call-to-action pair, while this instance
// configures both because they appear in all 21 captures. Read the "SKIN
// CONFIGURATION" section at the foot of this file before assuming any value here
// is a product default.
//
// WHY A SETTINGS FILE IS THE SANCTIONED INTEGRATION POINT (R1 Core Immutability)
//
// R1 forbids modifying MediaWiki core, the bundled extensions and the vendored
// libraries, and its scope statement explicitly excludes the site configuration
// file. That exclusion is structural rather than a concession: a MediaWiki
// checkout ships no LocalSettings.php at all — includes/Installer/
// LocalSettingsGenerator.php generates one per site, and mediawiki/
// LocalSettings.php is absent from this repository's checkout — so registration
// performed here can never appear in a pristine-core diff. The rendered output
// goes to the container's copy only; nothing is ever written to mediawiki/ in
// the repository, and the skin itself reaches the container by bind mount
// (harness/docker-compose.yml mounts the package root at $IP/skins/Blitzy).
//
// The four wfLoadExtension() calls below are permitted for the same reason.
// Enabling an extension that already ships in the release through site
// configuration is not a core modification; installing an extension that is not
// bundled with the release is prohibited, so there is no fifth call.
//
// ⚠ DO NOT "SIMPLIFY" THIS FILE BY INCLUDING includes/DevelopmentSettings.php
//
// Two settings this instance needs — $wgEnableJavaScriptTest and
// $wgForceDeferredUpdatesPreSend — are exactly the two core's development
// profile turns on, so requiring that file (or passing
// maintenance/install.php --with-developmentsettings) looks like the obvious
// shortcut. It is a trap. The same file sets $wgMaxArticleSize = 20, twenty
// kibibytes, together with a matching Parsoid wikitext-size limit. Every one of
// the four Wikipedia fixtures is far larger than that, so importing them would
// fail, or import and then refuse to parse. Both flags are therefore set
// explicitly below and $wgMaxArticleSize is deliberately left at core's default
// of 2048.
//
// HOW THIS TEMPLATE IS RENDERED
//
// The file is valid PHP exactly as committed: .phpcs.xml at the package root
// puts every .php file in the package under the MediaWiki coding standard and
// php-parallel-lint parses it, and Gate 2 requires both to exit with zero
// warnings. A bare {{TOKEN}} substitution marker at statement level would break
// that, so every operator-supplied value is resolved at load time instead, from
// two sources tried in order:
//
//   1. A substitution sentinel of the form '@@NAME@@', which sits INSIDE a
//      quoted PHP string literal. provision.sh may replace it with a literal
//      value; an unreplaced sentinel is recognised and ignored.
//   2. getenv( 'NAME' ), which is how the value arrives when provision.sh
//      renders the file unsubstituted and lets harness/docker-compose.yml's
//      environment supply it. Verified to work in both the apache2handler and
//      the CLI SAPI of the pinned mediawiki:1.43.9 image.
//
// A substituting renderer must escape the value for a single-quoted PHP string,
// which means doubling backslashes and escaping apostrophes. The getenv() route
// has no such hazard and is the recommended one.
//
// SECRETS
//
// This file contains no credential, token or key literal, and it must stay that
// way: every credential arrives from the environment. The two operator secrets
// that have no default — MW_ADMIN_PASSWORD and BLITZY_TEST_PASSWORD — are
// asserted by name in harness/verify.sh before any other work, and are
// deliberately NOT read here. A settings file has no use for either (the
// installer stores a password hash in the database; Playwright signs in with
// the other), so reading them would widen the secret surface for nothing. The
// inputs this file cannot invent are the database credentials, and they are
// resolved through the same fail-fast path: a missing one aborts with an error
// naming the variable rather than half-configuring a wiki.
//
// =============================================================================

// Protect against web entry. Setup.php defines MEDIAWIKI before it loads the
// site configuration, so a direct HTTP request for this file produces nothing.
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Input resolution helpers
// -----------------------------------------------------------------------------
//
// Declared once, inside a function_exists() guard. Setup.php loads the site
// configuration with require_once, so a single process cannot reach these
// declarations twice through that path — but an operator who wraps this file
// from a settings file of their own can, and a redeclaration is a fatal error
// rather than a warning. The guard costs two lines and removes the failure mode.
//
// The wf prefix is not decoration either: MediaWiki's coding standard admits
// only that prefix for a global function, and Gate 2 fails on the warning.

if ( !function_exists( 'wfBlitzyHarnessFail' ) ) {

	/**
	 * Abort provisioning with an error that names the offending variable.
	 *
	 * A half-configured wiki fails later, somewhere else, with a message about
	 * a database or a missing page. Naming the variable at the point of failure
	 * is what keeps a blank line in .env from costing an hour, and it is the
	 * same contract harness/verify.sh applies to the two operator secrets.
	 *
	 * @param string $variable Name of the environment variable at fault.
	 * @param string $reason Sentence completing "<variable> ...", explaining
	 *   what was wrong with it. Ends with a full stop.
	 * @return never
	 */
	function wfBlitzyHarnessFail( string $variable, string $reason ): never {
		$message = "Blitzy harness configuration error: $variable $reason\n"
			. "Set it in the .env file beside Blitzy/.env.example, or export it into the\n"
			. "container environment, then re-run harness/verify.sh.\n";

		// error_log() reaches the container's log stream under both SAPIs, so the
		// cause survives in `docker compose logs` even when the response body of a
		// health probe is discarded.
		error_log( rtrim( $message ) );

		if ( PHP_SAPI === 'cli' ) {
			fwrite( STDERR, $message );
		} else {
			// Plain text rather than HTML: nothing has rendered yet, and a probe
			// reading the body should see the message, not markup around it.
			if ( !headers_sent() ) {
				header( 'Content-Type: text/plain; charset=utf-8', true, 500 );
			}
			echo $message;
		}

		// A non-zero status is what makes a provisioning step stop rather than
		// carry on against a wiki that never finished configuring itself.
		exit( 1 );
	}

	/**
	 * Report whether a value is an unreplaced substitution sentinel.
	 *
	 * The pattern is anchored and restricted to the spelling this file uses for
	 * its own sentinels, so a legitimate value that merely happens to contain
	 * "@@" is not mistaken for one.
	 *
	 * @param string $value Candidate value.
	 * @return bool True when the value is still a sentinel and carries no data.
	 */
	function wfBlitzyHarnessIsSentinel( string $value ): bool {
		return (bool)preg_match( '/^@@[A-Z0-9_]+@@$/', $value );
	}

	/**
	 * Resolve one harness input from the rendered literal or the environment.
	 *
	 * Sources are tried in order — the substituted literal first, because a
	 * renderer that wrote a value made an explicit decision, then getenv().
	 * A value that trims to nothing is treated as absent rather than as an
	 * empty setting: a blank MW_DB_NAME is a mistake, never an intent. Where
	 * empty genuinely is the right answer, as it is for MW_SCRIPT_PATH on an
	 * image that serves the wiki at the web root, the default supplies it.
	 *
	 * @param string $variable Name of the environment variable to read.
	 * @param string $rendered The sentinel literal from the call site, which
	 *   provision.sh may have replaced with a value.
	 * @param string|null $default Value to use when neither source supplies
	 *   one. Null makes the input REQUIRED: it then aborts by name instead.
	 * @return string Resolved, trimmed value.
	 */
	function wfBlitzyHarnessSetting( string $variable, string $rendered, ?string $default = null ): string {
		foreach ( [ $rendered, getenv( $variable ) ] as $candidate ) {
			// getenv() answers false for an unset variable.
			if ( !is_string( $candidate ) || wfBlitzyHarnessIsSentinel( $candidate ) ) {
				continue;
			}

			$value = trim( $candidate );
			if ( $value !== '' ) {
				return $value;
			}
		}

		if ( $default === null ) {
			wfBlitzyHarnessFail( $variable, 'is required and has no default.' );
		}

		return $default;
	}

	/**
	 * Resolve a harness input that configures a boolean setting.
	 *
	 * Environment variables are strings and PHP treats every non-empty string
	 * as truthy, so the string "false" would switch a feature ON if it were
	 * cast rather than parsed. That is not a hypothetical: the announcement bar
	 * has to be ABSENT FROM THE DOM when it is disabled, and a bar that
	 * rendered because someone wrote "false" would pass a hidden-element check
	 * while failing the requirement. Parsing is therefore deliberate, and an
	 * unrecognised spelling aborts by name instead of guessing — a typo that
	 * silently selected the default would change every capture.
	 *
	 * @param string $variable Name of the environment variable to read.
	 * @param string $rendered The sentinel literal from the call site.
	 * @param bool $default Value to use when neither source supplies one. It is
	 *   passed through the same parser as a supplied value, so there is exactly
	 *   one resolution path to reason about.
	 * @return bool
	 */
	function wfBlitzyHarnessFlag( string $variable, string $rendered, bool $default ): bool {
		$raw = wfBlitzyHarnessSetting( $variable, $rendered, $default ? 'true' : 'false' );
		$flag = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		if ( $flag === null ) {
			wfBlitzyHarnessFail(
				$variable,
				"is not a boolean: '$raw'. Accepted: true, false, 1, 0, on, off, yes, no."
			);
		}

		return $flag;
	}

	/**
	 * Resolve $wgSecretKey without committing a secret to this file.
	 *
	 * The installer normally writes a random key into the settings file it
	 * generates; this template replaces that file, so the key has to come from
	 * somewhere else. MW_SECRET_KEY is honoured when the operator supplies one.
	 * Otherwise the key is derived by hashing values that are themselves not in
	 * this file — the database password among them — which keeps the committed
	 * template free of any key material while still yielding a real 256-bit
	 * secret that nobody holding only this file can predict.
	 *
	 * The derivation is deterministic on purpose. A random key regenerated per
	 * provisioning run would invalidate the session Playwright persists between
	 * runs, and R9 wants two consecutive runs to behave identically. The key
	 * also matters for more than sessions: Html/TemplateParser skips its cache
	 * of compiled Mustache templates when no secret key is configured, which
	 * would recompile all nine of the skin's templates on every request.
	 *
	 * @param string $rendered The MW_SECRET_KEY sentinel literal.
	 * @param string[] $material Values to derive from when no key was supplied.
	 * @return string A 64-character lower-case hexadecimal key.
	 */
	function wfBlitzyHarnessSecretKey( string $rendered, array $material ): string {
		$supplied = wfBlitzyHarnessSetting( 'MW_SECRET_KEY', $rendered, '' );
		if ( $supplied !== '' ) {
			return $supplied;
		}

		// NUL separators, so no concatenation of two inputs can collide with a
		// different pair of inputs.
		return hash( 'sha256', implode( "\0", array_merge( [ 'blitzy-harness' ], $material ) ) );
	}
}

// -----------------------------------------------------------------------------
// INSTALLATION CONTRACT
// -----------------------------------------------------------------------------
//
// ⇩ THESE TWO LINES ARE THE ENTIRE REQUIREMENT FOR INSTALLING THIS SKIN. ⇩
//
// Everything after them configures the verification instance; none of it is
// needed by a wiki that simply wants to run the skin.

wfLoadSkin( 'Blitzy' );

// The registered skin name is lower-case 'blitzy' — the key skin.json declares
// under ValidSkinNames — and is a different string from the 'Blitzy' directory
// name wfLoadSkin() takes above. Naming the directory here would leave the wiki
// on its previous default skin without reporting an error.
$wgDefaultSkin = 'blitzy';

// ⇧ END OF THE INSTALLATION CONTRACT. ⇧

// -----------------------------------------------------------------------------
// Site identity
// -----------------------------------------------------------------------------

// Rendered chrome: the site name reaches the page title, the footer and
// Special:Version, so it is a fixed string rather than an operator input. A
// variable site name would change the bytes of every capture (R9).
$wgSitename = 'Blitzy Verification Wiki';

// Project-namespace name, derived from the site name rather than repeated so
// the two can never drift. Core applies the same space-to-underscore
// substitution when it computes this default; doing it here makes the resulting
// namespace explicit for the fixtures that link into it.
$wgMetaNamespace = strtr( $wgSitename, ' ', '_' );

// Translation beyond the en source locale is out of scope, and the language
// code also fixes date and number formatting, which is capture-visible (R9).
$wgLanguageCode = 'en';

// -----------------------------------------------------------------------------
// URLs
// -----------------------------------------------------------------------------
//
// MW_SERVER and MW_SCRIPT_PATH are load bearing beyond this file:
// playwright.config.ts derives its baseURL from the pair, and core's own
// Gruntfile.js concatenates them into the in-wiki QUnit URL. Both are trimmed
// of a trailing slash, because a doubled slash in a generated URL is a
// difference a capture would record.

$wgServer = rtrim( wfBlitzyHarnessSetting( 'MW_SERVER', '@@MW_SERVER@@', 'http://localhost:8080' ), '/' );

// The pinned image serves MediaWiki at the web root, so the empty string is the
// correct default here and an empty value is a real answer rather than a
// mistake.
$wgScriptPath = rtrim( wfBlitzyHarnessSetting( 'MW_SCRIPT_PATH', '@@MW_SCRIPT_PATH@@', '' ), '/' );

// The URL path to static resources (images, scripts, stylesheets).
$wgResourceBasePath = $wgScriptPath;

// Pages are addressed as index.php?title=Page_title, and $wgArticlePath is
// deliberately NOT assigned: core derives it from this switch through
// MainConfigSchema::getDefaultArticlePath(), which answers "$wgScript?title=$1"
// when path info is off and "$wgScript/$1" when it is on. The default is on
// under the image's apache2handler SAPI, which would make /index.php/Page_title
// the canonical form, so the switch has to be turned off explicitly for the
// ?title= grammar every Playwright specification uses to be the one the wiki
// itself generates. Assigning $wgArticlePath directly is not an option: it
// would have to interpolate $wgScript, which core has not yet derived at the
// time this file is loaded.
//
// Genuinely pretty URLs (/wiki/Page_title) are out of scope: they need rewrite
// rules in the image's web-server configuration, and editing the image's
// infrastructure is exactly what this harness avoids.
$wgUsePathInfo = false;

// -----------------------------------------------------------------------------
// Database
// -----------------------------------------------------------------------------
//
// The MARIADB_* variables are the connection facts harness/docker-compose.yml
// sets on the mediawiki service, and they are the same values
// maintenance/install.php is invoked with during provisioning, so the installer
// and this file cannot disagree about where the wiki lives. MW_DB_NAME is the
// operator input .env.example documents; MARIADB_DATABASE mirrors it.
//
// The user and the password have NO default on purpose. A default would be a
// credential literal committed to the repository, and this file must contain
// none, so a missing one aborts with an error naming the variable instead.

$wgDBtype = 'mysql';
$wgDBserver = wfBlitzyHarnessSetting( 'MARIADB_HOST', '@@MARIADB_HOST@@', 'db' );
$wgDBname = wfBlitzyHarnessSetting( 'MW_DB_NAME', '@@MW_DB_NAME@@', 'blitzy_verify' );
$wgDBuser = wfBlitzyHarnessSetting( 'MARIADB_USER', '@@MARIADB_USER@@' );
$wgDBpassword = wfBlitzyHarnessSetting( 'MARIADB_PASSWORD', '@@MARIADB_PASSWORD@@' );
$wgDBprefix = '';

// The table options the installer creates the schema with. Repeated here so
// that maintenance/update.php, which runs after the four extensions below are
// enabled, creates their tables the same way core's were created.
$wgDBTableOptions = 'ENGINE=InnoDB, DEFAULT CHARSET=binary';

// -----------------------------------------------------------------------------
// Bundled extensions — exactly these four
// -----------------------------------------------------------------------------
//
// All four ship in the official MediaWiki tarball and in the pinned
// mediawiki:1.43.9 image, so nothing is installed here: enabling an extension
// that is already part of the release through site configuration is explicitly
// not a core modification (R1). The fixtures need all four — Lua modules,
// parser functions, <ref> citations and highlighted code blocks are transcluded
// by the imported articles, and harness/post-import-verify.php hard-fails on a
// script error or an unexpanded transclusion before any capture is taken.
//
// Installing an extension that is NOT bundled with the release is prohibited,
// so there is no fifth wfLoadExtension() call and none may be added. Nothing
// out of scope belongs here either: no VisualEditor, MobileFrontend,
// DiscussionTools or CirrusSearch (R12).

wfLoadExtension( 'Scribunto' );

// The standalone engine invokes the lua binary the image ships as a separate
// process. It has to be named explicitly: an unset or mis-set engine surfaces
// as a script error inside every infobox and citation template that transcludes
// a Lua module, which is precisely what post-import-verify.php refuses to
// proceed past.
$wgScribuntoDefaultEngine = 'luastandalone';

wfLoadExtension( 'ParserFunctions' );

// The string functions ({{#len:}}, {{#sub:}}, {{#replace:}} and the rest) are
// off by default, and Wikipedia keeps them off too, relying on Lua modules
// instead — so the imported fixtures should not need them. They are enabled
// because the failure mode is asymmetric: a template that did call one would
// render the call as literal wikitext, which post-import-verify.php counts as
// an unexpanded transclusion and hard-fails on, whereas enabling a parser
// function that nothing calls changes no rendered output at all.
$wgPFEnableStringFunctions = true;

wfLoadExtension( 'Cite' );

// The directory and load name carry the historical GeSHi suffix even though the
// extension has used Pygments for years. 'SyntaxHighlight' does not exist and
// would abort the request with a missing-extension error.
wfLoadExtension( 'SyntaxHighlight_GeSHi' );

// -----------------------------------------------------------------------------
// Logo
// -----------------------------------------------------------------------------
//
// $wgLogos is the only place the mark is wired: it is never embedded in a
// template. The schema declares this setting as 'map|false', so a map is the
// only way to supply an image, and both entries point at the single 32-pixel
// monoline mark the skin commits. There is no wordmark or tagline entry because
// the Blitzy wordmark is typographic — the skin sets it in the display font
// from its own stylesheet.
//
// The path resolves through the bind mount that puts the package root at
// $IP/skins/Blitzy, and it is a plain assignment with no existence check on
// purpose: a missing SVG must not stall the build. The wordmark then renders
// with no mark beside it and DELIVERY.md reports the fallback as unresolved.

$wgLogos = [
	'1x' => "$wgResourceBasePath/skins/Blitzy/resources/images/blitzy-logo.svg",
	'icon' => "$wgResourceBasePath/skins/Blitzy/resources/images/blitzy-logo.svg",
];

// -----------------------------------------------------------------------------
// R5 Zero External Origins
// -----------------------------------------------------------------------------
//
// The skin references no host outside the wiki's own origin, and
// tests/playwright/externalOrigins.spec.ts fails the run if any subresource
// request on any loaded page resolves to a foreign origin. Three of the
// settings below can breach that on their own, whatever the skin does —
// InstantCommons, external images and the pingback — and uploads are switched
// off alongside them because no upload backend is provisioned. All are pinned
// explicitly even where the pinned value matches core's default: defence in
// depth costs one line each, and a future default change would otherwise be
// invisible until a capture run failed.
//
// Accepted consequence, recorded here for DELIVERY.md: with uploads off and
// InstantCommons off, the [[File:…]] references inside the imported fixtures
// render without their media. That is the correct trade — R5 outranks
// decorative fidelity — and it is not a gate failure, because
// post-import-verify.php asserts zero red links in the TEMPLATE namespace only.

// InstantCommons fetches media from commons.wikimedia.org, a foreign origin
// that would be requested from every fixture page carrying a file reference.
$wgUseInstantCommons = false;

// Inline <img> from arbitrary hosts in wikitext, and the allowlist that would
// re-admit some of them.
$wgAllowExternalImages = false;
$wgAllowExternalImagesFrom = [];

// No upload backend is provisioned, so uploads stay off. This also keeps
// Special:Upload and the upload link out of every captured page.
$wgEnableUploads = false;

// The pingback would post installation telemetry to www.mediawiki.org.
$wgPingback = false;

// -----------------------------------------------------------------------------
// Mail and notifications
// -----------------------------------------------------------------------------
//
// Email is off so the harness needs no mail transport, and so that no
// notification or "confirm your email address" chrome can appear in a capture.
// The ordinary account provisioning creates never needs to receive anything.

$wgEnableEmail = false;
$wgEnableUserEmail = false;
$wgEnotifUserTalk = false;
$wgEnotifWatchlist = false;
$wgEmailAuthentication = false;

// -----------------------------------------------------------------------------
// Testing
// -----------------------------------------------------------------------------

// Gate 13 pairs every registered client-side listener with a test that
// dispatches its event, and the in-wiki half of that suite runs from
// Special:JavaScriptTest, which core gates behind this flag and ships disabled.
// Without it the QUnit module skin.json registers cannot be loaded at all: the
// special page simply does not exist, and Karma's request for
// index.php?title=Special:JavaScriptTest/qunit/export&component=Blitzy answers
// with an error page instead of the suite.
$wgEnableJavaScriptTest = true;

// Diagnosis aid: an exception carries its message and backtrace instead of a
// bare reference number. Invisible on a healthy page, so it cannot affect a
// capture, and worth far more than the log spelunking its absence costs.
$wgShowExceptionDetails = true;

// -----------------------------------------------------------------------------
// Determinism (R9)
// -----------------------------------------------------------------------------

// Deferred updates run before the response is sent, so a GET observes the
// effects of every previous POST (T230211). This is what stabilises captures
// taken immediately after fixture import and after the seeded edits that
// produce the history and diff surfaces.
//
// Caveat, straight from the setting's own schema documentation: it waits for
// neither the job queue nor database replication. That is why provisioning
// additionally drains the queue with maintenance/runJobs.php rather than
// trusting this flag to have done it.
$wgForceDeferredUpdatesPreSend = true;

// No job may run as a side effect of a page view. Core's default of 1 lets a
// queued job fire at the end of an arbitrary request, and a job that touches
// link tables or category membership can change what a later page renders — so
// with the default, two consecutive capture runs can legitimately differ, which
// is the one outcome R9 forbids. Provisioning drains the queue explicitly
// before any capture is taken, so nothing is left unexecuted; it is only the
// timing that is taken away.
$wgJobRunRate = 0;

// Rendered timestamps in the history, diff and recent-changes captures must not
// shift with the host's clock zone. The containers set TZ=UTC as well; this is
// the setting MediaWiki itself formats with.
$wgLocaltimezone = 'UTC';

// A visible debug bar, or debug output appended to the page, would appear in
// every capture and break byte-identity outright. Both are pinned off.
$wgDebugToolbar = false;
$wgShowDebug = false;

// $wgMaxArticleSize is deliberately NOT set, so it keeps core's default of 2048
// kibibytes. includes/DevelopmentSettings.php lowers it to 20, which is small
// enough to break the import of all four Wikipedia fixtures — see the warning
// in this file's header before reaching for that file again.

// The object cache is deliberately NOT configured either, so sessions and the
// parser cache fall back to their database-backed stores. The installer would
// have chosen the in-process accelerator, which is faster but lives and dies
// with the Apache worker: a graceful restart between provisioning and capture
// would drop the session Playwright persists, and the accelerator is absent
// from the CLI SAPI the maintenance scripts run under. A store that survives
// both is worth more here than the latency it costs (R9).

// Sessions and the compiled-template cache both need a key; see
// wfBlitzyHarnessSecretKey() for why it is derived rather than committed, and
// why the derivation is deterministic across provisioning runs.
$wgSecretKey = wfBlitzyHarnessSecretKey( '@@MW_SECRET_KEY@@', [ $wgServer, $wgDBname, $wgDBpassword ] );

// Provisioning authors several pages in quick succession through
// maintenance/edit.php, and the capture run signs in repeatedly, both of which
// core's default rate limits would throttle. The permitted counts are raised in
// place rather than $wgRateLimits being emptied, so the throttling code still
// runs and is still exercised: this mirrors the intent of core's development
// profile without including the file that carries the article-size trap.
//
// In each entry element 0 is the permitted count and element 1 the window in
// seconds, and only the count is touched. Non-array entries are skipped so that
// a marker key such as '&can-bypass', which some actions may carry, cannot turn
// this loop into a type error.
if ( is_array( $wgRateLimits ) ) {
	foreach ( $wgRateLimits as &$blitzyThrottledAction ) {
		if ( !is_array( $blitzyThrottledAction ) ) {
			continue;
		}

		foreach ( $blitzyThrottledAction as &$blitzyThrottledGroup ) {
			if ( is_array( $blitzyThrottledGroup ) && isset( $blitzyThrottledGroup[0] ) ) {
				$blitzyThrottledGroup[0] = PHP_INT_MAX;
			}
		}
		unset( $blitzyThrottledGroup );
	}
	unset( $blitzyThrottledAction );
}

// Sign-in attempts are throttled separately from the rate limits above. The
// capture run authenticates once and reuses the stored session, but a failed
// run leaves attempts behind, and a rerun must not be locked out of the account
// it needs for fifteen of the twenty-one captures.
$wgPasswordAttemptThrottle = [
	[ 'count' => 5000, 'seconds' => 300 ],
	[ 'count' => 500000, 'seconds' => 48 * 60 * 60 ],
];

// =============================================================================
// SKIN CONFIGURATION — the eight options skin.json declares
// =============================================================================
//
// ⚠ THESE VALUES CONFIGURE THE VERIFICATION INSTANCE ONLY. THEY ARE NOT THE
//   SKIN'S SHIPPED DEFAULTS. skin.json ships BlitzyAnnounceEnable as false and
//   the other seven as empty strings, so a wiki that merely installs the skin
//   gets no announcement bar and no call-to-action pair. The bar is switched on
//   here because it has to appear in all 21 captures, and its content is fixed
//   because byte-identity forbids variable chrome content (R9).
//
// This section is the write site half of the Gate 12 configuration-propagation
// audit that DELIVERY.md tabulates. Every option below is assigned exactly once,
// on one line, so each row of that table can be verified with a single grep, and
// none of them is scattered across a conditional. The read site for all eight is
// MediaWiki\Skins\Blitzy\BlitzyViewModel, which turns them into template data
// for AnnouncementBar.mustache and NavCard.mustache.
//
// Values are written RAW. HTML escaping happens exactly once, in the templates'
// {{ }} interpolation, and BlitzyViewModel deliberately does not escape either;
// pre-escaping here would double-escape and render an ampersand or an angle
// bracket visibly as an entity on every page. Injection payloads belong in
// tests/phpunit/integration/BlitzyConfigSecurityTest.php, never in this file.
//
// -----------------------------------------------------------------------------
// Announcement bar
// -----------------------------------------------------------------------------

// A boolean, resolved from a string environment variable through a parser
// rather than a cast — see wfBlitzyHarnessFlag(). When this is false the bar is
// absent from the DOM rather than hidden, because BlitzyViewModel omits the key
// the template's section is guarded on.
$wgBlitzyAnnounceEnable = wfBlitzyHarnessFlag( 'BLITZY_ANNOUNCE_ENABLE', '@@BLITZY_ANNOUNCE_ENABLE@@', true );

// The dash in the default is U+2014 EM DASH, not a hyphen: substituting one
// changes the announcement bar in all 21 captures and breaks byte-identity
// against the committed baselines. An empty value suppresses the bar even when
// the flag above is true, which is why this carries the fixed string as its
// default rather than an empty one.
$wgBlitzyAnnounceText = wfBlitzyHarnessSetting(
	'BLITZY_ANNOUNCE_TEXT',
	'@@BLITZY_ANNOUNCE_TEXT@@',
	'State of Wiki Engineering — Read the Notes'
);

// Label of the bar's call-to-action button. The button is rendered only when
// both this and the link target below survive validation, so an empty label
// leaves the bar showing its text alone rather than an anchor with no
// accessible name.
$wgBlitzyAnnounceLabel = wfBlitzyHarnessSetting( 'BLITZY_ANNOUNCE_LABEL', '@@BLITZY_ANNOUNCE_LABEL@@', 'Read Now' );

// Deliberately a fixed site-relative path and not an environment variable,
// which is what keeps .env at the eleven variables the specification allows
// (R12). Three properties matter and all three are satisfied: it is
// site-relative, so BlitzyUrlValidator accepts it and no foreign origin can be
// introduced (R5); it is fixed, so the rendered href is identical on every run
// (R9); and it addresses a page provisioning guarantees exists, because the
// main-page fixture is authored as this wiki's Main Page. The ?title= grammar
// matches the URL form pinned by $wgUsePathInfo above.
$wgBlitzyAnnounceLink = $wgScriptPath . '/index.php?title=Main_Page';

// -----------------------------------------------------------------------------
// Navigation-card calls to action
// -----------------------------------------------------------------------------
//
// Both labels are fixed strings rather than environment inputs, for two
// reasons. They are rendered chrome in every one of the 21 captures, so they
// have to be constant (R9). And they must be non-empty: NavCard.mustache
// renders no anchor at all for an unlabelled call to action, which is what
// keeps an anchor with no accessible name off the page and out of the blocking
// accessibility gate — so an empty label would silently delete a component the
// fidelity assertions require to be present.
//
// Neither label varies with login state, which is what keeps anonymous and
// logged-in chrome identical outside the personal menu. Captures 1 and 20
// photograph the same surface in both states and are compared on that basis.

// The outline-styled call to action.
$wgBlitzyPrimaryActionLabel = 'Get started';

// Empty ON PURPOSE, and assigned rather than omitted so that Gate 12 has a
// named write site for it. The empty value is the instruction: BlitzyViewModel
// derives the target from core when configuration supplies none, resolving
// Special:CreateAccount for an unregistered visitor and Special:Watchlist for a
// registered one. Writing a target here would replace that behaviour, and the
// href is the only attribute in the skin that may differ between the anonymous
// and the authenticated capture of the same surface.
$wgBlitzyPrimaryActionLink = '';

// The gradient-styled call to action.
$wgBlitzySecondaryActionLabel = 'Random article';

// Empty ON PURPOSE, exactly as for the primary target above: BlitzyViewModel
// then derives Special:Random, which needs no configuration to be useful and
// which behaves identically for every visitor.
$wgBlitzySecondaryActionLink = '';

// The theme preference is NOT configured here. skin.json declares
// DefaultUserOptions.blitzy-theme as 'os', and forcing a value at site level
// would change the sixteen light captures. The five dark captures select night
// mode per user, or through an injected client-preference cookie, in
// tests/playwright/capture.spec.ts — never from this file, and never by an
// ad-hoc query parameter.

// =============================================================================
// End of the Blitzy verification-instance settings.
// Add more configuration options below.
// =============================================================================
