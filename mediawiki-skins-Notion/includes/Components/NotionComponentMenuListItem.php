<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentMenuListItem component
 *
 * Adapter that decorates an already-built NotionComponentLink with the class and id belonging to
 * the `<li>` element that wraps it, so `MenuListItem.mustache` receives the link's own data and
 * the list item's own attributes as one flat array. This component never constructs the link:
 * callers -- the skin class and the menu-building code -- decide what the link points at, and this
 * class only adapts what they hand over.
 *
 * The emitted key set is exactly six. `icon`, `text`, `href` and `html-attributes` arrive from the
 * wrapped link and belong on the `<a>` element, while `item-class` and `item-id` are contributed
 * here and are interpolated onto the surrounding `<li>` element. The `item-` prefix is what keeps
 * those two groups apart instead of colliding. A caller marking a menu item collapsible, for
 * instance, passes the modifier class through $class so it lands on the list item rather than on
 * the anchor.
 *
 * Notion's visual language reaches this component only through the caller and the token layer, so
 * it contributes no presentational class of its own and no colour, spacing, radius, shadow or font
 * literal. Menu list items are styled by `resources/skins.notion.styles/components/Menu.less`
 * together with `links.less`; there is deliberately no `components/MenuListItem.less`.
 */
class NotionComponentMenuListItem implements NotionComponent {
	/**
	 * @param NotionComponentLink $link Link rendered inside the list item, already constructed by
	 *   the caller. The type is deliberately narrowed to the concrete link component rather than
	 *   widened to the NotionComponent interface: a menu list item always wraps a link, and the
	 *   narrowing is what documents -- and lets the engine enforce -- that the four link keys
	 *   `icon`, `text`, `href` and `html-attributes` are guaranteed to reach the template.
	 * @param string $class Classes for the `<li>` element itself, emitted as `item-class`. This is
	 *   the only channel through which caller-owned `notion-*` presentational classes reach the
	 *   list item. Defaults to the empty string rather than null because the template interpolates
	 *   the value straight into a `class` attribute.
	 * @param string $id Value of the `<li>` element's `id` attribute, emitted as `item-id`.
	 *   Defaults to the empty string for the same reason as $class.
	 */
	public function __construct(
		private readonly NotionComponentLink $link,
		private readonly string $class = '',
		private readonly string $id = '',
	) {
	}

	/**
	 * @inheritDoc
	 *
	 * Emits the wrapped link's own data -- `icon`, `text`, `href` and `html-attributes` -- plus
	 * `item-class` and `item-id`, and nothing else. The link's data is the left operand of the
	 * union because PHP's `+` operator is left-biased: on a key collision the link's value wins,
	 * which keeps the anchor's data authoritative and stops an item-level key from shadowing it.
	 *
	 * Returning the link's array rather than the link object is what keeps the result a plain,
	 * deterministic, JSON-encodable structure for the component snapshot tests, and it leaves the
	 * link's `html-attributes` string -- which carries `title`, `accesskey` and `aria-label` --
	 * passing through completely untouched.
	 */
	public function getTemplateData(): array {
		return $this->link->getTemplateData() + [
			'item-class' => $this->class,
			'item-id' => $this->id,
		];
	}
}
