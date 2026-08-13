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

const mockMediaWiki = require( '@wikimedia/mw-node-qunit/src/mockMediaWiki.js' );
global.mw = mockMediaWiki();

// `jquery` is deliberately not a declared devDependency: it resolves transitively through
// @wikimedia/mw-node-qunit, exactly as it does for the Vector skin. Do not add it to package.json.
global.$ = require( 'jquery' );

// Two members the mock omits but the skin's scripts call. `showPortlet` is used by
// resources/skins.notion.js/portlets.js; `saveOption` backs the pinning and appearance preference
// writes, and is stubbed here so that tests never attempt a real API request.
global.mw.util.showPortlet = function () {};
global.mw.Api.prototype.saveOption = function () {};

global.OO = require( 'oojs' );
require( 'oojs-ui' );

// OOUI registers no default theme, and its widgets fail to construct without one. WikimediaUI is
// the theme core's OOUIModule hands every skin by default, and the one this skin restyles through
// skinStyles/ooui.less, so it is the theme tests must run against.
require( 'oojs-ui/dist/oojs-ui-wikimediaui.js' );
