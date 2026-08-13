/** @interface MwApi */

// Two-state feature toggling for the Notion skin.
//
// This module is the one place where three separate naming systems have to agree, and it owns the
// translation between them:
//
// - The markup carries the BARE feature name. PinnableHeader.mustache emits
//   `data-feature-name="toc-pinned"`; no prefix ever appears in that attribute.
// - The document element carries `notion-feature-<name>-<suffix>` classes. They are rendered
//   server side by `FeatureManager::getFeatureBodyClass()` and are what
//   resources/skins.notion.styles/layouts/grid.less, CSSCustomProperties.less and
//   skins.notion.clientPreferences select on. This module keeps them in step with the reader's
//   action.
// - The stored preference is `notion-<name>` for named users and `notion-feature-<name>` for
//   anonymous ones. The two spellings are deliberate and are NOT interchangeable: the first is a
//   user option declared in the `DefaultUserOptions` block of skin.json and mirrored by
//   `Constants::PREF_KEY_*`, the second a client-preference key that `mw.user.clientPrefs`
//   mirrors into a cookie. Every string below is therefore load-bearing; a single wrong character
//   makes pinning stop persisting without raising an error anywhere.
//
// The suffix pair encodes how far a feature persists, and that is decided server side rather than
// here:
//
// - `clientpref-1` / `clientpref-0` for the features that persist for every reader:
//   `toc-pinned`, `limited-width` and `appearance-pinned`.
// - `enabled` / `disabled` for the features that only persist for logged-in users
//   (`main-menu-pinned`, `page-tools-pinned`) and for server-only flags that scripting must never
//   touch at all.
//
// Nothing here is required for the initial render. The server has already applied the correct
// class, and both the pinned and the unpinned layout are fully styled, so with scripting disabled
// the page is still complete and correct. This module only *changes* state in response to a user
// action, which is why it is safe for it to load late.

// `mediawiki.util` and `mediawiki.user` are declared dependencies of the skins.notion.js module,
// so `debounce` and the `mw.user` members used below are always available by the time this file
// runs. The cast is what stops the type checker from trying to resolve the ResourceLoader module
// name as a file on disk.
const debounce = require( /** @type {string} */ ( 'mediawiki.util' ) ).debounce;
const userPreferences = require( './userPreferences.js' );

/**
 * Persist a feature's new state for the current reader.
 *
 * Which of the two storage backends is used is not a choice this function makes; it follows from
 * whether the account is named:
 *
 * - Anonymous and temporary users get `mw.user.clientPrefs`, which stores the value in a cookie.
 *   Only the three features the server renders with a `clientpref-` suffix are supported here.
 *   Anything else falls through the `default` branch untouched, because writing a key the server
 *   will not read back on the next request is worse than storing nothing: the interface would
 *   claim a preference had been remembered when the next page load silently discards it.
 * - Named users get an `action=options` write through `userPreferences.saveOptions()`, deferred by
 *   500ms. `debounce()` is called afresh on every invocation, so each change owns its own timer and
 *   its own payload: the delay keeps the request off the click handler without ever discarding a
 *   preference. Hoisting one shared debounced function to module scope would look tidier and would
 *   be a bug -- flipping two different features inside the window would overwrite the pending
 *   payload and silently drop the first.
 *
 * The two backends also differ in value type, which is why the ternaries below are not shared:
 * `mw.user.clientPrefs.set()` takes a string, whereas user options carry the numbers 1 and 0 to
 * match the defaults declared in skin.json.
 *
 * Failure is deliberately not surfaced. `saveOptions()` returns a promise this function does not
 * consume: the document classes have already been swapped by the time it is called, and reverting
 * a reader's layout because a background request failed is more disruptive than letting the
 * preference fall back to its stored value on the next page load.
 *
 * @param {string} feature bare feature name, exactly as it appears in `data-feature-name`, for
 *  example `toc-pinned`. Never carries the `notion-` or `notion-feature-` prefix.
 * @param {boolean} enabled the state to store.
 */
function save( feature, enabled ) {
	if ( !mw.user.isNamed() ) {
		switch ( feature ) {
			case 'toc-pinned':
			case 'limited-width':
			case 'appearance-pinned':
				// Save the setting under the new system
				mw.user.clientPrefs.set( `notion-feature-${ feature }`, enabled ? '1' : '0' );
				break;
			default:
				// not a supported anonymous preference
				break;
		}
	} else {
		debounce( () => {
			userPreferences.saveOptions( {
				[ `notion-${ feature }` ]: enabled ? 1 : 0
			} );
		}, 500 )();
	}
}

