/**
 * Blitzy table of contents: marks the section the reader is currently in.
 *
 * ENHANCEMENT ONLY (R4). The card is complete before this file runs — the template
 * emits every entry as a real anchor, and TableOfContents.less turns that one DOM
 * instance into a sticky card at 1024px and up and an inline checkbox-hack disclosure
 * below it. R4 names active-section tracking as a behaviour that may degrade to a
 * static default, so with no `IntersectionObserver` this module binds the click path,
 * applies no class and returns: an unhighlighted card is that default, not a failure.
 *
 * ONE CLASS, ONE OWNER (R2, R7, R11). The only DOM effect is `blitzy-toc__link--active`,
 * plus the matching `aria-current`, on exactly one `.blitzy-toc__link` at a time.
 * Headings, ids and `mw-` prefixed classes are read and never written; no node is added
 * or removed; nothing is restyled from script, so the stylesheet alone decides what the
 * class looks like; and no inline style or custom property is ever written, because
 * fidelity.spec.ts compares computed styles against tokens.
 *
 * DETERMINISTIC BY CONSTRUCTION (R9). Four of the twenty-one committed captures
 * photograph this card, so the marked entry is part of a byte-compared PNG. Marking is
 * a pure function of the DOM and the viewport: no clock, no random source, no
 * `setTimeout`/`setInterval`, no `requestAnimationFrame`, no scroll listener, no
 * remembered geometry. One `IntersectionObserver` supplies every measurement — its
 * initial observation reports each target exactly once, which settles the marking
 * before capture — and `setActive()` is idempotent, so a second run from one state
 * places the class identically.
 *
 * ACCESSIBILITY (R10). The active entry is conveyed in the accessibility tree by
 * `aria-current`, never by colour alone. Nothing is hidden from assistive technology,
 * no link leaves the tab order and focus is never moved, so marking a section cannot
 * steal the caret from a reader tabbing through the card.
 *
 * DELIBERATELY ABSENT (R12). Expanding or collapsing entries, scrolling the active
 * entry into view, pinning, re-rendering on `wikipage.tableOfContents`, pausing around
 * VisualEditor, a back-to-top affordance, and any `mw.hook` subscription. Disclosure
 * behaviour is absent on purpose: below 1024px the card is core's documented checkbox
 * hack plus a `:checked` sibling rule, which keeps working with scripting off, and
 * dropdown.js owns any enhancement of it.
 *
 * No `require()` and no import-time side effect — the module declares, `init()` works —
 * which is what lets karma.conf.js instrument this file from disk and reach every
 * branch from a fixture (R14).
 */

'use strict';

// Cross-file DOM contract, shared verbatim with includes/templates/
// TableOfContents.mustache, which emits these names, and components/TableOfContents.less,
// which draws them; renaming one here without the other two silently stops sections
// being marked. LIST_SELECTOR is nested once per heading level, so a query returns the
// outermost list and one delegated listener there sees every entry by bubbling.
// ACTIVE_CLASS is the single class this module owns.
const TOC_SELECTOR = '.blitzy-toc';
const LIST_SELECTOR = '.blitzy-toc__list';
const LINK_SELECTOR = '.blitzy-toc__link';
const ACTIVE_CLASS = 'blitzy-toc__link--active';

// Core's parser wrapper around a heading, `<div class="mw-heading"><h2 id="…">…`.
// Read only, never written: core's output, not this skin's.
const HEADING_WRAPPER_SELECTOR = '.mw-heading';

// Cleanup handed back when there is nothing to track, so no caller needs a null check.
function noop() {}

/**
 * Decode a URL fragment without ever throwing.
 *
 * Core writes entry hrefs from its percent-encoded `linkAnchor` while the heading carries
 * the unencoded `anchor` as its id, so the two only meet after a decode. Page content can
 * produce a malformed sequence — a heading titled `100% pure` arrives as `#100%_pure` —
 * and `decodeURIComponent` throws a `URIError` on that. A malformed fragment must cost
 * one skipped entry, never the whole module.
 *
 * @param {string} value Percent-encoded fragment, without the leading `#`
 * @return {string} The decoded value, or `value` unchanged when it cannot be decoded
 */
function safeDecode( value ) {
	try {
		return decodeURIComponent( value );
	} catch ( e ) {
		return value;
	}
}

/**
 * Build the observer that drives scroll tracking, or report that it cannot be built.
 *
 * The feature guard sits inside this body rather than at module scope so the module keeps
 * its zero import-time side effects and `init()` has one honest place to learn that
 * tracking is unavailable. Returning `null` rather than throwing is what makes the R4
 * static default reachable.
 *
 * @param {Function} callback Receives the entry list on every intersection change
 * @return {IntersectionObserver|null} `null` when the browser has no observer
 */
function createObserver( callback ) {
	if ( typeof IntersectionObserver !== 'function' ) {
		return null;
	}
	return new IntersectionObserver( callback );
}

/**
 * Choose the heading that represents the reader's current position.
 *
 * The whole of the selection logic, reading nothing but the geometry the observer
 * reports: among the entries in one batch take the one closest to the top of the viewport
 * at or above it, and fall back to the closest one below when nothing is at or above —
 * the case at the top of a page, before any heading has scrolled past. An entry whose
 * rect is zero in both directions is a hidden heading and is skipped; MediaWiki records
 * that as a real occurrence (T330612), and treating it as sitting at the viewport top
 * would mark the wrong section.
 *
 * @param {IntersectionObserverEntry[]} entries Entries from one observer callback
 * @return {Element|null} The chosen heading, or `null` when the batch holds none
 */
