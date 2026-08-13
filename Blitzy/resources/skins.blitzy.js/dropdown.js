/**
 * Blitzy disclosures: the accessibility and dismissal enhancements that markup alone
 * cannot carry, for the mobile navigation and for the personal menu.
 *
 * ENHANCEMENT ONLY (R4). Both disclosures are complete before this file runs. Each is
 * core's documented checkbox hack — a visually hidden checkbox, then its label, then its
 * target, as siblings — and opening and closing is done entirely by a checked-state
 * sibling rule in resources/skins.blitzy.styles/components/NavCard.less. That rule needs
 * no script, so with scripting switched off both controls still open, still close, and
 * every link inside stays reachable. This module is NOT what opens a menu: it adds Enter
 * activation, dismissal on an outside click and on focus loss, and it keeps the expanded
 * state current. tests/playwright/nojs.spec.ts asserts the unscripted behaviour, so any
 * behaviour that only worked with this file present would be a defect, not a feature.
 *
 * ONE COLLABORATOR, ONE ENTRY POINT (R10). Every listener that touches accessibility
 * state comes from core's checkboxHack, reached by ResourceLoader module name and never
 * by filesystem path (R1), and only through its aggregate `bind()`. That is deliberate on
 * two counts. `bind()` is the single warning-free entry point: of core's nine exports, the
 * two that revise the expanded state log a 1.38 deprecation whenever a button is passed
 * to them, and the space-and-enter binder logs one unconditionally, whereas `bind()`
 * passes the checkbox alone. It is also what puts `aria-expanded` on the CHECKBOX rather
 * than on the label, which is core's contract since MediaWiki 1.38. Calling a constituent
 * directly, or hand-rolling any of it, would log into a console the harness asserts is
 * clean, duplicate about 250 lines against a 12KB budget, and put the attribute in the
 * wrong place.
 *
 * ONE CLASS, ONE OWNER (R2, R7, R11). The only DOM effect this module has of its own is
 * `blitzy-dropdown--expanded` on the container. Nothing else is written: no portlet id or
 * class is touched, no `mw-` prefixed attribute is rewritten, no list item moves between
 * menus, no node is added or removed, no inline style and no custom property is ever set.
 * The stylesheet alone decides what the class looks like, because fidelity.spec.ts
 * compares computed styles against tokens.
 *
 * DETERMINISTIC BY CONSTRUCTION (R9). Capture 21 photographs the personal menu open, so
 * this module's effect is inside a byte-compared PNG. The class is a pure function of
 * `checkbox.checked`, applied synchronously by `init()` and again on each `input` event:
 * no clock, no random source, no timer, no animation-frame callback, no transition
 * orchestration, and nothing derived from geometry.
 *
 * THE EVENT IS `input`, NEVER `change`. Core's `setCheckedState()` assigns
 * `checkbox.checked` and dispatches `input` only; its source records that `change` is
 * deliberately not fired. Clicking the label runs through that path, so a `change`
 * listener would miss every label-driven toggle — invisible to anyone who tested by
 * clicking the checkbox itself, and fatal to capture 21.
 *
 * DOM CONTRACT. A container holds a checkbox, a button and a target; all three must
 * resolve within it or the container is skipped. The selector lists below carry both the
 * generic `blitzy-dropdown` spellings this package documents and the semantic spellings
 * NavCard.mustache and PersonalMenu.mustache actually render, which is what keeps the
 * behaviour bound to the real templates while staying valid against either fixture. The
 * generic `mw-checkbox-hack-*` classes are deliberately absent: the table-of-contents
 * card uses them too, and this module's scope is these two disclosures and nothing else
 * (R12). Also deliberately absent, all present in the Vector reference: portlet-link
 * handlers, icon injection, moving items between menus by available width, sticky
 * headers, menu-tab styling, pinnable elements, search toggles and any appearance UI.
 *
 * No import-time side effect and no top-level `require()` — the module declares, `init()`
 * works — which is what lets karma.conf.js instrument this file from disk and reach every
 * branch from a fixture (R14).
 */

'use strict';

