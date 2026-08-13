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

use MediaWiki\Skins\Notion\Components\NotionComponent;
use MediaWiki\Skins\Notion\Components\NotionComponentMenu;

/**
 * Snapshot backed unit tests for the Notion skin's menu view model.
 *
 * `NotionComponentMenu` is the single funnel every portlet the skin renders passes through --
 * the main menu portlets, the namespace and action menus in the page toolbar, the page tools,
 * the language variants and each of the user-links menus -- and the array it emits is consumed
 * by four of the skin's templates: `Menu.mustache`, `MenuContents.mustache` and then either
 * `MenuListItem.mustache` or `Button.mustache` per item. That breadth is why the component is
 * worth locking down this precisely: a dropped default key renders as an undefined variable in
 * every menu at once, and a change in the emitted shape is a change to markup that no PHP error
 * would ever reveal.
 *
 * Three properties are asserted here, each with a different technique chosen deliberately:
 *
 *   - The whole emitted array is compared against a checked-in JSON fixture, so that any change
 *     to the template data -- an added key, a renamed key, a reordered class string -- surfaces
 *     as a reviewable diff rather than passing unnoticed. Fixtures live in `__snapshots__` beside
 *     this file and are regenerated, never hand-edited, with
 *     `PHPUNIT_UPDATE_SNAPSHOTS=1 composer phpunit:unit`.
 *   - The style resolution logic is exercised branch by branch: shared styles, an `iconOnly`
 *     button variation, a per-item override that beats the shared styles, and an override of
 *     exactly `false` that removes the item entirely. The removal branch is asserted directly
 *     rather than snapshotted, because "this id is gone" is the whole claim and a fixture would
 *     only obscure it.
 *   - `::count()` is checked in both rendering modes, since callers use it to decide whether a
 *     menu is worth rendering at all and the two modes count completely differently.
 *
 * The component touches no service, no global, no message cache and no storage backend, so it is
 * exercisable in complete isolation; the snapshot base this class extends is a
 * `MediaWikiUnitTestCase`, and nothing here may introduce a dependency that breaks that.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentMenu
 */
class NotionComponentMenuTest extends NotionComponentSnapshotTestCase {

	/**
	 * Two structured menu items in the shape core's portlet data arrives in.
	 *
	 * The records are deliberately minimal but complete: `id` is what per-item style overrides
	 * are keyed by, `class` is what the collapsible class is appended to, and `array-links`
	 * carries the `array-attributes` list of key/value records that the button branch has to
	 * invert back into a map. Both items already carry an icon -- `heart` and `userAdd` -- so
	 * that a test asserting an icon override is genuinely proving the override replaced
	 * something rather than merely filling a hole.
	 *
	 * @var array Menu item template data
	 */
	private static $arrayListItems = [
		[
			'html-item' => '<li><a>link1</a></li>',
			'name' => 'link1',
			'html' => '<a>link1</a>',
			'id' => 'link-1',
			'class' => '',
			'array-links' => [ [
				'icon' => 'heart',
				'array-attributes' => [ [
					'key' => 'href', 'value' => ''
				], [
					'key' => 'class', 'value' => ''
				] ],
				'text' => 'Link1'
			] ]
		],
		[
			'html-item' => '<li><a>link2</a></li>',
			'name' => 'link2',
			'html' => '<a>link2</a>',
			'id' => 'link-2',
			'class' => '',
			'array-links' => [ [
				'icon' => 'userAdd',
				'array-attributes' => [ [
					'key' => 'href', 'value' => ''
				], [
					'key' => 'class', 'value' => ''
				] ],
				'text' => 'Link2'
			] ]
		]
	];

	/**
	 * Both rendering modes and the count each one has to report.
	 *
	 * The HTML case uses three list items on purpose: it is the only way to prove the count
	 * comes from the markup rather than from a hardcoded assumption about the structured
	 * fixture, which has two.
	 *
	 * @return array[]
	 */
	public static function provideCountData(): array {
		return [
			[ [ 'array-list-items' => self::$arrayListItems ], 2 ],
			[ [ 'html-items' => '<li>Some item</li><li>Some item</li><li>Some item</li>' ], 3 ]
		];
	}

