/** @module SectionObserver */

/**
 * @callback OnIntersection
 * @param {HTMLElement} element The section that triggered the new intersection change.
 */

/**
 * @typedef {Object} SectionObserverProps
 * @property {NodeList} elements A list of HTML elements to observe for
 * intersection changes. This list can be replaced at any time through the
 * `setElements` setter, which is how the caller keeps the observer in step with
 * content that has been re-rendered (e.g. after the `wikipage.tableOfContents`
 * hook or after VisualEditor deactivates).
 * @property {OnIntersection} onIntersection Called when a new intersection is observed.
 * @property {number} [topMargin] The number of pixels to shrink the top of
 * the viewport's bounding box before calculating intersections. This is useful
 * for sticky elements (e.g. sticky headers). Defaults to 0 pixels.
 * @property {number} [throttleMs] The number of milliseconds that the scroll
 * handler should be throttled. Defaults to 200 milliseconds.
 */

/**
 * @callback initSectionObserver
 * @param {SectionObserverProps} props
 * @return {SectionObserver}
 */

/**
 * Observe intersection changes with the viewport for one or more elements. This
 * is intended to be used with the headings in the content so that the
 * corresponding section(s) in the table of contents can be "activated" (e.g.
 * bolded).
 *
 * When sectionObserver notices a new intersection change, the
 * `props.onIntersection` callback will be fired with the corresponding section
 * as a param.
 *
 * Because sectionObserver uses a scroll event listener (in combination with
 * IntersectionObserver), the changes are throttled to a default maximum rate of
 * 200ms so that the main thread is not excessively blocked.
 * IntersectionObserver is used to asynchronously calculate the positions of the
 * observed tags off the main thread and in a manner that does not cause
 * expensive forced synchronous layouts.
 *
 * Note that the throttled scroll listener, not IntersectionObserver, is what
 * drives the calculation here. The question this module answers is *which* of
 * many headings is the active one, which requires comparing the positions of
 * all of them against a single offset; IntersectionObserver on its own only
 * reports whether an individual element is visible.
 *
 * This module is a progressive enhancement. The table of contents rendered by
 * `includes/templates/TableOfContents__list.mustache` is fully usable without
 * it, and nothing here moves focus or changes tab order: the `onIntersection`
 * callback only toggles presentational active-state classes.
 *
 * `props.topMargin` is supplied by the caller, which derives it from the
 * document's `scroll-padding-top` plus the `@scroll-margin-heading` variable in
 * `resources/skins.notion.styles/variables.less`. Those two values must stay in
 * sync; when they drift, the activated section is consistently off by one
 * (T314419).
 *
 * @param {SectionObserverProps} props
 * @return {SectionObserver}
 */
