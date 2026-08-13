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

namespace MediaWiki\Skins\Notion\Services;

class LanguageService {
	/**
	 * The list of language codes for those languages that the search autocomplete widget cannot
	 * split a word on a Unicode code point followed by one or many combining marks (also code
	 * points).
	 *
	 * This list was compiled by [@TJones](https://phabricator.wikimedia.org/p/TJones/) as part
	 * of [T281797](https://phabricator.wikimedia.org/T281797).
	 *
	 * @var string[]
	 */
	private $splittableLanguages;

	public function __construct() {
		$this->splittableLanguages = [
			'ar', 'ary', 'arz', 'ckb', 'fa', 'glk', 'ks', 'mzn', 'pnb', 'ps', 'sd', 'skr', 'ug', 'ur',
			'as', 'bn', 'bpy',
			'awa', 'bh', 'dty', 'gom', 'hi', 'ks', 'mai', 'mr', 'ne', 'new', 'pi', 'sa',
			'gu',
			'pa',
			'kn', 'tcy',
			'km',
			'ml',
			'or',
			'si',
			'ta',
			'te',
		];
	}

	/**
	 * Gets whether or not we can split words arbitrarily, for example when highlighting the user's query in the search
	 * autocomplete widget.
	 *
	 * @param string $code
	 * @return bool
	 */
	public function canWordsBeSplitSafely( string $code ): bool {
		return !in_array( $code, $this->splittableLanguages );
	}
}
