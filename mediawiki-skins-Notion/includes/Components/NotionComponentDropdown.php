<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentDropdown component
 */
class NotionComponentDropdown implements NotionComponent {

	/**
	 * @param string $id Unique identifier for the dropdown. The template derives the checkbox id
	 *   ( `<id>-checkbox` ) and the label id ( `<id>-label` ) from it, so it must be unique within
	 *   the rendered page.
	 * @param string $label Already-localised text for the dropdown handle. It doubles as the
	 *   checkbox `aria-label` unless a consumer supplies its own `aria-label` after construction.
	 * @param string $class Additional space-separated CSS classes for the outermost element.
	 * @param string|null $icon Name of the icon to render inside the handle, or null for none.
	 * @param string $tooltip Pre-rendered HTML attribute string (for example from
	 *   `Linker::tooltip()`) placed on the outermost element. The template emits it unescaped, so
	 *   the caller owns its escaping.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $class = '',
		private readonly ?string $icon = null,
		private readonly string $tooltip = '',
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		// FIXME: Stop hardcoding button and icon styles, this assumes all dropdowns with icons are
		// icon buttons. Not the case for the language dropdown, page tools, etc.
		//
		// This limitation is carried forward knowingly rather than silently inherited, because the
		// dropdown templates and every consumer of this class are authored against the output
		// below. Stated precisely:
		//
		// (a) The assumption: any dropdown constructed with a non-null $icon is an icon-only
		//     control, so `cdx-button--icon-only` is appended to its label class unconditionally.
		// (b) Where it is wrong: NotionComponentPageToolbar passes the `verticalEllipsis` icon for
		//     a control that also renders its visible "toolbox" label, and
		//     NotionComponentLanguageDropdown pairs an icon with a visible language-count label.
		//     Neither control is icon-only, so neither wants that class.
		// (c) How they compensate today: both overwrite `label-class` on the array returned below
		//     *after* construction — NotionComponentLanguageDropdown additionally overwrites
		//     `icon` and `checkbox-class`. That is only possible because this method returns a
		//     plain, mutable array, so it must never return an object or a read-only structure.
		//
		// Any real fix has to be opt-in through a new parameter whose default reproduces the
		// output below byte for byte; flipping the default would silently restyle every consumer.
		$icon = $this->icon;
		$buttonClass = 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--weight-quiet';
		// The surrounding spaces are significant: consumers concatenate onto `label-class`.
		$iconButtonClass = $icon ? ' cdx-button--icon-only ' : '';

		// These nine keys are the whole contract with Dropdown/Open.mustache. Anything extra a
		// consumer needs (`aria-label`, `aria-description`, ...) is added by that consumer after
		// construction, so it does not widen the contract for the others.
		//
		// The three empty strings are deliberately `''` and never null: the template renders them
		// through Mustache sections, and a null would risk the literal text "null" reaching the
		// markup. They exist so the dropdown works with the checkbox hack and no JavaScript, and
		// so extensions have a documented seam — Extension:ULS, for instance, binds to the
		// `checkbox-class` value that NotionComponentLanguageDropdown substitutes here.
		return [
			'id' => $this->id,
			'label' => $this->label,
			'label-class' => $buttonClass . $iconButtonClass,
			'icon' => $this->icon,
			'html-notion-menu-label-attributes' => '',
			'html-notion-menu-checkbox-attributes' => '',
			'class' => $this->class,
			'html-tooltip' => $this->tooltip,
			'checkbox-class' => '',
		];
	}
}