module.exports = function sectionObserver( props ) {
	props = Object.assign( {
		topMargin: 0,
		throttleMs: 200,
		onIntersection: () => {}
	}, props );

	let /** @type {number | undefined} */ timeoutId;
	let /** @type {HTMLElement | undefined} */ current;

	const observer = new IntersectionObserver( ( entries ) => {
		let /** @type {IntersectionObserverEntry | undefined} */ closestNegativeEntry;
		let /** @type {IntersectionObserverEntry | undefined} */ closestPositiveEntry;
		const topMargin = /** @type {number} */ ( props.topMargin );

		entries.forEach( ( entry ) => {
			if ( entry.boundingClientRect.top === 0 && entry.boundingClientRect.bottom === 0 ) {
				// Zero height means that it's probably a hidden heading (T330612) - ignore it
				return;
			}
			// Positions are measured relative to the offset rather than to the
			// viewport's own top edge, so that a sticky header covering the first
			// `topMargin` pixels does not activate the section hidden behind it.
			const top = entry.boundingClientRect.top - topMargin;
			if (
				top > 0 &&
				(
					closestPositiveEntry === undefined ||
					top < closestPositiveEntry.boundingClientRect.top - topMargin
				)
			) {
				closestPositiveEntry = entry;
			}

			if (
				top <= 0 &&
				(
					closestNegativeEntry === undefined ||
					top > closestNegativeEntry.boundingClientRect.top - topMargin
				)
			) {
				closestNegativeEntry = entry;
			}
		} );

		// The section being read is the one whose heading is closest to, but not
		// past, the offset: the negative candidate nearest zero. A heading exactly
		// on the offset counts as negative, so scrolling a heading to the top
		// activates it. Above the first heading there is no negative candidate, so
		// fall back to the positive candidate nearest the offset. When neither
		// exists (an empty element list, or one whose every entry was skipped as
		// zero height) the selection is left undefined rather than guessed at.
		const closestTag =
			/** @type {HTMLElement | undefined} */ ( closestNegativeEntry ?
				closestNegativeEntry.target :
				closestPositiveEntry ? closestPositiveEntry.target : undefined );

		// If the intersection is new, fire the `onIntersection` callback.
		if ( current !== closestTag && closestTag ) {
			props.onIntersection( closestTag );
		}
		current = closestTag;

		// When finished finding the intersecting element, stop observing all
		// observed elements. The scroll event handler will be responsible for
		// throttling and reobserving the elements again. Because we don't have a
		// wrapper element around our content headings and their children, we can't
		// rely on IntersectionObserver (which is optimized to detect intersecting
		// elements *within* the viewport) to reliably fire this callback without
		// this manual step. Instead, we offload the work of calculating the
		// position of each element in an efficient manner to IntersectionObserver,
		// but do not use it to detect when a new element has entered the viewport.
		observer.disconnect();
	} );

	/**
	 * Calculate the intersection of each observed element.
	 */
	function calcIntersection() {
		// IntersectionObserver will asynchronously calculate the boundingClientRect
		// of each observed element off the main thread after `observe` is called.
		props.elements.forEach( ( element ) => {
			if ( !element.parentNode ) {
				mw.log.warn( 'Element being observed is not in DOM', element );
				return;
			}
			observer.observe( /** @type {HTMLElement} */ ( element ) );
		} );
	}

	function handleScroll() {
		// Throttle the scroll event handler to fire at a rate limited by `props.throttleMs`.
		if ( !timeoutId ) {
			timeoutId = window.setTimeout( () => {
				calcIntersection();
				timeoutId = undefined;
			}, props.throttleMs );
		}
	}

	function bindScrollListener() {
		window.addEventListener( 'scroll', handleScroll );
	}

	function unbindScrollListener() {
		window.removeEventListener( 'scroll', handleScroll );
	}

	/**
	 * Drop a calculation that has been scheduled but not yet run, so that no work
	 * is performed after observation has stopped. `clearTimeout` ignores an
	 * `undefined` handle, so this is safe to call whether or not a calculation is
	 * pending, and safe to call repeatedly.
	 */
	function cancelPendingCalculation() {
		clearTimeout( timeoutId );
		timeoutId = undefined;
	}

	/**
	 * Pauses intersection observation until `resume` is called.
	 *
	 * Unbinding the scroll listener is what makes the observer ignore events
	 * entirely while paused: no further scroll event can schedule a calculation,
	 * and any calculation already scheduled is cancelled. Callers depend on this
	 * to keep the section a reader clicked in the table of contents active while
	 * the browser is still scrolling towards it (T297614).
	 */
	function pause() {
		unbindScrollListener();
		cancelPendingCalculation();
		// Assume current is no longer valid while paused.
		current = undefined;
	}

	/**
	 * Resumes intersection observation.
	 */
	function resume() {
		bindScrollListener();
	}

	/**
	 * Cleans up event listeners and intersection observer. Should be called when
	 * the observer is permanently no longer needed.
	 */
	function unmount() {
		unbindScrollListener();
		// Cancel for the same reason `pause` does: once the consumer has declared
		// this observer dead, a calculation left in the queue must not call back
		// into it.
		cancelPendingCalculation();
		observer.disconnect();
	}

	/**
	 * Set a list of HTML elements to observe for intersection changes.
	 *
	 * @param {NodeList} list
	 */
	function setElements( list ) {
		props.elements = list;
	}

	bindScrollListener();

	/**
	 * @typedef {Object} SectionObserver
	 * @property {calcIntersection} calcIntersection
	 * @property {pause} pause
	 * @property {resume} resume
	 * @property {unmount} unmount
	 * @property {setElements} setElements
	 */
	return {
		calcIntersection,
		pause,
		resume,
		unmount,
		setElements
	};
};
