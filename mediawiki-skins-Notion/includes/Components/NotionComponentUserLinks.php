<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Html\Html;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Linker\Linker;
use MediaWiki\Message\Message;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;

/**
 * NotionComponentUserLinks component
 *
 * Assembles the personal-tools area of the Notion skin: five separate menus plus the dropdown
 * that carries the complete user menu on narrow viewports.
 *
 *   - `p-personal` -- the complete user menu, rendered inside the dropdown.
 *   - `p-notion-user-menu-preferences` -- core's user-interface-preferences portlet.
 *   - `p-notion-user-menu-userpage` -- the personal-page portlet.
 *   - `p-notion-user-menu-notifications` -- the notification badges, when Echo is installed.
 *   - `p-notion-user-menu-overflow` -- a deliberate second rendering of selected user-menu items,
 *     so that they stay reachable outside the dropdown on wide viewports.
 *
 * The output differs for three kinds of viewer -- anonymous, temporary
 * ( UserNameUtils::isTemp() ) and registered -- which is the only reason UserNameUtils is
 * injected: a temporary account must not be offered the account actions a second time in the
 * overflow menu.
 *
 * Two families of identifier meet in this class and they are handled differently. Identifiers
 * that belong to core are reproduced verbatim, because gadgets, extensions and
 * `mw.util.addPortletLink()` target them -- the `p-personal` portlet id, the `mw-portlet` and
 * `emptyPortlet` classes, every `pt-*` item id, the item names in the two key constants and the
 * core `personaltools` message. The container ids and state classes this skin invents carry the
 * `notion-` prefix instead.
 *
 * Overflow items are copies of user-menu items rendered in a second location, so every copy's id
 * gains a `-2` suffix; without it the document would carry duplicate ids. That suffix is also why
 * the per-item style overrides for the account actions and the donate link are keyed on the
 * suffixed ids rather than the originals.
 *
 * Nothing here decides how any of it looks. Notion's user menu -- the Codex-shaped popover at the
 * surface radius, its hairline border, its subtle elevation, its comfortable row height and its
 * icon-plus-label rows -- is described in `resources/skins.notion.styles/components/UserLinks.less`,
 * `Menu.less` and `Dropdown.less` on top of the skin's token layer. This class contributes
 * structure, ids, state classes and labels only; no colour, spacing, radius, shadow or inline
 * styling may ever appear in it. The Codex classes that do reach the markup arrive through
 * NotionComponentDropdown and, by way of NotionComponentMenu's style maps, through
 * NotionComponentButton -- they are never assembled here.
 *
 * Collapsing is expressed purely as class names that the stylesheets act on, so every menu and the
 * dropdown itself remain usable with JavaScript disabled.
 *
 * ::getTemplateData() returns plain data throughout -- scalars, arrays, booleans and null -- because
 * the component snapshots are JSON and Mustache can only traverse plain values. ::getMenus() is the
 * single internal seam that hands back components, and ::getTemplateData() resolves those before
 * returning.
 */
class NotionComponentUserLinks implements NotionComponent {

	/**
	 * Class that hides the entire user-links dropdown on wide viewports.
	 *
	 * `resources/skins.notion.styles/components/UserLinks.less` selects on this class, and on the
	 * `--none` variant appended to it below, which is what keeps the collapsing behaviour working
	 * without JavaScript. Only ::getDropdown() applies it, so it stays private.
	 */
	private const COLLAPSIBLE_CLASS = 'notion-user-links-dropdown--collapsible';

	/**
	 * Names of the account actions core places in the user menu.
	 *
	 * These are core portlet item names and are never rewritten by this skin. They are treated as
	 * a group three times over: they join the overflow keys for anonymous and registered viewers,
	 * they are withheld from temporary accounts, and their overflow copies are rendered as plain
	 * collapsible links rather than as buttons.
	 */
	private const ACCOUNT_MENU_ITEM_KEYS = [
		'createaccount',
		'login',
		'login-private',
	];