function pickCurrent( entries ) {
	let above = null;
	let below = null;
	entries.forEach( ( entry ) => {
		const rect = entry.boundingClientRect;
		if ( rect.top === 0 && rect.bottom === 0 ) {
			return;
		}
		if ( rect.top <= 0 ) {
			if ( above === null || rect.top > above.boundingClientRect.top ) {
				above = entry;
			}
		} else if ( below === null || rect.top < below.boundingClientRect.top ) {
			below = entry;
		}
	} );
	const chosen = above || below;
	return chosen ? chosen.target : null;
}

/**
 * Resolve every entry anchor to the heading it points at.
 *
 * Built from the card's own links rather than from a heading sweep, so a heading with no
 * entry is never observed and an entry that has outlived its heading is dropped rather
 * than thrown over. The fragment is only ever a lookup key for `getElementById`: it comes
 * from page content, so it is never interpolated into markup and never into a selector,
 * where a legal heading character such as `(` would be a syntax error and `CSS.escape` an
 * avoidable compatibility risk (R13). The map is keyed by the observed element rather
 * than the fragment string for the same reason — a heading may be titled `__proto__` or
 * `toString`, and an object keyed by content would hand back a prototype member for
 * those, while element identity cannot collide.
 *
 * @param {Document} doc Document to resolve fragments against
 * @param {Element} list The entry list whose anchors are read
 * @return {Map} Observed heading element to the anchor that points at it
 */
function resolveEntries( doc, list ) {
	const observed = new Map();
	Array.prototype.forEach.call( list.querySelectorAll( LINK_SELECTOR ), ( link ) => {
		const href = link.getAttribute( 'href' );
		if ( !href || !href.startsWith( '#' ) || href.length < 2 ) {
			return;
		}
		const heading = doc.getElementById( safeDecode( href.slice( 1 ) ) );
		if ( !heading ) {
			return;
		}
		// Prefer core's wrapper when the modern parser emitted one: its rect covers the
		// whole heading block, so the geometry matches what the reader sees.
		observed.set( heading.closest( HEADING_WRAPPER_SELECTOR ) || heading, link );
	} );
	return observed;
}

/**
 * Start marking the current section and hand back the way to stop.
 *
 * Both parameters exist so the unit suite can drive this from a fixture without a wiki
 * and without a real viewport (R14): the root scopes the search, and the factory lets a
 * test invoke the observer callback synchronously with hand-built entries and assert the
 * selection rule.
 *
 * @param {Element|Document} [rootElement] Subtree to search, defaults to `document`
 * @param {Function} [observerFactory] Given the observer callback, returns an
 *  `IntersectionObserver` or `null`. Defaults to the feature-guarded `createObserver`.
 * @return {Function} Removes the click listener and disconnects the observer. Always a
 *  function, so callers need no guard; a no-op when there was nothing to track, and
 *  harmless to call more than once.
 */
function init( rootElement, observerFactory ) {
	const root = rootElement || document;

	// The card is legitimately absent on the main page, on special pages, on the edit form
	// and on any article too short for a table of contents: a hot path, not an error path.
	const toc = root.querySelector( TOC_SELECTOR );
	if ( !toc ) {
		return noop;
	}
	const list = toc.querySelector( LIST_SELECTOR );
	if ( !list ) {
		return noop;
	}
	const observed = resolveEntries( root.ownerDocument || root, list );
	if ( observed.size === 0 ) {
		return noop;
	}

	let activeLink = null;
	let observer = null;

	/**
	 * Move the marking to one anchor, or leave it exactly where it is.
	 *
	 * The identity check makes marking idempotent: it keeps exactly one anchor active and
	 * stops needless class churn while a capture settles. The click path and the observer
	 * path both funnel through here, so there is one place where the class and the ARIA
	 * state could ever disagree, and it is tested.
	 *
	 * @param {Element} link The entry anchor to mark
	 * @return {void}
	 */
	function setActive( link ) {
		if ( link === activeLink ) {
			return;
		}
		if ( activeLink ) {
			// Removes: blitzy-toc__link--active
			activeLink.classList.remove( ACTIVE_CLASS );
			activeLink.removeAttribute( 'aria-current' );
		}
		// Adds: blitzy-toc__link--active
		link.classList.add( ACTIVE_CLASS );
		link.setAttribute( 'aria-current', 'true' );
		activeLink = link;
	}

	/**
	 * Mark the entry the reader just asked for, before any scrolling settles.
	 *
	 * One delegated listener rather than one per anchor, so nested sublists are covered by
	 * the same handler. `preventDefault()` is deliberately not called: the anchor must
	 * still navigate to its heading, the behaviour that survives with scripting disabled
	 * and the one core's markup promises.
	 *
	 * @param {Event} event The click that bubbled up to the entry list
	 * @return {void}
	 */
	function onClick( event ) {
		const target = event.target;
		if ( !target || typeof target.closest !== 'function' ) {
			return;
		}
		const link = target.closest( LINK_SELECTOR );
		if ( link ) {
			setActive( link );
		}
	}

	/**
	 * Mark the entry for whichever observed heading the selection rule chooses.
	 *
	 * @param {IntersectionObserverEntry[]} entries Entries for this intersection change
	 * @return {void}
	 */
	function onIntersection( entries ) {
		const current = pickCurrent( entries );
		const link = current ? observed.get( current ) : undefined;
		if ( link ) {
			setActive( link );
		}
	}

	// Aggregate teardown for everything init() bound.
	function cleanup() {
		list.removeEventListener( 'click', onClick );
		if ( observer ) {
			observer.disconnect();
			observer = null;
		}
	}

	list.addEventListener( 'click', onClick );

	observer = ( observerFactory || createObserver )( onIntersection );
	if ( observer ) {
		observed.forEach( ( link, element ) => {
			observer.observe( element );
		} );
	}

	return cleanup;
}

module.exports = { init };
