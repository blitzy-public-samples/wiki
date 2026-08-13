'use strict';

// Global test environment bootstrap for the Notion skin.
//
// Jest loads this file through `setupFiles` in jest.config.js, ahead of every test file and of any
// module under test. Its only job is to install the globals a real MediaWiki page exposes (`mw`,
// `$`, `OO` and an OOUI theme) so that the skin's production scripts under resources/ can be
// exercised unmodified, rather than rewritten to suit the test runner.
//
// Notes for maintainers:
//
// - The statement order below is load-bearing, not stylistic. `oojs-ui` is an IIFE that reads `OO`
//   and `$` off the global object the moment it is required, so both must already be assigned.
//   Reordering yields "ReferenceError: OO is not defined" or "ReferenceError: $ is not defined".
// - `oojs-ui` also dereferences `window` while loading, so this file only runs under Jest's
//   `jsdom` testEnvironment.
// - Keep skin-specific fixtures and DOM scaffolding out of here. Per-test DOM setup belongs in the
//   individual tests under tests/jest/; module-level fakes belong in tests/jest/__mocks__/.
// - tsconfig.json excludes this file, because assigning to Node globals is rejected by `strict`.
// - The stubs below are `jest.fn()` spies, and `setupFiles` runs once per test file, so each test
//   file gets its own. Within one file they accumulate calls across test cases: a test asserting
//   on `mock.calls` should clear them first (`jest.clearAllMocks()` in a `beforeEach`, or
//   `mockClear()` on the individual spy) rather than assume it is the only caller.
// - Every member stubbed here mirrors the real MediaWiki signature and return type. A double that
//   is easier to satisfy than the real API is worse than no double at all, because it lets a
//   production regression to the wrong method name or the wrong argument shape pass.

const mockMediaWiki = require( '@wikimedia/mw-node-qunit/src/mockMediaWiki.js' );
global.mw = mockMediaWiki();

// `jquery` is deliberately not a declared devDependency: it resolves transitively through
// @wikimedia/mw-node-qunit, exactly as it does for the Vector skin. Do not add it to package.json.
global.$ = require( 'jquery' );

// Members the skin's scripts call that the mock does not supply with the signature they need.
//
// `mw.Api.prototype.saveOptions` is what resources/skins.notion.js/userPreferences.js calls -- the
// plural form, taking the options object and a parameters object, because the skin passes
// `{ global: 'update' }` so a preference propagates across a global account. mw-node-qunit's Api
// double defines only `get`, `post`, `getToken` and `postWithToken`, so without this stub the
// pinning and appearance preference writes throw "saveOptions is not a function" -- and stubbing
// the singular `saveOption` instead would be worse than useless: correct code would still throw,
// while code that regressed to the wrong method name would pass.
//
// It is a jest.fn returning an already-resolved jQuery promise, which gives a test three things at
// once: no request is attempted, `.then()`/`.fail()` chains resolve synchronously, and the call
// itself is inspectable through `mw.Api.prototype.saveOptions.mock.calls`. A test that needs a
// rejection or a pending promise overrides the implementation for its own duration
// (`mockReturnValueOnce`), which is why the default is deliberately the success case.
global.mw.Api.prototype.saveOptions = jest.fn(
	() => global.$.Deferred().resolve( {} ).promise()
);

// `mw.user.clientPrefs` is the anonymous and temporary-user half of feature persistence: it is
// what resources/skins.notion.js/features.js writes the `notion-feature-*` client preferences
// through when `mw.user.isNamed()` is false, and mw-node-qunit's `mw.user` double omits it
// entirely. `set()` reports a boolean on a real page, so the spy does too; `get()` answers with
// `false`, which is what core returns for a preference that has not been stored.
global.mw.user.clientPrefs = {
	get: jest.fn( () => false ),
	set: jest.fn( () => true )
};

// Pinned here rather than relied upon: mw-node-qunit 7.2.0 does define `mw.util.showPortlet`, but
// resources/skins.notion.js/portlets.js calls it, so the skin's tests should not silently depend on
// which members a future version of the mock happens to keep.
global.mw.util.showPortlet = jest.fn();

global.OO = require( 'oojs' );
require( 'oojs-ui' );

// OOUI registers no default theme, and its widgets fail to construct without one. WikimediaUI is
// the theme core's OOUIModule hands every skin by default, and the one this skin restyles through
// skinStyles/ooui.less, so it is the theme tests must run against.
require( 'oojs-ui/dist/oojs-ui-wikimediaui.js' );
