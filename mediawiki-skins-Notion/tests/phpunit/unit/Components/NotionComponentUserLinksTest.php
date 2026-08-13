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

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentMenu;
use MediaWiki\Skins\Notion\Components\NotionComponentUserLinks;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's personal-tools component.
 *
 * `NotionComponentUserLinks` assembles five menus plus the dropdown that carries the complete user
 * menu on narrow viewports, and almost everything it does is conditional on the kind of viewer:
 * anonymous, temporary or registered. Those conditions are what this test covers, one property per
 * test, with the viewer kind supplied through data providers rather than assumed:
 *
 *  1. The dropdown's class list, including the two collapsing states. The dropdown is hidden on
 *     wide viewports when an anonymous viewer's menu holds nothing beyond the account actions, and
 *     hidden outright when the menu is empty because login is impossible — the `--none` suffix.
 *     Both are expressed purely as class names so the behaviour survives with JavaScript disabled,
 *     which is exactly why they are worth asserting here rather than in a browser.
 *  2. Which user-menu items are promoted into the overflow menu, and that a temporary account is
 *     not offered the account actions a second time. This is the only reason `UserNameUtils` is
 *     injected at all.
 *  3. That every overflow copy's id gains the `-2` suffix. Without it the rendered document would
 *     carry duplicate ids, and the per-item overrides — which are keyed on the suffixed ids — would
 *     silently miss their targets.
 *  4. The per-item styling of the overflow menu: icon-only buttons for a registered viewer, plain
 *     buttons for an anonymous one, and neither for the account actions or the donate link
 *     (T425721), which are rendered as collapsible links with no icon.
 *  5. `emptyPortlet` on each of the four secondary menus, which is what stops an absent Echo or a
 *     viewer with no personal page from leaving empty chrome the stylesheets would still lay out.
 *  6. `is-wide`, which is true if and only if at least one of the four secondary menus has content.
 *  7. That core's identifiers are reproduced verbatim — `p-personal`, the `pt-*` item ids,
 *     `mw-portlet`, `emptyPortlet` — since gadgets, extensions and `mw.util.addPortletLink()`
 *     target them, while the ids this skin invents carry the `notion-` prefix.
 *
 * One branch of `::getDropdown()` is deliberately not covered here: when core supplies no personal
 * page icon the component falls back to `Linker::tooltip()`, which resolves a message against the
 * main request context and so cannot run under `MediaWikiUnitTestCase`. It is covered in
 * `tests/phpunit/integration/Components/NotionComponentUserLinksTest.php` instead, against the real
 * service container, rather than left untested or forced into a unit test with a global stub.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentUserLinks
 */
class NotionComponentUserLinksTest extends MediaWikiUnitTestCase {

	/**
	 * A non-empty personal-page icon, which is what keeps every test in this class inside the
	 * unit-test boundary: ::getDropdown() only reaches Linker when the icon is an empty string.
	 */
	private const ICON = 'testAvatar';

	/** Name reported for the temporary account, in core's `~` form. */
	private const TEMP_USER_NAME = '~2026-1';

	/**
	 * The item class that marks a duplicated row as collapsible.
	 *
	 * Written out as a literal rather than read from `NotionComponentMenu::COLLAPSIBLE_CLASS`,
	 * because the value is a contract with `resources/skins.notion.styles/components/Menu.less` and
	 * borrowing the constant would make the assertions agree with production by construction. The
	 * test below pins the two together, so a rename is reported once, at its cause.
	 */
	private const COLLAPSIBLE_ITEM_CLASS = 'notion-menu-item--collapsible';

	/**
	 * A portlet item in the shape `SkinTemplate` produces.
	 *
	 * @param string $name core's item name, which decides overflow promotion
	 * @param string|null $icon
	 * @return array
	 */
	private static function item( string $name, ?string $icon = 'placeholder' ): array {
		return [
			'name' => $name,
			'id' => 'pt-' . $name,
			'class' => 'mw-list-item',
			'array-links' => [
				[
					'icon' => $icon,
					'array-attributes' => [
						[ 'key' => 'href', 'value' => '/' . $name ],
					],
					'text' => ucfirst( $name ),
				],
			],
		];
	}

