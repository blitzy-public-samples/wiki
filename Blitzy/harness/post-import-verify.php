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
// Blitzy skin — post-import fixture-integrity gate
// =============================================================================
//
// WHAT THIS SCRIPT IS
//
// A MediaWiki maintenance script that runs on the verification instance AFTER
// harness/provision.sh has imported the four Wikipedia fixtures and authored the
// four local fixture pages, and BEFORE any screenshot is taken. It asserts that
// the wiki is in the exact state the twenty-one-capture matrix depends on, and
// it fails the whole run when it is not.
//
// Invocation, either form (both verified; every step of the harness is
// individually invocable, which is Gate 10's debuggability requirement):
//
//     docker compose exec mediawiki \
//         php skins/Blitzy/harness/post-import-verify.php
//     docker compose exec mediawiki \
//         php maintenance/run.php ./skins/Blitzy/harness/post-import-verify.php
//
// The leading ./ in the second form is required, not decoration: MaintenanceRunner
// resolves a bare relative path against $IP/maintenance/, so the same path without
// it is reported as a script that does not exist. An absolute path works too.
//
// Add --json to emit a machine-readable summary on stdout INSTEAD of the
// human-readable report, so provision.sh or verify.sh can tee it and DELIVERY.md
// can quote post-import verification results without re-deriving them.
//
// THE EXIT CONTRACT — why this script matters structurally
//
// Gate 9 requires that every component and every derived treatment have at least
// one rendered instance asserted BY ELEMENT PRESENCE BEFORE any computed-style
// assertion executes. This script is where that happens. Without it a fidelity
// assertion in tests/playwright/fidelity.spec.ts could pass vacuously against an
// element that was never rendered at all.
//
// execute() therefore returns false — never merely warns — when any check fails.
// maintenance/run.php ends with `if ( !$success ) { exit( 1 ); }`, so a returned
// false becomes exit status 1, which is what provision.sh and verify.sh branch
// on to abort before the capture pass. fatalError() is the other hard-fail route
// and is used only for a condition that makes checking itself impossible.
//
// Every check runs even after an earlier one has failed, and every failure is
// collected and reported before the single `return false` at the end. Stopping
// at the first failure would hide the rest of the picture from the operator, and
// the whole point of the gate is to say what is wrong with the fixtures rather
// than merely that something is.
//
// HOW THE HTML IS OBTAINED — the one architectural decision that matters here
//
// ParserOutput exposes the ARTICLE BODY only. Most of the eleven components are
// skin chrome — the navigation card, the announcement bar, both call-to-action
// buttons, the hero title, the tab row, the table-of-contents card, the category
// chips — and a ParserOutput-only implementation cannot see any of them, so it
// would silently under-assert while looking thorough. The work is therefore
// split deliberately:
//
//   * PRESENCE assertions (checks 5 and 7) run over FULL RENDERED PAGES fetched
//     over HTTP from the wiki itself through core's HttpRequestFactory service.
//     Gate 9 asks for rendered output "asserted present in a fetched page", and
//     this is that. A request to the wiki's own origin is not a foreign origin,
//     so R5 Zero External Origins is unaffected.
//   * LINK, TRANSCLUSION and PARSE-ERROR assertions (checks 1, 2 and 3) run over
//     ParserOutput metadata, which is authoritative for those and far more
//     reliable than scraping HTML for them.
//
// Two hard-won details govern the HTTP half, both verified against the pinned
// mediawiki:1.43.9 image rather than assumed:
//
//   1. $wgServer is NOT necessarily reachable from where this script runs.
//      harness/docker-compose.yml publishes ${MW_DOCKER_PORT:-8080}:80, so
//      $wgServer is http://localhost:8080 as seen from the HOST while Apache
//      inside the container listens on port 80 — a request to $wgServer from
//      inside the wiki container fails to connect. resolveOrigin() therefore
//      tries the configured server first and falls back to loopback candidates
//      carrying a Host header for the configured host, exactly as the container's
//      own healthcheck probes http://127.0.0.1/index.php. The origin that
//      answered is reported, so the operator always knows which one was used.
//   2. Redirects are NOT followed. MediaWiki redirects some URLs to the
//      canonical $wgServer, so following a redirect from a loopback origin walks
//      straight into the unreachable host and reports a connection failure that
//      hides the real status. With followRedirects off, a 3xx is reported as the
//      3xx it is.
//
// An unreachable wiki is reported as a named failure. It is never silently
// reported as "no violations found".
//
// WHAT THIS SCRIPT DELIBERATELY DOES NOT DO
//
//   * It never repairs (R12 No Scope Expansion). It does not re-import, re-edit
//     a page, rebuild the search index or adjust configuration. Self-healing
//     would mask exactly the drift this gate exists to catch.
//   * It writes no file anywhere, and in particular nothing into
//     screenshots/audits/, which holds exactly twenty-three artefacts — the
//     twenty-one accessibility reports plus contrast.json and
//     measurement-768.json. A twenty-fourth would break the committed-output
//     inventory (R9). The report goes to stdout.
//   * It touches nothing in mediawiki/, mediawiki-vendor/ or
//     mediawiki-skins-Vector/ (R1 Core Immutability). Core is consumed through
//     its published service and metadata APIs, read-only.
//   * It widens no check beyond its specification. In particular the red-link
//     check covers the TEMPLATE namespace only: with uploads and InstantCommons
//     off, the [[File:...]] references inside the imported fixtures render
//     without media and place their pages in the broken-file tracking category.
//     That is the accepted consequence of R5, recorded as such in
//     harness/LocalSettings.template.php, and it is not a gate failure.
//
// VERSION TOLERANCE
//
// The runtime target is the 1.43 LTS line; the repository checkout used as the
// API reference is 1.47.0-alpha. Three divergences inside that range are
// navigated explicitly rather than discovered later:
//
//   * ParserOutput::getLinkList() takes ONE argument on 1.43 and gained an
//     optional $onlyNamespace parameter later, so exactly one argument is passed
//     and namespaces are filtered here. Passing a second argument would be
//     silently ignored on 1.43 and would mis-scope the check. The removed
//     getTemplates()/getLinks() accessors are not used at all.
//   * ParserOutputLinkTypes is a class of string constants on 1.43 and an enum
//     later. ParserOutputLinkTypes::TEMPLATE is valid input to getLinkList() in
//     both shapes, so the constant is referenced and never a bare string.
//   * SearchEngine and SearchEngineFactory are global-namespace classes on 1.43
//     and namespaced later, so neither class is ever named: the engine is
//     obtained from the service container and used through its methods only.
//     BaseSearchResultSet::next() has been deprecated since 1.32 and is avoided
//     in favour of numRows() and extractResults().
//   * ParserOutput::getRawText() and ::getText() both exist on 1.43 and were both
//     removed on a later release, so the body HTML is read through
//     ::getContentHolderText(), which exists across the whole range. This one was
//     found by running the script against both trees rather than by reading, and
//     it is the reason bodyHtml() exists.
//
// Two further version notes, in the same spirit. PageStore::getPageByText()
// returns a page IDENTITY — a non-null object with id zero for a page that does
// not exist — so page existence is resolved through getPageByReference(), which
// returns null. And getPageByText()'s result is not a PageRecord at all, which
// ParserOutputAccess::getParserOutput() requires.

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Title\Title;

