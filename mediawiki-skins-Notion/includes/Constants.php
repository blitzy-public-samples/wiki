<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * @file
 */

namespace MediaWiki\Skins\Notion;

use MediaWiki\Exception\FatalError;

/**
 * A namespace for Notion constants for internal Notion usage only. **Do not rely on this file as an
 * API as it may change without warning at any time.**
 *
 * The class exists so that the rest of the skin never repeats a magic string. Three families of
 * value live here and each has a distinct contract:
 *
 * - `CONFIG_KEY_*` holds the name of a configuration variable **without** its `$wg` prefix. Any of
 *   these that the skin actually reads must have a matching entry in the `config` block of
 *   `skin.json`, otherwise `Config::get()` raises a `ConfigException` the first time the value is
 *   read. There is one deliberate exception, and it is the reason this is not stated as an
 *   absolute: `CONFIG_KEY_NAVIGATION_UPDATE` names a variable that is intentionally *not* declared
 *   in the manifest, and its own documentation below records the contract that keeps that safe.
 * - `PREF_KEY_*` holds the name of a user preference. Every skin-owned preference must have a
 *   matching entry in the `DefaultUserOptions` block of `skin.json` and must be registered by
 *   `Hooks::onGetPreferences()`, otherwise reading it through `UserOptionsLookup` yields no default.
 * - `FEATURE_*` and `REQUIREMENT_*` hold the identifiers used by the feature-management system, the
 *   former with `FeatureManager::registerFeature()` and the latter with
 *   `FeatureManager::registerRequirement()`. A registered feature whose name
 *   `FeatureManager::getFeatureBodyClass()` cannot map raises a `RuntimeException`, so the two sets
 *   must be kept in step with `FeatureManagement\FeatureManagerFactory`. The classes that method
 *   returns are put on the document element — the `<html>` tag — not on `<body>`; see the note on
 *   `FeatureManager::getFeatureBodyClass()` for why the method name says otherwise.
 *
 * @package Notion
 * @internal
 */
final class Constants {

	// Skin identity.
	// =========================================================================

	/**
	 * The one and only skin name this skin registers.
	 *
	 * This is tightly coupled to the ValidSkinNames field in skin.json: it must equal that key, the
	 * `name` argument passed to the skin class and the directory the skin is reachable at under
	 * `$IP/skins`. It is also the value `Hooks::onSkinPageReadyConfig()` compares against in its
	 * early-return guard, so a mismatch silently disables the skin's search wiring.
	 *
	 * Per `Manual:$wgValidSkinNames` the identifier is all lower case.
	 *
	 * @var string
	 */
	public const SKIN_NAME = 'notion';

	/**
	 * The core preference that records which skin a user has chosen.
	 *
	 * Deliberately unprefixed: this is a core key, not a skin-owned one, and it is neither declared
	 * in `DefaultUserOptions` nor registered by this skin. It is used as the left-hand side of the
	 * `hide-if` conditions in `Hooks::onGetPreferences()` so that this skin's preferences are only
	 * offered to users actually reading with this skin.
	 *
	 * @var string
	 */
	public const PREF_KEY_SKIN = 'skin';

	// Feature management: foundational requirements.
	// =========================================================================

	/**
	 * Also known as `$wgFullyInitialised`. Set to true in core/includes/Setup.php.
	 *
	 * Read through `FeatureManagement\Requirements\DynamicConfigRequirement` rather than a plain
	 * config lookup because its value changes during the request lifecycle.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_FULLY_INITIALISED = 'FullyInitialised';

	/**
	 * Guards every feature: no feature may be considered enabled before core has finished setting
	 * up, because the services a requirement consults may not exist yet.
	 *
	 * @var string
	 */
	public const REQUIREMENT_FULLY_INITIALISED = 'FullyInitialised';

	/**
	 * Met when the request is made by a registered user. Features whose state is persisted in user
	 * preferences depend on it.
	 *
	 * @var string
	 */
	public const REQUIREMENT_LOGGED_IN = 'LoggedIn';

	/**
	 * Met when the requested title is the wiki's main page. Registered as a simple requirement
	 * because the answer is already known when the feature manager is built.
	 *
	 * @var string
	 */
	public const REQUIREMENT_IS_MAIN_PAGE = 'IsMainPage';

