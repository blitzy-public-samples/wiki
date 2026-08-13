/**
 * Dismissal of the Blitzy announcement bar, and nothing else.
 *
 * The bar is rendered server-side by includes/templates/AnnouncementBar.mustache, which is
 * reached only from a Mustache section on `data-blitzy-announcement`; a disabled bar is
 * therefore absent from the document's flow rather than hidden. This module adds the one
 * behaviour that markup alone cannot carry: a dismissal that survives the next page load.
 *
 * Dismissal is a two-sided contract, and both sides live here:
 *
 *   WRITE  a click on the dismiss control records the dismissal, then removes the band.
 *   READ   a later load finds the record and removes the band before it is interacted with.
 *
 * A write-only implementation looks correct in a browser and is not this contract, so the
 * two sites are separate, named and independently drivable from a fixture.
 *
 * Enhancement only. With scripting unavailable the bar renders exactly as the server sent
 * it and the dismiss control does nothing: readability and every core action are unaffected,
 * and only the persistence of a dismissal degrades. Nothing here is a functional dependency,
 * so this module must not be invoked at import time — resources/skins.blitzy.js/index.js
 * owns the bootstrap and this file declares constants and functions only.
 *
 * PERSISTENCE CONTRACT
 *   key    mw-blitzy-announcement-dismissed  (the `mw` prefix is required: MediaWiki does
 *          not namespace storage keys, so an unprefixed key can collide with a gadget)
 *   value  1                                 (any other value, and an absent value, mean
 *          "not dismissed"; no expiry is set, because a dismissal is permanent)
 *
 * The key is deliberately constant and deliberately published: it is asserted by
 * tests/qunit/announcement.test.js, and tests/playwright/fixtures/auth.ts must NOT persist
 * it in its storageState — a stored dismissal would delete the announcement bar from the
 * fifteen authenticated captures and leave them incomparable with the six anonymous ones.
 *
 * DOM CONTRACT. Two hooks, both Blitzy-owned, and this module touches nothing else: it
 * removes the band and never a core node, an `mw-`classed node or a node it did not match
 * here. The selectors below accept every spelling this package documents for those two
 * hooks — the `blitzy-announcement-bar` block named by the implementation plan, and the
 * `blitzy-announcement` block with the matching id that AnnouncementBar.mustache actually
 * renders and names as the identifiers this file reads. Matching both is what keeps the
 * behaviour bound to the real template while remaining valid against either fixture.
 */
'use strict';

/**
 * Storage key recording that the reader dismissed the announcement bar.
 *
 * Exported so that the unit suite asserts against this contract rather than against a
 * literal of its own that could drift out of step with it.
 *
 * @type {string}
 */
const DISMISSED_KEY = 'mw-blitzy-announcement-dismissed';

/**
 * The only stored value that counts as a dismissal.
 *
 * @type {string}
 */
const DISMISSED_VALUE = '1';

/**
 * Selector for the announcement band: the single node this module ever removes.
 *
 * @type {string}
 */
const BAR_SELECTOR = '.blitzy-announcement-bar, .blitzy-announcement, #blitzy-announcement';

/**
 * Selector for the dismiss control, resolved within the band rather than the document.
 *
 * @type {string}
 */
const DISMISS_SELECTOR = '.blitzy-announcement-bar__dismiss, .blitzy-announcement__dismiss, #blitzy-announcement-dismiss';

/**
 * Resolve the store that both sides of the contract use.
 *
 * First match wins, and there are exactly two tiers. An injected store always wins, so a
 * test never reaches real device storage even when the store it passed is unusable, and
 * `mw.storage` is used otherwise: it normalises browser differences and swallows its own
 * exceptions. There is deliberately no raw `localStorage` tier — direct access is
 * prohibited by lint, and suppressing that inline would breach the zero-warning build.
 *
 * @param {Object} [storage] Store injected by the caller, with `get` and `set` methods
 * @return {Object|null} Resolved store, or null when neither tier is available
 */
