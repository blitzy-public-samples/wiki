<?php

/**
 * Service Wirings for the Notion skin
 *
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

use MediaWiki\MediaWikiServices;
use MediaWiki\Skins\Notion\ConfigHelper;

// This file is named by `ServiceWiringFiles` in skin.json, so MediaWiki requires it while building
// the service container for every request that has the skin loaded. It must therefore stay free of
// side effects: it declares instantiator closures and returns them, and nothing here runs until a
// caller actually asks the container for one of these services.
//
// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// @codeCoverageIgnoreStart

/** @phpcs-require-sorted-array */
return [
	// Evaluates the request- and title-sensitive halves of the skin's configuration -- the
	// `$wgNotionMaxWidthOptions` and `$wgNotionFontSizeConfigurableOptions` exclusion sets -- for
	// the feature-management layer. The special-page factory is its only collaborator: it is what
	// lets a configuration entry written as a canonical English name such as `Special:Preferences`
	// still match a request that arrived under a localised alias or a redirect.
	'Notion.ConfigHelper' => static function ( MediaWikiServices $services ): ConfigHelper {
		return new ConfigHelper(
			$services->getSpecialPageFactory()
		);
	},
];

// @codeCoverageIgnoreEnd