	/**
	 * @param array $items
	 * @return array portlet data in core's shape
	 */
	private static function portlet( array $items ): array {
		return [
			'id' => 'p-portlet',
			'class' => '',
			'html-tooltip' => '',
			'array-items' => $items,
		];
	}

	/**
	 * @param string $kind one of `anon`, `temp` or `registered`
	 * @param array $portletData
	 * @param string $icon
	 * @return array template data
	 */
	private function buildTemplateData(
		string $kind, array $portletData, string $icon = self::ICON
	): array {
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback( function ( $key, ...$params ) {
			$msg = $this->createMock( Message::class );
			$msg->method( '__toString' )->willReturn( $key );
			$msg->method( 'escaped' )->willReturn( $key );
			$msg->method( 'rawParams' )->willReturnSelf();
			$msg->method( 'text' )->willReturn( $key );
			return $msg;
		} );

		$user = $this->createMock( UserIdentity::class );
		$user->method( 'isRegistered' )->willReturn( $kind !== 'anon' );
		$user->method( 'getName' )->willReturn(
			$kind === 'temp' ? self::TEMP_USER_NAME : ( $kind === 'anon' ? '127.0.0.1' : 'Admin' )
		);

		$userNameUtils = $this->createMock( UserNameUtils::class );
		// Asserted through `with()` rather than merely stubbed: the component must ask about the
		// viewer it was given, since asking about anybody else would answer the wrong question.
		$userNameUtils->method( 'isTemp' )
			->with( $user->getName() )
			->willReturn( $kind === 'temp' );

		return ( new NotionComponentUserLinks(
			$localizer, $user, $userNameUtils, $portletData, $icon
		) )->getTemplateData();
	}

	/**
	 * The collapsible marker this test asserts on is the one the menu component applies.
	 *
	 * Both halves matter: the stylesheet keys off the literal, and this component's collapsing
	 * behaviour is expressed by asking `NotionComponentMenu` for it. Pinning them together here
	 * means a rename fails this one assertion with an explanation, instead of failing four
	 * behavioural assertions that would each look like a lost feature.
	 *
	 * @coversNothing
	 */
	public function testCollapsibleMarkerMatchesTheMenuComponentsOwnClass() {
		$this->assertSame(
			self::COLLAPSIBLE_ITEM_CLASS,
			NotionComponentMenu::COLLAPSIBLE_CLASS,
			'components/Menu.less selects on this class name; renaming it in one place only would '
				. 'leave the collapsing behaviour styled by nothing.'
		);
	}