// The ResourceLoader module that publishes core's checkbox hack. Held as a constant
// rather than written inline at the call site because this is a ResourceLoader module
// name, not a file path and not an npm package: a literal here asks the linter's Node
// resolver to find it on disk, which it cannot, and a suppression comment is not
// available under the zero-warning build gate. skin.json must list this module in the
// dependencies of skins.blitzy.js so that it is ready before this one executes.
const CORE_MODULE = 'mediawiki.page.ready';

// Cross-file DOM contract, shared with includes/templates/NavCard.mustache and
// includes/templates/PersonalMenu.mustache, which emit these names, and with
// resources/skins.blitzy.styles/components/NavCard.less, which draws them. Every part is
// resolved INSIDE a matched container, so a comma list cannot stray into the desktop
// navigation or any other surface. EXPANDED_CLASS is the single class this module owns;
// blitzy-toc__link--active belongs to tableOfContents.js.
const CONTAINER_SELECTOR = '.blitzy-dropdown, .blitzy-nav-card__inner, .blitzy-personal-menu';
const CHECKBOX_SELECTOR = '.blitzy-dropdown__checkbox, .blitzy-nav-card__toggle-checkbox, .blitzy-personal-menu__checkbox';
const BUTTON_SELECTOR = '.blitzy-dropdown__label, .blitzy-nav-toggle, .blitzy-nav-card__toggle, .blitzy-personal-menu__toggle, .blitzy-personal-menu__button';
const TARGET_SELECTOR = '.blitzy-dropdown__content, .blitzy-nav-card__nav, .blitzy-personal-menu__content, .blitzy-personal-menu__list';
const EXPANDED_CLASS = 'blitzy-dropdown--expanded';

// Cleanup handed back when there was nothing to enhance, so no caller needs a null check.
function noop() {}

/**
 * Resolve core's checkbox hack, or report that it is unavailable.
 *
 * The call sits in a function body rather than at module scope for two reasons. It keeps
 * the module free of import-time side effects, and `CORE_MODULE` is a ResourceLoader
 * specifier that a from-disk loader cannot resolve — a top-level require would therefore
 * throw while karma.conf.js was still loading the file, taking the coverage floor with
 * it. The guard covers both failure modes: a loader that throws, and a loader that
 * resolves to nothing, where reading `.checkboxHack` throws a TypeError instead.
 *
 * @return {Object|null} Core's checkbox hack API, or null when it cannot be reached
 */
function resolveCheckboxHack() {
	try {
		return require( CORE_MODULE ).checkboxHack;
	} catch ( e ) {
		return null;
	}
}

/**
 * Find the one part of a disclosure that this container owns.
 *
 * Ownership matters because the disclosures nest: PersonalMenu.mustache renders inside the
 * navigation card, so both containers are matched and the outer one can see the inner
 * one's parts. Document order alone would resolve today's markup correctly, but a
 * container whose own parts were absent would silently adopt the nested container's and
 * bind them twice. Testing which container each candidate is nearest to removes that
 * possibility entirely.
 *
 * @param {Element} container A matched disclosure container
 * @param {string} selector One of the part selectors declared above
 * @return {Element|null} The nearest-owned match, or null when this container has none
 */
function findOwnPart( container, selector ) {
	const owned = Array.prototype.find.call(
		container.querySelectorAll( selector ),
		( candidate ) => candidate.closest( CONTAINER_SELECTOR ) === container
	);

	return owned || null;
}

/**
 * Enhance one disclosure, and hand back the way to undo it.
 *
 * @param {Window|null} view Window whose listeners core's dismissal binders use
 * @param {Object|null} checkboxHack Core's checkbox hack API, or null when unavailable
 * @param {Element} container A matched disclosure container
 * @return {Function|null} Undo for everything bound here, or null when this container is
 *  not a complete disclosure and was skipped
 */
