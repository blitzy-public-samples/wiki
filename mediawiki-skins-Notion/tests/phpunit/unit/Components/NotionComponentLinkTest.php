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
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @since 1.47
 */

namespace MediaWiki\Skins\Notion\Tests\Unit\Components;

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentLink;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's link primitive.
 *
 * `NotionComponentLink` is the smallest component in the skin: menu rows, footer entries and
 * dropdown items all end up rendering through `Link.mustache` with the data this class emits,
 * so the four keys it returns - `icon`, `text`, `href` and `html-attributes` - are a contract
 * the rest of the skin builds on. The tests below cover all three shapes the component can
 * take, because each one exercises a different arm of the guard in `getTemplateData()`:
 *
 *   - fully described (icon, localizer and access-key hint), the only combination that
 *     produces a non-empty `html-attributes` string;
 *   - bare (href and text only), which is how most links reach
 *     `NotionComponentMenuListItem`, and which must yield `html-attributes` as an empty
 *     string rather than `null` so the template renders no stray attributes;
 *   - localizer present but no access-key hint, where the tooltip must stay suppressed
 *     instead of being derived from an empty hint.
 *
 * The localizer is always a test double. `MediaWikiUnitTestCase` calls
 * `MediaWikiServices::disallowGlobalInstanceInUnitTests()`, so a real `MessageLocalizer`, or
 * a real `Message` built through `wfMessage()`, would trip the "Premature access to service
 * container" guard. The component stays testable in this isolation precisely because it hands
 * its localizer to `Linker::tooltipAndAccesskeyAttribs()` as that method's fourth argument,
 * which is what stops `Linker` from falling back to `RequestContext::getMain()`. Keep it that
 * way: constructing this component with a real localizer, or with a real `Title`, would make
 * these tests depend on a service container that does not exist here.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentLink
 */
class NotionComponentLinkTest extends MediaWikiUnitTestCase {

	/**
	 * The complete set of keys `getTemplateData()` is allowed to emit.
	 *
	 * Asserted on every path so that a key added without a matching template change, or a key
	 * silently dropped, fails here rather than as an empty hole in the rendered page.
	 */
	private const EXPECTED_TEMPLATE_DATA_KEYS = [ 'icon', 'text', 'href', 'html-attributes' ];

	/**
	 * A link described by an icon, a localizer and an access-key hint.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateData() {
		$href = '/mock-link';
		$text = 'Mock Text';
		$icon = 'mock-icon';
		$accessKeyHint = 'sample-accesskey';

		$localizer = $this->createMock( MessageLocalizer::class );
		// Adjusting mock to prevent calling the service container.
		$localizer->method( 'msg' )
			->willReturnCallback( function ( $key ) use ( $accessKeyHint ) {
				// Directly create Message object without accessing real message texts
				// to avoid 'Premature access to service container' error. Every message
				// reports itself as existing and renders as its own key, except the
				// aria-label companion of the access-key hint. That asymmetry is what lets
				// the assertions below prove which message each attribute was derived from,
				// instead of merely proving that some attribute was emitted.
				return $this->createConfiguredMock( Message::class, [
					'exists' => true,
					'text' => $key === $accessKeyHint . '-label' ? 'Mock aria label' : $key,
					'__toString' => 'Mock aria label',
				] );
			} );

		// Create the component
		$linkComponent = new NotionComponentLink( $href, $text, $icon, $localizer, $accessKeyHint );
		$actual = $linkComponent->getTemplateData();

		// Assert the expected values
		$this->assertEqualsCanonicalizing(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'A fully described link emits exactly the keys Link.mustache reads, and nothing more'
		);
		$this->assertSame( $icon, $actual['icon'], 'The icon name is passed through untouched' );
		$this->assertSame( $text, $actual['text'], 'The label is passed through untouched' );
		$this->assertSame( $href, $actual['href'], 'The target is passed through untouched' );

		// `Linker::titleAttrib()` assembles the title from three separate messages when the
		// 'withaccess' option is in play, which `Linker::tooltipAndAccesskeyAttribs()` always
		// adds: the tooltip for the hint, a word separator, and the bracketed access key.
		// The mocked localizer renders each of those as its own key, so the expected title is
		// simply those three keys concatenated. Deriving it here, rather than pasting a
		// pre-computed literal, keeps the expectation readable when the hint changes.
		$expectedTitle = 'tooltip-' . $accessKeyHint . 'word-separator' . 'brackets';
		// Only the '<hint>-label' message resolves to this text, so finding it in the output
		// proves the component asked for 'sample-accesskey-label' specifically.
		$expectedAriaLabel = 'Mock aria label';
		$attributesString = $actual['html-attributes'];

		// Assert that the expected attributes are present in the string. `html-attributes` is
		// a pre-serialised attribute fragment rather than an array, so it is matched by
		// substring; the mocked messages leave `plain()` unstubbed, which means the access key
		// itself resolves to null and `Html::expandAttributes()` legitimately omits it.
		$this->assertStringContainsString(
			'title="' . $expectedTitle . '"',
			$attributesString,
			'The tooltip title is built from the access-key hint via Linker::titleAttrib()'
		);
		$this->assertStringContainsString(
			'aria-label="' . $expectedAriaLabel . '"',
			$attributesString,
			'The aria-label is taken from the "<hint>-label" message when that message exists'
		);
	}

	/**
	 * A bare link: no icon, no localizer and no access-key hint.
	 *
	 * This is the default construction used across the skin's menus, so the empty-string
	 * fallback for `html-attributes` is load-bearing: `Link.mustache` interpolates the value
	 * directly, and `null` would render the literal word "null" into the markup.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataWithoutLocalizer() {
		$href = '/mock-link';
		$text = 'Mock Text';

		$linkComponent = new NotionComponentLink( $href, $text );
		$actual = $linkComponent->getTemplateData();

		$this->assertEqualsCanonicalizing(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'A bare link emits the same key set as a fully described one'
		);
		$this->assertSame( $text, $actual['text'], 'The label is passed through untouched' );
		$this->assertSame( $href, $actual['href'], 'The target is passed through untouched' );
		$this->assertNull(
			$actual['icon'],
			'An omitted icon stays null so the template skips the Icon partial entirely'
		);
		$this->assertSame(
			'',
			$actual['html-attributes'],
			'Without a localizer there is nothing to localise, so the attribute fragment is empty'
		);
	}

	/**
	 * A link with a localizer but no access-key hint.
	 *
	 * Both a localizer and a hint are required before any attribute is emitted, because
	 * there is no message to look up without a hint. Locking this arm of the guard stops a
	 * future change from deriving a meaningless `title="tooltip-"` from an absent hint.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataWithoutAccessKeyHint() {
		$href = '/mock-link';
		$text = 'Mock Text';
		$icon = 'mock-icon';

		// This localizer reports every message as existing, which is the worst case for this
		// path: even so, no attribute may be emitted while the access-key hint is missing.
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )
			->willReturnCallback( function ( $key ) {
				return $this->createConfiguredMock( Message::class, [
					'exists' => true,
					'text' => $key,
					'__toString' => $key,
				] );
			} );

		$linkComponent = new NotionComponentLink( $href, $text, $icon, $localizer );
		$actual = $linkComponent->getTemplateData();

		$this->assertEqualsCanonicalizing(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'Omitting the access-key hint does not change the key set'
		);
		$this->assertSame( $icon, $actual['icon'], 'The icon name is still passed through' );
		$this->assertSame(
			'',
			$actual['html-attributes'],
			'A localizer alone is not enough: without a hint there is no tooltip to build'
		);
	}
}
