// Blitzy skin — shared determinism controls for the Playwright verification pass
// ==============================================================================
//
// Four concerns live here, and they are four separately-callable exports rather
// than one `prepare( page )` helper, because the seven specifications need
// different subsets of them:
//
//   1. frozen motion    an injected stylesheet that stops animation, transition
//                       and smooth-scroll timing from reaching the pixels
//   2. volatile masking  named, surface-scoped mask targets for the two places a
//                       wall clock is rendered, plus a validator that refuses to
//                       let a drifted selector fail silently
//   3. font readiness    proof that all four self-hosted faces are really loaded
//   4. network idle      one bounded wait, shared so nothing re-implements it
//
// capture.spec.ts uses all four, in the order network idle -> font readiness ->
// frozen motion -> masking, before each of its twenty-one screenshots.
// axe.spec.ts uses three and deliberately not masking: masking would hide real
// page content from an audit that is a blocking gate. measurement-768.spec.ts,
// domContract.spec.ts, fidelity.spec.ts, nojs.spec.ts and
// externalOrigins.spec.ts each take the subset they need, and
// fixtures/auth.ts reuses `waitForNetworkIdle` rather than repeating it.
//
// No export assumes another has been called, and none assumes a call order. The
// module has no import-time side effects at all — no top-level await, no
// environment read, no auto-registered fixture or hook — so any single
// specification runs standalone through its own npm script (Gate 10).
//
// ------------------------------------------------------------------------------
// CAPABILITY CATEGORIES — read this before calling anything from nojs.spec.ts
// ------------------------------------------------------------------------------
//
// `nojs.spec.ts` runs in the project playwright.config.ts declares with
// `javaScriptEnabled: false`, and that switch is sharper than it looks: it
// disables main-world script execution, so every Playwright call implemented as
// a main-world evaluation stops working. Measured on the pinned toolchain
// (@playwright/test 1.62.1, Chromium 151 headless shell), on the host and again
// inside mcr.microsoft.com/playwright:v1.62.1-noble: `page.addStyleTag()` does
// not merely fail there, it closes the page — the next call reports "Target
// page, context or browser has been closed". `page.setContent()` behaves the
// same way. Utility-world work is unaffected: locators, `locator.count()`,
// `locator.evaluate()`, `page.waitForLoadState()` and `page.screenshot()` all
// keep working with scripting off.
//
// Every export below therefore declares one of two categories:
//
//   SCRIPTLESS-SAFE            usable in every project, including `nojs`
//     waitForNetworkIdle
//     installFrozenMotionBeforeNavigation
//     assertFrozenMotionApplied
//     assertMasksPresent
//     volatileMaskTargets, requiredMaskTargets, volatileMasks,
//     volatileMaskGroups, screenshotDeterminismOptions  (pure, no page traffic)
//
//   PAGE-SCRIPTING-REQUIRED    never call these from the `nojs` project
//     installFrozenMotion      (page.addStyleTag, main world)
//     waitForFonts             (page.evaluate then page.waitForFunction, main world)
//
// ------------------------------------------------------------------------------
// BOUNDARIES — deliberately absent, because another declared file owns it
// ------------------------------------------------------------------------------
//
//   screenshots            capture.spec.ts owns the twenty-one filenames and the
//                          page.screenshot() calls; this module only supplies the
//                          options object. Nothing here writes a file of any kind.
//   layout shift, overflow capture.spec.ts at 1440 and 390, and
//                          measurement-768.spec.ts at 768. No PerformanceObserver
//                          and no scroll-width helper appears here.
//   sign-in, storageState  fixtures/auth.ts.
//   dark-mode activation   capture.spec.ts, through the blitzy-theme preference.
//   preserved selectors    domContract.spec.ts.
//   component hooks        fidelity.spec.ts.
//   request classification externalOrigins.spec.ts.
//   pixel tolerance        nowhere, by design. Byte-identity comes from the
//                          digest- and platform-pinned capture container, the
//                          normalising browser flags and static font instances,
//                          never from tolerating a difference. No per-pixel or
//                          per-ratio difference allowance of any kind, and no
//                          snapshot assertion, exists anywhere in this package.
//   runner configuration   playwright.config.ts owns testDir, baseURL, the
//                          normalising launch flags, deviceScaleFactor, locale,
//                          timezone, viewports, workers, retries and projects.
//                          Not one of them is set or re-stated here.
//
// This file emits nothing that varies between runs: no timestamp, no clock read,
// no random value, no run identifier and no absolute host path appears in any
// value or message it produces.
//
// There is no user rules document for this project — `review_rules` reports "No
// user rules provided." The governing constraints are the fifteen numbered rules
// in §5 of the requirements, inventoried in AAP §0.9.2; the ones that bind this
// file are R1, R4, R9, R10, R11, R12 and R15, and the comments below name the
// rule behind each non-obvious decision. Core source is cited as evidence only:
// no code is copied from MediaWiki core or from the reference skin.
//
// ------------------------------------------------------------------------------
// A COMMENTING CONVENTION THIS FILE FOLLOWS DELIBERATELY
// ------------------------------------------------------------------------------
//
// Several constructs are prohibited here and are explained below precisely
// because they are prohibited — the paused animation play state, a
// reduced-motion media query, a concealing declaration, a difference allowance.
// None of them is written in its literal spelling anywhere in the commentary.
// The reviews that enforce those prohibitions are plain greps, so a literal
// spelling in a sentence saying "never do this" would raise a finding
// indistinguishable from the real thing. The constructs are therefore described
// in words, and the only literal declarations in this file are the seven real
// ones inside FROZEN_MOTION_CSS. `resources/skins.blitzy.styles/fonts.less`
// adopts the same convention for the same reason; keep it.

import type { Locator, Page } from '@playwright/test';

/*
 * Wait budgets. Every wait below takes an explicit, overridable timeout and
 * fails with a message naming the condition that was not met, following the
 * convention core's own browser tests use — `waitForModuleState()` in
 * mediawiki/tests/selenium/wdio-mediawiki/Util.js:59-85 pairs a default numeric
 * timeout with a message that names precisely what never happened. There is no
 * unbounded wait here and no bare sleep anywhere: a stalled page must fail
 * loudly rather than look like a slow pass (R11).
 *
 * The values are sized for a cold MediaWiki render, where the first request for
 * a fixture article expands templates and Scribunto modules against an empty
 * parser cache, and all of them sit inside the 120 s per-test budget
 * playwright.config.ts sets.
 */
export const NETWORK_IDLE_TIMEOUT_MS = 45_000;
export const FONT_READY_TIMEOUT_MS = 30_000;
export const MASK_PRESENCE_TIMEOUT_MS = 15_000;
export const FROZEN_MOTION_TIMEOUT_MS = 15_000;

/*
 * How often the font-readiness predicate is re-evaluated inside the page. An
 * explicit interval is used instead of Playwright's animation-frame default
 * because the predicate is asynchronous — polling sixty times a second would
 * queue redundant font-load requests for no benefit.
 */