	/**
	 * Names of the user-menu items promoted into the overflow menu for every viewer.
	 *
	 * Core owns these names too. `sitesupport` is the donate link, whose overflow copy is
	 * additionally stripped of its button and icon further down (T425721).
	 */
	private const OVERFLOW_MENU_ITEM_KEYS = [
		'readinglists',
		'watchlist',
		'sitesupport',
	];

	/**
	 * @param MessageLocalizer $localizer Resolves this component's interface messages.
	 * @param UserIdentity $user The viewing user. Registration state selects the dropdown's state
	 *   class and the styling of the overflow items, and the name is what identifies a temporary
	 *   account.
	 * @param UserNameUtils $userNameUtils Detects temporary accounts, which receive a reduced set
	 *   of overflow keys.
	 * @param array $portletData Core portlet data as assembled by SkinTemplate. Four keys are
	 *   read: `data-user-menu`, which is required because the user menu always exists even when it
	 *   is empty, plus the optional `data-user-interface-preferences`, `data-user-page` and
	 *   `data-notifications`, so that the component still renders on a wiki without Echo or for a
	 *   viewer without a personal page.
	 * @param string $userIcon that represents the current type of user. An empty string means core
	 *   supplied no personal-page icon, which selects both the fallback icon and the
	 *   `Linker::tooltip()` path in ::getDropdown().
	 */
	public function __construct(
		private readonly MessageLocalizer $localizer,
		private readonly UserIdentity $user,
		private readonly UserNameUtils $userNameUtils,
		private readonly array $portletData,
		private readonly string $userIcon = 'userAvatar',
	) {
	}

	/**
	 * @param string $key
	 * @return Message
	 */
	private function msg( $key ): Message {
		return $this->localizer->msg( $key );
	}

	/**
	 * Builds the dropdown that wraps the complete user menu.
	 *
	 * @param bool $isDefaultAnonUserLinks Whether an anonymous viewer's user menu holds nothing
	 *   beyond the account actions, in which case the dropdown is redundant on wide viewports.
	 * @param int $userLinksCount Number of items in the user menu.
	 * @return NotionComponentDropdown
	 */
	private function getDropdown( $isDefaultAnonUserLinks, $userLinksCount ) {
		$user = $this->user;
		$isAnon = !$user->isRegistered();

		$class = 'notion-user-menu';
		$class .= ' notion-button-flush-right';
		$class .= !$isAnon ?
			' notion-user-menu-logged-in' :
			' notion-user-menu-logged-out';

		// Hide entire user links dropdown on larger viewports if it only contains
		// create account & login link, which are only shown on smaller viewports
		if ( $isAnon && $isDefaultAnonUserLinks ) {
			$linkclass = ' ' . self::COLLAPSIBLE_CLASS;

			if ( $userLinksCount === 0 ) {
				// The user links can be completely empty when even login is not possible
				// (e.g using remote authentication). In this case, we need to hide the
				// dropdown completely not only on larger viewports.
				$linkclass .= '--none';
			}

			$class .= $linkclass;
		}

		$tooltip = Html::expandAttributes( [
			'title' => $this->msg( 'notion-personal-tools-tooltip' )->text(),
		] );
		$icon = $this->userIcon;
		if ( $icon === '' && $userLinksCount ) {
			$icon = 'userAvatar';
			// T287494 We use tooltips to provide title attributes on hover over certain menu icons.
			// Core's "tooltip-p-personal" key is set to "User menu" which is appropriate for the
			// dropdown indicator for the user links menu for logged-in users. For anonymous users
			// the menu also contains a donate link so we override the message. Linker::tooltip()
			// resolves the "tooltip-" prefixed message for the name it is given, which is why the
			// argument below is unprefixed and this skin declares
			// "tooltip-notion-anon-user-menu-title" in i18n/en.json.
			$tooltip = Linker::tooltip( 'notion-anon-user-menu-title' ) ?? '';
		}

		return new NotionComponentDropdown(
			'notion-user-links-dropdown', $this->msg( 'personaltools' )->text(), $class, $icon, $tooltip
		);
	}

