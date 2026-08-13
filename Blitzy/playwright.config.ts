// Blitzy skin — Playwright configuration for the verification harness
// ===================================================================
//
// Every capture, audit and measurement the delivery report cites is produced by a
// project declared in this file, which makes it the shared enforcement surface for
// five of the fifteen numbered rules — R9 Deterministic Captures, R11 Fidelity Is
// Asserted Never Eyeballed, R2 Structural Hook Preservation, R4 Progressive
// Enhancement and R5 Zero External Origins — with R10 Accessibility Gate Is
// Blocking and R12 No Scope Expansion constraining what may appear here at all.
//
// There is no user rules document for this project: `review_rules` reports "No user
// rules provided." The governing constraints are the fifteen numbered rules R1-R15
// in §5 of the requirements, inventoried per rule in AAP §0.9.2 with their gate
// bindings in AAP §0.8.4. Comments below name the rule behind each non-obvious
// decision, because this file is read by reviewers checking R9 and R11.
//
// -----------------------------------------------------------------------------
// CONTRACT — sibling files address the names defined here. Do not rename.
// -----------------------------------------------------------------------------
//
//   testDir              tests/playwright
//   specs                capture, axe, fidelity, measurement-768, domContract,
//                        nojs, externalOrigins — seven, and exactly seven (R12).
//                        Every project runs precisely one of them.
//   committed captures   screenshots/<group>-<page>-<viewport>-<mode>-<state>.png,
//                        written by explicit page.screenshot() calls in the spec
//   committed audits     screenshots/audits/<same stem>.json, one per capture
//   transient output     .playwright/ only, which Blitzy/.gitignore ignores
//   authenticated state  .playwright/auth/storage-state.json, exported below as
//                        AUTH_STORAGE_STATE so the auth fixture shares one literal
//   project metadata     each project publishes its dimensions for the specs to
//                        read through testInfo.project.metadata: spec,
//                        viewportLabel, mode, state, theme, and for the capture
//                        projects captureGroups and expectedCaptures, which sum to
//                        3 + 3 + 9 + 1 + 4 + 1 = 21
//   environment          MW_SERVER and MW_SCRIPT_PATH, defined by .env.example.
//                        Nothing else is read and no wiki host is hardcoded.
//
// -----------------------------------------------------------------------------
// INVOCATION — Gate 10 requires every step to be individually invocable
// -----------------------------------------------------------------------------
//
//     npx playwright test                                   the whole pass
//     npx playwright test --project=capture-1440-light-anon  one project
//     npm run test:capture                                   one spec
//
// Selecting a project that needs the authenticated session also runs `auth-setup`,
// because Playwright resolves the dependencies of the selected projects. Captures
// that must be byte-identical are taken inside the pinned capture container, whose
// image digest and `platform: linux/amd64` live in harness/docker-compose.yml; the
// exactly-1.62.1 playwright pins in package.json are what keep the client library
// and that container's bundled browser in step (R9).
//
// -----------------------------------------------------------------------------
// DELIBERATELY ABSENT — each omission is a requirement, not an oversight
// -----------------------------------------------------------------------------
//
//   provisioning             no webServer and no globalSetup entry: the harness
//                            provisions the wiki (harness/verify.sh and
//                            harness/provision.sh), and standing the stack up from
//                            here would duplicate it and expand scope (R12).
//   snapshot assertions      the built-in screenshot assertion writes its baseline
//                            to <spec>-snapshots/…-chromium-linux.png, which
//                            contradicts the required capture naming, so the specs
//                            call page.screenshot() with an explicit path instead.
//                            No baseline-path template is configured, and none may
//                            be, because none is used.
//   pixel tolerances         no per-pixel or per-ratio difference allowance of any
//                            kind is configured anywhere in this package — grep the
//                            file and find nothing. Byte-identity is achieved by
//                            fixing the environment, never by tolerating drift (R9).
//   reducedMotion            animation freezing is `0ms` exactly and belongs to
//                            tests/playwright/support/determinism.ts. Emulating a
//                            reduced-motion preference would instead change which
//                            media-query branch the skin renders.
//   channel                  no `channel: 'chrome'`: that runs the host's Chrome
//                            rather than the browser the pinned image bundles.
//   trace, video             off, so the artifact tree stays small (R12).
//   hosted reporters         nothing is uploaded and no external service is
//                            contacted, here or by any project (R5).