const FONT_POLL_INTERVAL_MS = 250;

/*
 * The text `document.fonts.check()` and `document.fonts.load()` are asked
 * about. A single space (U+0020) is deliberate: every one of the four faces
 * declares the same latin `unicode-range`, which begins at U+0000, so the space
 * is inside all four ranges. That matters, because both APIs only consider
 * faces whose range covers the sample — a sample outside the range would make
 * the question vacuous and the answer meaningless.
 */
const FONT_SAMPLE_TEXT = ' ';

// ==============================================================================
// 1. FROZEN MOTION
// ==============================================================================

/*
 * The injected stylesheet, and the reasoning behind every line of it.
 *
 * == WHY `0ms` EXACTLY, AND NEVER A SMALL NON-ZERO VALUE ==
 *
 * MediaWiki core records the reason in one line, and it is the only occurrence
 * of that task number in the whole tree: a non-zero duration "results in
 * infinite motion on iOS. See T308979"
 * (mediawiki/resources/src/mediawiki.skinning/accessibility.less:24, guarding
 * the `transition-duration: 0ms !important` on the next line). Core's reduced
 * motion block is also where `animation-iteration-count: 1` and
 * `scroll-behavior: auto` come from — the latter being the only
 * scroll-stabilisation precedent anywhere in core's client source.
 *
 * == A DELIBERATE DIVERGENCE FROM CORE, STATED SO IT IS NOT READ AS A SLIP ==
 *
 * Core uses `animation-duration: 0.01ms` and `animation-delay: -0.01ms`
 * (accessibility.less:19-20) — a fractional duration paired with a negative
 * delay, which seeks a finite animation past its own end so a single frame
 * renders at the final state. AAP §0.6.2.7, §0.1.1.2 implicit requirement 21 and
 * §0.5.5 all require exactly `0ms` for animation and transition alike, so the
 * requirement overrides the core value here. With a `0ms` duration the negative
 * delay has nothing left to seek, so the delay is `0ms` too, uniform with the
 * durations rather than inherited from a rule that no longer applies. Core is
 * cited as the evidence for why zero is the right value; none of its text is
 * reproduced.
 *
 * == UNCONDITIONAL, NOT INSIDE A REDUCED-MOTION MEDIA QUERY ==
 *
 * Core's declarations are wrapped in a media query on the reduced-motion user
 * preference, which can be read verbatim at accessibility.less:15. This sheet
 * has no media query at all: capture, audit and measurement stability is needed
 * on every run, whatever preference the browser is emulating. Wrapping it in
 * that query would also change which branch of the skin's own stylesheets
 * renders, which is a different page rather than a stabler one.
 *
 * == THE PROPERTY LIST IS CLOSED, AND SHORTER THAN IT LOOKS TEMPTING TO MAKE IT ==
 *
 * Seven declarations, and no eighth may be added. Every computed value
 * fidelity.spec.ts reads — colour, background, border, radius, shadow, the font
 * properties, line height, spacing, display, position, overflow, and anything
 * flex or grid — is off limits, for two independent reasons. Touching one would
 * change the very value an assertion exists to measure (R11), and reproducing a
 * Blitzy declaration here could satisfy a "this module was really delivered"
 * assertion that the skin's own stylesheet had failed to satisfy (Gate 9).
 *
 * Pausing the animation play state is likewise excluded, and not by oversight:
 * it freezes an animation wherever it happens to be, which is the opposite of
 * deterministic. Zero duration lands every animation on its final state
 * instead.
 *
 * == NOTHING HERE MAY CONCEAL AN ACCESSIBILITY FINDING ==
 *
 * axe.spec.ts applies this sheet before every analysis and that check is
 * blocking (R10), so a declaration that hid page content would turn a real
 * violation into a pass. Nothing here touches the display, visibility or
 * transparency of any element, and above all nothing resets a focus outline —
 * removing focus visibility would suppress exactly the kind of finding the gate
 * exists to catch.
 *
 * `caret-color: transparent` is the single appearance-affecting declaration, and
 * it is justified rather than assumed. No axe rule inspects the text caret,
 * while a blinking caret inside a focused control is a genuine byte-identity
 * hazard: it is visible on one frame and not the next. It is insurance rather
 * than dead configuration — core does not autofocus the wikitext textarea, and
 * SearchFormWidget only autofocuses the search box when the term is empty, which
 * the fixed `Lovelace` query is not — but a focused control anywhere in a
 * capture would otherwise decide the bytes on a coin toss.
 *
 * == NO LINT SUPPRESSION IS NEEDED, SO NONE IS PRESENT ==
 *
 * Core has to disable four stylelint rules to write `0ms !important` in a `.less`
 * file (accessibility.less:13-14). This CSS lives in a TypeScript template
 * literal and the package's stylelint run only covers `**\/*.{less,css}`, so
 * those rules never see it. A decorative suppression comment would be a Gate 2
 * suppression to justify and enumerate for no benefit, so there is none — this
 * file contributes nothing to that list.
 *
 * == CALLING AN INJECTOR TWICE IS HARMLESS ==
 *
 * Two identical sheets compute to the same values, verified by injecting twice
 * and re-reading the computed style. No guard is used, because a guard would
 * have to identify a previous injection through the DOM and would then fail
 * closed on the very page where the sheet is missing.
 */
export const FROZEN_MOTION_CSS = `*,
::before,
::after {
	animation-duration: 0ms !important;
	animation-delay: 0ms !important;
	animation-iteration-count: 1 !important;
	transition-duration: 0ms !important;
	transition-delay: 0ms !important;
	scroll-behavior: auto !important;
	caret-color: transparent !important;
}
`;

/*
 * The computed serialisations of `caret-color: transparent` that count as proof
 * the sheet is in effect. Chromium serialises the keyword as `rgba(0, 0, 0, 0)`;
 * the keyword itself is accepted as well so a future serialisation change
 * weakens nothing.
 *
 * Six of the seven declarations cannot serve as that proof, which is worth
 * stating because it is counter-intuitive: `animation-duration`,
 * `animation-delay`, `transition-duration` and `transition-delay` already
 * compute to `0s` on an untouched page, `animation-iteration-count` already
 * computes to `1` and `scroll-behavior` already computes to `auto`, because
 * those are the CSS initial values. Measured on a rendered article, the only
 * declaration whose computed value differs from the untouched default is
 * `caret-color`, which reads as the text colour until this sheet applies. An
 * assertion on any of the other six would pass whether or not the sheet had
 * been injected — a vacuous pass, which is precisely what R11 forbids.
 */
const FROZEN_CARET_COLOURS: readonly string[] = [ 'rgba(0, 0, 0, 0)', 'transparent' ];

/*
 * Marks the style element the response-rewriting injector adds, so a DOM dump
 * taken while debugging a scriptless run shows where the rules came from. It is
 * deliberately not exported and must not be used as a verification hook:
 * `page.addStyleTag()` cannot carry an attribute, so the marker exists on only
 * one of the two injection paths. `assertFrozenMotionApplied()` is the hook that
 * works for both, because it asserts the effect instead of the mechanism.
 */