	/**
	 * Which user-menu item names are promoted into the overflow menu for this viewer.
	 *
	 * @param UserIdentity $user
	 * @return array
	 */
	private function getOverflowKeys( $user ) {
		// Only certain items get promoted to the overflow menu:
		// * readinglist
		// * watchlist
		// * (account keys)
		if ( $this->userNameUtils->isTemp( $user->getName() ) ) {
			// Temporary accounts don't show the account items in overflow
			return array_diff(
				self::OVERFLOW_MENU_ITEM_KEYS,
				self::ACCOUNT_MENU_ITEM_KEYS
			);
		} else {
			return array_merge( self::OVERFLOW_MENU_ITEM_KEYS, self::ACCOUNT_MENU_ITEM_KEYS );
		}
	}

	/**
	 * The menus rendered inside the user-links dropdown.
	 *
	 * Only one menu goes inside the dropdown: the complete user menu under core's `p-personal`
	 * portlet id, which this skin never renames. Items that this component also renders in the
	 * overflow menu are marked collapsible here, so that the stylesheets can hide the duplicate
	 * on the viewport where the overflow copy is visible.
	 *
	 * Unlike every other menu in this class, the result is returned as components rather than as
	 * resolved data, because ::getTemplateData() maps NotionComponent::getTemplateData() over it.
	 *
	 * @return NotionComponentMenu[]
	 */
	private function getMenus() {
		$user = $this->user;
		$portletData = $this->portletData;
		$overflowKeys = self::getOverflowKeys( $user );
		$userMenuData = $portletData[ 'data-user-menu' ][ 'array-items' ];
		$userMenuOverrides = [];
		// Construct overrides for any menu item that is duplicated in the overflow menu
		foreach ( $overflowKeys as $key ) {
			$menuItem = array_filter( $userMenuData, static function ( $item ) use ( $key ) {
				return $item['name'] === $key;
			} );

			if ( $menuItem ) {
				$menuItem = array_values( $menuItem )[0];
				$userMenuOverrides[ $menuItem[ 'id' ] ] = [ 'collapsible' => true ];
			}
		}

		return [
			new NotionComponentMenu( [
				'id' => 'p-personal',
				'label' => null,
				'class' => $portletData[ 'data-user-menu' ][ 'class' ],
				'html-tooltip' => $portletData[ 'data-user-menu' ][ 'html-tooltip' ] ?? '',
				'array-list-items' => $userMenuData
			], [], $userMenuOverrides )
		];
	}