import * as path from 'path';
import { defineConfig } from '@playwright/test';

// Absolute paths only. Playwright resolves `testDir` and `outputDir` against this
// file's directory, but `storageState` is a browser-context option resolved against
// the current working directory — verified by running one config from two
// directories, where the relative form failed with ENOENT. Anchoring every path to
// __dirname makes the configuration invariant to where the harness invokes it from,
// whether that is the package root on the host or /work inside the capture
// container.
const PACKAGE_ROOT = __dirname;

// Transient artifacts live under .playwright/, which Blitzy/.gitignore ignores.
// `screenshots/` must never be an output directory: Playwright empties outputDir at
// the start of every run, which would delete the twenty-one committed captures.
const TRANSIENT_DIR = path.join( PACKAGE_ROOT, '.playwright' );
const OUTPUT_DIR = path.join( TRANSIENT_DIR, 'artifacts' );
const RESULTS_FILE = path.join( TRANSIENT_DIR, 'results.json' );

// The authenticated browser session, established once by
// tests/playwright/fixtures/auth.ts and reused by every project whose surfaces are
// captured logged in — fifteen of the twenty-one captures. Exported so the fixture
// imports this path rather than repeating the literal.
//
// It is deliberately a sibling of OUTPUT_DIR and not a child: outputDir is emptied
// at the start of every run, which would discard a session that is meant to be
// persisted once and reused. It holds a live session cookie, which is why it lives
// under the ignored .playwright/ tree and is never committed.
export const AUTH_STORAGE_STATE = path.join( TRANSIENT_DIR, 'auth', 'storage-state.json' );

// The project that establishes that session. Named through a constant so a
// `dependencies` entry can never drift from the project it names.
const AUTH_SETUP_PROJECT = 'auth-setup';

// The three fixed viewports. The widths are the ones the requirements name — 1440,
// 768 and 390 — and appear verbatim as the <viewport> segment of every capture
// filename. The heights are fixed here because a varying height changes sticky
// positioning, lazy loading and the scroll height a full-page capture stitches, and
// therefore changes the captured bytes (R9). 900 is the conventional 16:10 desktop
// height, 1024 the portrait tablet height at 768 wide, and 844 the logical height
// of the 390-wide phone class the specification targets.
//
// `isMobile` and `hasTouch` are deliberately not set: they emulate a device rather
// than a width, changing meta-viewport handling and scrollbar behaviour, and the
// specification asks for viewport widths.
const VIEWPORT_1440 = { width: 1440, height: 900 };
const VIEWPORT_768 = { width: 768, height: 1024 };
const VIEWPORT_390 = { width: 390, height: 844 };

// The three normalising browser flags, and only these three. Each removes one host
// dependency from the rendered pixels: software rendering instead of whatever GPU
// the host happens to expose, one fixed colour space, and no system-specific
// subpixel smoothing. Rendering otherwise varies with host operating system,
// version, settings and hardware, which is why byte-identical captures also require
// the pinned container rather than these flags alone.
//
// Nothing here can rescue a variable font: variable fonts render differently across
// environments while static instances render near-identically, which is why the
// four woff2 files are static single-weight subsets (R9).
//
// These three are appended to the launch arguments Playwright already supplies,
// which is why no fourth flag is needed: the shared-memory workaround that
// harness/docker-compose.yml defers to the browser launch flags,
// --disable-dev-shm-usage, is already among those defaults, and `ipc: host` in that
// same file gives the capture container the host's own /dev/shm.
const NORMALISING_BROWSER_FLAGS = [
	'--disable-gpu',
	'--force-color-profile=srgb',
	'--font-render-hinting=none',
];