	/**
	 * @covers ::getTemplateData
	 */
	public function testEmittedStructureIsExactlyTheTemplateKeysAndIsPlainData() {
		$data = $this->buildTemplateData( 'registered', [
			'data-user-menu' => self::portlet( [ self::item( 'watchlist' ) ] ),
		] );

		$this->assertSame(
			[
				'is-wide',
				'data-user-links-notifications',
				'data-user-links-overflow',
				'data-user-links-preferences',
				'data-user-links-user-page',
				'data-user-links-dropdown',
				'data-user-links-menus',
			],
			array_keys( $data ),
			'UserLinks.mustache reads exactly these keys.'
		);
		// ::getMenus() is the one internal seam that hands back components, and ::getTemplateData()
		// must resolve them: Mustache cannot traverse an object and the component snapshots are
		// JSON, so a component leaking through here would be a rendering failure.
		$this->assertSame(
			json_decode( json_encode( $data, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR ),
			$data,
			'The whole structure must survive a JSON round trip unchanged.'
		);
	}

	/**
	 * The dropdown's class list, per viewer and per menu size.
	 *
	 * @return array
	 */
	public static function provideDropdownClasses(): array {
		$account = [ self::item( 'login' ), self::item( 'createaccount' ), self::item( 'login-private' ) ];

		return [
			'anonymous viewer with only the account actions: hidden when wide' => [
				'anon',
				$account,
				'notion-user-menu notion-button-flush-right notion-user-menu-logged-out'
					. ' notion-user-links-dropdown--collapsible',
			],
			'anonymous viewer whose menu is empty: hidden outright' => [
				'anon',
				[],
				'notion-user-menu notion-button-flush-right notion-user-menu-logged-out'
					. ' notion-user-links-dropdown--collapsible--none',
			],
			'anonymous viewer with a fourth item: always shown' => [
				'anon',
				array_merge( $account, [ self::item( 'sitesupport' ) ] ),
				'notion-user-menu notion-button-flush-right notion-user-menu-logged-out',
			],
			'registered viewer: never collapsed, however few items' => [
				'registered',
				[ self::item( 'watchlist' ) ],
				'notion-user-menu notion-button-flush-right notion-user-menu-logged-in',
			],
			'registered viewer with an empty menu: still never collapsed' => [
				'registered',
				[],
				'notion-user-menu notion-button-flush-right notion-user-menu-logged-in',
			],
			'temporary account is treated as registered for the dropdown' => [
				'temp',
				[ self::item( 'watchlist' ) ],
				'notion-user-menu notion-button-flush-right notion-user-menu-logged-in',
			],
		];
	}

	/**
	 * @dataProvider provideDropdownClasses
	 * @covers ::getDropdown
	 * @param string $kind
	 * @param array $userMenuItems
	 * @param string $expectedClass
	 */
	public function testDropdownClassExpressesCollapsingWithoutJavaScript(
		string $kind, array $userMenuItems, string $expectedClass
	) {
		$dropdown = $this->buildTemplateData( $kind, [
			'data-user-menu' => self::portlet( $userMenuItems ),
		] )['data-user-links-dropdown'];

		$this->assertSame( $expectedClass, $dropdown['class'],
			'`components/UserLinks.less` selects on these class names, so collapsing must be '
				. 'expressed here and nowhere else if it is to work with JavaScript disabled.' );
		$this->assertSame( 'notion-user-links-dropdown', $dropdown['id'],
			'The container id is this skin\'s own and carries the notion- prefix.' );
		$this->assertSame( self::ICON, $dropdown['icon'],
			'A personal-page icon core supplied must be forwarded, not replaced by the fallback.' );
		$this->assertSame( ' title="notion-personal-tools-tooltip"', $dropdown['html-tooltip'],
			'The tooltip is this skin\'s own message, expanded into an attribute string.' );
		$this->assertSame( 'personaltools', $dropdown['label'],
			'The visible label is core\'s own `personaltools` message, never renamed.' );
	}

	/**
	 * Which user-menu items are promoted into the overflow menu.
	 *
	 * @return array
	 */
	public static function provideOverflowPromotion(): array {
		$menu = [
			self::item( 'watchlist' ),
			self::item( 'readinglists' ),
			self::item( 'sitesupport' ),
			self::item( 'login' ),
			self::item( 'createaccount' ),
			self::item( 'mycontris' ),
			self::item( 'preferences' ),
		];

		return [
			'anonymous viewer keeps the account actions in overflow' => [
				'anon',
				$menu,
				[ 'pt-readinglists-2', 'pt-watchlist-2', 'pt-sitesupport-2', 'pt-login-2', 'pt-createaccount-2' ],
			],
			'registered viewer keeps them too' => [
				'registered',
				$menu,
				[ 'pt-readinglists-2', 'pt-watchlist-2', 'pt-sitesupport-2', 'pt-login-2', 'pt-createaccount-2' ],
			],
			'temporary account is not offered the account actions again' => [
				'temp',
				$menu,
				[ 'pt-readinglists-2', 'pt-watchlist-2', 'pt-sitesupport-2' ],
			],
		];
	}

	/**
	 * @dataProvider provideOverflowPromotion
	 * @covers ::getOverflowKeys
	 * @covers ::getTemplateData
	 * @param string $kind
	 * @param array $userMenuItems
	 * @param string[] $expectedIds
	 */
	public function testOverflowPromotionAndTheDuplicateIdSuffix(
		string $kind, array $userMenuItems, array $expectedIds
	) {
		$data = $this->buildTemplateData( $kind, [
			'data-user-menu' => self::portlet( $userMenuItems ),
		] );
		$overflow = $data['data-user-links-overflow'];
		$ids = array_column( $overflow['array-list-items'], 'id' );

		// Order is the user menu's own; the expectations are written in the order core would have
		// produced, so comparing sorted lists would hide a reordering that duplicates or drops one.
		sort( $expectedIds );
		$actual = $ids;
		sort( $actual );
		$this->assertSame( $expectedIds, $actual,
			'Only the promoted names may appear, and each exactly once.' );

		foreach ( $ids as $id ) {
			$this->assertStringEndsWith( '-2', $id,
				'Every overflow copy is a second rendering of an item that is also in the user '
					. 'menu, so its id must be suffixed or the document carries duplicate ids.' );
		}
		// The originals must be untouched: the overflow copies are copies.
		$this->assertSame(
			array_column( $userMenuItems, 'id' ),
			array_column( $data['data-user-links-menus'][0]['array-list-items'], 'id' ),
			'Suffixing the copies must not renumber the user menu itself.'
		);
	}

	/**
	 * The account actions and the donate link are links, not buttons, in the overflow menu.
	 *
	 * @dataProvider provideViewerKind
	 * @covers ::getTemplateData
	 * @param string $kind
	 */
	public function testAccountActionsAndDonateAreDemotedToCollapsibleLinks( string $kind ) {
		$data = $this->buildTemplateData( $kind, [
			'data-user-menu' => self::portlet( [
				self::item( 'sitesupport' ),
				self::item( 'login' ),
				self::item( 'createaccount' ),
				self::item( 'login-private' ),
				self::item( 'watchlist' ),
			] ),
		] );

		$byId = [];
		foreach ( $data['data-user-links-overflow']['array-list-items'] as $item ) {
			$byId[$item['id']] = $item;
		}

		foreach ( [ 'pt-sitesupport-2', 'pt-login-2', 'pt-createaccount-2', 'pt-login-private-2' ] as $id ) {
			$this->assertArrayHasKey( $id, $byId );
			$link = $byId[$id]['array-links'][0];
			$this->assertArrayNotHasKey( 'data-button', $link,
				"$id must render as a plain link: the account actions and the donate link are not "
					. 'buttons in the overflow menu (T425721).' );
			$this->assertNull( $link['icon'],
				"$id must have its icon hidden; an explicit null is what suppresses it." );
			$this->assertStringContainsString( self::COLLAPSIBLE_ITEM_CLASS, $byId[$id]['class'],
				"$id is collapsible, which is how the stylesheets hide the duplicate on the "
					. 'viewport where the other copy is visible.' );
		}

		// The contrast that makes the overrides meaningful: an item with no override is styled by
		// the shared style map instead.
		$watchlist = $byId['pt-watchlist-2']['array-links'][0];
		$this->assertArrayHasKey( 'data-button', $watchlist,
			'An item without a per-item override must still follow the shared style map.' );
	}

	public static function provideViewerKind(): array {
		return [
			'anonymous' => [ 'anon' ],
			'registered' => [ 'registered' ],
		];
	}

	/**
	 * Registered viewers get icon-only overflow buttons; anonymous viewers get labelled ones.
	 *
	 * @covers ::getTemplateData
	 */
	public function testOverflowButtonStyleDependsOnRegistration() {
		$portletData = [ 'data-user-menu' => self::portlet( [ self::item( 'watchlist' ) ] ) ];

		$registered = $this->buildTemplateData( 'registered', $portletData );
		$anonymous = $this->buildTemplateData( 'anon', $portletData );

		$registeredButton =
			$registered['data-user-links-overflow']['array-list-items'][0]['array-links'][0]['data-button'];
		$anonymousButton =
			$anonymous['data-user-links-overflow']['array-list-items'][0]['array-links'][0]['data-button'];

		$this->assertStringContainsString( 'cdx-button--icon-only', $registeredButton['class'],
			'A registered viewer\'s overflow items are icon-only buttons.' );
		$this->assertStringNotContainsString( 'cdx-button--icon-only', $anonymousButton['class'],
			'An anonymous viewer\'s overflow items keep their labels, so they are not icon-only.' );
	}

	/**
	 * @covers ::getOverflowMenuClass
	 * @covers ::getTemplateData
	 */
	public function testEmptyPortletHidesEachSecondaryMenuIndependently() {
		$data = $this->buildTemplateData( 'registered', [
			'data-user-menu' => self::portlet( [ self::item( 'watchlist' ) ] ),
			'data-user-page' => self::portlet( [ self::item( 'userpage' ) ] ),
			// data-user-interface-preferences and data-notifications are absent, which is what a
			// wiki without Echo, or a viewer with no interface preferences, actually looks like.
		] );

		$this->assertSame( 'mw-portlet',
			$data['data-user-links-user-page']['class'],
			'A menu with content keeps only the shared portlet class.' );
		$this->assertSame( 'mw-portlet emptyPortlet',
			$data['data-user-links-preferences']['class'],
			'An absent portlet must be hidden with core\'s own emptyPortlet class rather than '
				. 'rendered as empty chrome.' );
		$this->assertSame( 'mw-portlet emptyPortlet',
			$data['data-user-links-notifications']['class'] );
		$this->assertSame( 'mw-portlet',
			$data['data-user-links-overflow']['class'],
			'The overflow menu has the watchlist item, so it is not empty.' );

		$this->assertSame( 'p-notion-user-menu-preferences', $data['data-user-links-preferences']['id'] );
		$this->assertSame( 'p-notion-user-menu-userpage', $data['data-user-links-user-page']['id'] );
		$this->assertSame( 'p-notion-user-menu-notifications', $data['data-user-links-notifications']['id'] );
		$this->assertSame( 'p-notion-user-menu-overflow', $data['data-user-links-overflow']['id'] );
	}

	/**
	 * `is-wide` is true exactly when one of the four secondary menus has something in it.
	 *
	 * @dataProvider provideIsWide
	 * @covers ::getTemplateData
	 * @param array $portletData
	 * @param bool $expected
	 */
	public function testIsWideTracksTheSecondaryMenus( array $portletData, bool $expected ) {
		$this->assertSame(
			$expected,
			$this->buildTemplateData( 'registered', $portletData )['is-wide']
		);
	}

	public static function provideIsWide(): array {
		return [
			'nothing but an empty user menu' => [
				[ 'data-user-menu' => self::portlet( [] ) ],
				false,
			],
			'a user-menu item that is not promoted leaves every secondary menu empty' => [
				[ 'data-user-menu' => self::portlet( [ self::item( 'preferences' ) ] ) ],
				false,
			],
			'a promoted item fills the overflow menu' => [
				[ 'data-user-menu' => self::portlet( [ self::item( 'watchlist' ) ] ) ],
				true,
			],
			'notifications alone are enough' => [
				[
					'data-user-menu' => self::portlet( [] ),
					'data-notifications' => self::portlet( [ self::item( 'notifications' ) ] ),
				],
				true,
			],
			'a personal page alone is enough' => [
				[
					'data-user-menu' => self::portlet( [] ),
					'data-user-page' => self::portlet( [ self::item( 'userpage' ) ] ),
				],
				true,
			],
			'interface preferences alone are enough' => [
				[
					'data-user-menu' => self::portlet( [] ),
					'data-user-interface-preferences' => self::portlet( [ self::item( 'uls' ) ] ),
				],
				true,
			],
		];
	}

	/**
	 * The complete user menu keeps core's portlet id and gains collapsible marks on the items the
	 * overflow menu renders a second time.
	 *
	 * @covers ::getMenus
	 */
	public function testUserMenuKeepsCoreIdentifiersAndMarksTheDuplicatedItems() {
		$data = $this->buildTemplateData( 'registered', [
			'data-user-menu' => [
				'id' => 'p-personal',
				'class' => 'mw-portlet mw-portlet-personal',
				'html-tooltip' => ' title="User menu"',
				'array-items' => [ self::item( 'watchlist' ), self::item( 'preferences' ) ],
			],
		] );
		$menus = $data['data-user-links-menus'];

		$this->assertCount( 1, $menus, 'Exactly one menu goes inside the dropdown.' );
		$menu = $menus[0];
		$this->assertSame( 'p-personal', $menu['id'],
			'Core owns this portlet id and gadgets target it, so it is never renamed.' );
		$this->assertNull( $menu['label'],
			'The dropdown handle already labels the menu, so the heading is suppressed.' );
		$this->assertSame( 'mw-portlet mw-portlet-personal', $menu['class'],
			'Core\'s portlet classes must reach the template unchanged.' );
		$this->assertSame( ' title="User menu"', $menu['html-tooltip'],
			'Core\'s tooltip attribute string must be forwarded verbatim.' );

		$byId = [];
		foreach ( $menu['array-list-items'] as $item ) {
			$byId[$item['id']] = $item;
		}
		$this->assertStringContainsString( self::COLLAPSIBLE_ITEM_CLASS, $byId['pt-watchlist']['class'],
			'watchlist is also rendered in the overflow menu, so its copy here is collapsible.' );
		$this->assertStringNotContainsString( self::COLLAPSIBLE_ITEM_CLASS, $byId['pt-preferences']['class'],
			'preferences is not promoted, so it must not be marked collapsible.' );
	}

	/**
	 * The notification badges are icon-only buttons, except the talk alert.
	 *
	 * @covers ::getTemplateData
	 */
	public function testTalkAlertIsExemptFromTheNotificationButtonStyling() {
		$data = $this->buildTemplateData( 'registered', [
			'data-user-menu' => self::portlet( [] ),
			'data-notifications' => self::portlet( [
				[
					'name' => 'notifications-alert',
					'id' => 'pt-notifications-alert',
					'class' => 'mw-list-item',
					'array-links' => [ [
						'icon' => 'bell',
						'array-attributes' => [ [ 'key' => 'href', 'value' => '/alerts' ] ],
						'text' => 'Alerts',
					] ],
				],
				[
					'name' => 'talk-alert',
					'id' => 'pt-talk-alert',
					'class' => 'mw-list-item',
					'array-links' => [ [
						'icon' => 'userTalk',
						'array-attributes' => [ [ 'key' => 'href', 'value' => '/talk' ] ],
						'text' => 'New messages',
					] ],
				],
			] ),
		] );

		$byId = [];
		foreach ( $data['data-user-links-notifications']['array-list-items'] as $item ) {
			$byId[$item['id']] = $item;
		}

		$alert = $byId['pt-notifications-alert']['array-links'][0];
		$this->assertArrayHasKey( 'data-button', $alert );
		$this->assertStringContainsString( 'cdx-button--icon-only', $alert['data-button']['class'],
			'Notification badges are icon-only buttons.' );

		$talk = $byId['pt-talk-alert']['array-links'][0];
		$this->assertArrayNotHasKey( 'data-button', $talk,
			'The talk alert is overridden to stay a plain link.' );
		$this->assertNull( $talk['icon'],
			'Its icon is suppressed by an explicit null in the override.' );
	}

	/**
	 * The personal-page menu forwards core's trailing markup and shows no icon.
	 *
	 * @covers ::getTemplateData
	 */
	public function testUserPageMenuForwardsHtmlAfterPortalAndSuppressesIcons() {
		$data = $this->buildTemplateData( 'registered', [
			'data-user-menu' => self::portlet( [] ),
			'data-user-page' => [
				'id' => 'p-user-page',
				'class' => '',
				'html-tooltip' => '',
				'array-items' => [ self::item( 'userpage' ) ],
				'html-after-portal' => '<span class="mw-echo-alert"></span>',
			],
		] );
		$menu = $data['data-user-links-user-page'];

		$this->assertSame( '<span class="mw-echo-alert"></span>', $menu['html-after-portal'],
			'Extensions append markup here, so it must survive the hand-off.' );
		$this->assertNull( $menu['array-list-items'][0]['array-links'][0]['icon'],
			'The personal-page row shows no icon; the override suppresses core\'s.' );
		$this->assertStringContainsString(
			self::COLLAPSIBLE_ITEM_CLASS, $menu['array-list-items'][0]['class'],
			'The row is collapsible, which is how the wide-viewport copy is hidden.'
		);
	}

	/**
	 * An absent `html-after-portal` must not become the string "null" or a missing key.
	 *
	 * @covers ::getTemplateData
	 */
	public function testUserPageMenuToleratesAPortletWithoutTrailingMarkup() {
		$menu = $this->buildTemplateData( 'registered', [
			'data-user-menu' => self::portlet( [] ),
			'data-user-page' => self::portlet( [ self::item( 'userpage' ) ] ),
		] )['data-user-links-user-page'];

		$this->assertSame( '', $menu['html-after-portal'],
			'The default is an empty string, so Mustache renders nothing rather than "null".' );
	}
}