const FROZEN_MOTION_MARKER = 'data-blitzy-frozen-motion';

/**
 * Injects the frozen-motion stylesheet into the current page.
 *
 * PAGE-SCRIPTING-REQUIRED. `page.addStyleTag()` is a main-world evaluation, and
 * on the pinned toolchain calling it with `javaScriptEnabled: false` closes the
 * page outright. `nojs.spec.ts` must call
 * `installFrozenMotionBeforeNavigation()` instead — the two are separate exports
 * precisely so that choice is made explicitly at the import site rather than by
 * a fallback this module hides.
 *
 * Call it after the document has been navigated to, and once per navigation: a
 * page load replaces the document and takes the injected element with it.
 *
 * The rules are supplied inline. A stylesheet URL is never used, because that
 * would itself be a subresource request from an origin the harness does not
 * serve, which is the exact condition externalOrigins.spec.ts fails the run on.
 *
 * == WHY THIS CARRIES ITS OWN TIMEOUT ==
 *
 * Measured on the pinned toolchain: called with scripting disabled, the
 * injection never settles at all. The page is closed underneath the call and it
 * goes on waiting for a document that no longer exists — for five minutes, until
 * the surrounding test timeout killed it. The configured action timeout does not
 * bound it. An unbounded wait would turn one mistaken import into an anonymous
 * "test timeout exceeded" with nothing naming the cause, so the call is
 * race-bounded here and the misuse is reported by name instead.
 *
 * Serves R9, and R10 through the stability axe.spec.ts analyses under.
 *
 * @param {Page} page The page to inject into, already navigated.
 * @param {number} timeoutMs How long to allow the injection to settle.
 * @return {Promise} Resolves once the stylesheet is attached to the document.
 */
export async function installFrozenMotion(
	page: Page,
	timeoutMs: number = FROZEN_MOTION_TIMEOUT_MS,
): Promise<void> {
	let timer: ReturnType<typeof setTimeout> | undefined;

	// A bound on a call that has no effective timeout of its own. It is not a
	// pause: it only wins when the injection never settles.
	const expiry = new Promise<never>( ( _resolve, reject ) => {
		timer = setTimeout(
			() => reject( new Error( `injection did not settle within ${ timeoutMs } ms` ) ),
			timeoutMs,
		);
	} );

	try {
		await Promise.race( [
			page.addStyleTag( { content: FROZEN_MOTION_CSS } ),
			expiry,
		] );
	} catch ( cause ) {
		throw new Error( [
			'determinism.installFrozenMotion: the frozen-motion stylesheet could not be',
			`injected through page script within ${ timeoutMs } ms.`,
			'',
			'The usual cause is calling this export from the nojs project, where page',
			'script is disabled and this injection path closes the page instead of',
			'failing. Call installFrozenMotionBeforeNavigation() before navigating',
			'there instead, then assert the effect with assertFrozenMotionApplied().',
		].join( '\n' ), { cause } );
	} finally {
		clearTimeout( timer );
	}
}

/**
 * Installs a route handler that appends the frozen-motion stylesheet to the
 * top-level document as it arrives, so the rules are present before the page
 * has run — or has been prevented from running — a single line of script.
 *
 * SCRIPTLESS-SAFE, and the only frozen-motion path available to `nojs.spec.ts`.
 * This is the contingency AAP §0.6.2.7 names for the case where injection
 * through page script is unavailable, and measurement on the pinned toolchain
 * showed that case to be real rather than hypothetical.
 *
 * MUST BE CALLED BEFORE THE NAVIGATION IT IS MEANT TO AFFECT. It rewrites a
 * response, so a document already loaded is past the point where it can help;
 * after installing it late, reload the page. `assertFrozenMotionApplied()`
 * turns that ordering mistake into a failure instead of a silent omission.
 *
 * Only the main frame's own GET document response is rewritten. Every other
 * request — every stylesheet, script, image, font and sub-frame document — is
 * handed straight on with `route.fallback()`, untouched and unrecorded, so
 * externalOrigins.spec.ts still sees the page's real subresource traffic and
 * the performance baseline behind Gate 8 check 3 still measures the real
 * payload. `fallback()` rather than `continue()` is deliberate: it lets any
 * other handler a specification has registered see the request too.
 *
 * A form submission — any document request that is not a GET — is passed
 * through untouched as well, and that restriction is a safety property rather
 * than a simplification. Rewriting means fetching the request here and serving
 * the result, and re-issuing a POST would submit the edit form a second time.
 * Passing it through leaves exactly one submission, at the cost of the page it
 * lands on not carrying the stylesheet; reload that page if it needs to be
 * stable, and `assertFrozenMotionApplied()` will say so if it is not.
 *
 * A document response that is not HTML is passed through as fetched. There is
 * nothing to freeze in a document that has no markup to attach a style element
 * to, and inventing one would corrupt the response.
 *
 * ONE TRADE-OFF, MEASURED AND ACCEPTED. The fetch follows redirects, so a
 * navigation that ends somewhere else — `Special:Random` is the clear case —
 * serves the final page's markup, correctly patched, while `page.url()` keeps
 * reporting the URL that was requested. The alternative was measured and is
 * worse: handing the redirect response to the browser and letting it follow
 * natively leaves `page.url()` right but the destination unpatched, because the
 * request the browser then makes is not offered to this handler. Every URL the
 * verification pass navigates to is a direct one, so the trade-off costs
 * nothing there; a caller that needs the post-redirect URL should navigate to
 * it directly.
 *
 * @param {Page} page The page whose navigations should carry the stylesheet.
 * @return {Promise} Resolves once the handler is installed.
 */
export async function installFrozenMotionBeforeNavigation( page: Page ): Promise<void> {
	await page.route( '**/*', async ( route ) => {
		const request = route.request();
		const isOwnDocumentLoad = request.resourceType() === 'document' &&
			request.frame() === page.mainFrame() &&
			request.method() === 'GET';

		if ( !isOwnDocumentLoad ) {
			await route.fallback();
			return;
		}

		const response = await route.fetch();
		const contentType = response.headers()[ 'content-type' ] ?? '';

		if ( !contentType.includes( 'text/html' ) ) {
			await route.fulfill( { response } );
			return;
		}

		const body = withFrozenMotionStyle( await response.text(), request.url() );
		await route.fulfill( { response, body } );
	} );
}

/**
 * Returns the given HTML document with the frozen-motion style element added.
 *
 * The element goes immediately before the closing head tag when there is one,
 * which places it after every stylesheet MediaWiki links there and so gives its
 * important declarations the last word in any tie. The closing body tag is the
 * second choice and appending is the third; both still apply to the whole
 * document, because a style element is document-scoped wherever it sits.
 *
 * An empty response body is a genuine anomaly rather than a document with no
 * head, so it throws and names the URL instead of returning markup invented
 * from nothing.
 *
 * @param {string} html The document as fetched.
 * @param {string} url The document URL, used only in the failure message.
 * @return {string} The document with the stylesheet added.
 */