function enhance( view, checkboxHack, container ) {
	const checkbox = findOwnPart( container, CHECKBOX_SELECTOR );
	const button = findOwnPart( container, BUTTON_SELECTOR );
	const target = findOwnPart( container, TARGET_SELECTOR );

	// A partially authored disclosure is skipped rather than thrown over. Half a checkbox
	// hack cannot be enhanced coherently, and this is also the shape a fixture takes when
	// it exercises exactly this path.
	if ( !( checkbox && button && target ) ) {
		return null;
	}

	/**
	 * Mirror the checkbox's own state onto the container.
	 *
	 * One deterministic call rather than an add and a remove, so there is a single place
	 * where the class and the checkbox could ever disagree, and it is idempotent: running
	 * it twice from one state leaves the container identical.
	 *
	 * @return {void}
	 */
	function sync() {
		// Toggles: blitzy-dropdown--expanded
		container.classList.toggle( EXPANDED_CLASS, checkbox.checked );
	}

	// Once from the server-rendered state, so a disclosure that arrives open is
	// immediately consistent, then on every subsequent change. `input` is the only event
	// core's toggling dispatches.
	sync();
	checkbox.addEventListener( 'input', sync );

	// Core owns aria-expanded, Enter activation, and dismissal on outside click, on focus
	// loss and on following a link inside the target. With the API unavailable only those
	// enhancements are skipped: the state class still tracks the checkbox and the CSS-only
	// disclosure still opens and closes, which is the degradation R4 permits.
	const unbind = view && checkboxHack && typeof checkboxHack.bind === 'function' ?
		checkboxHack.bind( view, checkbox, button, target ) :
		null;

	return function cleanup() {
		checkbox.removeEventListener( 'input', sync );
		if ( typeof unbind === 'function' ) {
			unbind();
		}
	};
}

/**
 * Enhance every Blitzy disclosure within a root.
 *
 * Both parameters exist so that the whole module is exercisable against a fixture without
 * a wiki (R14): the root scopes the search, and the collaborator lets a test observe the
 * exact arguments handed to core's binder, or withhold it entirely and assert the
 * degraded path. Safe to call more than once — a second call adds a second equivalent
 * listener whose effect is identical, because the class is derived from the checkbox
 * rather than flipped.
 *
 * @param {Document|DocumentFragment|Element} [rootElement] Search root, `document` by
 *  default
 * @param {Object} [checkboxHack] Checkbox hack API to use instead of core's
 * @return {Function} Undo for everything this call bound. Always a function, so callers
 *  need no guard; a no-op when there was nothing to enhance, and harmless to call twice.
 */
function init( rootElement, checkboxHack ) {
	const root = rootElement || ( typeof document === 'undefined' ? null : document );
	if ( !root || typeof root.querySelectorAll !== 'function' ) {
		return noop;
	}

	// A search root is normally an ancestor, but a caller that already holds one disclosure
	// may hand it over directly, and `querySelectorAll` never returns the element it was
	// called on. Including a matching root covers that without any risk of a duplicate.
	// Legitimately empty on a page rendered by another skin and in any fixture that holds
	// only content: a hot path, not an error path.
	const descendants = root.querySelectorAll( CONTAINER_SELECTOR );
	const containers = typeof root.matches === 'function' && root.matches( CONTAINER_SELECTOR ) ?
		[ root ].concat( Array.prototype.slice.call( descendants ) ) :
		descendants;
	if ( containers.length === 0 ) {
		return noop;
	}

	// Taken from the root rather than closed over, so a test drives its own document and
	// core's window-level dismissal listeners land where that test can dispatch to them.
	const view = ( root.ownerDocument || root ).defaultView ||
		( typeof window === 'undefined' ? null : window );
	const api = checkboxHack || resolveCheckboxHack();
	const undos = [];

	Array.prototype.forEach.call( containers, ( container ) => {
		const undo = enhance( view, api, container );
		if ( undo ) {
			undos.push( undo );
		}
	} );

	if ( undos.length === 0 ) {
		return noop;
	}

	// Aggregate teardown. Draining the list makes a second call a no-op, and it matters
	// beyond tidiness: core's dismissal binders attach listeners to the window, which
	// would otherwise outlive a fixture and interfere with the next test.
	return function cleanup() {
		undos.splice( 0 ).forEach( ( undo ) => {
			undo();
		} );
	};
}

module.exports = { init };
