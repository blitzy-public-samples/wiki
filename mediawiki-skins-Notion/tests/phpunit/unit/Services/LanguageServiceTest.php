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

namespace MediaWiki\Skins\Notion\Tests\Unit\Services;

use MediaWiki\Skins\Notion\NotionServices;
use MediaWiki\Skins\Notion\Services\LanguageService;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the language service and the narrow locator that exposes it.
 *
 * `LanguageService::canWordsBeSplitSafely()` answers one question for the search autocomplete
 * widget: may a match be highlighted by slicing the query mid-word? For most languages it may, but
 * for scripts where a base code point is followed by combining marks - Arabic, the Indic scripts,
 * Thaana and others - slicing between the two renders a broken glyph, which is what T281797
 * records. A wrong answer here is a visible rendering defect in the search results and nothing
 * else, so there is no error and no log line to notice it by.
 *
 * The service is deliberately dependency-free, so this is a pure unit test. `NotionServices` is
 * covered alongside it because its single method is what the ResourceLoader configuration callback
 * calls, and a static locator that returned a shared instance would introduce process-lifetime
 * state into a class documented as holding none.
 *
 * @group Notion
 * @coversDefaultClass \MediaWiki\Skins\Notion\Services\LanguageService
 */
class LanguageServiceTest extends MediaWikiUnitTestCase {

	/**
	 * Languages whose words must NOT be split, one per script family in the list.
	 *
	 * These are spot checks rather than a copy of the production array: repeating the whole list
	 * would assert that the file equals itself. Each entry stands for the script family it belongs
	 * to, so a family dropped wholesale from the list fails here.
	 *
	 * @return array[]
	 */
	public static function provideUnsplittableLanguages(): array {
		return [
			'Arabic' => [ 'ar' ],
			'Persian' => [ 'fa' ],
			'Central Kurdish' => [ 'ckb' ],
			'Bengali' => [ 'bn' ],
			'Hindi' => [ 'hi' ],
			'Gujarati' => [ 'gu' ],
			'Punjabi' => [ 'pa' ],
			'Kannada' => [ 'kn' ],
			'Khmer' => [ 'km' ],
			'Malayalam' => [ 'ml' ],
			'Odia' => [ 'or' ],
			'Sinhala' => [ 'si' ],
			'Tamil' => [ 'ta' ],
			'Telugu' => [ 'te' ],
			'Urdu' => [ 'ur' ],
		];
	}

	/**
	 * Languages whose words may be split, including the scripts most wikis are written in.
	 *
	 * @return array[]
	 */
	public static function provideSplittableLanguages(): array {
		return [
			'English' => [ 'en' ],
			'German' => [ 'de' ],
			'French' => [ 'fr' ],
			'Russian' => [ 'ru' ],
			'Greek' => [ 'el' ],
			'Hebrew' => [ 'he' ],
			'Japanese' => [ 'ja' ],
			'Chinese' => [ 'zh' ],
			'Korean' => [ 'ko' ],
			'Thai' => [ 'th' ],
			// A language code that does not exist at all must not be treated as unsplittable
			// either: the default is the permissive one.
			'Unknown code' => [ 'zzz-not-a-language' ],
		];
	}

	/**
	 * @covers ::canWordsBeSplitSafely
	 * @dataProvider provideUnsplittableLanguages
	 * @param string $code Language code.
	 */
	public function testUnsplittableLanguages( string $code ) {
		$this->assertFalse(
			( new LanguageService() )->canWordsBeSplitSafely( $code ),
			"Words in $code carry combining marks, so they must not be split for highlighting."
		);
	}

	/**
	 * @covers ::canWordsBeSplitSafely
	 * @dataProvider provideSplittableLanguages
	 * @param string $code Language code.
	 */
	public function testSplittableLanguages( string $code ) {
		$this->assertTrue(
			( new LanguageService() )->canWordsBeSplitSafely( $code ),
			"Words in $code may be split arbitrarily for highlighting."
		);
	}

	/**
	 * The answer is case-sensitive and exact, because language codes reach it already normalised.
	 *
	 * Asserted so that the lookup is not "improved" into a case-insensitive or prefix match: a
	 * prefix match would sweep in every code beginning with an unsplittable one, and MediaWiki
	 * hands the skin a lower-case code, so neither leniency buys anything.
	 *
	 * @covers ::canWordsBeSplitSafely
	 */
	public function testLookupIsExact() {
		$service = new LanguageService();

		$this->assertFalse( $service->canWordsBeSplitSafely( 'ar' ) );
		$this->assertTrue(
			$service->canWordsBeSplitSafely( 'ar-x-custom' ),
			'A code that merely starts with an unsplittable one is not itself unsplittable.'
		);
		$this->assertTrue(
			$service->canWordsBeSplitSafely( '' ),
			'An empty code is not in the list, so the permissive default applies.'
		);
	}

	/**
	 * The locator hands back a fresh, working service on every call.
	 *
	 * Documented as neither cached nor shared, which this asserts directly: a shared instance
	 * would put process-lifetime state behind a static accessor the ResourceLoader configuration
	 * callback reaches on every request.
	 *
	 * @coversNothing
	 */
	public function testNotionServicesReturnsAFreshLanguageService() {
		$first = NotionServices::getLanguageService();
		$second = NotionServices::getLanguageService();

		$this->assertInstanceOf( LanguageService::class, $first );
		$this->assertNotSame( $first, $second, 'Each call must construct its own instance.' );
		$this->assertFalse( $first->canWordsBeSplitSafely( 'hi' ) );
		$this->assertTrue( $second->canWordsBeSplitSafely( 'en' ) );
	}
}