function withFrozenMotionStyle( html: string, url: string ): string {
	if ( html.trim() === '' ) {
		throw new Error( [
			'determinism.installFrozenMotionBeforeNavigation: the document response for',
			`${ url } was empty, so the frozen-motion stylesheet could not be added.`,
			'A page that served no markup cannot be captured or audited either, so this',
			'is reported rather than worked around.',
		].join( '\n' ) );
	}

	const style = `<style ${ FROZEN_MOTION_MARKER }="1">${ FROZEN_MOTION_CSS }</style>`;
	const headEnd = html.search( /<\/head\s*>/i );

	if ( headEnd !== -1 ) {
		return html.slice( 0, headEnd ) + style + html.slice( headEnd );
	}

	const bodyEnd = html.search( /<\/body\s*>/i );

	if ( bodyEnd !== -1 ) {
		return html.slice( 0, bodyEnd ) + style + html.slice( bodyEnd );
	}

	return html + style;
}

/**
 * Asserts that the frozen-motion stylesheet is in effect on the current page,
 * throwing if it is not.
 *
 * SCRIPTLESS-SAFE. It reads a computed style through a locator, which runs in
 * Playwright's utility world and keeps working with `javaScriptEnabled: false`.
 *
 * It exists because both injectors can fail quietly. A route handler installed
 * after the navigation it was meant to affect changes nothing at all, and an
 * injection made against the wrong page simply lands elsewhere; in both cases
 * the capture still succeeds and is merely no longer reproducible. This turns
 * that into a failure at the point of use.
 *
 * `caret-color` is the property read, because it is the only one of the seven
 * whose computed value differs from an untouched page's default — see
 * FROZEN_CARET_COLOURS for the measurements. Reading `animation-duration`
 * instead would pass on a page where nothing had been injected.
 *
 * Serves R9 and R11.
 *
 * @param {Page} page The page to check.
 * @param {number} timeoutMs How long to wait for the document body.
 * @return {Promise} Resolves when the stylesheet is confirmed to apply.
 */
export async function assertFrozenMotionApplied(
	page: Page,
	timeoutMs: number = FROZEN_MOTION_TIMEOUT_MS,
): Promise<void> {
	let caretColour: string;

	try {
		caretColour = await page.locator( 'body' ).evaluate(
			( element ) => window.getComputedStyle( element ).caretColor,
			undefined,
			{ timeout: timeoutMs },
		);
	} catch ( cause ) {
		throw new Error( [
			'determinism.assertFrozenMotionApplied: could not read the computed caret',
			`colour of the document body on ${ page.url() } within ${ timeoutMs } ms.`,
			describeCause( cause ),
		].join( '\n' ), { cause } );
	}

	if ( !FROZEN_CARET_COLOURS.includes( caretColour ) ) {
		throw new Error( [
			'determinism.assertFrozenMotionApplied: the frozen-motion stylesheet is not',
			`in effect on ${ page.url() }. The document body computes caret-color as`,
			`${ caretColour }, and the stylesheet sets it to transparent.`,
			'With scripting enabled, call installFrozenMotion() after navigating.',
			'With scripting disabled, call installFrozenMotionBeforeNavigation()',
			'before navigating — installing that handler after the navigation it was',
			'meant to affect leaves the document exactly as it was served.',
		].join( '\n' ) );
	}
}

// ==============================================================================
// 2. VOLATILE-CONTENT MASKING
// ==============================================================================

/**
 * The surfaces volatile content is grouped by. A caller names the groups that
 * apply to the page it is capturing, so a mask is never configured for a surface
 * it cannot appear on.
 */
export type VolatileMaskGroup =
	| 'recentChanges'
	| 'preferences'
	| 'notifications'
	| 'lastModified';

/**
 * Whether a target is expected on every render of its surface, or only under
 * conditions the caller controls.
 *
 * `always`      present whenever its surface is loaded, so a zero match is a
 *               drifted selector and `assertMasksPresent()` must fail.
 * `conditional` legitimately absent sometimes, and the caller decides when to
 *               assert it. Two things make a target conditional here: content
 *               that only exists after an action, such as a save confirmation,
 *               and content core's own client script removes — the structured
 *               change-filters interface detaches the legacy recent-changes
 *               links as soon as it initialises.
 */
export type MaskPresence = 'always' | 'conditional';

/**
 * One volatile element: what to mask, which surface it belongs to, whether it is
 * always there, and why it is masked at all. The reason is carried in the data
 * rather than in a comment so a failure message can explain itself.
 */
export interface VolatileMaskTarget {
	readonly selector: string;
	readonly group: VolatileMaskGroup;
	readonly presence: MaskPresence;
	readonly reason: string;
}