	// Feature management: placement of the language links.
	// =========================================================================

	/**
	 * Moves the language links out of the main menu and into a button beside the page title.
	 *
	 * @var string
	 */
	public const FEATURE_LANGUAGE_IN_HEADER = 'LanguageInHeader';

	/**
	 * @var string
	 */
	public const REQUIREMENT_LANGUAGE_IN_HEADER = 'LanguageInHeader';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_LANGUAGE_IN_HEADER = 'NotionLanguageInHeader';

	/**
	 * Keeps a copy of the language links in the main menu even while they are shown in the header.
	 *
	 * @var string
	 */
	public const FEATURE_LANGUAGE_IN_MAIN_MENU = 'LanguageInMainMenu';

	/**
	 * @var string
	 */
	public const REQUIREMENT_LANGUAGE_IN_MAIN_MENU = 'LanguageInMainMenu';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_LANGUAGE_IN_MAIN_MENU = 'NotionLanguageInMainMenu';

	/**
	 * T293470: on the main page the language button sits at the bottom of the content by default.
	 * This feature promotes it to the header instead.
	 *
	 * @var string
	 */
	public const FEATURE_LANGUAGE_IN_MAIN_PAGE_HEADER = 'LanguageInMainPageHeader';

	/**
	 * @var string
	 */
	public const REQUIREMENT_LANGUAGE_IN_MAIN_PAGE_HEADER = 'LanguageInMainPageHeader';

	/**
	 * Named without the `_KEY` infix so that the identifier matches the one the feature-management
	 * blueprint this skin is modelled on already publishes; every consumer in this skin uses this
	 * spelling.
	 *
	 * @var string
	 */
	public const CONFIG_LANGUAGE_IN_MAIN_PAGE_HEADER = 'NotionLanguageInMainPageHeader';

	// Feature management: navigation update.
	// =========================================================================

	/**
	 * Server-side only flag for rolling out changes to the navigation. It yields a
	 * `notion-feature-navigation-update-{enabled,disabled}`-style class on the document element and
	 * can therefore be targeted by stylesheets without any client-side scripting.
	 *
	 * @var string
	 */
	public const FEATURE_NAVIGATION_UPDATE = 'NavigationUpdate';

	/**
	 * @var string
	 */
	public const REQUIREMENT_NAVIGATION_UPDATE = 'NavigationUpdate';

	/**
	 * Suffixed `Temporary` to advertise that the variable exists only for the duration of the
	 * roll-out and may be withdrawn once the change is unconditional.
	 *
	 * Contract for consumers: the `config` block of skin.json declares this variable as
	 * `$wgNotionNavigationUpdateTemporary`, an audience map whose default
	 * `[ 'logged_in' => false, 'logged_out' => false ]` leaves the roll-out switched off, so it is
	 * always readable through `Config::get()`. That declaration is mandatory rather than decorative
	 * — `Config::get()` throws a `ConfigException` for an undeclared option instead of returning a
	 * default — and it is what makes this key safe to consult from an overridable requirement. Read
	 * it through an `OverridableConfigRequirement`, which additionally honours the per-request
	 * `?notionnavigationupdate=1` override and the per-audience overrides it layers on top, and keep
	 * the constant and the manifest key in step: renaming either side alone breaks the lookup. The pairing of every
	 * skin-owned `CONFIG_KEY_*` constant with its manifest declaration is asserted by
	 * `MediaWiki\Skins\Notion\Tests\Structure\ConfigurationClosureTest`.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_NAVIGATION_UPDATE = 'NotionNavigationUpdateTemporary';

	// Search instrumentation.
	// =========================================================================

	/**
	 * The `mediawiki.searchSuggest` protocol piece of the SearchSatisfaction instrumention reads
	 * the value of an element with the "data-search-loc" attribute and set the event's
	 * `inputLocation` property accordingly.
	 *
	 * When the search widget is moved as part of the "Search 1: Search widget move" feature, the
	 * "data-search-loc" attribute is set to this value.
	 *
	 * This string is a core instrumentation contract, not a presentational detail: renaming it
	 * would silently break the analytics pipeline. It is deliberately left unchanged.
	 *
	 * See also:
	 * - https://www.mediawiki.org/wiki/Reading/Web/Desktop_Improvements/Features/Search
	 * - https://phabricator.wikimedia.org/T261636 and https://phabricator.wikimedia.org/T256100
	 * - https://gerrit.wikimedia.org/g/mediawiki/core/+/61d36def2d7adc15c88929c824b444f434a0511a/resources/src/mediawiki.searchSuggest/searchSuggest.js#106
	 *
	 * @var string
	 */
	public const SEARCH_BOX_INPUT_LOCATION_MOVED = 'header-moved';

