<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentButton component
 *
 * Server-rendered view model for the skin's reusable, component-backed buttons: the sticky header's
 * icon and labelled buttons, the collapsed search toggle, the add-topic button, and any menu entry
 * whose component asked to be drawn as a button rather than as a link. `Button.mustache` renders the
 * data this class returns as either a `<button>` element (no `href`) or an `<a>` element styled as a
 * button (`href` present), so those callers describe a button semantically -- label, icon, weight,
 * action -- and never hand-write a class string.
 *
 * It is not the only button in the skin, and the surrounding documentation should not be read as
 * saying so. `PinnableHeader.mustache` writes its pin and unpin `<button>` elements inline with
 * `notion-pinnable-header-*` classes and no Codex composition at all, and `Dropdown/Open.mustache`
 * composes its own `cdx-button` class list for the dropdown handle. This class is nevertheless the
 * single place where the Codex `cdx-button` family is composed *in PHP*, which is the property the
 * rest of this docblock relies on.
 *
 * Notion's visual language reaches this component exclusively through design tokens. The
 * `cdx-button` classes are styled by Codex, and the skin retargets the Codex token values -- the
 * accent colour, the control border radius and the hover and active surfaces -- inside
 * `resources/mediawiki.less/notion/mediawiki.skin.variables.less`. That indirection is why no
 * colour, spacing, radius, shadow or font literal, and no inline `style` attribute, may ever
 * appear here: the token layer is the skin's single source of visual truth, and emitting a
 * literal from PHP would put a value outside it.
 *
 * Two construction styles are in use and both are part of the contract. Positional, as used by
 * the sticky header, the search box and the skin class:
 *
 * @code
 *     $button = new NotionComponentButton(
 *         $this->msg( 'search' )->text(), 'search', null, 'notion-search-toggle',
 *         [ 'tabindex' => '-1' ], 'quiet', 'default', true
 *     );
 *     $templateData = $button->getTemplateData();
 * @endcode
 *
 * and named, as used by the menu component:
 *
 * @code
 *     $button = new NotionComponentButton( label: $text, icon: $icon, weight: 'quiet' );
 * @endcode
 *
 * Consequently neither the order nor the names of the constructor parameters may change without
 * updating every call site: a reordered parameter is a silent behavioural change rather than a
 * fatal error, and a renamed one breaks named-argument callers at runtime.
 *
 * An icon is named, never composed. `$icon` is a plain string -- an icon name from the skin's own
 * OOUI icon pack, optionally carrying a variant suffix such as `-progressive` -- and that is the
 * finished contract rather than a stepping stone towards an icon component object. This skin ships
 * no icon component class by design: an icon carries no data of its own beyond its name, so there
 * is nothing for a component class to assemble. `Icon.mustache` is the single place an icon name is
 * expanded into its mask-image and `cdx-button__icon` classes, the mask-image recolours itself from
 * the token layer, and `skin.json` is the single place the available names are declared, which
 * leaves nothing for PHP to decide. A component class between the two would add an indirection with
 * no behaviour in it and a second vocabulary for the same value, and would widen a contract every
 * call site above already constructs either positionally or by name. A name is also what every
 * caller already has, because the names come from core's portlet data and from the icon pack rather
 * than from this skin's own markup. Passing anything other than a declared name -- markup, a path,
 * an object -- is a caller error: `Icon.mustache` interpolates the name straight into a class, so an
 * unknown one renders an icon-shaped gap rather than an error.
 *
 * @internal
 */
class NotionComponentButton implements NotionComponent {