/**
 * Swap the feature classes on the document element and report the resulting state.
 *
 * The function is total: every path either returns a boolean or throws, so a caller can rely on
 * the return value describing the document it is now looking at.
 *
 * It resolves the suffix pair by inspection rather than by consulting a list. A first pass looks
 * for the client-preference classes; if neither is present the feature is either a logged-in-only
 * one rendered with the `-enabled`/`-disabled` pair or not a feature at all, and the function
 * calls itself once with `isNotClientPreference` set to distinguish the two. That single retry is
 * what lets one API serve both kinds of feature without the caller -- `pinnableElement.js`, which
 * only ever sees a `data-feature-name` -- having to know which kind it holds.
 *
 * Only the classes are touched here. Persisting the new state is the caller's decision, because
 * some transitions are responsive rather than chosen: a narrow viewport unpins a menu without
 * that ever becoming the reader's stored preference.
 *
 * @param {string} name feature name
 * @param {boolean} [override] option to force enabled or disabled state.
 * @param {boolean} [isNotClientPreference] the feature is not a client preference,
 *  so does not persist for logged out users.
 * @return {boolean} The new feature state (false=disabled, true=enabled).
 * @throws {Error} if unknown feature toggled.
 */
function toggleDocClasses( name, override, isNotClientPreference ) {
	const suffixEnabled = isNotClientPreference ? 'enabled' : 'clientpref-1';
	const suffixDisabled = isNotClientPreference ? 'disabled' : 'clientpref-0';
	const featureClassEnabled = `notion-feature-${ name }-${ suffixEnabled }`,
		classList = document.documentElement.classList,
		featureClassDisabled = `notion-feature-${ name }-${ suffixDisabled }`,
		// If neither of the classes can be found it is a legacy feature
		isLoggedInOnlyFeature = !classList.contains( featureClassDisabled ) &&
			!classList.contains( featureClassEnabled );

	// Check in legacy mode.
	if ( isLoggedInOnlyFeature && !isNotClientPreference ) {
		// try again using the legacy classes
		return toggleDocClasses( name, override, true );
	} else if ( override === true ||
			( override === undefined && classList.contains( featureClassDisabled ) ) ) {
		classList.remove( featureClassDisabled );
		classList.add( featureClassEnabled );
		return true;
	} else if ( override === false ||
			( override === undefined && classList.contains( featureClassEnabled ) ) ) {
		classList.add( featureClassDisabled );
		classList.remove( featureClassEnabled );
		return false;
	} else {
		// Reached only when the retry above also found neither class, so the name does not
		// correspond to any feature this page rendered. Throwing surfaces drift between a
		// template's data-feature-name and the features the skin registers loudly, at the moment
		// of the click, instead of leaving a control that quietly does nothing.
		throw new Error( `Attempt to toggle unknown feature: ${ name }` );
	}
}

/**
 * Flip a feature and remember the result.
 *
 * The order matters: the classes are swapped first so the interface responds immediately, and the
 * state that was actually reached -- not the state that was requested -- is what gets stored. If
 * `toggleDocClasses()` throws, nothing is persisted.
 *
 * @param {string} name
 * @throws {Error} if unknown feature toggled.
 */
function toggle( name ) {
	const featureState = toggleDocClasses( name );
	save( name, featureState );
}

/**
 * Checks if the feature is enabled.
 *
 * Both spellings of the enabled class are tested because the caller does not know whether the
 * feature persists for anonymous readers, and only one of the two pairs is ever rendered.
 *
 * @param {string} name
 * @return {boolean}
 */
function isEnabled( name ) {
	return document.documentElement.classList.contains( getClass( name, true ) ) ||
		document.documentElement.classList.contains( getClass( name, true, true ) );
}

/**
 * Get name of feature class.
 *
 * The single definition of the class-name shape. `isEnabled()` and `toggleDocClasses()` are its
 * only callers today; it is nonetheless part of the module's public API so that a sibling script
 * needing a feature class can derive it here rather than re-spelling the pattern and drifting from
 * what the server renders.
 *
 * @param {string} name
 * @param {boolean} featureEnabled
 * @param {boolean} [isClientPreference] whether the feature is also a client preference
 * @return {string}
 */
function getClass( name, featureEnabled, isClientPreference ) {
	if ( featureEnabled ) {
		const suffix = isClientPreference ? 'clientpref-1' : 'enabled';
		return `notion-feature-${ name }-${ suffix }`;
	} else {
		const suffix = isClientPreference ? 'clientpref-0' : 'disabled';
		return `notion-feature-${ name }-${ suffix }`;
	}
}

module.exports = { getClass, isEnabled, toggle, toggleDocClasses, save };