// @codeCoverageIgnoreStart
// The bootstrap mediawiki/skins/README documents for an out-of-tree component,
// with the fallback depth this file's own location requires: harness -> Blitzy
// -> skins -> $IP is three levels. MW_INSTALL_PATH wins when it is set, which is
// what makes the script work when the package is reached through a symlink —
// the same README warns that under POSIX the parent of a symbolic path resolves
// to the link source, not to the target. harness/docker-compose.yml sets the
// variable for the wiki container, so the fallback is the exception rather than
// the rule.
$IP = getenv( 'MW_INSTALL_PATH' );
// strval() rather than a false test, matching core's own bootstrap: an
// environment that exports the variable as an empty string must fall back too,
// or $IP becomes '' and every path below resolves from the filesystem root.
if ( strval( $IP ) === '' ) {
	$IP = __DIR__ . '/../../..';
}
if ( !is_file( "$IP/maintenance/Maintenance.php" ) ) {
	// Named rather than left to a "failed to open stream" notice. This is the one
	// failure an operator hits by invoking the script from an unexpected location,
	// and it is exactly what the symlink hazard in mediawiki/skins/README produces:
	// through a symlink, the parent of this file resolves to the link source rather
	// than to the skin inside the wiki, so the relative fallback lands outside the
	// installation and only MW_INSTALL_PATH can put it right.
	fwrite(
		STDERR,
		"post-import-verify.php: cannot find a MediaWiki installation.\n"
		. "  Looked for $IP/maintenance/Maintenance.php\n"
		. "  Set MW_INSTALL_PATH to the wiki's installation directory and try again.\n"
	);
	exit( 1 );
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Asserts that the verification instance holds the fixture state the capture
 * matrix depends on, and fails the run when it does not.
 *
 * The class lives in the global namespace on purpose. skin.json maps the PSR-4
 * namespace MediaWiki\Skins\Blitzy\ to includes/ only, and this file sits in
 * harness/, so it is loaded by path rather than by the autoloader and needs no
 * autoload entry. Every service is taken from the base class's service-container
 * accessor rather than from global state.
 */
class BlitzyPostImportVerify extends Maintenance {

	/**
	 * The search term the search checks use, fixed on every run.
	 *
	 * AAP section 0.1.2.5 fixes it because R9 byte-identity forbids variable
	 * chrome content, and the same string names the search captures. It is
	 * deliberately not an option: a parameterised query would let a green run
	 * prove something other than the captured surface does.
	 */
	private const SEARCH_QUERY = 'Lovelace';

	/** Imported fixture article: biographical infobox, dense references, images. */
	private const PAGE_ADA = 'Ada Lovelace';

	/** Imported fixture article: software infobox, highlighted code, nested lists. */
	private const PAGE_PYTHON = 'Python (programming language)';

	/** Imported fixture article: the large sortable wikitable and overflow case. */
	private const PAGE_GDP = 'List of countries by GDP (nominal)';

	/** Imported fixture article: deep heading nesting, notation, hatnotes. */
	private const PAGE_PHOTOSYNTHESIS = 'Photosynthesis';

	/** Authored fixture: the only rendered instance of components 6 and 7. */
	private const PAGE_MAIN = 'Main Page';

	/** Authored fixture: the talk half of the namespace tab pair. */
	private const PAGE_TALK = 'Talk:Ada Lovelace';

	/** Authored fixture: the category description page behind the chip footer. */
	private const PAGE_CATEGORY = 'Category:Blitzy verification fixtures';

	/**
	 * Default installer administrator account name.
	 *
	 * Overridden by MW_ADMIN_USER, which harness/docker-compose.yml passes into
	 * the wiki container and .env.example already enumerates, so no new
	 * environment variable is introduced here. The default matches the Compose
	 * default exactly; a mismatch would make check 7 assert the wrong name.
	 */
	private const DEFAULT_ADMIN_USER = 'BlitzyInstaller';

	/**
	 * Default ordinary, preferences-populated account name.
	 *
	 * Overridden by BLITZY_TEST_USER on the same terms as the administrator
	 * name above. Every authored fixture revision belongs to this account.
	 */
	private const DEFAULT_TEST_USER = 'BlitzyReviewer';

	/** Maximum characters of surrounding context printed for a marker match. */
	private const CONTEXT_RADIUS = 60;

	/** Maximum offending items listed per failing check, so a report stays readable. */
	private const MAX_REPORTED_ITEMS = 25;

	// -------------------------------------------------------------------------
	// Page sets
	// -------------------------------------------------------------------------

	/** The four articles harness/provision.sh imports with importDump.php. */
	private const IMPORTED_PAGES = [
		self::PAGE_ADA,
		self::PAGE_PYTHON,
		self::PAGE_GDP,
		self::PAGE_PHOTOSYNTHESIS,
	];

	/**
	 * The titles the four authored wikitext fixtures resolve to.
	 *
	 * Three titles for four fixtures, and the arithmetic is recorded rather than
	 * hidden: fixtures/history-seed.wikitext and fixtures/main-page.wikitext are
	 * both saved to "Main Page", the seed first. The fourth fixture is therefore
	 * evidenced by Main Page carrying at least two revisions, which check 6
	 * asserts, and not by a fourth title.
	 */
	private const AUTHORED_PAGES = [
		self::PAGE_MAIN,
		self::PAGE_TALK,
		self::PAGE_CATEGORY,
	];

	// -------------------------------------------------------------------------
	// Fetched surfaces
	// -------------------------------------------------------------------------

	/** Surface key: the imported biography, and the general chrome surface. */
	private const SURFACE_ARTICLE = 'article';

	/** Surface key: the imported software article. */
	private const SURFACE_PYTHON = 'article-python';

	/** Surface key: the imported large-wikitable article. */
	private const SURFACE_GDP = 'article-gdp';

	/** Surface key: the imported deep-heading article. */
	private const SURFACE_PHOTOSYNTHESIS = 'article-photosynthesis';

	/** Surface key: the authored main page. */
	private const SURFACE_MAIN_PAGE = 'main-page';

	/** Surface key: the authored talk page. */
	private const SURFACE_TALK = 'talk-page';

	/** Surface key: the authored category page. */
	private const SURFACE_CATEGORY = 'category-page';

	/** Surface key: the wikitext edit form. */
	private const SURFACE_EDIT = 'edit-form';

	/** Surface key: the main page history list. */
	private const SURFACE_HISTORY = 'history';

	/** Surface key: the main page diff between the seed and the current revision. */
	private const SURFACE_DIFF = 'diff';

	/** Surface key: the fixed-query search results page. */
	private const SURFACE_SEARCH = 'search-results';

	/**
	 * The pages fetched over HTTP, as data.
	 *
	 * One row per surface: the title to request, the extra query parameters, and
	 * a human label used in the report. Special:Preferences is deliberately
	 * absent: it redirects an anonymous request to the login page, so it is a
	 * capture surface for the authenticated Playwright projects and cannot be
	 * asserted from here.
	 *
	 * SURFACE_DIFF carries 'latest-oldid' rather than a revision id, because
	 * fixtures/history-seed.wikitext records that no capture URL may hardcode
	 * one: imported revisions do not keep the ids recorded in the XML, and the
	 * ids of the two authored main page edits are assigned when they are saved.
	 * The id is resolved from the page's current revision at run time and
	 * combined with diff=prev, which is what makes the request name the seed and
	 * the current revision without naming either id.
	 */
	private const SURFACES = [
		self::SURFACE_ARTICLE => [
			'label' => 'article (Ada Lovelace)',
			'title' => self::PAGE_ADA,
			'query' => [],
		],
		self::SURFACE_PYTHON => [
			'label' => 'article (Python)',
			'title' => self::PAGE_PYTHON,
			'query' => [],
		],
		self::SURFACE_GDP => [
			'label' => 'article (GDP list)',
			'title' => self::PAGE_GDP,
			'query' => [],
		],
		self::SURFACE_PHOTOSYNTHESIS => [
			'label' => 'article (Photosynthesis)',
			'title' => self::PAGE_PHOTOSYNTHESIS,
			'query' => [],
		],
		self::SURFACE_MAIN_PAGE => [
			'label' => 'main page',
			'title' => self::PAGE_MAIN,
			'query' => [],
		],
		self::SURFACE_TALK => [
			'label' => 'talk page',
			'title' => self::PAGE_TALK,
			'query' => [],
		],
		self::SURFACE_CATEGORY => [
			'label' => 'category page',
			'title' => self::PAGE_CATEGORY,
			'query' => [],
		],
		self::SURFACE_EDIT => [
			'label' => 'edit form',
			'title' => self::PAGE_ADA,
			'query' => [ 'action' => 'edit' ],
		],
		self::SURFACE_HISTORY => [
			'label' => 'page history',
			'title' => self::PAGE_MAIN,
			'query' => [ 'action' => 'history' ],
		],
		self::SURFACE_DIFF => [
			'label' => 'diff',
			'title' => self::PAGE_MAIN,
			'query' => [ 'diff' => 'prev' ],
			'latest-oldid' => true,
		],
		self::SURFACE_SEARCH => [
			'label' => 'search results',
			'title' => 'Special:Search',
			'query' => [ 'search' => self::SEARCH_QUERY, 'fulltext' => '1' ],
		],
	];

	// -------------------------------------------------------------------------
	// Error tracking categories (check 3, structural route)
	// -------------------------------------------------------------------------

	/**
	 * Message keys naming the tracking categories a script or parser failure
	 * places a page in. Each key is resolved in the content language and matched
	 * against every fixture page's own category set, so a failure names both the
	 * page and the category.
	 *
	 * The tracking-category route is used because it is structural: it does not
	 * depend on the wording of an error message or on where in the markup it
	 * lands. A mis-set $wgScribuntoDefaultEngine surfaces here, which is the
	 * point of the check.
	 *
	 * TWO KEYS ARE DELIBERATELY ABSENT, and both would produce a false failure:
	 *
	 *   broken-file-category — populated by design. R5 keeps uploads and
	 *     InstantCommons off, so the [[File:...]] references inside the imported
	 *     fixtures render without media and their pages join this category.
	 *     harness/LocalSettings.template.php records that as the accepted trade
	 *     and states that this gate asserts red links in the TEMPLATE namespace
	 *     only.
	 *   cite-tracking-category-cite-error — a citation error is not a script
	 *     error, and on this instance the category holds only Template: pages
	 *     that carry a <ref> and are rendered standalone, which no capture shows.
	 *     Reference errors on a captured page are still caught, by the HTML route
	 *     of the same check: Cite renders them with the parser error class.
	 */
	private const ERROR_TRACKING_CATEGORY_KEYS = [
		'scribunto-common-error-category',
		'scribunto-module-with-errors-category',
		'expensive-parserfunction-category',
		'post-expand-template-inclusion-category',
		'node-count-exceeded-category',
		'expansion-depth-exceeded-category',
	];

	/**
	 * Class tokens the parser puts on an element that reports a failure, and the
	 * text a Scribunto failure renders. Matched inside the parser output region
	 * of every fetched page, which is where a parser error can appear at all;
	 * matching page-wide would flag unrelated chrome.
	 */
	private const PARSER_ERROR_CLASSES = [ 'error', 'scribunto-error' ];

	/** Literal text a Lua failure renders into the article body. */
	private const LUA_ERROR_TEXT = 'Lua error';

	// -------------------------------------------------------------------------
	// The component and treatment expectation tables (check 5)
	// -------------------------------------------------------------------------
	//
	// These two tables are DATA, kept apart from the checking logic on purpose,
	// so that a reviewer can diff them against the eleven components of AAP
	// section 3.6 and the nine derived treatments of section 3.7 without reading
	// any control flow. Every row must be found or check 5 fails, naming the rows
	// that were not.
	//
	// Each row carries:
	//   label    the component or treatment, worded as the specification words it
	//   surface  which fetched page should render it, and why that page
	//   match    the hook that proves it, as a structured selector: element,
	//            class, id, attribute/value, within (an ancestor class token),
	//            plus min (default 1) or exact
	//
	// The match is structured rather than a selector string so that the report's
	// description of it is generated from the same value that is evaluated —
	// there is no second copy to drift out of step.
	//
	// The hooks are the ones the skin's own partials and the fixtures actually
	// emit, read from includes/templates/*.mustache and
	// fixtures/main-page.wikitext rather than guessed. Note that
	// Sanitizer::safeEncodeAttribute() serialises an underscore in an attribute
	// value as &#95;, so the main page's raw HTML reads
	// class="blitzy-card&#95;&#95;title"; every assertion here is made against
	// the parsed DOM, which decodes it, and never against the HTML as text.

	/**
	 * The eleven components of the Blitzy design language, plus the personal-menu
	 * disclosure, which the requirements make a captured surface in its own right.
	 *
	 * Components 1 to 5 and 11 are chrome and are asserted on the article surface,
	 * which every reading capture shows. Components 6 and 7 have no
	 * MediaWiki-native structure and exist nowhere but the authored main page,
	 * which is why fixtures/main-page.wikitext is load-bearing rather than
	 * decorative. Components 8 to 10 are parser output and are asserted inside
	 * mw-parser-output so that a hook belonging to chrome cannot satisfy them.
	 */
	private const COMPONENT_EXPECTATIONS = [
		[
			'label' => 'Component 1 — floating navigation card',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-nav-card' ],
		],
		[
			'label' => 'Component 1 — navigation links derived from a core portlet',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'mw-portlet-navigation', 'within' => 'blitzy-nav-card' ],
		],
		[
			'label' => 'Component 2 — outline call to action',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-cta--outline' ],
		],
		[
			'label' => 'Component 3 — gradient call to action',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-cta--gradient' ],
		],
		[
			'label' => 'Component 4 — announcement bar',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'id' => 'blitzy-announcement' ],
		],
		[
			'label' => 'Component 4 — announcement dismiss control',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'id' => 'blitzy-announcement-dismiss' ],
		],
		[
			'label' => 'Personal menu disclosure',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-personal-menu' ],
		],
		[
			'label' => 'Personal menu — core portlet identity preserved',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'id' => 'p-personal' ],
		],
		[
			'label' => 'Component 5 — hero page title',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'mw-first-heading', 'within' => 'blitzy-content-header' ],
		],
		[
			'label' => 'Component 5 — sub-headline (tagline)',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-content-header__tagline' ],
		],
		[
			'label' => 'Component 6 — lavender band',
			'surface' => self::SURFACE_MAIN_PAGE,
			'match' => [ 'class' => 'blitzy-band--lavender' ],
		],
		[
			'label' => 'Component 6 — card panel inside the lavender band',
			'surface' => self::SURFACE_MAIN_PAGE,
			'match' => [ 'class' => 'blitzy-card-panel', 'within' => 'blitzy-band' ],
		],
		[
			'label' => 'Component 7 — card grid',
			'surface' => self::SURFACE_MAIN_PAGE,
			'match' => [ 'class' => 'blitzy-card-grid' ],
		],
		[
			'label' => 'Component 7 — exactly four cards in the grid',
			'surface' => self::SURFACE_MAIN_PAGE,
			'match' => [ 'class' => 'blitzy-card', 'within' => 'blitzy-card-grid', 'exact' => 4 ],
		],
		[
			'label' => 'Component 8 — content heading',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'element' => 'h2', 'within' => 'mw-parser-output' ],
		],
		[
			'label' => 'Component 9 — prose paragraph',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'element' => 'p', 'within' => 'mw-parser-output' ],
		],
		[
			'label' => 'Component 10 — inline link in prose',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'element' => 'a', 'within' => 'mw-parser-output' ],
		],
		[
			'label' => 'Component 11 — page tabs',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-tabs__list' ],
		],
		[
			'label' => 'Component 11 — namespace tab pair (article and talk)',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-tabs__group--associated-pages' ],
		],
	];

	/**
	 * The nine derived MediaWiki treatments, plus the highlighted code block.
	 *
	 * The code block is included because the Python article is its only source
	 * anywhere on the wiki, and because a mis-named wfLoadExtension() call for
	 * SyntaxHighlight_GeSHi shows up here and nowhere else in this gate.
	 *
	 * The infobox is asserted on two articles, as the requirements ask, because
	 * the biographical and software infoboxes are built by different templates
	 * and a Lua failure can take one without the other.
	 */
	private const TREATMENT_EXPECTATIONS = [
		[
			'label' => 'Treatment — infobox (biography)',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'element' => 'table', 'class' => 'infobox' ],
		],
		[
			'label' => 'Treatment — infobox (software)',
			'surface' => self::SURFACE_PYTHON,
			'match' => [ 'element' => 'table', 'class' => 'infobox' ],
		],
		[
			'label' => 'Treatment — wikitable',
			'surface' => self::SURFACE_GDP,
			'match' => [ 'element' => 'table', 'class' => 'wikitable' ],
		],
		[
			'label' => 'Treatment — reference list',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'references' ],
		],
		[
			'label' => 'Treatment — reference marker',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'reference' ],
		],
		[
			'label' => 'Treatment — reference backlink',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'mw-cite-backlink' ],
		],
		[
			'label' => 'Treatment — table of contents card',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-toc' ],
		],
		[
			'label' => 'Treatment — table of contents link',
			'surface' => self::SURFACE_ARTICLE,
			'match' => [ 'class' => 'blitzy-toc__link', 'within' => 'blitzy-toc' ],
		],
		[
			'label' => 'Treatment — hatnote / message box',
			'surface' => self::SURFACE_PHOTOSYNTHESIS,
			'match' => [ 'element' => 'div', 'class' => 'hatnote' ],
		],
		[
			'label' => 'Treatment — highlighted code block',
			'surface' => self::SURFACE_PYTHON,
			'match' => [ 'class' => 'mw-highlight' ],
		],
		[
			'label' => 'Treatment — edit form (wikitext textarea)',
			'surface' => self::SURFACE_EDIT,
			'match' => [ 'element' => 'textarea', 'id' => 'wpTextbox1' ],
		],
		[
			'label' => 'Treatment — edit form (core form element preserved)',
			'surface' => self::SURFACE_EDIT,
			'match' => [ 'element' => 'form', 'id' => 'editform' ],
		],
		[
			'label' => 'Treatment — edit form (edit token preserved)',
			'surface' => self::SURFACE_EDIT,
			'match' => [ 'element' => 'input', 'attribute' => 'name', 'value' => 'wpEditToken' ],
		],
		[
			'label' => 'Treatment — edit form (Unicode check field preserved)',
			'surface' => self::SURFACE_EDIT,
			'match' => [ 'element' => 'input', 'attribute' => 'name', 'value' => 'wpUnicodeCheck' ],
		],
		[
			'label' => 'Treatment — edit form (parent revision field preserved)',
			'surface' => self::SURFACE_EDIT,
			'match' => [ 'element' => 'input', 'attribute' => 'name', 'value' => 'parentRevId' ],
		],
		[
			'label' => 'Treatment — edit form (summary field preserved)',
			'surface' => self::SURFACE_EDIT,
			'match' => [ 'id' => 'wpSummary' ],
		],
		[
			'label' => 'Treatment — diff (added line)',
			'surface' => self::SURFACE_DIFF,
			'match' => [ 'class' => 'diff-addedline' ],
		],
		[
			'label' => 'Treatment — diff (removed line)',
			'surface' => self::SURFACE_DIFF,
			'match' => [ 'class' => 'diff-deletedline' ],
		],
		[
			'label' => 'Treatment — diff (core diff table preserved)',
			'surface' => self::SURFACE_DIFF,
			'match' => [ 'element' => 'table', 'class' => 'diff' ],
		],
		[
			'label' => 'Treatment — search results (single-column card list)',
			'surface' => self::SURFACE_SEARCH,
			'match' => [ 'class' => 'mw-search-results' ],
		],
		[
			'label' => 'Treatment — search results (at least one result)',
			'surface' => self::SURFACE_SEARCH,
			'match' => [ 'class' => 'mw-search-result' ],
		],
		[
			'label' => 'Treatment — category links footer (Blitzy chip wrapper)',
			'surface' => self::SURFACE_MAIN_PAGE,
			'match' => [ 'class' => 'blitzy-category-footer' ],
		],
		[
			'label' => 'Treatment — category links footer (core catlinks preserved)',
			'surface' => self::SURFACE_MAIN_PAGE,
			'match' => [ 'id' => 'catlinks', 'within' => 'blitzy-category-footer' ],
		],
		[
			'label' => 'Treatment — page history list',
			'surface' => self::SURFACE_HISTORY,
			'match' => [ 'id' => 'pagehistory' ],
		],
	];

	/**
	 * The remaining in-scope action surfaces, which are neither one of the eleven
	 * components nor one of the nine treatments but are captured all the same:
	 * category pages and talk pages are named in the in-scope requirement list.
	 *
	 * This third table exists for a structural reason as well as a coverage one.
	 * Every surface fetched by this script carries at least one expectation row,
	 * so a surface that cannot be fetched always produces a named failure in check
	 * 5 rather than passing quietly through the checks that merely skip a surface
	 * they could not read.
	 */
	private const ACTION_SURFACE_EXPECTATIONS = [
		[
			'label' => 'Captured surface — category page listing',
			'surface' => self::SURFACE_CATEGORY,
			'match' => [ 'id' => 'mw-pages' ],
		],
		[
			'label' => 'Captured surface — talk page discussion',
			'surface' => self::SURFACE_TALK,
			'match' => [ 'element' => 'h2', 'within' => 'mw-parser-output' ],
		],
	];

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/** @var array[] One entry per check, in run order, holding its verdict and failures. */
	private array $checkResults = [];

	/** @var int Total number of collected failures across every check. */
	private int $failureCount = 0;

	/** @var bool Whether the machine-readable summary replaces the human report. */
	private bool $jsonMode = false;

	/** @var string|null The origin every page fetch is made against, once resolved. */
	private ?string $origin = null;

	/** @var string|null Host header sent with each fetch, set for a loopback origin. */
	private ?string $hostHeader = null;

	/** @var string[] What each candidate origin answered, reported so a failure is diagnosable. */
	private array $originAttempts = [];

	/** @var array<string,string> Requested URL per surface key. */
	private array $surfaceUrls = [];

	/** @var array<string,string> Fetched HTML per surface key, only for surfaces that returned 200. */
	private array $surfaceHtml = [];

	/** @var array<string,string> Why a surface could not be fetched, per surface key. */
	private array $surfaceErrors = [];

	/** @var array<string,DOMXPath> Parsed document per surface key. */
	private array $surfaceDocuments = [];

	/** @var array<string,ParserOutput> Parser output per prefixed page title. */
	private array $parserOutputs = [];

	/**
	 * @var array<string,\MediaWiki\Page\ExistingPageRecord|null> Page record per
	 *   fixture title, with null recorded for a page that does not exist.
	 */
	private array $pageRecords = [];

	/** @var array<string,string> Why a page could not be parsed, per prefixed page title. */
	private array $parseErrors = [];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Verify that the Blitzy verification wiki holds the imported and authored fixture '
			. 'state the capture matrix depends on. Exits non-zero on any failure, which is what '
			. 'stops harness/verify.sh before the capture pass.'
		);
		$this->addOption(
			'json',
			'Emit a machine-readable summary on stdout instead of the human-readable report.',
			false,
			false
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Stated explicitly rather than inherited: the script reads the page,
	 * revision, category, user and search index tables.
	 */
	public function getDbType() {
		return self::DB_STD;
	}

	/**
	 * Run every check, report, and fail the run if anything is wrong.
	 *
	 * The checks run in the order the specification numbers them, and all of them
	 * run even once one has failed, so that a single invocation tells the operator
	 * everything that is wrong with the fixtures rather than only the first thing.
	 *
	 * @return bool False when any check failed, which maintenance/run.php turns
	 *   into exit status 1.
	 */
	public function execute() {
		$this->jsonMode = $this->hasOption( 'json' );

		$this->say( "Blitzy skin — post-import fixture verification\n" );
		$this->say( str_repeat( '=', 78 ) . "\n" );

		$this->resolveOrigin();

		$this->checkTemplateRedLinks();
		$this->checkUnexpandedTransclusions();
		$this->checkScriptErrors();
		$this->checkSearchResults();
		$this->checkRenderedInstances();
		$this->checkFixtureShape();
		$this->checkAdministratorInvisible();

		return $this->reportOutcome();
	}

	// -------------------------------------------------------------------------
	// Reporting
	// -------------------------------------------------------------------------

	/**
	 * Print the summary and return the run verdict.
	 *
	 * @return bool False when any check failed.
	 */
	private function reportOutcome(): bool {
		$failedChecks = 0;
		foreach ( $this->checkResults as $result ) {
			if ( !$result['ok'] ) {
				$failedChecks++;
			}
		}
		$passed = $this->failureCount === 0;

		if ( $this->jsonMode ) {
			$this->output( $this->buildJsonReport( $passed, $failedChecks ) . "\n" );
			return $passed;
		}

		$this->say( str_repeat( '=', 78 ) . "\n" );
		if ( $passed ) {
			$this->say( sprintf(
				"RESULT: PASS — all %d checks passed. The fixtures are ready to capture.\n",
				count( $this->checkResults )
			) );
			return true;
		}

		$this->say( sprintf(
			"RESULT: FAIL — %d of %d checks failed, %d problem%s in total.\n",
			$failedChecks,
			count( $this->checkResults ),
			$this->failureCount,
			$this->failureCount === 1 ? '' : 's'
		) );
		$this->say(
			"The capture pass must not run against this wiki: fix the fixtures or the\n"
			. "provisioning step named above, then run this script again.\n"
		);

		return false;
	}

	/**
	 * Build the machine-readable summary --json emits.
	 *
	 * Deliberately printed to stdout rather than written to a file: nothing may be
	 * added to screenshots/audits/, which holds exactly twenty-three committed
	 * artefacts (R9).
	 *
	 * @param bool $passed
	 * @param int $failedChecks
	 * @return string One JSON document, pretty-printed so a diff of a teed copy
	 *   stays readable.
	 */
	private function buildJsonReport( bool $passed, int $failedChecks ): string {
		$report = [
			'tool' => 'blitzy-post-import-verify',
			'ok' => $passed,
			'checksTotal' => count( $this->checkResults ),
			'checksFailed' => $failedChecks,
			'failureCount' => $this->failureCount,
			'origin' => $this->origin,
			'originAttempts' => $this->originAttempts,
			'searchQuery' => self::SEARCH_QUERY,
			'surfaces' => $this->surfaceUrls,
			'checks' => $this->checkResults,
		];

		$json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json === false ) {
			// Cannot happen with the scalar and string data assembled above, and
			// reporting it as a JSON document would be self-defeating anyway.
			return '{"tool":"blitzy-post-import-verify","ok":false,'
				. '"failureCount":' . $this->failureCount . ',"error":"summary-encoding-failed"}';
		}

		return $json;
	}

	/**
	 * Record the outcome of one check and print its line.
	 *
	 * @param string $name Short name of the check, as the specification words it.
	 * @param string $detail What was measured, printed on the same line as the verdict.
	 * @param string[] $failures One entry per problem, each naming the offending item.
	 */
	private function finishCheck( string $name, string $detail, array $failures ): void {
		$number = count( $this->checkResults ) + 1;
		$ok = $failures === [];
		$this->checkResults[] = [
			'number' => $number,
			'name' => $name,
			'ok' => $ok,
			'detail' => $detail,
			'failures' => $failures,
		];
		$this->failureCount += count( $failures );

		$this->say( sprintf(
			"[ check %d ] %s  %s\n            %s\n",
			$number,
			$ok ? 'PASS' : 'FAIL',
			$name,
			$detail
		) );

		$shown = 0;
		foreach ( $failures as $failure ) {
			if ( $shown === self::MAX_REPORTED_ITEMS ) {
				$this->say( sprintf(
					"            ... and %d more\n",
					count( $failures ) - $shown
				) );
				break;
			}
			$this->say( '              - ' . $failure . "\n" );
			$shown++;
		}
	}

	/**
	 * Print a line of the human-readable report.
	 *
	 * Suppressed entirely in --json mode so that stdout carries one parseable
	 * document and nothing else.
	 *
	 * @param string $text
	 */
	private function say( string $text ): void {
		if ( !$this->jsonMode ) {
			$this->output( $text );
		}
	}

	// -------------------------------------------------------------------------
	// Check 1 — zero Template-namespace red links
	// -------------------------------------------------------------------------

	/**
	 * Assert that no fixture page transcludes a template that does not exist.
	 *
	 * A red template link means the import did not bring a template the article
	 * calls, so the article renders a link where an infobox, a citation or a
	 * hatnote belongs. Every missing template is reported by full title, because
	 * a count alone cannot be acted on.
	 *
	 * The transcluded set comes from getLinkList( TEMPLATE ), and the ordinary
	 * link set is cross-checked for Template-namespace entries as well: a call
	 * that the parser recorded as a link rather than as a transclusion is still a
	 * missing template. The namespace filter is applied here rather than passed to
	 * getLinkList(), which takes only one argument on the 1.43 runtime target.
	 */
	private function checkTemplateRedLinks(): void {
		$failures = [];
		$pagesChecked = 0;
		$templateLinks = 0;

		foreach ( $this->fixturePages() as $pageTitle ) {
			$output = $this->getParserOutput( $pageTitle );
			if ( !$output ) {
				$failures[] = $this->parseErrors[$pageTitle];
				continue;
			}
			$pagesChecked++;

			$seen = [];
			foreach ( [ ParserOutputLinkTypes::TEMPLATE, ParserOutputLinkTypes::LOCAL ] as $linkType ) {
				foreach ( $output->getLinkList( $linkType ) as $entry ) {
					$link = $entry['link'];
					if ( $link->getNamespace() !== NS_TEMPLATE ) {
						continue;
					}
					$templateLinks++;
					// A page that exists carries its page id here; a missing one
					// carries zero, or nothing at all for a link type that does
					// not record ids.
					if ( !empty( $entry['pageid'] ) ) {
						continue;
					}
					$missing = Title::newFromLinkTarget( $link )->getPrefixedText();
					if ( isset( $seen[$missing] ) ) {
						continue;
					}
					$seen[$missing] = true;
					$failures[] = sprintf(
						'%s transcludes [[%s]], which does not exist',
						$pageTitle,
						$missing
					);
				}
			}
		}

		$this->finishCheck(
			'Zero Template-namespace red links',
			sprintf(
				'%d page%s parsed, %d Template-namespace link%s inspected',
				$pagesChecked,
				$pagesChecked === 1 ? '' : 's',
				$templateLinks,
				$templateLinks === 1 ? '' : 's'
			),
			$failures
		);
	}

	// -------------------------------------------------------------------------
	// Check 2 — zero unexpanded transclusion markers
	// -------------------------------------------------------------------------

	/**
	 * Assert that nothing in the rendered body is an unexpanded transclusion.
	 *
	 * Two shapes are looked for. In the parser output, a literal brace pair or a
	 * strip-state sentinel, either of which means a template call reached the
	 * output instead of being expanded — a mis-set parser function or a template
	 * the sanitizer refused, and both are invisible to a red-link check. In the
	 * fetched page, an anchor rendered red whose target is in the Template
	 * namespace, which is the same fault seen from the reader's side.
	 *
	 * The scan runs over the parser output rather than over the whole document on
	 * purpose: the inline configuration script every MediaWiki page carries ends
	 * a JSON object with a brace pair, so scanning the document as text would
	 * report a false failure on every surface at once.
	 */
	private function checkUnexpandedTransclusions(): void {
		$failures = [];
		$pagesChecked = 0;
		$markers = [
			'{{' => 'opening template braces',
			'}}' => 'closing template braces',
			'UNIQ' => 'strip-state sentinel',
			'QINU' => 'strip-state sentinel',
			"\x7f" => 'strip-marker delimiter',
		];

		foreach ( $this->fixturePages() as $pageTitle ) {
			$output = $this->getParserOutput( $pageTitle );
			if ( !$output ) {
				$failures[] = $this->parseErrors[$pageTitle];
				continue;
			}
			$pagesChecked++;
			$body = $this->bodyHtml( $output );
			if ( $body === null ) {
				$failures[] = sprintf(
					'%s carries no rendered body text, so it cannot be scanned',
					$pageTitle
				);
				continue;
			}

			foreach ( $markers as $marker => $description ) {
				$offset = strpos( $body, $marker );
				if ( $offset === false ) {
					continue;
				}
				$failures[] = sprintf(
					'%s renders %s (%s) at offset %d: %s',
					$pageTitle,
					$description,
					$this->printable( $marker ),
					$offset,
					$this->context( $body, $offset, strlen( $marker ) )
				);
			}
		}

		$redAnchors = 0;
		foreach ( array_keys( self::SURFACES ) as $surface ) {
			$xpath = $this->surfaceDocument( $surface );
			if ( !$xpath ) {
				continue;
			}
			foreach ( $this->matchingNodes( $xpath, [ 'element' => 'a', 'class' => 'new' ] ) as $node ) {
				$href = $node->getAttribute( 'href' );
				if ( !$this->targetsTemplateNamespace( $href ) ) {
					continue;
				}
				$redAnchors++;
				$failures[] = sprintf(
					'%s renders a red Template-namespace link: %s (href %s)',
					self::SURFACES[$surface]['label'],
					trim( $node->textContent ),
					$href
				);
			}
		}

		$this->finishCheck(
			'Zero unexpanded transclusion markers',
			sprintf(
				'%d page bod%s scanned for brace pairs and strip sentinels, '
					. '%d fetched surface%s scanned for red template links, %d found',
				$pagesChecked,
				$pagesChecked === 1 ? 'y' : 'ies',
				count( $this->surfaceHtml ),
				count( $this->surfaceHtml ) === 1 ? '' : 's',
				$redAnchors
			),
			$failures
		);
	}

	// -------------------------------------------------------------------------
	// Check 3 — zero script errors
	// -------------------------------------------------------------------------

	/**
	 * Assert that no fixture page reports a script or parser failure.
	 *
	 * Both routes the requirements name are covered, and they fail for different
	 * reasons, which is why neither replaces the other. The structural route
	 * matches each page's own categories against the resolved error tracking
	 * categories, and is immune to the wording of any message. The HTML route
	 * looks for the class the parser puts on a reported failure and for the text a
	 * Lua failure renders, and catches a failure inside a surface this script does
	 * not parse itself, such as the diff or the edit preview.
	 */
	private function checkScriptErrors(): void {
		$failures = [];
		$trackingCategories = $this->resolveErrorTrackingCategories();
		$pagesChecked = 0;

		foreach ( $this->fixturePages() as $pageTitle ) {
			$output = $this->getParserOutput( $pageTitle );
			if ( !$output ) {
				$failures[] = $this->parseErrors[$pageTitle];
				continue;
			}
			$pagesChecked++;

			foreach ( $output->getCategoryNames() as $category ) {
				if ( isset( $trackingCategories[$category] ) ) {
					$failures[] = sprintf(
						'%s is in the error tracking category "%s"',
						$pageTitle,
						$trackingCategories[$category]
					);
				}
			}

			$body = (string)$this->bodyHtml( $output );
			if ( strpos( $body, self::LUA_ERROR_TEXT ) !== false ) {
				$failures[] = sprintf(
					'%s renders the text "%s" in its body',
					$pageTitle,
					self::LUA_ERROR_TEXT
				);
			}
		}

		foreach ( array_keys( self::SURFACES ) as $surface ) {
			$xpath = $this->surfaceDocument( $surface );
			if ( !$xpath ) {
				continue;
			}
			$label = self::SURFACES[$surface]['label'];

			foreach ( self::PARSER_ERROR_CLASSES as $class ) {
				$match = [ 'class' => $class, 'within' => 'mw-parser-output' ];
				foreach ( $this->matchingNodes( $xpath, $match ) as $node ) {
					$failures[] = sprintf(
						'%s renders an element with the parser error class "%s": %s',
						$label,
						$class,
						$this->summarise( $node->textContent )
					);
				}
			}

			if ( strpos( $this->contentText( $xpath ), self::LUA_ERROR_TEXT ) !== false ) {
				$failures[] = sprintf(
					'%s shows the text "%s" in its content region',
					$label,
					self::LUA_ERROR_TEXT
				);
			}
		}

		$this->finishCheck(
			'Zero script errors',
			sprintf(
				'%d page%s checked against %d error tracking categor%s, '
					. '%d fetched surface%s checked for parser error markup',
				$pagesChecked,
				$pagesChecked === 1 ? '' : 's',
				count( $trackingCategories ),
				count( $trackingCategories ) === 1 ? 'y' : 'ies',
				count( $this->surfaceHtml ),
				count( $this->surfaceHtml ) === 1 ? '' : 's'
			),
			$failures
		);
	}

	// -------------------------------------------------------------------------
	// Check 4 — the fixed search query returns something
	// -------------------------------------------------------------------------

	/**
	 * Assert that the fixed capture query returns at least one result.
	 *
	 * This is the check that proves rebuildtextindex.php ran: importDump.php does
	 * not populate the search index under the database backend in use, so a wiki
	 * that skipped the rebuild imports and renders perfectly and then produces two
	 * search captures of an empty result list.
	 *
	 * The query is the fixed string the capture matrix uses and is deliberately
	 * not parameterised.
	 */
	private function checkSearchResults(): void {
		$failures = [];
		$detail = sprintf( 'query "%s"', self::SEARCH_QUERY );

		try {
			// The engine class is never named: it is global on the 1.43 runtime
			// target and namespaced on later releases, so it is used only through
			// the object the factory returns.
			$engine = $this->getServiceContainer()->getSearchEngineFactory()->create();
			$engine->setLimitOffset( 20, 0 );
			$result = $engine->searchText( self::SEARCH_QUERY );

			if ( $result instanceof StatusValue ) {
				if ( !$result->isOK() ) {
					$failures[] = sprintf(
						'the search engine rejected the query "%s": %s',
						self::SEARCH_QUERY,
						$this->statusText( $result )
					);
					$result = null;
				} else {
					$result = $result->getValue();
				}
			}

			if ( $failures === [] && !$result ) {
				$failures[] = sprintf(
					'the search engine returned no result set for "%s"; '
						. 'rebuildtextindex.php has probably not run',
					self::SEARCH_QUERY
				);
			} elseif ( $result ) {
				$rows = $result->numRows();
				$titles = [];
				foreach ( $result->extractResults() as $hit ) {
					$title = $hit->getTitle();
					if ( $title ) {
						$titles[] = $title->getPrefixedText();
					}
				}
				if ( $rows < 1 ) {
					$failures[] = sprintf(
						'the query "%s" returned no results; rebuildtextindex.php has '
							. 'probably not run, so the search captures would show an empty list',
						self::SEARCH_QUERY
					);
				}
				$detail = sprintf(
					'query "%s" returned %d result%s%s',
					self::SEARCH_QUERY,
					$rows,
					$rows === 1 ? '' : 's',
					$titles === [] ? '' : ': ' . implode( ', ', array_slice( $titles, 0, 5 ) )
				);
			}
		} catch ( Throwable $e ) {
			// A gate reports; it does not crash. The exception text is the
			// diagnosis, so it is carried into the failure rather than rethrown.
			$failures[] = sprintf(
				'the search engine threw %s while running the query "%s": %s',
				get_class( $e ),
				self::SEARCH_QUERY,
				$e->getMessage()
			);
		}

		$this->finishCheck( 'Fixed search query returns a result', $detail, $failures );
	}

	// -------------------------------------------------------------------------
	// Check 5 — every component and every treatment has a rendered instance
	// -------------------------------------------------------------------------

	/**
	 * Assert that each row of the two expectation tables is actually rendered.
	 *
	 * This is the check Gate 9 depends on. Element presence is established here,
	 * before any computed-style assertion runs, so that a fidelity assertion in
	 * tests/playwright/fidelity.spec.ts cannot pass vacuously against an element
	 * that was never rendered. A surface that could not be fetched fails every row
	 * that needed it rather than skipping them, for the same reason.
	 */
	private function checkRenderedInstances(): void {
		$failures = [];
		$rowsChecked = 0;
		$rows = array_merge(
			self::COMPONENT_EXPECTATIONS,
			self::TREATMENT_EXPECTATIONS,
			self::ACTION_SURFACE_EXPECTATIONS
		);

		// Rows are grouped by surface so that an unfetchable surface produces one
		// failure naming what it blocked, rather than the same explanation repeated
		// once per row. Every row it blocked is still counted as unasserted, which
		// is what keeps a fetch failure from reading as a pass.
		$rowsBySurface = [];
		foreach ( $rows as $row ) {
			$rowsBySurface[$row['surface']][] = $row;
		}

		foreach ( $rowsBySurface as $surface => $surfaceRows ) {
			$surfaceLabel = self::SURFACES[$surface]['label'];
			if ( !$this->surfaceDocument( $surface ) ) {
				$labels = [];
				foreach ( $surfaceRows as $blocked ) {
					$labels[] = $blocked['label'];
				}
				$failures[] = sprintf(
					'the %s surface was not fetched, so %d expectation row%s could not be '
						. 'asserted (%s): %s',
					$surfaceLabel,
					count( $labels ),
					count( $labels ) === 1 ? '' : 's',
					$this->surfaceErrors[$surface] ?? 'reason unrecorded',
					$this->firstFew( $labels )
				);
			}
		}

		foreach ( $rows as $row ) {
			$surface = $row['surface'];
			$surfaceLabel = self::SURFACES[$surface]['label'];
			$xpath = $this->surfaceDocument( $surface );
			if ( !$xpath ) {
				continue;
			}

			$rowsChecked++;
			$found = $this->countMatches( $xpath, $row['match'] );
			$description = $this->describeMatch( $row['match'] );

			if ( isset( $row['match']['exact'] ) ) {
				$expected = $row['match']['exact'];
				if ( $found !== $expected ) {
					$failures[] = sprintf(
						'%s: expected exactly %d instance%s of %s on the %s surface, found %d — %s',
						$row['label'],
						$expected,
						$expected === 1 ? '' : 's',
						$description,
						$surfaceLabel,
						$found,
						$this->surfaceUrls[$surface]
					);
				}
				continue;
			}

			$minimum = $row['match']['min'] ?? 1;
			if ( $found < $minimum ) {
				$failures[] = sprintf(
					'%s: expected at least %d instance%s of %s on the %s surface, found %d — %s',
					$row['label'],
					$minimum,
					$minimum === 1 ? '' : 's',
					$description,
					$surfaceLabel,
					$found,
					$this->surfaceUrls[$surface]
				);
			}
		}

		$this->finishCheck(
			'Every component and treatment has a rendered instance',
			sprintf(
				'%d of %d expectation rows evaluated across %d fetched surface%s '
					. '(%d component, %d treatment, %d action-surface rows)',
				$rowsChecked,
				count( $rows ),
				count( $this->surfaceHtml ),
				count( $this->surfaceHtml ) === 1 ? '' : 's',
				count( self::COMPONENT_EXPECTATIONS ),
				count( self::TREATMENT_EXPECTATIONS ),
				count( self::ACTION_SURFACE_EXPECTATIONS )
			),
			$failures
		);
	}

	// -------------------------------------------------------------------------
	// Check 6 — fixture shape
	// -------------------------------------------------------------------------

	/**
	 * Assert that the pages, the seeded revision and the ordinary account exist.
	 *
	 * The revision count is not a nicety. The history capture of a page with one
	 * revision is a single row, and a diff of it has nothing to compare, so both
	 * captures would be produced and both would be wrong. Two revisions is what
	 * saving fixtures/history-seed.wikitext before fixtures/main-page.wikitext
	 * produces, and this is where that ordering is evidenced.
	 */
	private function checkFixtureShape(): void {
		$failures = [];
		$found = 0;

		foreach ( self::IMPORTED_PAGES as $pageTitle ) {
			if ( $this->getPageRecord( $pageTitle ) ) {
				$found++;
				continue;
			}
			$failures[] = sprintf(
				'imported fixture page "%s" does not exist; importDump.php has not run '
					. 'or imported a different export',
				$pageTitle
			);
		}

		foreach ( self::AUTHORED_PAGES as $pageTitle ) {
			if ( $this->getPageRecord( $pageTitle ) ) {
				$found++;
				continue;
			}
			$failures[] = sprintf(
				'authored fixture page "%s" does not exist; the edit.php step that writes it '
					. 'has not run',
				$pageTitle
			);
		}

		$revisions = 0;
		$mainPage = $this->getPageRecord( self::PAGE_MAIN );
		if ( $mainPage ) {
			$revisions = $this->countRevisions( $mainPage->getId() );
			if ( $revisions < 2 ) {
				$failures[] = sprintf(
					'"%s" holds %d revision%s; the seed revision from '
						. 'fixtures/history-seed.wikitext is missing, so the history capture '
						. 'would show one row and the diff capture would have nothing to compare',
					self::PAGE_MAIN,
					$revisions,
					$revisions === 1 ? '' : 's'
				);
			}
		}

		$testUser = $this->testUserName();
		$identity = $this->getServiceContainer()->getUserIdentityLookup()
			->getUserIdentityByName( $testUser );
		if ( !$identity || !$identity->isRegistered() ) {
			$failures[] = sprintf(
				'the ordinary account "%s" does not exist; createAndPromote.php has not run, '
					. 'so every authored revision and all fifteen authenticated captures lack '
					. 'their account (set BLITZY_TEST_USER if the name differs)',
				$testUser
			);
		}

		$this->finishCheck(
			'Fixture shape',
			sprintf(
				'%d of %d fixture pages exist, "%s" holds %d revision%s, ordinary account "%s"',
				$found,
				count( self::IMPORTED_PAGES ) + count( self::AUTHORED_PAGES ),
				self::PAGE_MAIN,
				$revisions,
				$revisions === 1 ? '' : 's',
				$testUser
			),
			$failures
		);
	}

	// -------------------------------------------------------------------------
	// Check 7 — the installer administrator appears in no capture surface
	// -------------------------------------------------------------------------

	/**
	 * Assert that the installer administrator's name is nowhere on a fetched page.
	 *
	 * Every authored fixture revision is saved with edit.php --user set to the
	 * ordinary account precisely so that no capture displays the administrator
	 * name, and the history and diff surfaces are where a slip would show. This
	 * check is what proves the attribution actually took.
	 *
	 * Three probes, deliberately different in kind: the user links core renders
	 * for a revision author, any link whose target names the account, and the
	 * visible text of the page, which is what a capture actually shows.
	 */
	private function checkAdministratorInvisible(): void {
		$failures = [];
		$admin = $this->adminUserName();
		$normalisedAdmin = $this->normaliseUserName( $admin );
		$surfacesChecked = 0;
		$linkTargets = [];
		foreach ( array_unique( [ $admin, str_replace( ' ', '_', $admin ) ] ) as $variant ) {
			$linkTargets[] = 'User:' . $variant;
			$linkTargets[] = 'User talk:' . $variant;
			$linkTargets[] = 'User_talk:' . $variant;
			$linkTargets[] = 'Special:Contributions/' . $variant;
		}

		foreach ( array_keys( self::SURFACES ) as $surface ) {
			$xpath = $this->surfaceDocument( $surface );
			if ( !$xpath ) {
				continue;
			}
			$surfacesChecked++;
			$label = self::SURFACES[$surface]['label'];

			foreach ( $this->matchingNodes( $xpath, [ 'class' => 'mw-userlink' ] ) as $node ) {
				if ( $this->normaliseUserName( $node->textContent ) === $normalisedAdmin ) {
					$failures[] = sprintf(
						'%s attributes a revision to the installer administrator "%s"',
						$label,
						$admin
					);
				}
			}

			foreach ( $this->matchingNodes( $xpath, [ 'element' => 'a', 'attribute' => 'href' ] ) as $node ) {
				$href = rawurldecode( $node->getAttribute( 'href' ) );
				foreach ( $linkTargets as $target ) {
					if ( strpos( $href, $target ) !== false ) {
						$failures[] = sprintf(
							'%s links to the installer administrator account: %s',
							$label,
							$node->getAttribute( 'href' )
						);
						break;
					}
				}
			}

			$pattern = '/(?<![^\W_])' . preg_quote( $admin, '/' ) . '(?![^\W_])/u';
			if ( preg_match( $pattern, $this->visibleText( $xpath ) ) === 1 ) {
				$failures[] = sprintf(
					'%s shows the installer administrator name "%s" in its visible text',
					$label,
					$admin
				);
			}
		}

		if ( $surfacesChecked === 0 ) {
			// Every probe in this check reads a fetched page, so with nothing fetched
			// the check has established nothing. Reporting that as a pass would be a
			// vacuous green line of exactly the kind this gate exists to prevent.
			$failures[] = sprintf(
				'no surface was fetched, so the absence of the installer administrator '
					. '"%s" from the capture surfaces could not be verified at all',
				$admin
			);
		}

		$this->finishCheck(
			'Installer administrator invisible',
			sprintf(
				'%d fetched surface%s checked for the account "%s" '
					. '(set MW_ADMIN_USER if the name differs)',
				$surfacesChecked,
				$surfacesChecked === 1 ? '' : 's',
				$admin
			),
			array_values( array_unique( $failures ) )
		);
	}

	// -------------------------------------------------------------------------
	// Fetching pages over HTTP
	// -------------------------------------------------------------------------

	/**
	 * Decide which origin to request pages from, and say so.
	 *
	 * The configured server is tried first, because that is the origin the
	 * captures themselves use and the one whose rendered URLs the pages contain.
	 * Loopback candidates follow, each carrying a Host header naming the
	 * configured host, because inside the wiki container the published host port
	 * does not exist and Apache answers on the default port — the same reason the
	 * container's own healthcheck probes 127.0.0.1.
	 *
	 * When no candidate answers, every surface is marked unreachable with a named
	 * reason, so the run fails with a diagnosis instead of reporting that nothing
	 * was found to be wrong.
	 */
	private function resolveOrigin(): void {
		$config = $this->getConfig();
		$configuredServer = (string)$config->get( MainConfigNames::CanonicalServer );
		$host = (string)parse_url( $configuredServer, PHP_URL_HOST );
		$port = parse_url( $configuredServer, PHP_URL_PORT );
		$hostHeader = $port ? $host . ':' . $port : $host;

		$candidates = [];
		foreach ( [ $configuredServer, (string)$config->get( MainConfigNames::Server ) ] as $server ) {
			if ( preg_match( '#^https?://#', $server ) === 1 ) {
				$candidates[rtrim( $server, '/' )] = null;
			}
		}
		// Loopback fallbacks for the in-container case. Both spellings are tried
		// because a host may resolve only one of them to the listening socket.
		$candidates['http://127.0.0.1'] = $hostHeader;
		$candidates['http://localhost'] = $hostHeader;

		$factory = $this->getServiceContainer()->getHttpRequestFactory();
		if ( !$factory->canMakeRequests() ) {
			$this->originAttempts[] = 'HTTP requests are disabled in this installation';
			$this->markEverySurfaceUnreachable(
				'HTTP requests are disabled in this installation, so no page could be fetched'
			);
			$this->say( "Wiki origin: NOT RESOLVED — HTTP requests are disabled\n\n" );
			return;
		}

		// The probe addresses the main page by its canonical database key rather
		// than by its display title. MediaWiki answers a non-canonical title form
		// with a 301 to the canonical one, so probing with a space in the title
		// would report every origin as a redirect and resolve none of them.
		$probeTitle = Title::newFromText( self::PAGE_MAIN );
		$probe = 'title=' . rawurlencode(
			$probeTitle ? $probeTitle->getPrefixedDBkey() : self::PAGE_MAIN
		);
		$script = (string)$this->getConfig()->get( MainConfigNames::Script );
		foreach ( $candidates as $origin => $header ) {
			$result = $this->request( $origin . $script . '?' . $probe, $header );
			$this->originAttempts[] = sprintf(
				'%s%s -> %s',
				$origin,
				$header === null ? '' : ' (Host: ' . $header . ')',
				$result['diagnosis']
			);
			if ( $result['ok'] ) {
				$this->origin = $origin;
				$this->hostHeader = $header;
				$this->say( sprintf(
					"Wiki origin: %s%s\n\n",
					$origin,
					$header === null ? '' : ' (Host: ' . $header . ')'
				) );
				return;
			}
		}

		// The reason recorded against each surface is short on purpose: the full
		// list of what every candidate answered is printed once, immediately below,
		// and repeated verbatim in the --json summary. Repeating it per surface
		// would bury the report it belongs to.
		$this->markEverySurfaceUnreachable(
			'the wiki answered on no candidate origin; see the origin line of this report'
		);
		$this->say(
			"Wiki origin: NOT RESOLVED — the wiki could not be reached over HTTP.\n"
			. '  ' . implode( "\n  ", $this->originAttempts ) . "\n\n"
		);
	}

	/**
	 * Record the same unreachable reason against every surface.
	 *
	 * @param string $reason
	 */
	private function markEverySurfaceUnreachable( string $reason ): void {
		foreach ( array_keys( self::SURFACES ) as $surface ) {
			$this->surfaceErrors[$surface] = $reason;
		}
	}

	/**
	 * Perform one HTTP GET and describe the outcome.
	 *
	 * Redirects are deliberately not followed: MediaWiki redirects some URLs to
	 * the canonical server, and following one from a loopback origin walks into a
	 * host that may not exist there, replacing a clear 3xx with a misleading
	 * connection error.
	 *
	 * @param string $url
	 * @param string|null $hostHeader Host header to send, or null to send none.
	 * @return array{ok:bool,code:int,body:string,diagnosis:string}
	 */
	private function request( string $url, ?string $hostHeader ): array {
		$options = [
			'timeout' => 30,
			'connectTimeout' => 5,
			'followRedirects' => false,
		];
		$requestFactory = $this->getServiceContainer()->getHttpRequestFactory();
		$request = $requestFactory->create( $url, $options, __METHOD__ );
		if ( $hostHeader !== null ) {
			$request->setHeader( 'Host', $hostHeader );
		}

		$status = $request->execute();
		$code = (int)$request->getStatus();
		$body = (string)$request->getContent();

		if ( $code === 200 ) {
			return [
				'ok' => true,
				'code' => $code,
				'body' => $body,
				'diagnosis' => 'HTTP 200',
			];
		}

		if ( $code === 0 ) {
			return [
				'ok' => false,
				'code' => $code,
				'body' => '',
				'diagnosis' => 'no HTTP response (' . $this->statusText( $status ) . ')',
			];
		}

		return [
			'ok' => false,
			'code' => $code,
			'body' => $body,
			'diagnosis' => 'HTTP ' . $code,
		];
	}

	/**
	 * The absolute URL of a surface, or null when it cannot be built.
	 *
	 * @param string $surface One of the SURFACES keys.
	 * @return string|null
	 */
	private function surfaceUrl( string $surface ): ?string {
		if ( isset( $this->surfaceUrls[$surface] ) ) {
			return $this->surfaceUrls[$surface];
		}
		if ( $this->origin === null ) {
			return null;
		}

		$definition = self::SURFACES[$surface];
		$title = Title::newFromText( $definition['title'] );
		if ( !$title ) {
			$this->surfaceErrors[$surface] = sprintf(
				'"%s" is not a valid title, so the %s surface has no URL',
				$definition['title'],
				$definition['label']
			);
			return null;
		}

		$query = [ 'title' => $title->getPrefixedDBkey() ] + $definition['query'];

		if ( !empty( $definition['latest-oldid'] ) ) {
			$page = $this->getPageRecord( $definition['title'] );
			if ( !$page ) {
				$this->surfaceErrors[$surface] = sprintf(
					'"%s" does not exist, so the %s surface has no revision to address',
					$definition['title'],
					$definition['label']
				);
				return null;
			}
			// Resolved at run time rather than hardcoded: imported revisions do not
			// keep the ids recorded in the XML, and the authored revision ids are
			// assigned when they are saved.
			$query['oldid'] = (string)$page->getLatest();
		}

		$parts = [];
		foreach ( $query as $key => $value ) {
			$parts[] = rawurlencode( (string)$key ) . '=' . rawurlencode( (string)$value );
		}
		$url = $this->origin
			. (string)$this->getConfig()->get( MainConfigNames::Script )
			. '?' . implode( '&', $parts );
		$this->surfaceUrls[$surface] = $url;

		return $url;
	}

	/**
	 * Fetch a surface once and return an XPath query object over its document.
	 *
	 * @param string $surface One of the SURFACES keys.
	 * @return DOMXPath|null Null when the surface could not be fetched or parsed,
	 *   in which case the reason is recorded against the surface.
	 */
	private function surfaceDocument( string $surface ): ?DOMXPath {
		if ( isset( $this->surfaceDocuments[$surface] ) ) {
			return $this->surfaceDocuments[$surface];
		}
		if ( isset( $this->surfaceErrors[$surface] ) ) {
			return null;
		}

		$url = $this->surfaceUrl( $surface );
		if ( $url === null ) {
			$this->surfaceErrors[$surface] ??= sprintf(
				'no URL could be built for the %s surface',
				self::SURFACES[$surface]['label']
			);
			return null;
		}

		$result = $this->request( $url, $this->hostHeader );
		if ( !$result['ok'] ) {
			$this->surfaceErrors[$surface] = sprintf(
				'%s returned %s',
				$url,
				$result['diagnosis']
			);
			return null;
		}

		$this->surfaceHtml[$surface] = $result['body'];
		$xpath = $this->parseHtml( $result['body'] );
		if ( !$xpath ) {
			$this->surfaceErrors[$surface] = sprintf( '%s returned unparseable HTML', $url );
			return null;
		}
		$this->surfaceDocuments[$surface] = $xpath;

		return $xpath;
	}

	/**
	 * Parse a rendered page into a queryable document.
	 *
	 * The DOM is used rather than string matching for a specific reason:
	 * Sanitizer::safeEncodeAttribute() serialises an underscore in an attribute
	 * value as a numeric character reference, so the main page's raw HTML reads
	 * class="blitzy-card&#95;&#95;title". A parser decodes that; a substring search
	 * for blitzy-card__title does not find it.
	 *
	 * @param string $html
	 * @return DOMXPath|null
	 */
	private function parseHtml( string $html ): ?DOMXPath {
		if ( trim( $html ) === '' ) {
			return null;
		}

		$document = new DOMDocument();
		// MediaWiki emits HTML5, which libxml reports unknown-element warnings
		// for. They are collected and discarded rather than printed: the document
		// tree libxml builds is exactly what is needed here.
		$previous = libxml_use_internal_errors( true );
		$loaded = $document->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( !$loaded ) {
			return null;
		}

		return new DOMXPath( $document );
	}

	// -------------------------------------------------------------------------
	// Matching elements
	// -------------------------------------------------------------------------

	/**
	 * Translate one structured match into an XPath expression.
	 *
	 * The supported keys are the ones the expectation tables use: element, class,
	 * id, attribute with an optional value, and within, which requires the match to
	 * be a descendant of an element carrying that class. A class is matched as a
	 * whole token, the way a CSS class selector does, so blitzy-card never matches
	 * blitzy-card-grid.
	 *
	 * Values come from the constant tables above and contain no apostrophe, which
	 * is what lets them be embedded in single-quoted XPath literals directly.
	 *
	 * @param array $match
	 * @return string
	 */
	private function buildMatchXPath( array $match ): string {
		$path = '';
		if ( isset( $match['within'] ) ) {
			$path .= $this->classTokenPath( $match['within'] );
		}

		$predicates = [];
		if ( isset( $match['class'] ) ) {
			$predicates[] = sprintf(
				"contains(concat(' ', normalize-space(@class), ' '), ' %s ')",
				$match['class']
			);
		}
		if ( isset( $match['id'] ) ) {
			$predicates[] = sprintf( "@id='%s'", $match['id'] );
		}
		if ( isset( $match['attribute'] ) ) {
			$predicates[] = isset( $match['value'] )
				? sprintf( "@%s='%s'", $match['attribute'], $match['value'] )
				: sprintf( '@%s', $match['attribute'] );
		}

		$path .= '//' . ( $match['element'] ?? '*' );
		if ( $predicates !== [] ) {
			$path .= '[' . implode( '][', $predicates ) . ']';
		}

		return $path;
	}

	/**
	 * XPath selecting every element carrying a class token.
	 *
	 * @param string $class
	 * @return string
	 */
	private function classTokenPath( string $class ): string {
		return sprintf(
			"//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
			$class
		);
	}

	/**
	 * Every element a match selects, in document order.
	 *
	 * @param DOMXPath $xpath
	 * @param array $match
	 * @return DOMElement[]
	 */
	private function matchingNodes( DOMXPath $xpath, array $match ): array {
		$nodes = $xpath->query( $this->buildMatchXPath( $match ) );
		if ( !$nodes ) {
			return [];
		}

		$elements = [];
		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$elements[] = $node;
			}
		}

		return $elements;
	}

	/**
	 * How many elements a match selects.
	 *
	 * @param DOMXPath $xpath
	 * @param array $match
	 * @return int
	 */
	private function countMatches( DOMXPath $xpath, array $match ): int {
		return count( $this->matchingNodes( $xpath, $match ) );
	}

	/**
	 * Render a match as the CSS-like selector a reviewer would write.
	 *
	 * Generated from the same value that is evaluated, so the report can never
	 * describe a selector other than the one that ran.
	 *
	 * @param array $match
	 * @return string
	 */
	private function describeMatch( array $match ): string {
		$selector = $match['element'] ?? '';
		if ( isset( $match['class'] ) ) {
			$selector .= '.' . $match['class'];
		}
		if ( isset( $match['id'] ) ) {
			$selector .= '#' . $match['id'];
		}
		if ( isset( $match['attribute'] ) ) {
			$selector .= isset( $match['value'] )
				? sprintf( '[%s="%s"]', $match['attribute'], $match['value'] )
				: sprintf( '[%s]', $match['attribute'] );
		}
		if ( $selector === '' ) {
			$selector = '*';
		}
		if ( isset( $match['within'] ) ) {
			$selector = '.' . $match['within'] . ' ' . $selector;
		}

		return $selector;
	}

	// -------------------------------------------------------------------------
	// Reading page state
	// -------------------------------------------------------------------------

	/**
	 * The seven titles the fixtures resolve to, in a stable order.
	 *
	 * @return string[]
	 */
	private function fixturePages(): array {
		return array_merge( self::IMPORTED_PAGES, self::AUTHORED_PAGES );
	}

	/**
	 * The page record for a fixture title, or null when it does not exist.
	 *
	 * getPageByReference() is used rather than the more obvious getPageByText():
	 * the latter returns a page IDENTITY, which is a non-null object with id zero
	 * for a page that does not exist and is not a page RECORD at all, so a null
	 * test on it reports every missing fixture as present and then fails a type
	 * check inside the parser. getPageByReference() returns null for a missing
	 * page, which is the contract the callers here need.
	 *
	 * @param string $pageTitle
	 * @return \MediaWiki\Page\ExistingPageRecord|null
	 */
	private function getPageRecord( string $pageTitle ) {
		if ( array_key_exists( $pageTitle, $this->pageRecords ) ) {
			return $this->pageRecords[$pageTitle];
		}

		$record = null;
		$title = Title::newFromText( $pageTitle );
		// canExist() excludes the title shapes a page record cannot be built for,
		// such as a special page or an interwiki link, which would otherwise throw.
		if ( $title && $title->canExist() ) {
			$record = $this->getServiceContainer()->getPageStore()->getPageByReference( $title );
		}
		$this->pageRecords[$pageTitle] = $record;

		return $record;
	}

	/**
	 * The parser output of a fixture page, or null with the reason recorded.
	 *
	 * The output is taken the way a reader takes it, through the parser cache, so
	 * that the metadata checked here describes the same rendering the fetched pages
	 * and the captures show. Nothing is forced and no page is edited: this gate
	 * reports, it never repairs.
	 *
	 * @param string $pageTitle
	 * @return ParserOutput|null
	 */
	private function getParserOutput( string $pageTitle ): ?ParserOutput {
		if ( isset( $this->parserOutputs[$pageTitle] ) ) {
			return $this->parserOutputs[$pageTitle];
		}
		if ( isset( $this->parseErrors[$pageTitle] ) ) {
			return null;
		}

		$page = $this->getPageRecord( $pageTitle );
		if ( !$page ) {
			$this->parseErrors[$pageTitle] = sprintf(
				'"%s" does not exist, so it could not be parsed or asserted on',
				$pageTitle
			);
			return null;
		}

		$status = $this->getServiceContainer()->getParserOutputAccess()->getParserOutput(
			$page,
			ParserOptions::newFromAnon()
		);
		if ( !$status->isOK() ) {
			$this->parseErrors[$pageTitle] = sprintf(
				'"%s" could not be parsed: %s',
				$pageTitle,
				$this->statusText( $status )
			);
			return null;
		}

		$output = $status->getValue();
		$this->parserOutputs[$pageTitle] = $output;

		return $output;
	}

	/**
	 * The stored body HTML of a parsed page, or null when it holds none.
	 *
	 * getContentHolderText() is used rather than the more familiar getRawText() or
	 * getText() for a version reason that is easy to get wrong: it is the one body
	 * accessor available across the whole supported range, present since 1.42 and
	 * therefore on the 1.43 runtime target, whereas both getRawText() and getText()
	 * were removed from ParserOutput on a later release. Either of those would work
	 * on the target and end the run with a fatal error on a newer tree — verified
	 * by running this script against both.
	 *
	 * The accessor throws when the output carries no text at all, which is turned
	 * into a reported failure here rather than allowed to end the run: a gate
	 * reports what is wrong, it does not crash.
	 *
	 * @param ParserOutput $output
	 * @return string|null
	 */
	private function bodyHtml( ParserOutput $output ): ?string {
		try {
			return $output->getContentHolderText();
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * How many revisions a page holds.
	 *
	 * @param int $pageId
	 * @return int
	 */
	private function countRevisions( int $pageId ): int {
		return $this->getReplicaDB()->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'revision' )
			->where( [ 'rev_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	/**
	 * Resolve the error tracking category message keys to category database keys.
	 *
	 * A key whose message is absent or disabled is skipped, which is how the script
	 * stays correct on an installation where one of the extensions that defines it
	 * is not loaded.
	 *
	 * @return array<string,string> Category database key mapped to its display name.
	 */
	private function resolveErrorTrackingCategories(): array {
		$categories = [];
		foreach ( self::ERROR_TRACKING_CATEGORY_KEYS as $key ) {
			$message = wfMessage( $key )->inContentLanguage();
			if ( !$message->exists() || $message->isDisabled() ) {
				continue;
			}
			$name = $message->text();
			$title = Title::makeTitleSafe( NS_CATEGORY, $name );
			if ( !$title ) {
				continue;
			}
			$categories[$title->getDBkey()] = $name;
		}

		return $categories;
	}

	// -------------------------------------------------------------------------
	// Text extraction and formatting
	// -------------------------------------------------------------------------

	/**
	 * The text a reader sees on a page, with script and style content excluded.
	 *
	 * @param DOMXPath $xpath
	 * @return string
	 */
	private function visibleText( DOMXPath $xpath ): string {
		return $this->joinTextNodes(
			$xpath,
			'//body//text()[not(ancestor::script)][not(ancestor::style)]'
		);
	}

	/**
	 * The text inside the parser output region of a page.
	 *
	 * @param DOMXPath $xpath
	 * @return string
	 */
	private function contentText( DOMXPath $xpath ): string {
		return $this->joinTextNodes(
			$xpath,
			$this->classTokenPath( 'mw-parser-output' )
				. '//text()[not(ancestor::script)][not(ancestor::style)]'
		);
	}

	/**
	 * Concatenate the text nodes an expression selects.
	 *
	 * @param DOMXPath $xpath
	 * @param string $expression
	 * @return string
	 */
	private function joinTextNodes( DOMXPath $xpath, string $expression ): string {
		$nodes = $xpath->query( $expression );
		if ( !$nodes ) {
			return '';
		}

		$parts = [];
		foreach ( $nodes as $node ) {
			$parts[] = $node->nodeValue ?? '';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Describe a status object in English, the way core's own maintenance base
	 * class does, and only when it is not good — asking a good status for its text
	 * is reported by core as an internal error.
	 *
	 * @param StatusValue $status
	 * @return string
	 */
	private function statusText( StatusValue $status ): string {
		if ( $status->isGood() ) {
			return 'no error reported';
		}

		$lines = [];
		foreach ( [ 'error', 'warning' ] as $type ) {
			foreach ( $status->getMessages( $type ) as $message ) {
				$lines[] = wfMessage( $message )
					->inLanguage( 'en' )
					->useDatabase( false )
					->text();
			}
		}

		return $lines === [] ? 'no message reported' : implode( '; ', $lines );
	}

	/**
	 * Join a list for a report, naming the first few entries and counting the rest.
	 *
	 * @param string[] $items
	 * @param int $limit
	 * @return string
	 */
	private function firstFew( array $items, int $limit = 5 ): string {
		if ( count( $items ) <= $limit ) {
			return implode( '; ', $items );
		}

		return implode( '; ', array_slice( $items, 0, $limit ) )
			. sprintf( '; and %d more', count( $items ) - $limit );
	}

	/**
	 * A short, single-line, printable form of a fragment of text.
	 *
	 * @param string $text
	 * @return string
	 */
	private function summarise( string $text ): string {
		// No /u modifier deliberately. The class being collapsed is ASCII
		// whitespace, so the flag buys nothing, and preg_replace() returns null
		// rather than a string when a /u pattern meets a byte sequence that is not
		// valid UTF-8 — which is exactly the case a truncated slice of HTML can
		// produce, and would quietly empty the context it was asked to summarise.
		$flat = trim( (string)preg_replace( '/\s+/', ' ', $text ) );
		if ( $flat === '' ) {
			return '(no text)';
		}
		if ( mb_strlen( $flat ) <= self::CONTEXT_RADIUS ) {
			return $flat;
		}

		return mb_substr( $flat, 0, self::CONTEXT_RADIUS ) . '...';
	}

	/**
	 * The text surrounding a marker, so a failure can be acted on.
	 *
	 * @param string $body
	 * @param int $offset
	 * @param int $length
	 * @return string
	 */
	private function context( string $body, int $offset, int $length ): string {
		$start = max( 0, $offset - self::CONTEXT_RADIUS );
		$slice = substr( $body, $start, $length + 2 * self::CONTEXT_RADIUS );

		// printable() first, so that a control character inside the slice cannot
		// reach the terminal, and summarise() second, on already-escaped text.
		return '"' . $this->summarise( $this->printable( $slice ) ) . '"';
	}

	/**
	 * Replace control characters with an escaped form so a report stays readable
	 * and cannot be corrupted by what it quotes.
	 *
	 * @param string $text
	 * @return string
	 */
	private function printable( string $text ): string {
		return (string)preg_replace_callback(
			'/[\x00-\x1f\x7f]/',
			static function ( array $matches ): string {
				return sprintf( '\x%02x', ord( $matches[0] ) );
			},
			$text
		);
	}

	/**
	 * Whether a link target addresses the Template namespace.
	 *
	 * Both URL shapes core can produce are covered: a query-string title and a
	 * path-based one. The href is decoded first, so a percent-encoded colon is
	 * recognised too.
	 *
	 * @param string $href
	 * @return bool
	 */
	private function targetsTemplateNamespace( string $href ): bool {
		$decoded = rawurldecode( $href );

		return strpos( $decoded, 'title=Template:' ) !== false
			|| strpos( $decoded, '/Template:' ) !== false;
	}

	// -------------------------------------------------------------------------
	// Account names
	// -------------------------------------------------------------------------

	/**
	 * The installer administrator account name check 7 must find nowhere.
	 *
	 * @return string
	 */
	private function adminUserName(): string {
		return $this->environmentName( 'MW_ADMIN_USER', self::DEFAULT_ADMIN_USER );
	}

	/**
	 * The ordinary, preferences-populated account every authored revision belongs to.
	 *
	 * @return string
	 */
	private function testUserName(): string {
		return $this->environmentName( 'BLITZY_TEST_USER', self::DEFAULT_TEST_USER );
	}

	/**
	 * Read an account name from the environment, falling back to the default that
	 * harness/docker-compose.yml supplies for the same variable.
	 *
	 * @param string $variable
	 * @param string $default
	 * @return string
	 */
	private function environmentName( string $variable, string $default ): string {
		$value = getenv( $variable );
		if ( $value === false || trim( $value ) === '' ) {
			return $default;
		}

		return $this->normaliseUserName( $value );
	}

	/**
	 * Compare account names the way MediaWiki stores them: underscores and spaces
	 * are the same character, and surrounding whitespace is not part of the name.
	 *
	 * @param string $name
	 * @return string
	 */
	private function normaliseUserName( string $name ): string {
		return trim( (string)preg_replace( '/[\s_]+/', ' ', $name ) );
	}
}

// @codeCoverageIgnoreStart
$maintClass = BlitzyPostImportVerify::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
