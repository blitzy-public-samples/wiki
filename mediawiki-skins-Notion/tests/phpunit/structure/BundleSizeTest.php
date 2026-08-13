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
 * @since 1.47
 */

namespace MediaWiki\Skins\Notion\Tests\Structure;

use MediaWiki\Skins\Notion\Constants;

/**
 * Asserts the byte budgets in the skin's own bundlesize.config.json against real ResourceLoader
 * output, so that an oversized stylesheet is a build-breaking change rather than a silent
 * regression.
 *
 * Both methods below are overrides, and the second one is the whole reason this class is not a
 * bare subclass: `BundleSizeTestBase::testBundleSize()` builds a `FauxRequest` whose `skin`
 * parameter comes from `getSkinName()`, and the base implementation returns
 * `$wgDefaultSkin`. This project deliberately keeps that default at `vector-2022` so Vector stays
 * the shipped default and the "before" capture baseline, which means an un-overridden gate would
 * compile every `skins.notion.*` module under ResourceLoader context `skin=vector-2022`. That
 * context never pushes this skin's `SkinLessImportPaths` entry onto the Less import path, so the
 * modules would resolve `mediawiki.skin.variables.less` to core's neutral fallback, silently drop
 * the entire Notion token layer, and report byte counts for a compilation nobody ever ships.
 */
class BundleSizeTest extends \MediaWiki\Tests\Structure\BundleSizeTestBase {

	/**
	 * Skin id this skin's own modules must be measured under.
	 *
	 * The inherited implementation returns the wiki's configured `DefaultSkin`, which this
	 * project deliberately keeps at `vector-2022` so that Vector remains the comparison
	 * baseline. That default is right for the application and wrong for this test: the skin
	 * name reaches ResourceLoader as the `skin` parameter of the request the base class builds,
	 * and `MediaWiki\ResourceLoader\FileModule::compileLessString()` selects the Less import
	 * directory from `SkinLessImportPaths[ $context->getSkin() ]`. Measured under `vector-2022`,
	 * every `@import 'mediawiki.skin.variables.less'` in this skin's stylesheets would resolve
	 * to Vector's token layer rather than to
	 * `resources/mediawiki.less/notion/mediawiki.skin.variables.less`, so the recorded byte
	 * budget would describe a stylesheet this skin never ships. The same substitution also
	 * decides which `ResourceModuleSkinStyles` entries are folded into a module's response.
	 *
	 * Overriding it changes the test's ResourceLoader context only. Nothing here alters
	 * `$wgDefaultSkin`, and no tracked configuration file sets this skin as the wiki default.
	 *
	 * The value is taken from the shared constant rather than written as a literal, so it stays in
	 * step with the `ValidSkinNames` key, the `args.name` option and the `SkinLessImportPaths` key,
	 * all of which must be the same lower-case identifier.
	 *
	 * @return string
	 */
	public function getSkinName(): string {
		return Constants::SKIN_NAME;
	}

	/** @inheritDoc */
	public static function getBundleSizeConfigData(): string {
		return dirname( __DIR__, 3 ) . '/bundlesize.config.json';
	}
}
