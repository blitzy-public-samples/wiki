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

namespace MediaWiki\Skins\Notion\Tests\Unit\Components;

use MediaWiki\Skins\Notion\Components\NotionComponent;
use MediaWiki\Skins\Notion\Components\NotionComponentLink;
use MediaWiki\Skins\Notion\Components\NotionComponentMenuListItem;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's menu list item component.
 *
 * `NotionComponentMenuListItem` is an adapter rather than a builder, and a parity-only one: it
 * decorates an already-constructed `NotionComponentLink` with the class and the id describing an
 * `<li>` element and returns one flat array. It has no call site in the rendering path -- rendered
 * menus are built from core's own `SkinComponentLink` records, which `MenuListItem.mustache` is
 * written against -- so what these tests protect is the component set's fidelity to the reference
 * skin, not a live template contract. Because the class owns no logic beyond a single array union,
 * the only thing worth locking is the exact shape of that array, which is what the assertions
 * below do instead of merely proving that an array came back.
 *
 * Three properties of the emitted contract are load-bearing:
 *
 *   - The key set is exactly five, and the two groups inside it are kept apart by the `item-`
 *     prefix. `icon`, `text` and `array-attributes` describe the anchor and arrive from the
 *     wrapped link -- they are the skin's canonical link shape, the same one core's portlet
 *     records use; `item-class` and `item-id` describe the surrounding list item and are
 *     contributed here. A key added on either side without a matching template change, or a
 *     key quietly dropped, would surface as an empty hole in a rendered menu rather than as an
 *     error, so it has to fail here instead.
 *   - The link's data is the LEFT operand of the union. PHP's `+` operator is left-biased, so
 *     on a collision the anchor's value wins and an item-level key can never shadow it. The
 *     observable consequence of that choice is key order - the three link keys first, the two
 *     item keys last - which is why the assertions use assertSame(): it compares arrays with
 *     `===`, and that is sensitive to key order and to value types as well as to contents.
 *   - Both `item-class` and `item-id` default to the empty string rather than to null, because
 *     each value is interpolated straight into an attribute. Not because null would print -- a
 *     null interpolation renders as the empty string under LightnCandy, never as the literal word
 *     "null" -- but because a key holding null is resolved as though it were missing, so the
 *     lookup escapes to the enclosing context. A menu row nested in a list that carries its own
 *     `item-id` would then borrow it and emit a duplicate id. The empty string is falsy enough for
 *     the template's guards and present enough to stop the walk.
 *
 * The wrapped link is a real `NotionComponentLink` in every test rather than a test double.
 * That is cheaper than a mock and strictly stronger: it proves the two classes agree on the
 * three key names the union depends on, which a mock would happily fake. It is also safe under
 * `MediaWikiUnitTestCase`, which calls
 * `MediaWikiServices::disallowGlobalInstanceInUnitTests()`: a link constructed without a
 * localizer emits its href and nothing else, so it never reaches
 * `Linker::tooltipAndAccesskeyAttribs()` and no service container is touched. Keep it that way -
 * handing this component a localized link would drag these tests into the container for nothing,
 * since the link's localized paths already have their own coverage in NotionComponentLinkTest.
 *
 * This class extends MediaWikiUnitTestCase directly rather than the skin's
 * NotionComponentSnapshotTestCase, because the component emits five values that are all
 * asserted inline here and therefore owns no JSON fixture.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentMenuListItem
 */
class NotionComponentMenuListItemTest extends MediaWikiUnitTestCase {

	/**
	 * The complete set of keys `getTemplateData()` is allowed to emit, in the order the array
	 * union produces them: the wrapped link's three keys, then the two item keys.
	 */
	private const EXPECTED_TEMPLATE_DATA_KEYS = [
		'icon',
		'text',
		'array-attributes',
		'item-class',
		'item-id',
	];

	/**
	 * The keys this component contributes on top of the wrapped link's own data.
	 */
	private const ITEM_TEMPLATE_DATA_KEYS = [ 'item-class', 'item-id' ];

	/**
	 * A menu list item is a component, so menu-building code can treat it as one.
	 *
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$item = new NotionComponentMenuListItem(
			new NotionComponentLink( '/mock-href', 'Mock Text', 'mock-icon' ),
			'mock-item-class',
			'mock-item-id'
		);

		$this->assertInstanceOf(
			NotionComponent::class,
			$item,
			'A menu list item implements the component interface, which is what lets the '
				. 'skin collect it alongside every other Notion component and render it '
				. 'through getTemplateData() without special-casing it.'
		);
	}

	/**
	 * A fully described item: a link carrying an icon, plus a class and an id for the `<li>`.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateData() {
		$href = '/mock-href';
		$text = 'Mock Text';
		$icon = 'mock-icon';
		$itemClass = 'mock-item-class';
		$itemId = 'mock-item-id';

		$link = new NotionComponentLink( $href, $text, $icon );
		$item = new NotionComponentMenuListItem( $link, $itemClass, $itemId );
		$actual = $item->getTemplateData();

		$this->assertSame(
			[
				'icon' => $icon,
				'text' => $text,
				'array-attributes' => [ [ 'key' => 'href', 'value' => $href ] ],
				'item-class' => $itemClass,
				'item-id' => $itemId,
			],
			$actual,
			'The component emits the wrapped link\'s three keys followed by item-class and '
				. 'item-id, every value carried through verbatim and nothing else added.'
		);
		$this->assertSame(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'The link\'s keys precede the item keys because the link\'s data is the left '
				. 'operand of the union, which is what keeps the anchor\'s data authoritative.'
		);
		$this->assertSame(
			$link->getTemplateData(),
			array_diff_key( $actual, array_fill_keys( self::ITEM_TEMPLATE_DATA_KEYS, null ) ),
			'Removing the two item keys leaves exactly what the link emitted, proving the '
				. 'adapter copies that data through without renaming, reordering or coercing it.'
		);
	}

	/**
	 * An item built from a link alone, relying on both documented defaults.
	 *
	 * This is the shape a menu produces for an ordinary row: most callers have no modifier
	 * class and no id to place on the list item, so they omit both arguments. The defaults are
	 * therefore part of the contract the menu-building code depends on when it wraps
	 * structured portlet items, not an implementation detail, and they are pinned here so a
	 * change to either one cannot pass unnoticed.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataDefaults() {
		$href = '/mock-href';
		$text = 'Mock Text';

		$item = new NotionComponentMenuListItem( new NotionComponentLink( $href, $text ) );
		$actual = $item->getTemplateData();

		$this->assertSame(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'Omitting both optional arguments leaves the key set unchanged: the item keys are '
				. 'always present, so no consumer would have to guard against a missing key.'
		);
		$this->assertSame(
			'',
			$actual['item-class'],
			'item-class defaults to the empty string, which is what lets a caller that has no '
				. 'modifier class to contribute omit the argument and still get valid markup.'
		);
		$this->assertSame(
			'',
			$actual['item-id'],
			'item-id defaults to the empty string for the same reason: the value reaches an '
				. 'id attribute directly, and a null would be looked up as an absent key and '
				. 'resolve against the enclosing context instead.'
		);
		$this->assertNull(
			$actual['icon'],
			'An icon-less link keeps its null icon here rather than gaining an empty string, '
				. 'which is the falsy value a Mustache section skips on.'
		);
		$this->assertSame(
			[ [ 'key' => 'href', 'value' => $href ] ],
			$actual['array-attributes'],
			'A link built without a localizer contributes no attribute beyond its href, and '
				. 'the adapter passes that list through untouched.'
		);
	}
}