/*
 * The complete inventory, and the evidence for each entry.
 *
 * == WHY MASKING NEEDS A VALIDATOR AT ALL ==
 *
 * Playwright accepts a mask locator that matches nothing without complaint: it
 * simply paints no rectangle. A selector that was mistyped, or that core has
 * since renamed, therefore disables its own mask in perfect silence, and the
 * failure surfaces much later as two captures differing for a reason that looks
 * unrelated to masking. `assertMasksPresent()` closes that hole by refusing to
 * pass on a target that resolved to nothing (R11).
 *
 * == WHY THE TARGETS ARE GROUPED BY SURFACE ==
 *
 * A blanket "every mask must resolve" rule would be wrong, because these
 * elements exist on different pages. Grouping lets a caller mask and assert
 * exactly what its own surface renders.
 *
 * == WHAT IS MASKED, AND WHY EACH ONE IS A REAL HAZARD ==
 *
 * `.rclistfrom`, `.rcnotefrom` — the "show new changes starting from" line on
 * Special:RecentChanges. SpecialRecentChanges.php:744 takes the current
 * timestamp, :745-747 formats it for display and :756-764 wraps it in a span of
 * that class, so the rendered text carries the wall clock and changes every
 * minute; `.rcnotefrom` at :666 is the same value when a `from` parameter is
 * present. Both are conditional rather than always, and the reason is specific:
 * mediawiki.rcfilters/ui/FormWrapperWidget.js:164-166 detaches both from the DOM
 * when the structured filters interface initialises, then detaches the emptied
 * legacy fieldset. Measured on the running instance, the scripted render has
 * none of them while the scriptless render shows the clock — which is why they
 * are masked and why their absence with scripting on is not a fault.
 *
 * `#wpLocalTime` — a live clock on Special:Preferences.
 * DefaultPreferencesFactory.php:1068 takes the current timestamp and :1070-1071
 * renders it into a span with that id. Without this mask, two preferences
 * captures taken either side of a minute boundary cannot be byte-identical.
 *
 * `#mw-input-wpnowserver` — the server-time row on the same page, built at
 * :1072-1074 and registered at :1076-1082 as an info field in the
 * `rendering/timeoffset` section. The id is derived rather than written in
 * core: HTMLFormField.php:602 prefixes the field name with `wp` and :608 forms
 * the id as `mw-input-` followed by that name, and :615 allows an override the
 * field does not use. Because Special:Preferences renders through the OOUI form
 * class, the derivation was confirmed against the running instance instead of
 * being trusted — the id resolves to the OOUI label element that holds the time,
 * and the hidden `wpServerTime` input beside it needs no mask because it renders
 * no pixels.
 *
 * `.mw-notification-area`, `.mw-notification`, `.postedit` — the toast layer.
 * notification.js builds each notification with the `mw-notification` class and
 * an auto-hide modifier (:81-86) inside the area its own documentation names at
 * :5, and postEdit.js:75 gives the post-save confirmation the `postedit` class.
 * They auto-hide on a timer, so one caught mid-fade decides the captured bytes;
 * provisioning seeds edits and the edit, diff and history captures follow saves,
 * which is exactly when one can still be on screen.
 *
 * `#footer-info-lastmod` — the last-modified line, whose id
 * SkinComponentFooter.php:204 composes from the `lastmod` key supplied at :168.
 * This one renders the page's own revision timestamp, which does not move
 * between two consecutive runs against one provisioned instance, so it is
 * insurance rather than a requirement. It is scoped to that single element and
 * never to the surrounding footer, because the footer and the category chips
 * both carry fidelity assertions.
 *
 * == WHAT IS DELIBERATELY NOT MASKED ==
 *
 * `.printfooter` looks like the obvious target, since it carries a
 * revision-bearing "retrieved from" URL — and it is the wrong one.
 * mediawiki/resources/src/mediawiki.skinning/interface.less:21-25 removes it
 * from the screen rendering entirely for T167956, which was confirmed by reading
 * its computed display value on the running instance, so it contributes no
 * pixels to any screen capture. Masking it would be configuration that never
 * does anything.
 *
 * `#pt-userpage` and `.mw-userlink` are stable for the one fixture account, and
 * masking them would destroy evidence rather than protect it: the anonymous and
 * authenticated captures of the same article at the same width exist precisely
 * to show that the chrome differs only in the personal menu.
 *
 * `.mw-changeslist-date` and `.mw-changeslist-time` — and
 * `.mw-enhanced-rc-time`, which is what the enhanced changes list renders
 * instead — each carry their own change's timestamp, not the current time.
 * Measured on the running instance, every value was a past edit time and none
 * tracked the clock. Blanket-masking the changes list would erase most of the
 * content the recent-changes capture exists to show.
 *
 * `.rclinks` and `.rcshowhide` sit on the same lines as `.rclistfrom`
 * (SpecialRecentChanges.php:748 and :750-754) and are stable option links, so
 * only their clock-bearing sibling is masked.
 *
 * == MASKING CANNOT MAKE A FIDELITY ASSERTION PASS VACUOUSLY ==
 *
 * A mask is painted into the encoded image; the DOM is untouched, so computed
 * styles read exactly the same with masks configured as without. Every target is
 * still kept as narrow as possible so that no surface under assertion is
 * covered.
 */
export const VOLATILE_MASK_TARGETS: readonly VolatileMaskTarget[] = [
	{
		selector: '.rclistfrom',
		group: 'recentChanges',
		presence: 'conditional',
		reason: 'wall-clock "show new changes starting from" link, detached by the ' +
			'structured change-filters interface when scripting is enabled',
	},
	{
		selector: '.rcnotefrom',
		group: 'recentChanges',
		presence: 'conditional',
		reason: 'wall-clock note shown when a from parameter is set, detached by the ' +
			'structured change-filters interface when scripting is enabled',
	},
	{
		selector: '#wpLocalTime',
		group: 'preferences',
		presence: 'always',
		reason: 'live local-time clock rendered into Special:Preferences',
	},
	{
		selector: '#mw-input-wpnowserver',
		group: 'preferences',
		presence: 'always',
		reason: 'live server-time row rendered into Special:Preferences',
	},
	{
		selector: '.mw-notification-area',
		group: 'notifications',
		presence: 'conditional',
		reason: 'container for auto-hiding notifications, present only once one has ' +
			'been raised',
	},
	{
		selector: '.mw-notification',
		group: 'notifications',
		presence: 'conditional',
		reason: 'an auto-hiding notification, which may be mid-fade when a capture is ' +
			'taken',
	},
	{
		selector: '.postedit',
		group: 'notifications',
		presence: 'conditional',
		reason: 'the post-save confirmation notification, present only just after a ' +
			'save',
	},
	{
		selector: '#footer-info-lastmod',
		group: 'lastModified',
		presence: 'conditional',
		reason: 'the page revision timestamp, stable between consecutive runs and ' +
			'masked as insurance on content pages only',
	},
];

/**
 * Returns every target belonging to the named groups, in inventory order.
 *
 * SCRIPTLESS-SAFE, and pure — it touches no page.
 *
 * Use it to build the mask list, which should be the whole set for the surface:
 * a conditional target that happens to be absent costs nothing, because a mask
 * that matches nothing paints nothing.
 *
 * @param {Array} groups The surfaces in play.
 * @return {Array} The matching targets.
 */
export function volatileMaskTargets(
	groups: readonly VolatileMaskGroup[],
): readonly VolatileMaskTarget[] {
	return VOLATILE_MASK_TARGETS.filter( ( target ) => groups.includes( target.group ) );
}

/**
 * Returns only the targets in the named groups that must be present whenever
 * their surface is rendered.
 *
 * SCRIPTLESS-SAFE, and pure — it touches no page.
 *
 * This is the set to hand to `assertMasksPresent()`. A caller that knows a
 * conditional target must be there for its own run — `nojs.spec.ts` knows the
 * recent-changes clock is rendered, because nothing detached it — can assert it
 * too by passing that target with its presence overridden.
 *
 * @param {Array} groups The surfaces in play.
 * @return {Array} The always-present targets among them.
 */
export function requiredMaskTargets(
	groups: readonly VolatileMaskGroup[],
): readonly VolatileMaskTarget[] {
	return volatileMaskTargets( groups ).filter( ( target ) => target.presence === 'always' );
}

/**
 * Builds the mask locators for the named groups.
 *
 * SCRIPTLESS-SAFE. Creating a locator performs no page traffic; resolution
 * happens when the screenshot is taken.
 *
 * @param {Page} page The page the locators belong to.
 * @param {Array} groups The surfaces in play.
 * @return {Array} Locators to pass as the screenshot mask.
 */
export function volatileMasks( page: Page, groups: readonly VolatileMaskGroup[] ): Locator[] {
	return volatileMaskTargets( groups ).map( ( target ) => page.locator( target.selector ) );
}

/**
 * Builds every group's mask locators at once, keyed by group.
 *
 * SCRIPTLESS-SAFE and pure. Provided for a caller that composes several
 * surfaces in one pass and wants them addressable by name; the keys are listed
 * explicitly so adding a group to the union without extending this function is
 * a type error rather than a missing mask.
 *
 * @param {Page} page The page the locators belong to.
 * @return {Object} Locators for every group.
 */
