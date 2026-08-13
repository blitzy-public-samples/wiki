// Persistence for the Notion skin's user preferences.
//
// The skin's `notion-*` preferences (main menu, table of contents, page tools and appearance
// pinning, limited width, font size and theme) are stored for named users through `action=options`.
// This module is the single place that write happens, and writing is deliberately the only thing it
// does:
//
// - Callers own timing and batching. `features.js` debounces its writes by 500ms; a second debounce
//   here would make the delay unpredictable for every other caller.
// - Callers own the logged-out path. Anonymous preferences live client side in
//   `mw.user.clientPrefs`, never in the API, so this module is not reached for them.
// - Callers own the key names. No `notion-` string appears below: option keys arrive from the
//   caller, which keeps this module usable by every preference surface in the skin.
//
// The export is an object exposing `saveOptions` rather than a bare function because `skin.js`
// passes this module straight into `clientPreferences.render( selector, config, userPreferences )`,
// whose `UserPreferencesApi` contract requires a `saveOptions` member.

// Constructed on first use and then reused for the lifetime of the page, so a page on which no
// preference is ever changed constructs no `mw.Api` at all.
let /** @type {MwApi} */ api;

/**
 * Save one or more user preferences.
 *
 * @param {Object<string,string|number>} options Preference keys mapped to the values to store, for
 *  example `{ 'notion-toc-pinned': 1 }`. Values are sent as supplied: numbers are the convention
 *  for the skin's boolean preferences and strings for its enumerated ones.
 * @return {JQuery.Promise<Object>} Resolves with the API response once the write succeeds, and
 *  rejects on API or network failure so that callers can revert an optimistic interface change.
 */
function saveOptions( options ) {
	api = api || new mw.Api();
	// `global: 'update'` propagates the change to every wiki the account is attached to where the
	// GlobalPreferences extension is installed, and is inert where it is not. The ambient `MwApi`
	// type reached through resources/mw.d.ts declares `saveOptions` as taking the options object
	// alone, so this second argument has to be excused for `tsc --noEmit` to pass.
	// @ts-ignore
	return api.saveOptions( options, {
		global: 'update'
	} );
}

module.exports = {
	saveOptions
};