function resolveStore( storage ) {
	if ( storage ) {
		return storage;
	}

	if ( typeof mw !== 'undefined' && mw.storage && typeof mw.storage.get === 'function' ) {
		return mw.storage;
	}

	return null;
}

/**
 * Read side of the contract: has this reader already dismissed the bar?
 *
 * Non-throwing by construction. A missing store, a store without a readable `get`, a store
 * that throws, and any stored value other than the dismissal value all answer "no", which
 * is the safe answer: the bar stays visible and the reader can dismiss it again.
 *
 * @param {Object|null} store Store resolved by {@link resolveStore}
 * @return {boolean} Whether a dismissal is on record
 */
function isDismissed( store ) {
	if ( !store || typeof store.get !== 'function' ) {
		return false;
	}

	try {
		return store.get( DISMISSED_KEY ) === DISMISSED_VALUE;
	} catch ( e ) {
		return false;
	}
}

/**
 * Write side of the contract: record that this reader dismissed the bar.
 *
 * No expiry is passed. The key is a single constant that this module can always rediscover,
 * and a dismissal is meant to last, so an expiring record would silently reinstate the bar.
 *
 * @param {Object|null} store Store resolved by {@link resolveStore}
 * @return {boolean} Whether the dismissal was recorded
 */
function setDismissed( store ) {
	if ( !store || typeof store.set !== 'function' ) {
		return false;
	}

	try {
		store.set( DISMISSED_KEY, DISMISSED_VALUE );
		return true;
	} catch ( e ) {
		return false;
	}
}

/**
 * Cleanup handle returned when there was nothing to bind, so every caller can treat the
 * return value of {@link init} as a function without testing it first.
 *
 * @return {void}
 */
function noop() {}

/**
 * Wire the announcement bar's dismissal within a root.
 *
 * Both the root and the store are parameters rather than globals so that the whole module
 * is exercisable against a fixture. Every absent element is an early return, never a throw:
 * the missing-bar path is the common case on a wiki that leaves the bar disabled.
 *
 * Safe to call more than once against the same root. A dismissed bar has already been
 * removed, so a later call finds nothing; an undismissed bar gains a second equivalent
 * listener whose effects are idempotent — the record is rewritten with the same value and
 * removing an already-detached node does nothing.
 *
 * @param {Document|DocumentFragment|Element} [rootElement] Search root, `document` by default
 * @param {Object} [storage] Store to use instead of `mw.storage`
 * @return {Function} Cleanup that detaches the listener this call attached
 */
function init( rootElement, storage ) {
	const root = rootElement || ( typeof document === 'undefined' ? null : document );
	if ( !root || typeof root.querySelector !== 'function' ) {
		return noop;
	}

	const bar = root.querySelector( BAR_SELECTOR );
	if ( !bar ) {
		return noop;
	}

	const store = resolveStore( storage );

	// READ SITE. A dismissal already on record removes the whole band in one operation,
	// rather than emptying or hiding it, so no focusable node is orphaned in a half
	// dismantled subtree and no state class or stylesheet is involved in the outcome.
	if ( isDismissed( store ) ) {
		bar.remove();
		return noop;
	}

	const dismissControl = bar.querySelector( DISMISS_SELECTOR );
	if ( !dismissControl ) {
		return noop;
	}

	// WRITE SITE. Record first, remove second: a storage failure then leaves the bar
	// standing and dismissable rather than gone but unrecorded, which would reinstate it
	// on the next load and read as a broken control.
	function onDismiss( event ) {
		event.preventDefault();
		setDismissed( store );
		bar.remove();
	}

	dismissControl.addEventListener( 'click', onDismiss );

	return function cleanup() {
		dismissControl.removeEventListener( 'click', onDismiss );
	};
}

module.exports = { init, DISMISSED_KEY };