	/**
	 * Similar to `Constants::SEARCH_BOX_INPUT_LOCATION_MOVED`, when the search widget hasn't been
	 * moved, the "data-search-loc" attribute is set to this value.
	 *
	 * @var string
	 */
	public const SEARCH_BOX_INPUT_LOCATION_DEFAULT = 'header-navigation';

	// Feature management: pinnable elements.
	// =========================================================================
	// Each pinnable element is a triad: a feature name that becomes a class on the document element
	// (the `<html>` tag), a requirement name that resolves the user's choice, and the preference
	// the choice is stored in. The preference
	// names below are byte-identical to the keys declared in the `DefaultUserOptions` block of
	// skin.json; changing one without the other resets every user's layout.

	/**
	 * The page tools menu is rendered in the end column rather than as a dropdown.
	 *
	 * @var string
	 */
	public const FEATURE_PAGE_TOOLS_PINNED = 'PageToolsPinned';

	/**
	 * @var string
	 */
	public const REQUIREMENT_PAGE_TOOLS_PINNED = 'PageToolsPinned';

	/**
	 * @var string
	 */
	public const PREF_KEY_PAGE_TOOLS_PINNED = 'notion-page-tools-pinned';

	/**
	 * The table of contents is rendered in the start column rather than as a dropdown beside the
	 * page title.
	 *
	 * @var string
	 */
	public const FEATURE_TOC_PINNED = 'TOCPinned';

	/**
	 * @var string
	 */
	public const REQUIREMENT_TOC_PINNED = 'TOCPinned';

	/**
	 * @var string
	 */
	public const PREF_KEY_TOC_PINNED = 'notion-toc-pinned';

	/**
	 * The main menu is rendered as the start column rather than as a dropdown in the header.
	 *
	 * @var string
	 */
	public const FEATURE_MAIN_MENU_PINNED = 'MainMenuPinned';

	/**
	 * @var string
	 */
	public const REQUIREMENT_MAIN_MENU_PINNED = 'MainMenuPinned';

	/**
	 * @var string
	 */
	public const PREF_KEY_MAIN_MENU_PINNED = 'notion-main-menu-pinned';

	/**
	 * The appearance panel is rendered in the end column rather than as a dropdown.
	 *
	 * @var string
	 */
	public const FEATURE_APPEARANCE_PINNED = 'AppearancePinned';

	/**
	 * @var string
	 */
	public const REQUIREMENT_APPEARANCE_PINNED = 'AppearancePinned';

	/**
	 * @var string
	 */
	public const PREF_KEY_APPEARANCE_PINNED = 'notion-appearance-pinned';

	// Feature management: reading width.
	// =========================================================================
	// Two separate features. The first is the reader's own choice and applies to the whole skin; the
	// second is a server-side decision about the current page, so that pages which need the full
	// viewport (history, diffs, some special pages) opt out regardless of the reader's preference.

	/**
	 * The reader's choice to constrain the reading column to a comfortable measure.
	 *
	 * @var string
	 */
	public const FEATURE_LIMITED_WIDTH = 'LimitedWidth';

	/**
	 * @var string
	 */
	public const REQUIREMENT_LIMITED_WIDTH = 'LimitedWidth';

	/**
	 * @var string
	 */
	public const PREF_KEY_LIMITED_WIDTH = 'notion-limited-width';

	/**
	 * Whether the current page's content may be constrained at all, decided from the configured
	 * inclusion and exclusion rules rather than from any user preference.
	 *
	 * @var string
	 */
	public const FEATURE_LIMITED_WIDTH_CONTENT = 'LimitedWidthContent';

	/**
	 * @var string
	 */
	public const REQUIREMENT_LIMITED_WIDTH_CONTENT = 'LimitedWidthContent';

