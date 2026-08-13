<?php

namespace MediaWiki\Skins\Notion\Components;

use Countable;

/**
 * NotionComponentMenu component
 *
 * Normalises a single MediaWiki portlet into the array shape that `Menu.mustache`,
 * `MenuContents.mustache` and `MenuListItem.mustache` render. Every menu the Notion skin emits
 * passes through this class -- the main menu portlets, the namespace and action menus in the page
 * toolbar, the page tools, the language variants and each of the user-links menus -- which makes
 * it the single place where portlet data acquires per-item styling.
 *
 * Two rendering modes are supported and they are mutually exclusive by design:
 *
 *   - Pre-rendered `html-items`, used by portlets whose markup arrives as an HTML string, such as
 *     the language menu and portlets contributed by extensions. When present it wins and
 *     `array-list-items` is nulled out, because `MenuContents.mustache` renders both keys into the
 *     same `<ul>` and would otherwise emit every item twice.
 *   - Structured `array-list-items`, used everywhere else, whose entries are normalised by
 *     ::updateMenuItemStyles() so that callers describe styling declaratively -- as a button, as
 *     collapsible, with an icon, with an extra class -- instead of hand-editing item records.
 *
 * ::count() therefore has to answer for both modes, which is why it inspects the structured list
 * first and falls back to counting list items in the HTML string. Callers rely on that to decide
 * whether a menu is worth rendering at all, so an empty menu is hidden rather than emitted as an
 * empty dropdown.
 *
 * Notion's menu appearance is entirely token-driven and none of it is decided here. The row
 * height, the control border radius and the hover and active fills live in
 * `resources/skins.notion.styles/components/Menu.less` on top of the skin's token layer, so this
 * class contributes exactly one presentational class -- ::COLLAPSIBLE_CLASS -- and delegates every
 * Codex class it emits to NotionComponentButton rather than building class strings itself.
 * Collapsing is expressed purely as that class name, which keeps it working with JavaScript
 * disabled, and no colour, spacing, radius, shadow or inline style may ever appear in this file.
 *
 * The array this class returns is plain data: scalars, arrays, booleans and null only. That is a
 * hard requirement rather than a convention, because the component snapshots are JSON and because
 * Mustache can only traverse plain values -- which is why a requested button is stored as
 * NotionComponentButton::getTemplateData() output and never as the component object itself.
 */
class NotionComponentMenu implements NotionComponent, Countable {

	/**
	 * Presentational class marking a menu item that collapses out of its menu, applied to the
	 * item's LI element.
	 *
	 * This declaration is the contract for the value: the skin's menu, page-tools, page-toolbar
	 * and user-links stylesheets select on it, and the sticky header's clone logic looks items up
	 * by it. It is public so that no consumer has to repeat the literal.
	 */
	public const COLLAPSIBLE_CLASS = 'notion-menu-item--collapsible';

	/**
	 * @param array $data menu data
	 * @param array $menuItemStyles all menu items will use default styles unless there's an item-specific override
	 * @param array $menuItemStyleOverrides styles for individual menu items keyed by item id
	 */
	public function __construct(
		private array $data,
		private array $menuItemStyles = [],
		private array $menuItemStyleOverrides = []
	) {
		// Fill in every key the menu templates read unconditionally. `+=` supplies only the keys
		// the caller omitted, so caller data always wins -- including an explicit null for
		// `label` or `html-items`, which several callers pass to opt out of a heading or of HTML
		// string rendering. A missing default here would surface as an undefined-variable render.
		$this->data += [
			'class' => '',
			'label' => '',
			'html-tooltip' => '',
			'label-class' => '',
			'html-before-portal' => '',
			'html-items' => '',
			'html-after-portal' => '',
			'array-list-items' => null,
		];

		$menuItemsData = $this->data['array-list-items'];
		if ( $this->data[ 'html-items' ] ) {
			// Using HTML string rendering
			$this->data[ 'array-list-items' ] = null;
		} elseif ( $menuItemsData ) {
			// Using template based rendering, update the menu item styles
			$this->data['array-list-items'] = $this->updateMenuItemStyles(
				$menuItemsData,
				$menuItemStyles,
				$menuItemStyleOverrides
			);
		}
	}

	/**
	 * Counts how many items the menu has.
	 *
	 * Both rendering modes are covered: the structured list when there is one, otherwise the
	 * pre-rendered HTML string. The needle is deliberately the unclosed `<li`, so that items
	 * carrying attributes -- `<li class="...">` -- are counted alongside bare `<li>` elements.
	 * `html-items` is coalesced because callers pass an explicit null to opt out of this mode.
	 */
	public function count(): int {
		$items = $this->data['array-list-items'] ?? null;
		if ( $items ) {
			return count( $items );
		}
		$htmlItems = $this->data['html-items'] ?? '';
		return substr_count( $htmlItems, '<li' );
	}