	/**
	 * What class should the overflow menu have?
	 *
	 * Both class names belong to core and are reproduced verbatim: `mw-portlet` is the shared
	 * portlet class, and `emptyPortlet` is what hides a portlet holding nothing, so an absent
	 * notification extension or an anonymous viewer leaves no empty chrome behind rather than an
	 * empty menu the stylesheets would still lay out.
	 *
	 * @param array $arrayListItems
	 * @return string
	 */
	private static function getOverflowMenuClass( $arrayListItems ) {
		$overflowMenuClass = 'mw-portlet';
		if ( count( $arrayListItems ) === 0 ) {
			$overflowMenuClass .= ' emptyPortlet';
		}
		return $overflowMenuClass;
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		$portletData = $this->portletData;
		$user = $this->user;
		$userLinksCount = count( $portletData['data-user-menu']['array-items'] );
		// Three items is the default anonymous user menu, i.e. nothing beyond the account
		// actions in self::ACCOUNT_MENU_ITEM_KEYS.
		$isDefaultAnonUserLinks = $userLinksCount <= 3;

		$preferencesData = $portletData[ 'data-user-interface-preferences' ]['array-items'] ?? [];
		$preferencesMenu = new NotionComponentMenu( [
			'id' => 'p-notion-user-menu-preferences',
			'class' => self::getOverflowMenuClass( $preferencesData ),
			'label' => null,
			'html-items' => null,
			'array-list-items' => $preferencesData,
		], [
			'button' => true,
			'collapsible' => true,
		] );

		$userPageData = $portletData[ 'data-user-page' ]['array-items'] ?? [];
		$userPageMenu = new NotionComponentMenu( [
			'id' => 'p-notion-user-menu-userpage',
			'class' => self::getOverflowMenuClass( $userPageData ),
			'label' => null,
			'html-items' => null,
			'array-list-items' => $userPageData,
			'html-after-portal' => $portletData[ 'data-user-page' ]['html-after-portal'] ?? '',
		], [
			'collapsible' => true,
			'icon' => null,
		] );

		$notificationsData = $portletData[ 'data-notifications' ]['array-items'] ?? [];
		$notificationsMenu = new NotionComponentMenu( [
			'id' => 'p-notion-user-menu-notifications',
			'class' => self::getOverflowMenuClass( $notificationsData ),
			'label' => null,
			'html-items' => null,
			'array-list-items' => $notificationsData,
		], [
			'button' => [
				'iconOnly' => true,
			],
		], [
			'pt-talk-alert' => [
				'button' => false,
				'icon' => null,
			],
		] );

		$overflowKeys = self::getOverflowKeys( $user );
		$overflowData = array_map(
			static function ( $item ) {
				// Since we're creating duplicate icons
				$item['id'] .= '-2';
				return $item;
			},
			// array_filter preserves keys so use array_values to restore array.
			array_values(
				array_filter(
					$portletData['data-user-menu']['array-items'] ?? [],
					static function ( $item ) use ( $overflowKeys ) {
						$name = $item['name'];
						return in_array( $name, $overflowKeys );
					}
				)
			)
		);

		// Logged in overflow menu items are icon only buttons
		// Styles for anon overflow menu is generally collapsible with no icon
		// the overflow donate link (pt-sitesupport-2) is an exception
		$overflowStyles = $this->user->isRegistered() ? [
			'button' => [
				'iconOnly' => true,
			],
			'collapsible' => true,
		] : [
			'button' => true,
			'collapsible' => true,
		];

		// Disable buttons and hide icons for the account actions:
		$overflowOverrides = [];
		foreach ( self::ACCOUNT_MENU_ITEM_KEYS as $key ) {
			$overflowOverrides['pt-' . $key . '-2'] = [
				'button' => false,
				'icon' => null,
				'collapsible' => true,
			];
		}
		// also disable button and hide icon for donate (T425721)
		$overflowOverrides['pt-sitesupport-2'] = [
			'button' => false,
			'icon' => null,
			'collapsible' => true,
		];

		$overflowMenu = new NotionComponentMenu( [
			'id' => 'p-notion-user-menu-overflow',
			'class' => self::getOverflowMenuClass( $overflowData ),
			'label' => null,
			'html-items' => null,
			'array-list-items' => $overflowData,
		], $overflowStyles, $overflowOverrides );

		return [
			'is-wide' => array_filter(
				[ $overflowData, $notificationsData, $userPageData, $preferencesData ]
			) !== [],
			'data-user-links-notifications' => $notificationsMenu->getTemplateData(),
			'data-user-links-overflow' => $overflowMenu->getTemplateData(),
			'data-user-links-preferences' => $preferencesMenu->getTemplateData(),
			'data-user-links-user-page' => $userPageMenu->getTemplateData(),
			'data-user-links-dropdown' => $this->getDropdown(
				$isDefaultAnonUserLinks, $userLinksCount )->getTemplateData(),
			'data-user-links-menus' => array_map( static function ( $menu ) {
				return $menu->getTemplateData();
			}, $this->getMenus() ),
		];
	}
}