// Timeouts. Generous enough for a cold MediaWiki render — the first request for a
// fixture article expands templates and Scribunto modules against an empty parser
// cache — but short enough that a hung page fails instead of looking like a pass.
// A long expect timeout never converts a failure into a skip: an assertion against
// an absent element still fails, it merely takes its full budget first (R11).
const TEST_TIMEOUT_MS = 120_000;
const EXPECT_TIMEOUT_MS = 15_000;
const ACTION_TIMEOUT_MS = 15_000;
const NAVIGATION_TIMEOUT_MS = 60_000;
const RUN_TIMEOUT_MS = 3_600_000;

// Reads one environment variable, treating a blank value as absent. Docker Compose
// writes an empty string for a key it could not interpolate, and an empty MW_SERVER
// is a misconfiguration rather than a value. An empty MW_SCRIPT_PATH, by contrast,
// is the documented default and is handled by normaliseScriptPath below.
function readEnv( name: string ): string {
	const value = process.env[ name ];
	return typeof value === 'string' ? value.trim() : '';
}

// Normalises MW_SCRIPT_PATH to either the empty string or a rooted path with no
// trailing slash. Empty is the documented default, because the pinned
// mediawiki:1.43.9 image serves MediaWiki at the web root; MediaWiki core's own
// development stack uses '/w', so both shapes have to work.
function normaliseScriptPath( raw: string ): string {
	const trimmed = raw.replace( /\/+$/, '' );
	if ( trimmed === '' ) {
		return '';
	}
	return trimmed.startsWith( '/' ) ? trimmed : '/' + trimmed;
}

// Fails with an error that names the offending variable, the way harness/verify.sh
// names the missing secret before it does any other work (Gate 10). Silently
// falling back to a default host would be worse than failing: the pass would
// capture whatever wiki happened to answer, and the bytes would be wrong rather
// than absent.
function environmentError( variable: string, problem: string ): Error {
	return new Error( [
		`playwright.config.ts: ${ variable } ${ problem }.`,
		'The capture baseURL is derived from MW_SERVER + MW_SCRIPT_PATH and no wiki',
		'host is hardcoded here. Copy .env.example to .env and run harness/verify.sh,',
		'or export the variable yourself: MW_SERVER=http://localhost:8080',
	].join( '\n' ) );
}

// Resolves the wiki this pass runs against. MediaWiki emits absolute
// self-referential URLs built from $wgServer, and harness/docker-compose.yml gives
// the capture container host networking precisely so that one MW_SERVER is correct
// both inside and outside it — so the browser must be pointed at exactly that
// value, not at a rewritten equivalent.
//
// The returned base always ends in a slash. Playwright resolves a page URL through
// the URL constructor, and only a base with a trailing slash keeps a non-rooted
// relative path such as `index.php?title=Ada_Lovelace` inside a non-empty
// MW_SCRIPT_PATH. With the empty script path the harness actually uses, rooted and
// non-rooted spec paths resolve identically.
function wikiBaseUrl(): string {
	const rawServer = readEnv( 'MW_SERVER' );
	if ( rawServer === '' ) {
		throw environmentError( 'MW_SERVER', 'is not set' );
	}
	let server: URL;
	try {
		server = new URL( rawServer );
	} catch {
		throw environmentError( 'MW_SERVER', `is not a valid URL: ${ rawServer }` );
	}
	if ( server.protocol !== 'http:' && server.protocol !== 'https:' ) {
		throw environmentError( 'MW_SERVER', `must use http or https: ${ rawServer }` );
	}
	if ( server.pathname !== '/' || server.search !== '' || server.hash !== '' ) {
		throw environmentError(
			'MW_SERVER',
			'must carry only a scheme, host and port — a path belongs in MW_SCRIPT_PATH',
		);
	}
	return `${ server.origin }${ normaliseScriptPath( readEnv( 'MW_SCRIPT_PATH' ) ) }/`;
}