	/**
	 * Update menu item styling based of default menu styles and overrides
	 * Style options include: 'button', 'collapsible', 'icon', 'class'
	 * 'button' can be boolean or an array with button options, e.g. ['iconOnly' => true]
	 *
	 * Precedence is per item and all-or-nothing: when an item's id has an entry in
	 * $menuItemStyleOverrides that entry *replaces* $menuItemStyles rather than merging with it.
	 * The single exception is 'class', which falls back from the override to the shared styles so
	 * that a caller can add a class to every item and still override one item's other styling.
	 *
	 * Items are keyed by the ids core assigns to portlet entries, for example `ca-watch` or
	 * `pt-login-2`. Those identifiers belong to core and are never rewritten by this skin, so no
	 * id is hardcoded here.
	 *
	 * Each entry in $menuItems is assumed to carry the `id` and `class` keys that core's portlet
	 * data always supplies, because that shape is produced upstream by SkinTemplate rather than
	 * by any caller of this class. `array-links` is optional and defaults to an empty list.
	 *
	 * @param array $menuItems
	 * @param array $menuItemStyles all menu items will use these styles unless there's an item specific override
	 * @param array $menuItemStyleOverrides styles for individual menu items keyed by item id.
	 *   A menu item can be removed if it is set to "false"
	 * @return array
	 */
	private static function updateMenuItemStyles( $menuItems, $menuItemStyles, $menuItemStyleOverrides ) {
		$menuItems = array_map( static function ( $item ) use ( $menuItemStyles, $menuItemStyleOverrides ) {
			$id = $item['id'];
			$hasOverrides = $id && isset( $menuItemStyleOverrides[ $id ] );
			$styles = $hasOverrides ? $menuItemStyleOverrides[ $id ] : $menuItemStyles;

			// Identity comparison, not a truthiness test: only an override of exactly false
			// removes the item. An empty style set is still a style set, so [] must leave the
			// item in place -- a loose comparison would silently drop it.
			if ( $styles === false ) {
				return null;
			}

			$isCollapsible = $styles['collapsible'] ?? false;
			// collapsible class is added to the item (LI element) class
			if ( $isCollapsible ) {
				$class = $item['class'] ?? '';
				$item['class'] = $class . ' ' . self::COLLAPSIBLE_CLASS;
			}

			$customClass = $menuItemStyleOverrides[ $id ]['class'] ?? $menuItemStyles['class'] ?? '';
			if ( $customClass ) {
				$item['class'] = trim( $item['class'] . ' ' . $customClass );
			}

			// Update link classes
			$item['array-links'] = array_map( static function ( $link ) use ( $styles ) {
				// Override existing icon if needed
				// array_key_exists rather than ?? on purpose: callers suppress an icon by
				// passing 'icon' => null or 'icon' => false, and ?? would ignore both.
				$existingIcon = $link['icon'] ?? null;
				$icon = array_key_exists( 'icon', $styles ) ? $styles['icon'] : $existingIcon;

				$buttonStyles = $styles['button'] ?? false;
				if ( $buttonStyles ) {
					// The button emits `array-attributes` as a list of key/value records and
					// handles id, class and href through their own parameters, so the link's
					// list is inverted back into a map here and handed over whole. That keeps
					// title, accesskey and every aria-* attribute on the rendered button.
					$attributes = array_column( $link['array-attributes'] ?? [], 'value', 'key' );
					$buttonComponent = new NotionComponentButton(
						label: $link['text'] ?? '',
						icon: $icon,
						id: $attributes['id'] ?? '',
						class: $attributes['class'] ?? '',
						attributes: $attributes,
						weight: 'quiet',
						action: $styles['button']['action'] ?? 'default',
						iconOnly: $styles['button']['iconOnly'] ?? false,
						href: $attributes['href'] ?? '',
					);
					// The resolved array, never the component: this data is snapshotted as JSON
					// and rendered by Mustache. Returning only this key replaces the link
					// record, which is how MenuListItem.mustache knows to include
					// Button.mustache in place of Link.mustache.
					return [
						'data-button' => $buttonComponent->getTemplateData()
					];
				}

				// TODO: Use NotionComponentLink instead of mutating link data directly
				$link['icon'] = $icon;
				return $link;
			}, $item['array-links'] ?? [] );
			return $item;
		}, $menuItems );
		// Drop the items removed above and reindex, so the list stays a JSON array rather than
		// becoming an object with gaps in its keys.
		return array_values(
			array_filter(
				$menuItems,
				static function ( $view ) {
					return $view !== null;
				}
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		return $this->data;
	}
}