	/**
	 * The fallback used when the limited-width preference has no stored value. It mirrors the
	 * `notion-limited-width` entry in the `DefaultUserOptions` block of skin.json, where `1` means
	 * the reading column is constrained.
	 *
	 * @var int
	 */
	public const CONFIG_DEFAULT_LIMITED_WIDTH = 1;

	// Feature management: font size.
	// =========================================================================

	/**
	 * @var string
	 */
	public const PREF_KEY_FONT_SIZE = 'notion-font-size';

	/**
	 * Deliberately named `CustomFontSize` rather than `FontSize`.
	 *
	 * `FeatureManager::getFeatureBodyClass()` derives the emitted class from this camel-case name —
	 * a class the skin puts on the document element (the `<html>` tag) despite the method's
	 * historical name — and the message catalogue already declares the matching
	 * `notion-feature-custom-font-size-*` keys, so the two must agree.
	 *
	 * @var string
	 */
	public const FEATURE_FONT_SIZE = 'CustomFontSize';

	/**
	 * @var string
	 */
	public const REQUIREMENT_FONT_SIZE = 'CustomFontSize';

	// Feature management: night mode.
	// =========================================================================

	/**
	 * Unlike the other preferences this one is not a flag: its three valid values are `day`, `night`
	 * and `os`, with `day` declared as the default in skin.json.
	 *
	 * @var string
	 */
	public const PREF_KEY_NIGHT_MODE = 'notion-theme';

	/**
	 * @var string
	 */
	public const REQUIREMENT_PREF_NIGHT_MODE = 'PrefNightMode';

	/**
	 * The feature name registered for night mode. It is spelled `PREF_` rather than `FEATURE_`
	 * because the class it produces is a client preference (`skin-theme-clientpref-<value>`) shared
	 * with other skins so that editors can target one class everywhere. Core places client
	 * preference classes on the `<html>` element — see the `<key>-clientpref-<value>` note in
	 * `MediaWiki\ResourceLoader\ClientHtml` (T339268) — not on `<body>`.
	 *
	 * @var string
	 */
	public const PREF_NIGHT_MODE = 'PrefNightMode';

	// Configuration variables.
	// =========================================================================
	// Each value below is the name of a variable declared in the `config` block of skin.json, held
	// without its `$wg` prefix because that is the form `Config::get()` expects. They are named here
	// rather than repeated as literals at each call site so that a typo is caught by tooling rather
	// than by a user: a mistyped constant name is reported by phan as
	// `PhanUndeclaredConstantOfClass` before the code ever runs, and if it does run it raises
	// `Error: Undefined constant` on the spot. Neither failure is a compile-time one — `php -l`
	// accepts a reference to a constant that does not exist — but both are louder and earlier than
	// the alternative, where a mistyped string literal is invisible to every tool and surfaces only
	// as a `ConfigException` from `Config::get()` on whichever request first reaches that lookup.

	/**
	 * Temporary switch for the roll-out of horizontally scrollable tables. When enabled, qualifying
	 * tables are wrapped in a container so that a wide table can scroll inside the reading column
	 * instead of widening it.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_WRAP_TABLES = 'NotionWrapTablesTemporary';

	/**
	 * Inclusion and exclusion rules deciding on which pages the font size may be configured.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_FONT_SIZE_CONFIGURABLE_OPTIONS = 'NotionFontSizeConfigurableOptions';

	/**
	 * Search typeahead configuration: the API endpoints to query and the result options to render.
	 * It is passed through to the search module as ResourceLoader configuration.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_TYPEAHEAD = 'NotionTypeahead';

	/**
	 * Whether the watch and unwatch page actions render as a star icon instead of a text link.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_USE_ICON_WATCH = 'NotionUseIconWatch';

	/**
	 * Inclusion and exclusion rules deciding on which pages the maximum content width applies.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_MAX_WIDTH_OPTIONS = 'NotionMaxWidthOptions';

	/**
	 * Whether the skin emits a viewport meta tag and drops its minimum width, making the layout
	 * responsive down to narrow viewports.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_RESPONSIVE = 'NotionResponsive';

	/**
	 * This class is for namespacing constants only. Forbid construction.
	 * @throws FatalError
	 * @return never
	 */
	private function __construct() {
		throw new FatalError( "Cannot construct a utility class." );
	}
}