	/**
	 * @param string $label Visible button text, rendered inside a `<span>`. May be an empty
	 *   string for an icon-only button whose label is populated later by client-side code.
	 * @param string|null $icon Icon name from the skin's icon pack, emitted verbatim by
	 *   `Icon.mustache`. Two forms are in use and both are supported. The bare name, optionally
	 *   with a variant suffix -- `search`, `article`, `speechBubbleAdd-progressive` -- and the
	 *   historical `wikimedia-` prefixed form, which `NotionComponentStickyHeader` passes for every
	 *   one of its icon descriptors (`wikimedia-history`, `wikimedia-star`,
	 *   `wikimedia-bookmarkOutline`, `wikimedia-edit`, `wikimedia-wikiText`, `wikimedia-editLock`).
	 *   Both resolve because the partial emits `mw-ui-icon-{name}` alongside
	 *   `mw-ui-icon-wikimedia-{name}`, so a prefixed name matches on the first class and a bare one
	 *   on the second. Null renders no icon.
	 * @param string|null $id Value of the button's `id` attribute. Null omits the attribute.
	 * @param string|null $class Additional classes appended verbatim after the Codex classes.
	 *   This is the only channel through which caller-owned `notion-*` presentational classes
	 *   reach the button; this component owns no presentational class of its own.
	 * @param array|null $attributes Further HTML attributes keyed by attribute name, such as
	 *   `tabindex`, `title`, `aria-*` and `data-event-name`. This is the accessibility and
	 *   instrumentation channel, so everything passed is forwarded except null values and the
	 *   `id`, `class` and `href` keys, which are handled by their own parameters.
	 * @param string|null $weight One of `normal`, `primary` or `quiet`. Any other value,
	 *   including null, is normalised to `normal`.
	 * @param string|null $action One of `default`, `progressive` or `destructive`. Any other
	 *   value, including null, is normalised to `default`.
	 * @param bool|null $iconOnly Whether only the icon is shown, the label being reserved for
	 *   assistive technology.
	 * @param string|null $href Target of the button. When set the button renders as an anchor
	 *   and gains the Codex fake-button classes.
	 */
	public function __construct(
		private readonly string $label,
		private readonly ?string $icon = null,
		private readonly ?string $id = null,
		private readonly ?string $class = null,
		private readonly ?array $attributes = [],
		private ?string $weight = 'normal',
		private ?string $action = 'default',
		private readonly ?bool $iconOnly = false,
		private readonly ?string $href = null,
	) {
		// Weight can only be normal, primary, or quiet
		if ( $this->weight !== 'primary' && $this->weight !== 'quiet' ) {
			$this->weight = 'normal';
		}
		// Action can only be default, progressive or destructive
		if ( $this->action !== 'progressive' && $this->action !== 'destructive' ) {
			$this->action = 'default';
		}
	}

	/**
	 * Constructs button classes based on the props
	 *
	 * The composition order is part of the rendered contract and is asserted by the inline
	 * `assertSame()` expectations in `NotionComponentButtonTest`, which compares whole class strings
	 * directly -- this component keeps no snapshot file: the base class, then the fake-button pair,
	 * then weight, then action, then
	 * icon-only, then the caller's own classes. Every modifier carries its own leading space
	 * while the base class does not, so the pieces concatenate into a valid class attribute
	 * without any post-processing -- normalising that spacing would change the emitted markup.
	 *
	 * The normalised `normal` weight and `default` action intentionally add nothing, because
	 * Codex defines no modifier for either: they are the styling `cdx-button` already provides.
	 *
	 * @return string Space-separated class list for the button element
	 */
	private function getClasses(): string {
		$classes = 'cdx-button';
		// Codex distinguishes a real button from an anchor styled as one, so the fake-button
		// pair is correct only when the element will actually render as a link.
		if ( $this->href ) {
			$classes .= ' cdx-button--fake-button cdx-button--fake-button--enabled';
		}
		switch ( $this->weight ) {
			case 'primary':
				$classes .= ' cdx-button--weight-primary';
				break;
			case 'quiet':
				$classes .= ' cdx-button--weight-quiet';
				break;
		}
		switch ( $this->action ) {
			case 'progressive':
				$classes .= ' cdx-button--action-progressive';
				break;
			case 'destructive':
				$classes .= ' cdx-button--action-destructive';
				break;
		}
		if ( $this->iconOnly ) {
			$classes .= ' cdx-button--icon-only';
		}
		if ( $this->class ) {
			$classes .= ' ' . $this->class;
		}
		return $classes;
	}

	/**
	 * @inheritDoc
	 *
	 * Emits `label`, `icon`, `id`, `class`, `href` and `array-attributes`, in that order.
	 * `array-attributes` is a list of `[ 'key' => ..., 'value' => ... ]` records rather than an
	 * associative map, because the template iterates it as a Mustache section. Every value is a
	 * plain scalar or array so the result stays deterministic and JSON-encodable for snapshots.
	 */
	public function getTemplateData(): array {
		$arrayAttributes = [];
		// The parameter is nullable, so coalesce rather than assuming a caller relied on the
		// default empty array; iterating null would emit a warning on every render.
		foreach ( $this->attributes ?? [] as $key => $value ) {
			// Filter out empty attributes and named attributes that are handled separately.
			if ( $value === null || in_array( $key, [ 'id', 'class', 'href' ], true ) ) {
				continue;
			}
			$arrayAttributes[] = [ 'key' => $key, 'value' => $value ];
		}
		return [
			'label' => $this->label,
			'icon' => $this->icon,
			'id' => $this->id,
			'class' => $this->getClasses(),
			'href' => $this->href,
			'array-attributes' => $arrayAttributes
		];
	}
}
