<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Title\Title;

/**
 * NotionComponentLanguageDropdown component
 *
 * Assembles the template data for the interlanguage-links control that
 * `includes/templates/LanguageDropdown.mustache` renders. The control is a
 * `NotionComponentDropdown` whose handle is restyled according to page context, combined with
 * the menu contents that core's `data-languages` portlet supplies.
 *
 * Two variants are produced from one set of inputs:
 *
 *   - a prominent, progressive button that carries the number of available languages as its
 *     visible label. It is used on subject pages that exist, and on special pages that do have
 *     interlanguage links.
 *   - a quiet, icon-only button with no visible label, used everywhere else — most commonly on
 *     talk pages and on pages that do not exist yet.
 *
 * Every identifier emitted below belongs to MediaWiki core or to
 * Extension:UniversalLanguageSelector, never to this skin, so each one is reproduced verbatim
 * instead of being re-expressed under this skin's own presentational prefix the way purely
 * decorative class names are: the portlet id `p-lang-btn`, the heading classes
 * `mw-portlet-lang-heading-empty` and `mw-portlet-lang-heading-<count>`, the layout hook
 * `mw-portlet-lang-icon-only`, and the checkbox classes `mw-interlanguage-selector` and
 * `mw-interlanguage-selector-empty` that `ext.uls.interface` attaches its click handler to.
 * Renaming any of them would silently break language switching for ULS, for gadgets and for user
 * scripts, which is why this class deliberately contains no skin-prefixed string at all.
 *
 * Notion's appearance is contributed entirely by the Codex `cdx-button` classes emitted below,
 * resolved through the skin's design-token layer and refined by the skin's own
 * `components/LanguageDropdown.less` stylesheet. No colour, spacing, radius, shadow or other
 * visual value is decided here, and no inline style is ever emitted.
 *
 * The control is fully usable without JavaScript: the dropdown is disclosed by the checkbox hack
 * that `NotionComponentDropdown` and the dropdown templates implement, and ULS merely enhances
 * it when present.
 *
 * Both label strings arrive already localised from `SkinNotion`, so this class resolves no
 * message of its own. That keeps it a pure, deterministic transformation of its constructor
 * arguments, which is what makes its emitted data safe to lock behind a snapshot test.
 */
class NotionComponentLanguageDropdown implements NotionComponent {
	/**
	 * Menu-contents payload passed straight through to `>MenuContents`.
	 *
	 * Assembled once in the constructor because the three source strings are not needed
	 * individually anywhere else, and declared here rather than promoted because a `readonly`
	 * property may only be assigned from the constructor body when it is not a promoted
	 * parameter.
	 */
	private readonly array $menuContentsData;

	/**
	 * @param string $label human readable, already localised by SkinNotion. On a page with
	 *   interlanguage links this is the language count, for example "12 languages"; where there
	 *   are none it is the bare "Add languages" style label.
	 * @param string $ariaLabel label for accessibility, already localised. Emitted as
	 *   `aria-description` and, for the quiet icon-only variant which renders no visible label,
	 *   it is the only description assistive technology has to work with.
	 * @param string $class of the dropdown component, taken from core's `data-languages`
	 *   portlet. It is appended to rather than replaced when the quiet variant is selected,
	 *   which is precisely why this promoted property is not declared readonly.
	 * @param int $numLanguages number of interlanguage links available for the page. Used twice
	 *   and for two different purposes: as the guard deciding whether a special page shows its
	 *   links, and as the count baked into the `mw-portlet-lang-heading-<count>` class that
	 *   stylesheets and extensions select on.
	 * @param string $itemHTML the HTML of the list e.g. `<li>...</li>`. Emitted unescaped by
	 *   `>MenuContents`, so the caller owns its escaping.
	 *   The `@todo` below records an upstream API shape inherited from the component set this
	 *   skin mirrors, not work deferred from this class: passing pre-rendered HTML is what
	 *   core's `data-languages` portlet hands over today, and replacing it with a menu-contents
	 *   class would change the eight-argument positional contract `SkinNotion` calls through.
	 * @param string $beforePortlet no known usages. Perhaps can be removed in future; the
	 *   parameter is retained because removing it would renumber the positional arguments that
	 *   `SkinNotion` supplies.
	 * @param string $afterPortlet used by Extension:ULS
	 * @param Title|null $title page this control is rendered for. Null when no title is
	 *   available, in which case the quiet variant is selected.
	 */
	public function __construct(
		private readonly string $label,
		private readonly string $ariaLabel,
		private string $class,
		private readonly int $numLanguages,
		// @todo: replace with >MenuContents class.
		string $itemHTML,
		string $beforePortlet = '',
		string $afterPortlet = '',
		private readonly ?Title $title = null,
	) {
		$this->menuContentsData = [
			'html-items' => $itemHTML,
			'html-before-portal' => $beforePortlet,
			'html-after-portal' => $afterPortlet,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		$title = $this->title;
		$isSubjectPage = ( $title && $title->exists() && !$title->isTalkPage() ) ||
			( $title && $title->isSpecialPage() && $this->numLanguages );
		// If page doesn't exist or if it's in a talk namespace, we should
		// display a less prominent "language" button, without a label, and
		// quiet instead of progressive. For this reason some default values
		// should be updated for this case. (T316559)
		//
		// However, if it is a special page and has interlanguage links, those
		// should be displayed. (T389192)
		$buttonClasses = 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--weight-quiet';
		if ( !$isSubjectPage ) {
			$icon = 'language';
			$this->class .= ' mw-portlet-lang-icon-only';
			$labelClass = $buttonClasses . ' cdx-button--icon-only mw-portlet-lang-heading-empty';
			$checkboxClass = 'mw-interlanguage-selector-empty';
		} else {
			$icon = 'language-progressive';
			$labelClass = $buttonClasses . ' cdx-button--action-progressive'
				. ' mw-portlet-lang-heading-' . strval( $this->numLanguages );
			$checkboxClass = 'mw-interlanguage-selector';
		}
		// The dropdown is constructed without an icon on purpose. Passing one would make
		// NotionComponentDropdown treat this control as icon-only, and the icon it selects is
		// overwritten below anyway because only this class knows which of the two language
		// glyphs the page context calls for.
		$dropdown = new NotionComponentDropdown( 'p-lang-btn', $this->label, $this->class );
		$dropdownData = $dropdown->getTemplateData();
		// override default heading class.
		$dropdownData['label-class'] = $labelClass;
		// ext.uls.interface attaches click handler to this selector.
		$dropdownData['checkbox-class'] = $checkboxClass;
		$dropdownData['icon'] = $icon;
		$dropdownData['aria-description'] = $this->ariaLabel;
		$dropdownData['is-language-selector-empty'] = !$isSubjectPage;

		// Left-biased on purpose: `+` keeps the left operand's value for any key present in
		// both, so the dropdown data — including everything overridden above — always wins over
		// the menu contents. The three menu-contents keys do not collide with the dropdown's
		// today; the operand order guarantees that a future collision cannot quietly undo an
		// override made here.
		return $dropdownData + $this->menuContentsData;
	}
}