	/**
	 * The emitted template data for each way a menu can be constructed.
	 *
	 * The middle case supplies *both* `html-items` and `array-list-items`, which is the
	 * precedence rule made executable: the fixture records `array-list-items` as null, proving
	 * the pre-rendered string wins and that `MenuContents.mustache` cannot emit every item
	 * twice.
	 *
	 * @return array[]
	 */
	public static function provideMenuData(): array {
		return [
			"Initializes data correctly" => [
				'data' => [ 'class' => 'some-class' ],
				'menuItemStyles' => [],
				'menuItemStyleOverrides' => [],
				'expectedData' => 'menu-1.json',
			],
			"Renders with html string" => [
				'data' => [
					'html-items' => '<li><a>link1</a></li><li><a>link2</a></li>',
					'array-list-items' => self::$arrayListItems
				],
				'menuItemStyles' => [],
				'menuItemStyleOverrides' => [],
				'expectedData' => 'menu-2.json',
			],
			"Renders with template data" => [
				'data' => [
					'html-items' => null, 'array-list-items' => self::$arrayListItems
				],
				'menuItemStyles' => [],
				'menuItemStyleOverrides' => [],
				'expectedData' => 'menu-3.json',
			],
		];
	}

	/**
	 * One case per branch of the per-item style resolution.
	 *
	 * "Button styles" applies a shared style set to every item and combines all three shared
	 * options at once -- button, collapsible and an icon override -- so the fixture records the
	 * Codex button payload, the collapsible class on the LI and the replaced icon together.
	 * "Button with iconOnly variation" proves the nested button options are read and that an
	 * absent `icon` key leaves each item's own icon in place. "Overrides applied to specific
	 * items" proves a per-id entry replaces the shared styles wholesale for that item only,
	 * leaving its sibling on the shared styles.
	 *
	 * @return array[]
	 */
	public static function provideUpdateMenuItemStylesData(): array {
		return [
			"Button styles" => [
				'menuItemStyles' => [ 'button' => true, 'collapsible' => true, 'icon' => 'star' ],
				'menuItemStyleOverrides' => [],
				'expectedData' => 'menu-4.json',
			],
			"Button with iconOnly variation" => [
				'menuItemStyles' => [ 'button' => [ 'iconOnly' => true ] ],
				'menuItemStyleOverrides' => [],
				'expectedData' => 'menu-5.json',
			],
			"Overrides applied to specific items" => [
				'menuItemStyles' => [ 'button' => true ],
				'menuItemStyleOverrides' => [
					'link-1' => [ 'button' => false, 'icon' => 'star' ]
				],
				'expectedData' => 'menu-6.json',
			]
		];
	}

	/**
	 * The component satisfies the skin's component contract with no data at all.
	 *
	 * Menus are constructed from portlet data the skin has not inspected, so an empty array has
	 * to be a valid construction rather than a fatal: the interface check is what guarantees
	 * `getTemplateData()` is callable on whatever comes back.
	 *
	 * @covers ::__construct
	 */
	public function testConstruct() {
		// Create a new NotionComponentMenu object
		$menu = new NotionComponentMenu( [] );

		// Check if the object is an instance of NotionComponent
		$this->assertInstanceOf( NotionComponent::class, $menu );
	}

	/**
	 * Counting works in both rendering modes.
	 *
	 * Callers treat a zero count as "do not render this menu", so an undercount silently hides
	 * a populated menu and an overcount emits an empty dropdown.
	 *
	 * @covers ::count
	 * @dataProvider provideCountData
	 */
	public function testCount( array $data, int $expected ) {
		// Create a new NotionComponentMenu object
		$menu = new NotionComponentMenu( $data );

		// Check if the count method returns the correct number of items
		$this->assertSame( $expected, $menu->count() );
	}

