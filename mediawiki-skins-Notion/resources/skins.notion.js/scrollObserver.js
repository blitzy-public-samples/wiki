/**
 * Scroll observation for the Notion skin.
 *
 * The skin needs one fact about the reader's scroll position: whether the viewport has travelled
 * past the bottom edge of the page title. Everything that changes on scroll is driven from that
 * single observation - revealing the sticky header, moving the unpinned table of contents into it,
 * switching the table of contents toggle styles, and adding the below-page-title class to `body` -
 * which is why the observation lives in a module of its own instead of in each consumer.
 *
 * `IntersectionObserver` is used in preference to a scroll listener because the browser evaluates
 * intersections without running script on every scroll event, and calls back only when the answer
 * changes. That keeps scrolling smooth on long articles. The observer is created here but left
 * unobserved: the caller chooses the target element and supplies the behaviour for each direction.
 *
 * Nothing in this module is required to read a page. The skin's stylesheets hide the sticky header
 * by default and reveal it only once a class added by the consumer of this observer is present, so
 * with JavaScript unavailable the page is complete without any of the behaviour below.
 */
const
	SCROLL_TITLE_HOOK = 'notion.page_title_scroll',
	SCROLL_TITLE_CONTEXT_ABOVE = 'scrolled-above-page-title',
	SCROLL_TITLE_CONTEXT_BELOW = 'scrolled-below-page-title',
	SCROLL_TITLE_ACTION = 'scroll-to-top';

/**
 * Fire the skin's page title scroll hook so that the scroll direction can be logged.
 *
 * This is called once per direction change rather than once per scroll event, because the observer
 * that drives it reports only when the intersection state flips. `mw.hook` is core-owned API and is
 * deliberately the only channel used: firing a hook keeps this module independent of any particular
 * consumer, and a hook nobody subscribes to costs nothing to fire.
 *
 * Any direction other than `'down'` is treated as upward, which keeps the two branches exhaustive
 * so that no scroll direction change can go unreported.
 *
 * @param {string} direction the scroll direction
 */
function firePageTitleScrollHook( direction ) {
	/**
	 * Internal instrumentation. This skin has no consumer of this hook, and no extension in this
	 * repository subscribes to it; the payload is documented here so that instrumentation added
	 * later has a stable contract to read, and so that the hook can be observed during
	 * development with `mw.hook( 'notion.page_title_scroll' ).add( ... )`.
	 *
	 * Scrolling down past the page title reports the below-title context alone. Scrolling back up
	 * also reports an action, because arriving above the title is what a scroll-to-top interaction
	 * produces.
	 *
	 * @event notion.page_title_scroll
	 * @internal
	 * @property {string} context
	 * @property {string} action
	 */
	if ( direction === 'down' ) {
		mw.hook( SCROLL_TITLE_HOOK ).fire( {
			context: SCROLL_TITLE_CONTEXT_BELOW
		} );
	} else {
		mw.hook( SCROLL_TITLE_HOOK ).fire( {
			context: SCROLL_TITLE_CONTEXT_ABOVE,
			action: SCROLL_TITLE_ACTION
		} );
	}
}

/**
 * Create an observer for showing/hiding feature and for firing scroll event hooks.
 *
 * The returned observer is not yet watching anything. Callers observe the element they care about -
 * the first heading, which marks the bottom edge of the page title - and may pass the observer on
 * to the sticky header so that observation can be paused and resumed while its layout changes.
 * Neither callback is given arguments, so any function can be supplied without having to interpret
 * an observer entry.
 *
 * Only the first entry is inspected because a single element is observed. `isIntersecting` cannot
 * answer the question on its own, since it is equally false when the target is above the viewport
 * and when it is below; the sign of `boundingClientRect.top` is what distinguishes the two.
 *
 * @param {Function} show functionality for when feature is visible
 * @param {Function} hide functionality for when feature is hidden
 * @return {IntersectionObserver}
 */
function initScrollObserver( show, hide ) {
	return new IntersectionObserver( ( entries ) => {
		if ( !entries[ 0 ].isIntersecting && entries[ 0 ].boundingClientRect.top < 0 ) {
			// Viewport has crossed the bottom edge of the target element.
			show();
		} else {
			// Viewport is above the bottom edge of the target element.
			hide();
		}
	} );
}

module.exports = {
	initScrollObserver,
	firePageTitleScrollHook
};