export function volatileMaskGroups( page: Page ): Record<VolatileMaskGroup, Locator[]> {
	return {
		recentChanges: volatileMasks( page, [ 'recentChanges' ] ),
		preferences: volatileMasks( page, [ 'preferences' ] ),
		notifications: volatileMasks( page, [ 'notifications' ] ),
		lastModified: volatileMasks( page, [ 'lastModified' ] ),
	};
}

/**
 * Asserts that every given mask target resolves to at least one element on the
 * current page, throwing and naming the exact selector that did not.
 *
 * SCRIPTLESS-SAFE. It waits through a locator, which runs in Playwright's
 * utility world and keeps working with `javaScriptEnabled: false`.
 *
 * The contract has no hidden exemption: every target passed in must resolve.
 * Nothing is skipped and nothing is reported as a warning, because a mask that
 * silently matches nothing is the failure mode this exists to prevent (R11).
 * Choosing which targets to assert is the caller's job — `requiredMaskTargets()`
 * gives the set that is safe to assert unconditionally. An empty list therefore
 * throws as well: a caller that computed no targets is asserting nothing, and
 * that is a mistake in the caller rather than a pass.
 *
 * Attachment, not visibility, is what is required. Special:Preferences renders
 * its sections as sibling panels of which one is shown at a time, so the
 * time-offset row is in the document but off-panel on the default view; an
 * off-panel element contributes no pixels and so needs no mask, while a
 * visibility requirement would fail on a page that is perfectly reproducible.
 * Masking an attached element that has no box is harmless — Playwright paints
 * nothing for it.
 *
 * @param {Page} page The page to check.
 * @param {Array} targets The targets that must be present.
 * @param {string} label What is being captured, quoted back in any failure.
 * @param {number} timeoutMs How long to wait for each target.
 * @return {Promise} Resolves when every target is present.
 */
export async function assertMasksPresent(
	page: Page,
	targets: readonly VolatileMaskTarget[],
	label: string,
	timeoutMs: number = MASK_PRESENCE_TIMEOUT_MS,
): Promise<void> {
	if ( targets.length === 0 ) {
		throw new Error( [
			`determinism.assertMasksPresent: called for "${ label }" with an empty`,
			'target list, which would assert nothing at all. Pass the targets this',
			'surface must have — requiredMaskTargets() returns them for a set of',
			'groups — or do not call this for a surface that has none.',
		].join( '\n' ) );
	}

	for ( const target of targets ) {
		try {
			await page.locator( target.selector ).first().waitFor( {
				state: 'attached',
				timeout: timeoutMs,
			} );
		} catch ( cause ) {
			throw new Error( [
				`determinism.assertMasksPresent: the mask target ${ target.selector }`,
				`matched no element on ${ page.url() } while preparing "${ label }",`,
				`within ${ timeoutMs } ms.`,
				`That element carries ${ target.reason }.`,
				'A mask locator that matches nothing paints nothing and reports no',
				'error, so this would otherwise have surfaced only as two captures',
				'differing for no visible reason. Either the selector has drifted from',
				'what core renders, or this target does not belong to this surface.',
			].join( '\n' ), { cause } );
		}
	}
}

// ==============================================================================
// 3. FONT READINESS
// ==============================================================================

/**
 * One of the four self-hosted faces, described in the two forms the checks need:
 * the `font-weight` descriptor as authored, so a registered face can be matched
 * against it, and a `font` shorthand the font-loading API can be asked about.
 */
export interface BlitzyFontFace {
	readonly family: string;
	readonly weightDescriptor: string;
	readonly checkWeight: number;
	readonly shorthand: string;
	readonly file: string;
}

/*
 * The four faces, taken from resources/skins.blitzy.styles/fonts.less, which
 * declares exactly four and no more.
 *
 * The family strings are a character-for-character contract with that file and
 * with the three `@font-family-*` assignments in
 * resources/mediawiki.less/blitzy/mediawiki.skin.variables.less. Both quoted
 * names contain a space, so the shorthands quote them: an unquoted multi-word
 * family does not parse as a font shorthand and the question would be rejected
 * rather than answered.
 *
 * The semibold face is declared over the range 600-700 because one static
 * instance answers both Blitzy's own request for 600 and core's bold weight of
 * 700. It is checked at 600, the weight the skin actually asks for, and the
 * range is carried here as well so a registered face can be matched against
 * what was authored rather than against a single number.
 *
 * == WHY THIS WAIT IS LOAD-BEARING RATHER THAN DEFENSIVE (R9) ==
 *
 * All four files are static single-weight instances, and that is a determinism
 * requirement: a variable font is rasterised from an interpolated instance and
 * renders measurably differently between environments, which would defeat
 * byte-identical captures outright. Static instances only help if they have
 * actually arrived, though. Every face is declared `font-display: swap`, so text
 * renders in the fallback family and is replaced when the real face loads — a
 * capture taken during that window is not reproducible. Because these same
 * family strings reach unmodified core and extension stylesheets through the
 * skin variables, a capture caught mid-swap shifts the edit form, the diff, the
 * page history, Special:Preferences and the OOUI and Codex widgets too, not just
 * the surfaces the skin templates.
 */
export const BLITZY_FONT_FACES: readonly BlitzyFontFace[] = [
	{
		family: 'Inter',
		weightDescriptor: '400',
		checkWeight: 400,
		shorthand: '400 16px "Inter"',
		file: 'Inter-Regular.latin.woff2',
	},
	{
		family: 'Inter',
		weightDescriptor: '600 700',
		checkWeight: 600,
		shorthand: '600 16px "Inter"',
		file: 'Inter-SemiBold.latin.woff2',
	},
	{
		family: 'Space Grotesk',
		weightDescriptor: '700',
		checkWeight: 700,
		shorthand: '700 16px "Space Grotesk"',
		file: 'SpaceGrotesk-Bold.latin.woff2',
	},
	{
		family: 'Fira Code',
		weightDescriptor: '400',
		checkWeight: 400,
		shorthand: '400 16px "Fira Code"',
		file: 'FiraCode-Regular.latin.woff2',
	},
];

/* One registered face as read back from the page, for diagnosis only. */
interface RegisteredFontFace {
	family: string;
	weight: string;
	status: string;
}

/* One shorthand check as answered by the page, for diagnosis only. */
interface FontFaceCheck {
	shorthand: string;
	satisfied: boolean;
}

/* The state of the page's font-face set at the moment a wait gave up. */
interface FontFaceSetSnapshot {
	setStatus: string;
	registered: RegisteredFontFace[];
	checks: FontFaceCheck[];
}

