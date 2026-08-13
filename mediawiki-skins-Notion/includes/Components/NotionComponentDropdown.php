<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentDropdown component
 *
 * The base dropdown surface: a checkbox-hack disclosure whose handle is a `<label>` styled as a
 * Codex quiet fake button. Consumers describe the handle they want -- id, text, icon, whether the
 * icon stands alone, any extra classes -- and this class composes the Codex class list for them.
 *
 * Whether the handle is icon-only is a property of the CONTROL, not of whether an icon happens to
 * be present, and `$iconOnly` is how a caller says which. That distinction is the whole reason the
 * parameter exists: an earlier shape inferred icon-only from `$icon !== null`, which was wrong for
 * every dropdown that pairs an icon with a visible label -- the language dropdown and the page
 * toolbar both do -- and left those consumers overwriting `label-class` on the returned array to
 * undo a class this component should never have added. Passing `iconOnly: false` says it once, at
 * construction, where the caller already knows the answer.
 *
 * `$labelClass` and `$checkboxClass` exist for the same reason. Before them, a consumer that needed
 * one extra class on the handle had to rebuild the entire Codex class string itself -- duplicating
 * the exact `cdx-button …` composition below, so the two could drift apart silently -- and a
 * consumer that needed a hook class on the checkbox had to assign the key afterwards. Both are now
 * additive inputs: this class still owns the Codex composition, and the caller contributes only its
 * own classes.
 *
 * The returned array stays a plain, mutable array of exactly ten keys. Consumers that need a key
 * this component does not model -- `aria-label`, `aria-description` -- still add it after
 * construction, which keeps the shared contract narrow for everyone else.
 */
class NotionComponentDropdown implements NotionComponent {

	/**
	 * Codex classes that make the handle `<label>` render as a quiet fake button.
	 *
	 * A `<label>` rather than a `<button>` is what lets the checkbox hack drive the dropdown with
	 * no JavaScript, so Codex styling has to arrive entirely through this class list. It is a
	 * constant so that no consumer has to restate it: the language dropdown used to, and a
	 * duplicated class string is a silent divergence waiting to happen.
	 */
	private const HANDLE_CLASSES = 'cdx-button cdx-button--fake-button ' .
		'cdx-button--fake-button--enabled cdx-button--weight-quiet';

	/**
	 * Codex modifier applied when, and only when, the caller declares the handle icon-only.
	 *
	 * The surrounding spaces are part of the emitted value, not formatting: `label-class` is a
	 * concatenation and both neighbours rely on the separation.
	 */
	private const ICON_ONLY_CLASS = ' cdx-button--icon-only ';

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
	 * @param bool|null $iconOnly Whether the handle shows its icon alone, with `$label` reserved
	 *   for assistive technology, which is what the Codex `cdx-button--icon-only` class expresses.
	 *   Null means "decide from `$icon`", which reproduces the historical behaviour exactly and
	 *   keeps every positional call site that predates this parameter byte-for-byte unchanged.
	 *   Pass `false` explicitly for a control that pairs an icon with a visible label (the
	 *   page-toolbar handle, the language button on a page that has interlanguage links) and `true`
	 *   to state icon-only regardless of whether an icon is present.
	 * @param string $labelClass Additional space-separated classes appended to the handle's Codex
	 *   class list. Appended, never substituted: the Codex composition above stays owned by this
	 *   class.
	 * @param string $checkboxClass Additional space-separated classes for the checkbox input.
	 *   These are core- and extension-owned hook names rather than skin presentation -- ULS binds
	 *   its click handler to `mw-interlanguage-selector` -- so they are forwarded verbatim.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $class = '',
		private readonly ?string $icon = null,
		private readonly string $tooltip = '',
		private readonly ?bool $iconOnly = null,
		private readonly string $labelClass = '',
		private readonly string $checkboxClass = '',
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		// Icon-only is what the caller declared, and the declaration is honoured as a property of
		// the control: `iconOnly: true` keeps the modifier even when no icon is passed, because the
		// class describes the handle rather than its contents. Falling back to the presence of an
		// icon when nothing was declared is what keeps this identical to the pre-parameter
		// behaviour for any call site that has not been updated, so adopting the parameter is
		// opt-in and never restyles a consumer by surprise. An empty icon name counts as no icon
		// for that inference only, so an undeclared handle with nothing to show is not styled as
		// though it had a glyph.
		//
		// Two controls are the reason the parameter exists: the page-toolbar handle pairs the
		// `verticalEllipsis` icon with a visible "toolbox" label, and the language button pairs an
		// icon with a visible language count. Both are icon-plus-label controls that the inference
		// alone would have marked icon-only, and both now say `iconOnly: false` at construction
		// rather than repairing `label-class` afterwards.
		//
		// `getTemplateData()` still returns a plain, mutable array on purpose -- a consumer adds
		// `aria-label` or `aria-description` to it, which is how the language button contributes
		// the attributes only it can know. It must never return an object or a read-only structure.
		$iconOnly = $this->iconOnly ?? ( $this->icon !== null && $this->icon !== '' );

		$labelClass = self::HANDLE_CLASSES . ( $iconOnly ? self::ICON_ONLY_CLASS : '' );
		if ( $this->labelClass !== '' ) {
			// One separator, never two and never none: ICON_ONLY_CLASS already ends in a space,
			// HANDLE_CLASSES does not. Adding one unconditionally would emit a double space into
			// the icon-only path and change the value every existing snapshot records.
			$labelClass .= ( str_ends_with( $labelClass, ' ' ) ? '' : ' ' ) . $this->labelClass;
		}

		// These ten keys are the whole contract with Dropdown/Open.mustache. Anything extra a
		// consumer needs (`aria-label`, `aria-description`, ...) is added by that consumer after
		// construction, so it does not widen the contract for the others.
		//
		// The two `html-notion-menu-*-attributes` values are deliberately `''` and never null, but
		// not because null would print. It would not: LightnCandy renders a null interpolation as
		// the empty string, never as the literal text "null". The reason is name resolution. A key
		// whose value is null is treated exactly like a missing key, so the lookup walks out to the
		// enclosing context and an outer key of the same name would be rendered in its place --
		// measured, not assumed. The empty string is falsy for a section yet present enough to stop
		// that walk, so it is what keeps these keys inert wherever the dropdown is nested. They
		// exist so the dropdown works with the checkbox hack and no JavaScript, and so extensions
		// have a documented seam -- Extension:ULS, for instance, binds its click handler to the
		// `checkbox-class` value NotionComponentLanguageDropdown supplies at construction.
		//
		// `is-expanded` is the dropdown's initial disclosure state, and it is false because every
		// dropdown this skin renders arrives closed. It is emitted rather than left to the
		// template because the template derives BOTH the checkbox's `checked` attribute and the
		// `aria-expanded` the checkbox announces from it: the two describe one state, so they come
		// from one key and cannot drift apart or be forgotten. Without it a dropdown that had
		// never been touched would carry `role="button"` and no expanded state at all, since
		// core's checkbox-hack helper only writes `aria-expanded` from the first input event
		// onwards. A consumer that genuinely needs to serve an open dropdown sets this key to true
		// on the returned array, the same documented post-construction seam the other keys use.
		return [
			'id' => $this->id,
			'label' => $this->label,
			'label-class' => $labelClass,
			'icon' => $this->icon,
			'html-notion-menu-label-attributes' => '',
			'html-notion-menu-checkbox-attributes' => '',
			'class' => $this->class,
			'html-tooltip' => $this->tooltip,
			'checkbox-class' => $this->checkboxClass,
			'is-expanded' => false,
		];
	}
}
