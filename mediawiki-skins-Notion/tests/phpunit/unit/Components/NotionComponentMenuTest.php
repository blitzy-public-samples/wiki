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
use MediaWiki\Skins\Notion\Components\NotionComponentMenu;

/**
 * Snapshot backed unit tests for the Notion skin's menu view model.
 *
 * `NotionComponentMenu` is the funnel the skin's menu portlets pass through -- the main menu
 * portlets, the namespace and action menus in the page toolbar, the page tools, the language
 * variants and each of the user-links menus -- and the array it emits is consumed by four of the
 * skin's templates: `Menu.mustache`, `MenuContents.mustache` and then either `MenuListItem.mustache`
 * or `Button.mustache` per item. It is not every portlet the skin renders: the three footer
 * portlets (`data-footer-info`, `data-footer-places` and `data-footer-icons`) are handed straight to
 * `Footer__row.mustache` from `Footer.mustache` and never reach this component at all. The breadth
 * it does have is why the component is worth locking down this precisely: a dropped default key
 * renders as an undefined variable in every menu at once, and a change in the emitted shape is a
 * change to markup that no PHP error would ever reveal.
 *
 * Three properties are asserted here, each with a different technique chosen deliberately:
 *
 *   - The whole emitted array is compared against a checked-in JSON fixture, so that any change
 *     to the template data -- an added key, a renamed key, a reordered class string -- surfaces
 *     as a reviewable diff rather than passing unnoticed. Fixtures live in `__snapshots__` beside
 *     this file and are regenerated, never hand-edited, with
 *     `PHPUNIT_UPDATE_SNAPSHOTS=1 composer phpunit:unit`. A fixture on its own only proves that
 *     nothing changed since it was recorded, never that what it recorded was right in the first
 *     place, so every named behaviour below is *also* asserted inline against the emitted array.
 *     The two are complementary and both are required: the inline assertions state what the
 *     component is supposed to do, and the fixture catches the incidental keys nobody thought
 *     to assert.
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
	 * The keys the constructor guarantees on every menu, whatever the caller passed.
	 *
	 * `Menu.mustache` and `MenuContents.mustache` interpolate all eight without a guard, so an
	 * absent one is an undefined-variable render rather than an empty string. The list is
	 * asserted as a set of required keys rather than as an exact key list, because callers
	 * legitimately pass portlet keys of their own -- `id`, `html-item`, `name` -- straight
	 * through, and the fixtures are what lock those down.
	 */
	private const EXPECTED_DEFAULT_KEYS = [
		'class',
		'label',
		'html-tooltip',
		'label-class',
		'html-before-portal',
		'html-items',
		'html-after-portal',
		'array-list-items',
	];

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
	 * The remaining cases close the branches the two originals leave open. An empty menu must
	 * report zero in either mode, since callers read a zero count as "do not render this menu"
	 * and would otherwise emit an empty dropdown. The two mixed cases carry a structured list
	 * and an HTML string whose item counts differ *on purpose*: the constructor nulls out
	 * `array-list-items` whenever `html-items` is non-empty, so the answer must come from the
	 * markup, and equal counts could not tell the two sources apart. `<li` is the needle rather
	 * than `<li>` so that an item carrying attributes still counts.
	 *
	 * @return array[]
	 */
	public static function provideCountData(): array {
		return [
			'Structured items' => [ [ 'array-list-items' => self::$arrayListItems ], 2 ],
			'Pre-rendered HTML items' => [
				[ 'html-items' => '<li>Some item</li><li>Some item</li><li>Some item</li>' ], 3
			],
			'No data at all' => [ [], 0 ],
			'Empty structured list' => [ [ 'array-list-items' => [] ], 0 ],
			'Empty HTML string' => [ [ 'html-items' => '' ], 0 ],
			'HTML wins over a longer structured list' => [
				[
					'html-items' => '<li>Only item</li>',
					'array-list-items' => self::$arrayListItems
				],
				1
			],
			'HTML wins over a shorter structured list' => [
				[
					'html-items' => '<li class="a">1</li><li id="b">2</li><li>3</li>',
					'array-list-items' => [ self::$arrayListItems[0] ]
				],
				3
			]
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
				// One expectation record per item, in list order. `button` states whether the
				// link record must have been replaced by a `data-button` payload, `icon` the
				// glyph that must have survived or replaced the item's own, and `collapsible`
				// whether the LI must carry the collapsible class.
				'expectedItems' => [
					[
						'label' => 'Link1',
						'button' => true,
						'icon' => 'star',
						'class' => 'cdx-button cdx-button--weight-quiet',
						'collapsible' => true,
					],
					[
						'label' => 'Link2',
						'button' => true,
						'icon' => 'star',
						'class' => 'cdx-button cdx-button--weight-quiet',
						'collapsible' => true,
					],
				],
			],
			"Button with iconOnly variation" => [
				'menuItemStyles' => [ 'button' => [ 'iconOnly' => true ] ],
				'menuItemStyleOverrides' => [],
				'expectedData' => 'menu-5.json',
				// No `icon` key in the style set, so each item keeps the glyph it arrived with:
				// `array_key_exists` is what distinguishes "not specified" from "specified as
				// null", and this case is what proves the distinction is honoured.
				'expectedItems' => [
					[
						'label' => 'Link1',
						'button' => true,
						'icon' => 'heart',
						'class' => 'cdx-button cdx-button--weight-quiet cdx-button--icon-only',
						'collapsible' => false,
					],
					[
						'label' => 'Link2',
						'button' => true,
						'icon' => 'userAdd',
						'class' => 'cdx-button cdx-button--weight-quiet cdx-button--icon-only',
						'collapsible' => false,
					],
				],
			],
			"Overrides applied to specific items" => [
				'menuItemStyles' => [ 'button' => true ],
				'menuItemStyleOverrides' => [
					'link-1' => [ 'button' => false, 'icon' => 'star' ]
				],
				'expectedData' => 'menu-6.json',
				// The override replaces the shared style set wholesale for link-1 only: it is
				// left a plain link with a replaced icon, while its sibling still becomes a
				// button from the shared styles.
				'expectedItems' => [
					[
						'label' => 'Link1',
						'button' => false,
						'icon' => 'star',
						'class' => null,
						'collapsible' => false,
					],
					[
						'label' => 'Link2',
						'button' => true,
						'icon' => 'userAdd',
						'class' => 'cdx-button cdx-button--weight-quiet',
						'collapsible' => false,
					],
				],
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

		// Every key the menu templates read unconditionally is present, whether or not the
		// caller supplied it. A missing one renders as an undefined variable in every menu the
		// skin emits, which is why this is asserted for all three constructions rather than
		// left to the fixture of one of them.
		foreach ( self::EXPECTED_DEFAULT_KEYS as $key ) {
			$this->assertArrayHasKey(
				$key,
				$actualData,
				"MenuContents.mustache reads $key unconditionally, so it must always be present."
			);
		}

		// Caller data wins over the defaults: `+=` may only fill gaps.
		foreach ( $data as $key => $value ) {
			if ( $key === 'array-list-items' ) {
				// Deliberately excluded: this is the one key the constructor is *allowed* to
				// rewrite, and the two assertions below state exactly when and to what.
				continue;
			}
			$this->assertSame(
				$value,
				$actualData[$key],
				"The caller's own $key must survive the defaults instead of being overwritten."
			);
		}

		// The two rendering modes are mutually exclusive, and the pre-rendered string wins.
		// Without this, `MenuContents.mustache` -- which renders `html-items` and iterates
		// `array-list-items` into the same `<ul>` -- would emit every item twice.
		if ( $actualData['html-items'] ) {
			$this->assertNull(
				$actualData['array-list-items'],
				'A menu rendering pre-rendered HTML must have no structured list left to render.'
			);
		} else {
			$this->assertSame(
				$data['array-list-items'] ?? null,
				$actualData['array-list-items'],
				'Without an HTML string the structured list is what reaches the template.'
			);
		}
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
		string $expectedData,
		array $expectedItems
	) {
		$data = [
			'html-items' => null,
			'array-list-items' => self::$arrayListItems
		];
		$menu = new NotionComponentMenu( $data, $menuItemStyles, $menuItemStyleOverrides );
		$actualData = $menu->getTemplateData();
		$items = $actualData[ 'array-list-items' ];

		$this->assertEqualsSnapshot( $expectedData, $items );

		// The fixture above records what the component emitted; the assertions below state what
		// it was supposed to emit. Both are needed: a fixture co-authored with the component
		// would happily record wrong output as the expectation, and only these can fail when
		// the styling logic itself is wrong.
		$this->assertSameSize(
			$expectedItems,
			$items,
			'Styling must not add or drop items unless an override removes one.'
		);

		foreach ( $expectedItems as $index => $expected ) {
			$item = $items[ $index ];
			$link = $item[ 'array-links' ][ 0 ];
			$context = 'item ' . $index . ' (' . $item[ 'id' ] . ')';

			if ( $expected[ 'button' ] ) {
				// A requested button replaces the whole link record with a single
				// `data-button` key, which is how MenuListItem.mustache decides to include
				// Button.mustache in place of Link.mustache.
				$this->assertSame(
					[ 'data-button' ],
					array_keys( $link ),
					"$context must be replaced by a data-button payload, not decorated with one."
				);
				$button = $link[ 'data-button' ];
				$this->assertSame( $expected[ 'label' ], $button[ 'label' ],
					"$context must keep the link text as the button label." );
				$this->assertSame( $expected[ 'icon' ], $button[ 'icon' ],
					"$context must carry the icon the resolved style set asked for." );
				$this->assertSame( $expected[ 'class' ], $button[ 'class' ],
					"$context must be composed from exactly the Codex classes its options imply." );
				// `href`, `class` and `id` are handled by the button's own parameters, so they
				// must not be repeated in the attribute list the template iterates.
				foreach ( $button[ 'array-attributes' ] as $attribute ) {
					$this->assertNotContains(
						$attribute[ 'key' ],
						[ 'href', 'class', 'id' ],
						"$context must not emit $attribute[key] twice."
					);
				}
			} else {
				// Not a button: the record stays the core portlet link shape the Link partial
				// renders, with only the icon substituted.
				$this->assertArrayNotHasKey( 'data-button', $link,
					"$context must stay a plain link when its style set asks for no button." );
				$this->assertSame( $expected[ 'label' ], $link[ 'text' ],
					"$context must keep its label untouched." );
				$this->assertSame( $expected[ 'icon' ], $link[ 'icon' ],
					"$context must carry the icon the resolved style set asked for." );
				$this->assertSame(
					self::$arrayListItems[ $index ][ 'array-links' ][ 0 ][ 'array-attributes' ],
					$link[ 'array-attributes' ],
					"$context must forward core's own attribute records unchanged."
				);
			}

			if ( $expected[ 'collapsible' ] ) {
				$this->assertStringContainsString(
					NotionComponentMenu::COLLAPSIBLE_CLASS,
					$item[ 'class' ],
					"$context must carry the collapsible class on its list item, not its link."
				);
			} else {
				$this->assertStringNotContainsString(
					NotionComponentMenu::COLLAPSIBLE_CLASS,
					$item[ 'class' ],
					"$context must not be marked collapsible when no style set asked for it."
				);
			}
		}
	}

	/**
	 * An icon is suppressed by an explicit null or false, not merely by omission.
	 *
	 * The distinction is the reason ::updateMenuItemStyles() reaches for `array_key_exists()`
	 * instead of `??`: omitting `icon` means "leave the item's own glyph alone", while passing
	 * `null` or `false` means "this menu shows no glyphs". A `??` would collapse the second into
	 * the first and quietly restore every icon a caller had asked to remove.
	 *
	 * @covers ::updateMenuItemStyles
	 */
	public function testIconIsSuppressedByAnExplicitNullOrFalse() {
		$data = [ 'html-items' => null, 'array-list-items' => self::$arrayListItems ];

		foreach ( [ 'null' => null, 'false' => false ] as $label => $suppressed ) {
			$menu = new NotionComponentMenu( $data, [ 'icon' => $suppressed ] );
			$items = $menu->getTemplateData()[ 'array-list-items' ];

			$this->assertSame(
				[ $suppressed, $suppressed ],
				array_map( static fn ( $item ) => $item[ 'array-links' ][ 0 ][ 'icon' ], $items ),
				"An icon of $label must replace each item's own glyph rather than be ignored."
			);
		}

		// The control case: the same menu with no `icon` key keeps both original glyphs, which
		// is what makes the two assertions above meaningful rather than vacuous.
		$menu = new NotionComponentMenu( $data, [ 'collapsible' => true ] );
		$items = $menu->getTemplateData()[ 'array-list-items' ];
		$this->assertSame(
			[ 'heart', 'userAdd' ],
			array_map( static fn ( $item ) => $item[ 'array-links' ][ 0 ][ 'icon' ], $items ),
			'Omitting the icon key must leave each item with the glyph it arrived with.'
		);
	}

	/**
	 * The button action is read from the nested button options and reaches the Codex class list.
	 *
	 * `action` is the one button option with a visual consequence beyond layout -- it is what
	 * makes "add topic" the single progressive control in the skin's chrome -- so a style set
	 * that fails to propagate it renders a neutral button where a progressive one was asked for,
	 * with no error anywhere.
	 *
	 * @covers ::updateMenuItemStyles
	 */
	public function testButtonActionAndWeightReachTheClassList() {
		$data = [ 'html-items' => null, 'array-list-items' => self::$arrayListItems ];
		$menu = new NotionComponentMenu(
			$data,
			[ 'button' => [ 'action' => 'progressive' ] ]
		);
		$items = $menu->getTemplateData()[ 'array-list-items' ];

		foreach ( $items as $item ) {
			$class = $item[ 'array-links' ][ 0 ][ 'data-button' ][ 'class' ];
			$this->assertSame(
				'cdx-button cdx-button--weight-quiet cdx-button--action-progressive',
				$class,
				'A progressive action must reach the button on top of the quiet menu weight.'
			);
		}

		// A destructive action travels the same path, and an unknown one is normalised away by
		// the button component rather than emitted as an invented Codex modifier.
		$menu = new NotionComponentMenu( $data, [ 'button' => [ 'action' => 'destructive' ] ] );
		$items = $menu->getTemplateData()[ 'array-list-items' ];
		$this->assertStringContainsString(
			'cdx-button--action-destructive',
			$items[ 0 ][ 'array-links' ][ 0 ][ 'data-button' ][ 'class' ]
		);

		$menu = new NotionComponentMenu( $data, [ 'button' => [ 'action' => 'not-a-codex-action' ] ] );
		$items = $menu->getTemplateData()[ 'array-list-items' ];
		$this->assertSame(
			'cdx-button cdx-button--weight-quiet',
			$items[ 0 ][ 'array-links' ][ 0 ][ 'data-button' ][ 'class' ],
			'An action Codex does not define must be normalised away, not emitted verbatim.'
		);
	}

	/**
	 * A custom class lands on the list item, and an override's class beats the shared one.
	 *
	 * `class` is the single style option that does *not* follow the all-or-nothing precedence
	 * the rest of the style set follows: an item with its own override still falls back to the
	 * shared class when the override does not name one. That exception is what lets a caller add
	 * one class to every row while restyling a single row, so it is asserted in all three
	 * arrangements rather than left to a fixture.
	 *
	 * @covers ::updateMenuItemStyles
	 */
	public function testCustomClassPrecedence() {
		$data = [ 'html-items' => null, 'array-list-items' => self::$arrayListItems ];

		// Shared class only: every item gets it.
		$menu = new NotionComponentMenu( $data, [ 'class' => 'notion-shared-row' ] );
		$items = $menu->getTemplateData()[ 'array-list-items' ];
		$this->assertSame( [ 'notion-shared-row', 'notion-shared-row' ],
			array_column( $items, 'class' ),
			'A shared class reaches every list item.' );

		// An override naming its own class wins for that item alone.
		$menu = new NotionComponentMenu(
			$data,
			[ 'class' => 'notion-shared-row' ],
			[ 'link-1' => [ 'class' => 'notion-own-row' ] ]
		);
		$items = $menu->getTemplateData()[ 'array-list-items' ];
		$this->assertSame( [ 'notion-own-row', 'notion-shared-row' ],
			array_column( $items, 'class' ),
			'An override class replaces the shared one for its own item only.' );

		// An override that names no class still receives the shared one: the documented
		// exception to the otherwise all-or-nothing precedence.
		$menu = new NotionComponentMenu(
			$data,
			[ 'class' => 'notion-shared-row', 'collapsible' => true ],
			[ 'link-1' => [ 'collapsible' => false ] ]
		);
		$items = $menu->getTemplateData()[ 'array-list-items' ];
		$this->assertSame( 'notion-shared-row', $items[ 0 ][ 'class' ],
			'An override without a class of its own still falls back to the shared class.' );
		$this->assertStringNotContainsString(
			NotionComponentMenu::COLLAPSIBLE_CLASS,
			$items[ 0 ][ 'class' ],
			'The override replaced the shared style set, so the item is no longer collapsible.'
		);
		$this->assertStringContainsString(
			NotionComponentMenu::COLLAPSIBLE_CLASS,
			$items[ 1 ][ 'class' ],
			'Its sibling is still on the shared styles and stays collapsible.'
		);
	}

	/**
	 * An item without links, and an empty style set, both survive styling intact.
	 *
	 * Portlet items arrive from core rather than from this skin, and a heading-only item with no
	 * `array-links` is a legitimate shape. Iterating a missing key would raise a warning and
	 * emit an item the template cannot render, so the key is defaulted to an empty list. The
	 * empty override is the companion claim: `[]` is a style set that asks for nothing, and only
	 * exactly `false` removes an item, so an item overridden with `[]` must stay.
	 *
	 * @covers ::__construct
	 * @covers ::count
	 * @covers ::updateMenuItemStyles
	 */
	public function testItemWithoutLinksAndEmptyOverrideSurvive() {
		$data = [
			'html-items' => null,
			'array-list-items' => [
				[ 'id' => 'heading-only', 'class' => 'notion-menu-heading' ],
				self::$arrayListItems[ 1 ],
			]
		];
		$menu = new NotionComponentMenu(
			$data,
			[ 'collapsible' => true ],
			[ 'heading-only' => [] ]
		);
		$items = $menu->getTemplateData()[ 'array-list-items' ];

		$this->assertSame( [ 'heading-only', 'link-2' ], array_column( $items, 'id' ),
			'An empty style set is still a style set, so the item it applies to must remain.' );
		$this->assertSame( 2, $menu->count(),
			'The count must agree with the list the template will iterate.' );
		$this->assertSame( [], $items[ 0 ][ 'array-links' ],
			'An item that arrived without links must be given an empty list to iterate.' );
		$this->assertSame( 'notion-menu-heading', $items[ 0 ][ 'class' ],
			'An empty override adds nothing at all, not even the shared collapsible class.' );
		$this->assertStringContainsString(
			NotionComponentMenu::COLLAPSIBLE_CLASS,
			$items[ 1 ][ 'class' ],
			'The item left on the shared styles is still collapsible.'
		);
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
