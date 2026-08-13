<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentMenuListItem component
 *
 * Parity-only, non-production contract. This class has no call site in the rendering path and
 * emits no data that any template consumes: it exists so the skin's component set matches the
 * reference skin's one for one, and so the shape below is covered by a unit test.
 *
 * Rendered menus take a different route. Core's SkinComponentLink builds each portlet link and
 * delivers it as `data-portlets.*.array-items[].array-links[]`, a record carrying
 * `array-attributes`, `id`, `class` and `text`; NotionComponentMenu forwards those records
 * essentially untouched and `MenuListItem.mustache` is written against that core shape, exactly as
 * the reference skin's partial is. This class diverges one level up, at the list item: it re-keys
 * the item's own class and id as `item-class` and `item-id`, whereas a core record carries them as
 * the item-level `class` and `id` alongside `html-item`, `name`, `html` and `array-links`. Do not
 * wire it into the templates and do not rewrite the templates towards it -- read against a core
 * portlet record the `item-` prefixed keys resolve to nothing, so every list item would silently
 * lose its class and its id.
 *
 * The emitted key set is exactly five. `icon`, `text` and `array-attributes` arrive from the
 * wrapped link and belong on the `<a>` element -- they are the skin's canonical link shape, the
 * same one core's portlet records use -- while `item-class` and `item-id` are contributed here and
 * are interpolated onto the surrounding `<li>` element. The `item-` prefix is what keeps those two
 * groups apart instead of colliding. A caller marking a menu item collapsible, for instance, passes
 * the modifier class through $class so it lands on the list item rather than on the anchor.
 *
 * This class decides no appearance: it contributes no presentational class of its own and no
 * colour, spacing, radius, shadow or font literal. The menu list items a page actually renders are
 * styled by `resources/skins.notion.styles/components/Menu.less` together with `links.less`; there
 * is deliberately no `components/MenuListItem.less`.
 */
class NotionComponentMenuListItem implements NotionComponent {
	/**
	 * @param NotionComponentLink $link Link rendered inside the list item, already constructed by
	 *   the caller. The type is deliberately narrowed to the concrete link component rather than
	 *   widened to the NotionComponent interface: a menu list item always wraps a link, and the
	 *   narrowing is what documents -- and lets the engine enforce -- that the three canonical link
	 *   keys `icon`, `text` and `array-attributes` are guaranteed to reach the template.
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
	 * Emits the wrapped link's own data -- `icon`, `text` and `array-attributes` -- plus
	 * `item-class` and `item-id`, and nothing else. The link's data is the left operand of the
	 * union because PHP's `+` operator is left-biased: on a key collision the link's value wins,
	 * which keeps the anchor's data authoritative and stops an item-level key from shadowing it.
	 *
	 * Returning the link's array rather than the link object is what keeps the result a plain,
	 * deterministic, JSON-encodable structure for the component snapshot tests, and it leaves
	 * `array-attributes` -- which carries `href` along with `title`, `accesskey` and `aria-label` --
	 * passing through completely untouched. Nothing in the rendering path reads
	 * the emitted array today; see the class comment.
	 */
	public function getTemplateData(): array {
		return $this->link->getTemplateData() + [
			'item-class' => $this->class,
			'item-id' => $this->id,
		];
	}
}