export default defineConfig( {
	// The specs live here and nowhere else. fixtures/ and support/ sit inside this
	// tree but are never collected as tests, because every project names the single
	// file it runs; fixtures/auth.ts is the one exception, and only for auth-setup.
	testDir: path.join( PACKAGE_ROOT, 'tests', 'playwright' ),

	// Transient artifacts only. Playwright empties this directory at the start of
	// every run, which is exactly why it is not screenshots/.
	outputDir: OUTPUT_DIR,

	timeout: TEST_TIMEOUT_MS,
	globalTimeout: RUN_TIMEOUT_MS,

	expect: {
		// An assertion budget, and nothing else. There is deliberately no screenshot
		// or snapshot comparison block here: no pixel-difference allowance exists
		// anywhere in this package (R9).
		timeout: EXPECT_TIMEOUT_MS,
	},

	// Serialised, and not negotiable. Parallel workers interleave state on a single
	// wiki instance — one signs in while another edits the page a third is
	// capturing — so capture order, and therefore capture content, would vary
	// between runs (R9).
	workers: 1,
	fullyParallel: false,

	// A retry can turn a genuine ordering failure into a pass on the second attempt,
	// which is precisely the failure R11 exists to catch: an assertion against an
	// element that was never rendered must fail, not be smoothed over.
	retries: 0,

	// A stray `test.only` would silently shrink the pass from twenty-one captures to
	// one. Unconditional rather than gated on a CI variable, because no continuous
	// integration definition exists in this repository and the harness is the only
	// runner there is.
	forbidOnly: true,

	// Guarantees no snapshot baseline can ever be written, even if a snapshot
	// assertion were introduced later: it would fail rather than quietly mint a new
	// baseline and make the next run agree with it (R9).
	updateSnapshots: 'none',

	// A capture pass is legitimately slow, and the slow-test notice is noise that a
	// reader could mistake for a warning under Gate 2's zero-warning posture.
	reportSlowTests: null,

	// Local reporters only. `list` is the human-readable stream the harness log
	// keeps; the JSON report is the machine-readable artifact DELIVERY.md draws pass
	// rates from. No hosted or uploading reporter appears here (R5). The HTML
	// reporter is omitted on purpose: it bundles a trace viewer this pass has no use
	// for, and Playwright rejects an HTML folder that overlaps outputDir.
	reporter: [
		[ 'list' ],
		[ 'json', { outputFile: RESULTS_FILE } ],
	],

	use: {
		baseURL: wikiBaseUrl(),

		// The browser is fixed explicitly rather than inherited from a default,
		// because R9 makes the browser part of the environment being pinned.
		// `channel` is left unset on purpose, so the run uses the browser bundled
		// with the pinned capture image rather than anything installed on the host.
		browserName: 'chromium',
		headless: true,
		launchOptions: {
			args: NORMALISING_BROWSER_FLAGS,
		},

		// Fixed at 1. Left to the default, the host's display scaling would leak into
		// the PNG bytes and two hosts would disagree (R9).
		deviceScaleFactor: 1,

		// Both leak into rendered content. The fixed timezone is what keeps the
		// last-modified line and every history and diff timestamp stable, and it
		// matches TZ=UTC on all three services in harness/docker-compose.yml. The
		// locale fixes Accept-Language and Intl formatting inside the browser; the
		// wiki's own content language comes from $wgLanguageCode, not from here.
		locale: 'en-US',
		timezoneId: 'UTC',

		// colorScheme is deliberately absent from this shared block and set on every
		// project instead: what a capture has to emulate depends on the theme that
		// project renders. See the capture projects below.

		// Per-action and per-navigation budgets, sized by the same reasoning as the
		// test timeout — a cold first render is slow, a hung one must still fail.
		actionTimeout: ACTION_TIMEOUT_MS,
		navigationTimeout: NAVIGATION_TIMEOUT_MS,

		// No trace, no video and no automatic failure screenshot. This keeps the
		// artifact tree small (R12) and keeps Playwright from writing PNGs of its own
		// beside the twenty-one deliberate ones. It does not affect the explicit
		// page.screenshot() calls that produce the committed captures — those are
		// made by the spec. Pass `--trace on` when debugging a single project.
		trace: 'off',
		video: 'off',
		screenshot: 'off',
	},

	// One project per spec, viewport, mode and state. The names are the public
	// handles Gate 10 requires, so `--project=<name>` reads as the thing it produces
	// and each project runs exactly one specification.
	projects: [
		{
			// Establishes the authenticated session every logged-in surface needs, by
			// running tests/playwright/fixtures/auth.ts once and persisting the result
			// to AUTH_STORAGE_STATE. Nine projects declare it as a dependency, so
			// Playwright runs it first and exactly once — and running any one of those
			// projects alone still establishes the session first (Gate 10).
			//
			// This is a setup project, not an eighth specification: the seven specs
			// are fixed (R12) and this runs a fixture. If that fixture instead
			// supplies the session by overriding the `storageState` fixture, this
			// project simply contributes no tests and the override wins; both
			// arrangements work, and neither leaves an unauthenticated capture.
			name: AUTH_SETUP_PROJECT,
			testMatch: 'fixtures/auth.ts',
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
			},
			metadata: {
				spec: 'fixtures/auth.ts',
				viewportLabel: '1440',
				produces: AUTH_STORAGE_STATE,
			},
		},
		{
			// AAP §0.8.1 rows 1, 3 and 5: the imported article, the main page fixture
			// and the fixed-query search results, anonymous and light at 1440.
			//
			// colorScheme is light because an anonymous visitor has no server-side
			// preference. The skin emits skin-theme-clientpref-os, which resolves
			// against the operating system setting, and core's client-preference
			// script rewrites that class only for unregistered users and only with
			// scripting on. Emulating light is therefore what makes an anonymous light
			// capture deterministic instead of host-dependent (R9).
			name: 'capture-1440-light-anon',
			testMatch: 'capture.spec.ts',
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
			},
			metadata: {
				spec: 'capture.spec.ts',
				viewportLabel: '1440',
				mode: 'light',
				state: 'anon',
				theme: 'os',
				captureGroups: [ 'reading' ],
				expectedCaptures: 3,
			},
		},
		{
			// AAP §0.8.1 rows 2, 4 and 6: the same three reading surfaces at 390.
			name: 'capture-390-light-anon',
			testMatch: 'capture.spec.ts',
			use: {
				viewport: VIEWPORT_390,
				colorScheme: 'light',
			},
			metadata: {
				spec: 'capture.spec.ts',
				viewportLabel: '390',
				mode: 'light',
				state: 'anon',
				theme: 'os',
				captureGroups: [ 'reading' ],
				expectedCaptures: 3,
			},
		},
		{
			// AAP §0.8.1 rows 7-9 (editing), 11-14 (special) and 20-21 (chrome): nine
			// authenticated light captures at 1440.
			//
			// Row 20 is not a redundant duplicate of row 1. Same surface, same width,
			// same mode, different authentication state — which is exactly how the
			// requirement that anonymous and logged-in chrome differ only in personal
			// menu contents gets evidenced. Paired with row 21 it also evidences the
			// closed and open states of that disclosure.
			name: 'capture-1440-auth',
			testMatch: 'capture.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'capture.spec.ts',
				viewportLabel: '1440',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
				captureGroups: [ 'editing', 'special', 'chrome' ],
				expectedCaptures: 9,
			},
		},
		{
			// AAP §0.8.1 row 10: the wikitext edit form at 390, authenticated. One
			// capture, because row 10 is the only mobile editing surface the matrix
			// names.
			name: 'capture-390-auth',
			testMatch: 'capture.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_390,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'capture.spec.ts',
				viewportLabel: '390',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
				captureGroups: [ 'editing' ],
				expectedCaptures: 1,
			},
		},
		{
			// AAP §0.8.1 rows 15-18: article, preferences, edit form and diff,
			// authenticated and dark at 1440.
			//
			// The theme comes from the blitzy-theme user preference, which
			// includes/Hooks/BlitzyHooks.php resolves into an
			// html.skin-theme-clientpref-night class, and never from a query
			// parameter. An injected client-preference cookie is not an option for
			// these five: core inlines the script that consumes that cookie only for
			// unregistered users, and every dark capture is authenticated.
			//
			// colorScheme stays light on purpose. `night` forces dark irrespective of
			// the operating system setting, so emulating light is what proves the
			// explicit root-element override AAP §6.4 requires — forced dark inside an
			// OS-light environment. Emulating dark instead would render these captures
			// dark even with the override broken, and the gate would pass vacuously.
			//
			// Because that preference is stored server-side against one account, every
			// project establishes its own theme before it captures. That is what keeps
			// `--project=capture-1440-auth` reproducible after a dark run, and what
			// keeps two consecutive full runs byte-identical (R9, Gate 10);
			// metadata.theme is the value each project has to establish.
			name: 'capture-1440-dark-auth',
			testMatch: 'capture.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'capture.spec.ts',
				viewportLabel: '1440',
				mode: 'dark',
				state: 'auth',
				theme: 'night',
				authStorageState: AUTH_STORAGE_STATE,
				captureGroups: [ 'dark' ],
				expectedCaptures: 4,
			},
		},
		{
			// AAP §0.8.1 row 19: the article at 390, authenticated and dark. The same
			// preference path and the same deliberate light colour-scheme emulation as
			// the project above.
			name: 'capture-390-dark-auth',
			testMatch: 'capture.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_390,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'capture.spec.ts',
				viewportLabel: '390',
				mode: 'dark',
				state: 'auth',
				theme: 'night',
				authStorageState: AUTH_STORAGE_STATE,
				captureGroups: [ 'dark' ],
				expectedCaptures: 1,
			},
		},
		{
			// R11 at 1440: the computed-style assertions for all eleven components and
			// the colour and radius assertions for the nine derived treatments.
			//
			// Authenticated, because the assertion surface spans pages only a
			// logged-in user reaches and because fifteen of the twenty-one captures
			// are authenticated, so this asserts the rendering the captures actually
			// show. Nothing but the personal menu branches on identity, so every value
			// asserted here is the value an anonymous visitor sees too.
			name: 'fidelity-1440',
			testMatch: 'fidelity.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'fidelity.spec.ts',
				viewportLabel: '1440',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
			},
		},
		{
			// R11 at 390. The eight viewport-independent components must assert
			// identical values here and at 1440; only components 1, 5 and 7 change,
			// and only they are asserted again in the 768px pass below.
			name: 'fidelity-390',
			testMatch: 'fidelity.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_390,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'fidelity.spec.ts',
				viewportLabel: '390',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
			},
		},
		{
			// AAP §0.8.1.1, the 768px pass. It loads three distinct URLs — the
			// article, the Main Page (which is the main page fixture) and the
			// wikitable-bearing article — while recording assertions for all four
			// named surface requirements; asserts the horizontal-overflow and
			// layout-shift budgets; and asserts every viewport-dependent component
			// at its 768px value. Those boundaries do not move together: the
			// navigation card is still at its full height because the disclosure
			// applies below 768px, the card grid is still at two columns because two
			// columns apply at and above 768px, and the hero type takes its
			// middle-band value — the only value that changes at this width.
			//
			// It commits no PNG and is not accessibility-audited, so it is excluded
			// both from the twenty-one and from the axe project. Anonymous, because
			// all three URLs are public and the pass needs no session.
			name: 'measurement-768',
			testMatch: 'measurement-768.spec.ts',
			use: {
				viewport: VIEWPORT_768,
				colorScheme: 'light',
			},
			metadata: {
				spec: 'measurement-768.spec.ts',
				viewportLabel: '768',
				mode: 'light',
				state: 'anon',
				theme: 'os',
				commitsScreenshots: false,
				accessibilityAudited: false,
			},
		},
		{
			// R10, the blocking accessibility gate: one report per capture, mirrored
			// at screenshots/audits/<same stem>.json, and any critical or serious
			// violation fails the run.
			//
			// One project, because the spec has to audit each of the twenty-one
			// surfaces in the mode and state it was captured in — three states across
			// two viewports — which no single project default can express. The
			// viewport below is therefore only the default the spec overrides per
			// surface, and the authenticated session arrives as a dependency rather
			// than as a `use` value for the same reason.
			//
			// Neither a rule exclusion nor an element exclusion belongs in that spec:
			// every exclusion is a blind spot, so findings that originate in core are
			// reported rather than suppressed. The run metadata each report carries is
			// stripped before it is committed, since accessibility reports are exempt
			// from byte-identity but must still be stable (R9).
			name: 'axe',
			testMatch: 'axe.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
			},
			metadata: {
				spec: 'axe.spec.ts',
				viewportLabel: 'per-surface',
				mode: 'per-surface',
				state: 'per-surface',
				theme: 'per-surface',
				authStorageState: AUTH_STORAGE_STATE,
				expectedReports: 21,
			},
		},
		{
			// R2: fetches a rendered article and asserts every provided-contract
			// selector is present with unchanged semantics.
			//
			// Authenticated, because the preserved surface is at its fullest for a
			// logged-in user — the personal portlet carries its real entries rather
			// than the anonymous pair — so this asserts a superset of what an
			// anonymous run could reach.
			name: 'domContract',
			testMatch: 'domContract.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'domContract.spec.ts',
				viewportLabel: '1440',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
			},
		},
		{
			// R4: loads the article, edit and diff pages with scripting disabled and
			// asserts content presence and form submissability.
			//
			// javaScriptEnabled is false at the project level, which is the only
			// correct way to express it: switching scripting off inside the spec would
			// let the page's own scripts run first, and the run would prove nothing.
			//
			// The authenticated session still applies — MediaWiki authentication is
			// cookie-based and needs no scripting, which was verified against this
			// exact combination — and it is used deliberately, so a real edit form
			// with its hidden inputs, edit token and summary field is guaranteed
			// however anonymous editing happens to be configured.
			name: 'nojs',
			testMatch: 'nojs.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
				javaScriptEnabled: false,
			},
			metadata: {
				spec: 'nojs.spec.ts',
				viewportLabel: '1440',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
				javaScriptEnabled: false,
			},
		},
		{
			// R5: fails the run if any request on any harness-loaded page resolves to
			// a foreign origin, while excluding editorial hyperlinks inside article
			// content — the imported fixtures carry many, and counting those as
			// violations would fail every page spuriously.
			//
			// Authenticated, because the OOUI and Codex asset families load on the
			// preferences and edit surfaces, which is where a content-delivery-network
			// reference would most plausibly appear.
			name: 'externalOrigins',
			testMatch: 'externalOrigins.spec.ts',
			dependencies: [ AUTH_SETUP_PROJECT ],
			use: {
				viewport: VIEWPORT_1440,
				colorScheme: 'light',
				storageState: AUTH_STORAGE_STATE,
			},
			metadata: {
				spec: 'externalOrigins.spec.ts',
				viewportLabel: '1440',
				mode: 'light',
				state: 'auth',
				theme: 'os',
				authStorageState: AUTH_STORAGE_STATE,
			},
		},
	],
} );