/**
 * Waits until all four self-hosted faces are loaded and in use on the current
 * page, throwing a message that names the offending face if they are not.
 *
 * PAGE-SCRIPTING-REQUIRED. It evaluates in the page's main world, so it must not
 * be called from the `nojs` project — with scripting disabled that evaluation is
 * unavailable and the call would close the page rather than fail.
 *
 * == WHY `document.fonts.ready` IS NOT THE ANSWER ==
 *
 * Three measurements on the running instance, each of which rules out a simpler
 * implementation:
 *
 *   `document.fonts.ready` resolves, and `document.fonts.status` already reads
 *   "loaded", while all four faces are still `unloaded`. The set reports that
 *   nothing is in flight, not that everything has arrived — a face is fetched
 *   lazily, when a glyph it covers is first needed.
 *
 *   `document.fonts.check()` returned true for a family that was never declared
 *   at all. With no matching face the question is vacuously satisfied, so a
 *   misspelled family name reads as success. It also returned true for a
 *   declared-but-unloaded face on one surface. `check()` is therefore kept as a
 *   secondary signal and is never the only one.
 *
 *   `document.fonts.load()` resolves with the faces it matched — one per
 *   declared face, none for an undeclared family — and requesting a face is what
 *   makes the browser fetch it. After loading all four, the four expected woff2
 *   requests were observed and every face read `loaded`. A face whose file is
 *   missing makes that promise reject and leaves the face in `error`.
 *
 * == WHY THIS IS TWO PHASES, AND WHY THE SECOND ONE IS SYNCHRONOUS ==
 *
 * A fourth measurement, taken against this very helper, forced the shape below.
 * An earlier draft asked all four questions from a single asynchronous polling
 * predicate, and that predicate resolved the wait on its first poll whatever the
 * font state was: a deliberately misspelled family and a deliberately missing
 * file both reported success. The polling primitive takes the predicate's return
 * value as the verdict, and a promise is always truthy, so an asynchronous
 * predicate always votes yes. The result is a silent, always-passing wait, which
 * is worse than no wait at all because it looks like coverage.
 *
 * The questions are therefore split by whether they can be answered without
 * awaiting anything:
 *
 *   Phase 1 requests each of the four faces and waits for the set to go idle,
 *   inside one evaluation that genuinely does await promises. Requests are
 *   settled rather than awaited plainly, so a missing file surfaces as a named
 *   failure here instead of as an unhandled rejection inside the page. The phase
 *   is race-bounded inside the page against the caller's budget so a fetch that
 *   never settles cannot hold the evaluation open, and losing that race is
 *   reported immediately rather than falling through to phase 2 — which is why
 *   the two phases can never both consume the whole budget.
 *
 *   Phase 2 polls a strictly synchronous predicate: the set must be idle, every
 *   expected face must be registered and loaded, and the shorthand check must
 *   agree. All three are readable without awaiting, so the predicate returns a
 *   real boolean and this wait can genuinely fail.
 *
 * @param {Page} page The page to wait on, already navigated.
 * @param {number} timeoutMs How long to wait for all four faces.
 * @return {Promise} Resolves when every face is loaded and in use.
 */