	/**
	 * The full emitted array matches its snapshot, defaults included.
	 *
	 * @covers ::getTemplateData
	 * @dataProvider provideMenuData
	 */
	public function testGetTemplateData(
		array $data,
		array $menuItemStyles,
		array $menuItemStyleOverrides,
		string $expectedData
	) {
		// Create a new NotionComponentMenu object
		$menu = new NotionComponentMenu( $data, $menuItemStyles, $menuItemStyleOverrides );

		// Call the getTemplateData method
		$actualData = $menu->getTemplateData();

		// Check if the getTemplateData method returns the correct data
		$this->assertEqualsSnapshot( $expectedData, $actualData );
	}

	/**
	 * Styled items match their snapshot.
	 *
	 * Only `array-list-items` is snapshotted: the surrounding keys are already locked by
	 * ::testGetTemplateData(), and narrowing the fixture to the list keeps a styling regression
	 * pointing at the item that changed instead of at the whole menu.
	 *
	 * @covers ::updateMenuItemStyles
	 * @dataProvider provideUpdateMenuItemStylesData
	 */
	public function testUpdateMenuItemStyles(
		array $menuItemStyles,
		array $menuItemStyleOverrides,
		string $expectedData
	) {
		$data = [
			'html-items' => null,
			'array-list-items' => self::$arrayListItems
		];
		$menu = new NotionComponentMenu( $data, $menuItemStyles, $menuItemStyleOverrides );
		$actualData = $menu->getTemplateData();

		$this->assertEqualsSnapshot( $expectedData, $actualData[ 'array-list-items' ] );
	}

	/**
	 * An override of exactly `false` removes that item and leaves the list a reindexed array.
	 *
	 * This is the one branch of the style resolution that changes the *shape* of the menu rather
	 * than the styling of an item, and it is asserted directly rather than through a fixture
	 * because the claim is an absence: a snapshot would record the surviving item and say
	 * nothing about the one that had to disappear.
	 *
	 * Four things are proven together, and each has its own failure mode:
	 *
	 *   - `false` removes the item, and only that item. A truthiness test in place of the
	 *     identity comparison would also drop the sibling, whose own resolved style set is the
	 *     shared one, and empty the menu.
	 *   - The surviving keys are `[ 0 ]`. Without the reindex the list would encode as a JSON
	 *     object with a gap, which Mustache cannot iterate as a section.
	 *   - `::count()` agrees with the shortened list, so a removed item cannot leave a caller
	 *     rendering a menu that no longer has anything in it.
	 *   - The survivor still carries ::COLLAPSIBLE_CLASS, so removing one item cannot cost its
	 *     siblings the shared styles. The class is read from the constant rather than repeated
	 *     as a literal, which is what keeps this assertion honest if the value ever changes.
	 *
	 * @covers ::__construct
	 * @covers ::count
	 * @covers ::updateMenuItemStyles
	 */
	public function testUpdateMenuItemStylesRemovesItemsStyledFalse() {
		$data = [
			'html-items' => null,
			'array-list-items' => self::$arrayListItems
		];
		// Every item is collapsible by default, and link-1 is removed outright. The shared
		// styles are non-empty on purpose: they are what the surviving item must still receive.
		$menu = new NotionComponentMenu(
			$data,
			[ 'collapsible' => true ],
			[ 'link-1' => false ]
		);
		$items = $menu->getTemplateData()[ 'array-list-items' ];

		$this->assertSame( [ 'link-2' ], array_column( $items, 'id' ),
			'An override of exactly false removes that item, and only that item, from the menu.' );
		$this->assertSame( [ 0 ], array_keys( $items ),
			'The remaining items are reindexed so the list still encodes as a JSON array.' );
		$this->assertSame( 1, $menu->count(),
			'The count reflects the shortened list rather than the list that was passed in.' );
		$this->assertStringContainsString(
			NotionComponentMenu::COLLAPSIBLE_CLASS,
			$items[ 0 ][ 'class' ],
			'The surviving item still receives the shared styles it was not overriding.'
		);
	}
}