export async function waitForFonts(
	page: Page,
	timeoutMs: number = FONT_READY_TIMEOUT_MS,
): Promise<void> {
	const probe = { faces: BLITZY_FONT_FACES, sample: FONT_SAMPLE_TEXT };

	// Phase 1. Requesting a face is what makes the browser fetch it, and this is
	// the only place that can await, so it is where the requests are made.
	const arrived = await page.evaluate( async ( expected ) => {
		const set = document.fonts;
		const arrival = Promise.allSettled(
			expected.faces.map( ( face ) => set.load( face.shorthand, expected.sample ) ),
		).then( () => set.ready ).then( () => true );

		// A bound on a condition that may never settle, not a fixed pause: it
		// only wins when a face never arrives at all, and it exists so a stalled
		// fetch cannot hold this evaluation open past the caller's budget.
		const bound = new Promise<boolean>( ( resolve ) => {
			setTimeout( () => resolve( false ), expected.budgetMs );
		} );

		return Promise.race( [ arrival, bound ] );
	}, { ...probe, budgetMs: timeoutMs } );

	if ( !arrived ) {
		throw new Error( [
			'determinism.waitForFonts: requesting the four self-hosted Blitzy faces',
			`did not settle on ${ page.url() } within ${ timeoutMs } ms, so at least`,
			'one face is still in flight. A capture taken while a face is swapping in',
			'is not reproducible, so this is reported rather than waited out.',
			...await describeFontFailure( page ),
		].join( '\n' ) );
	}

	// Phase 2. Strictly synchronous, which is what makes this wait able to fail.
	try {
		await page.waitForFunction( ( expected ) => {
			const set = document.fonts;

			if ( set.status !== 'loaded' ) {
				return false;
			}

			return expected.faces.every( ( face ) => {
				const matches = Array.from( set ).filter( ( candidate ) => {
					if ( candidate.family.replace( /^['"]|['"]$/g, '' ) !== face.family ) {
						return false;
					}

					// The descriptor is one weight or a two-weight range, and a
					// keyword is accepted in either position, so both are mapped
					// to their numeric equivalents before comparing.
					const bounds = candidate.weight.trim().split( /\s+/ ).map( ( part ) => {
						if ( part === 'normal' ) {
							return 400;
						}
						if ( part === 'bold' ) {
							return 700;
						}
						return Number( part );
					} );
					const lower = bounds[ 0 ];
					const upper = bounds.length > 1 ? bounds[ 1 ] : bounds[ 0 ];

					return Number.isFinite( lower ) && Number.isFinite( upper ) &&
						face.checkWeight >= lower && face.checkWeight <= upper;
				} );

				return matches.length > 0 &&
					matches.every( ( candidate ) => candidate.status === 'loaded' ) &&
					set.check( face.shorthand, expected.sample );
			} );
		}, probe, { timeout: timeoutMs, polling: FONT_POLL_INTERVAL_MS } );
	} catch ( cause ) {
		throw new Error( [
			'determinism.waitForFonts: the four self-hosted Blitzy faces were not all',
			`loaded and in use on ${ page.url() } within ${ timeoutMs } ms. A capture`,
			'taken while a face is still swapping in is not reproducible, so this is',
			'reported rather than waited out.',
			...await describeFontFailure( page ),
		].join( '\n' ), { cause } );
	}
}

/**
 * Reads the page's font-face set, for use in a failure message.
 *
 * @param {Page} page The page to read.
 * @return {Promise} Resolves with the set's state.
 */
async function readFontFaceSet( page: Page ): Promise<FontFaceSetSnapshot> {
	return page.evaluate( ( expected ) => ( {
		setStatus: document.fonts.status,
		registered: Array.from( document.fonts ).map( ( face ) => ( {
			family: face.family,
			weight: face.weight,
			status: face.status,
		} ) ),
		checks: expected.faces.map( ( face ) => ( {
			shorthand: face.shorthand,
			satisfied: document.fonts.check( face.shorthand, expected.sample ),
		} ) ),
	} ), { faces: BLITZY_FONT_FACES, sample: FONT_SAMPLE_TEXT } );
}

/**
 * Explains, face by face, why the font wait did not succeed.
 *
 * The read is deliberately synchronous inside the page so it cannot hang while
 * an error is already being constructed. If the read itself fails, that is
 * reported as part of the diagnosis: this returns text for an error the caller
 * throws unconditionally, so no failure is absorbed here.
 *
 * @param {Page} page The page to describe.
 * @return {Promise} Resolves with the lines to append to the failure message.
 */
async function describeFontFailure( page: Page ): Promise<string[]> {
	try {
		return describeFontFaceSet( await readFontFaceSet( page ) );
	} catch ( readFailure ) {
		return [
			'The font-face set could not be read to explain this failure.',
			describeCause( readFailure ),
		];
	}
}

/**
 * Turns a font-face-set snapshot into one line per expected face.
 *
 * @param {Object} snapshot The state read from the page.
 * @return {Array} Human-readable lines naming each face and its verdict.
 */
function describeFontFaceSet( snapshot: FontFaceSetSnapshot ): string[] {
	const lines = [
		`document.fonts.status is "${ snapshot.setStatus }" with ` +
			`${ snapshot.registered.length } face(s) registered.`,
	];

	for ( const expected of BLITZY_FONT_FACES ) {
		const declared = snapshot.registered.filter(
			( face ) => face.family.replace( /^['"]|['"]$/g, '' ) === expected.family,
		);
		const check = snapshot.checks.find( ( entry ) => entry.shorthand === expected.shorthand );
		const verdict = check && check.satisfied ? 'in use' : 'not in use';

		if ( declared.length === 0 ) {
			lines.push(
				`- ${ expected.shorthand } (${ expected.file }): no face for that family ` +
					'is declared on this page at all, so the stylesheet that declares it ' +
					'was not delivered.',
			);
			continue;
		}

		const states = declared
			.map( ( face ) => `weight ${ face.weight } is ${ face.status }` )
			.join( ', ' );

		lines.push(
			`- ${ expected.shorthand } (${ expected.file }): declared as weight ` +
				`${ expected.weightDescriptor }; ${ states }; ${ verdict }.`,
		);
	}

	return lines;
}

// ==============================================================================
// 4. NETWORK IDLE
// ==============================================================================

/**
 * Waits until the current page has stopped making network requests.
 *
 * SCRIPTLESS-SAFE. A load state is a lifecycle signal rather than page script,
 * verified working with `javaScriptEnabled: false`, so `nojs.spec.ts` may call
 * it. It is also the one helper `fixtures/auth.ts` reuses, which is why the
 * signature carries nothing beyond a page and a budget.
 *
 * Playwright discourages this load state for general-purpose waiting, and that
 * advice is right for an ordinary test, where waiting on the element you are
 * about to act on is both faster and more precise. It is the wrong advice here:
 * a capture is a statement about the whole viewport rather than about one
 * element, and AAP §0.6.2.7 makes settled network traffic part of what makes a
 * capture reproducible. A late-arriving image, deferred module or lazily fetched
 * font changes the pixels of a page that no element assertion was watching.
 *
 * The wait is bounded and names the page it gave up on, because a hung page must
 * fail rather than resemble a slow pass (R11).
 *
 * @param {Page} page The page to wait on.
 * @param {number} timeoutMs How long to wait for the network to settle.
 * @return {Promise} Resolves once no request has been in flight for the
 *   interval Playwright uses to define this state.
 */
export async function waitForNetworkIdle(
	page: Page,
	timeoutMs: number = NETWORK_IDLE_TIMEOUT_MS,
): Promise<void> {
	try {
		await page.waitForLoadState( 'networkidle', { timeout: timeoutMs } );
	} catch ( cause ) {
		throw new Error( [
			'determinism.waitForNetworkIdle: network traffic on',
			`${ page.url() } did not settle within ${ timeoutMs } ms.`,
			'A page still fetching is a page whose pixels are still changing, so this',
			'is reported rather than captured as-is.',
			describeCause( cause ),
		].join( '\n' ), { cause } );
	}
}

// ==============================================================================
// 5. SCREENSHOT OPTIONS
// ==============================================================================

/**
 * The colour every mask is painted in. Pinned rather than left to Playwright's
 * default so that a change to that default cannot alter twenty-one committed
 * captures (R9). The value is the default magenta, which is chosen for being
 * unlike anything in the Blitzy palette: a mask can never be mistaken for a
 * rendered surface.
 *
 * This is a screenshot option rather than a stylesheet declaration, which is why
 * a literal is correct here — the prohibition on colour literals is scoped to
 * the stylesheets, where every colour must resolve to a design token.
 */
export const MASK_COLOR = '#FF00FF';

/**
 * Playwright's own screenshot determinism options, pre-filled. Not marked
 * readonly, because the object is built fresh on every call and is meant to be
 * spread into a screenshot call alongside the caller's own path.
 */
export interface ScreenshotDeterminismOptions {
	animations: 'disabled';
	caret: 'hide';
	mask: Locator[];
	maskColor: string;
}

/**
 * Builds the screenshot options that make a capture reproducible, for the caller
 * to spread into its own `page.screenshot()` call.
 *
 * SCRIPTLESS-SAFE and pure: it takes no screenshot, touches no page and writes
 * nothing.
 *
 * `groups` is required rather than defaulted, so every call states which surface
 * it is capturing instead of quietly masking everything.
 *
 * These options complement the injected stylesheet and do not replace it.
 * `animations: 'disabled'` and `caret: 'hide'` apply only while a screenshot is
 * being taken, whereas axe.spec.ts and measurement-768.spec.ts need a stable
 * page while they analyse and measure — and measurement-768.spec.ts takes no
 * screenshot at all.
 *
 * What is deliberately absent: `path`, because capture.spec.ts owns the
 * twenty-one filenames; `fullPage`, because that is a per-capture decision;
 * `deviceScaleFactor`, which playwright.config.ts fixes; and any difference
 * tolerance, of which this package has none anywhere. Byte-identity is achieved
 * by fixing the environment, and `tools/byte-identity.js` compares raw bytes
 * (R9).
 *
 * @param {Page} page The page being captured.
 * @param {Array} groups The volatile-content surfaces this page can render.
 * @return {Object} Options to spread into a screenshot call.
 */
export function screenshotDeterminismOptions(
	page: Page,
	groups: readonly VolatileMaskGroup[],
): ScreenshotDeterminismOptions {
	return {
		animations: 'disabled',
		caret: 'hide',
		mask: volatileMasks( page, groups ),
		maskColor: MASK_COLOR,
	};
}

// ==============================================================================
// SHARED FAILURE REPORTING
// ==============================================================================

/**
 * Renders the reason behind a wrapped failure as one line.
 *
 * Only the first line is quoted, because a Playwright timeout message carries a
 * long call log that would bury the condition being reported. Nothing is lost:
 * every error thrown from this module attaches the original as its cause, so the
 * full detail is still on the error object.
 *
 * @param {*} cause Whatever was caught.
 * @return {string} A single line naming the underlying failure.
 */
function describeCause( cause: unknown ): string {
	const message = cause instanceof Error ? cause.message : String( cause );
	return `Underlying failure: ${ message.split( '\n' )[ 0 ] }`;
}
