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
 *   - a quiet, icon-only button with no visible label, used everywhere else. "Everywhere else" is
 *     exactly four cases, taken from the condition in ::getTemplateData(): there is no title at
 *     all, the page does not exist, the title is a talk page, or the title is a special page with
 *     no interlanguage links. Note that talk pages take the quiet variant however many languages
 *     they have, and that a non-existent subject page does too.
 *
 * No identifier emitted below is owned by this skin, so each is reproduced verbatim instead of
 * being re-expressed under this skin's own presentational prefix the way purely decorative class
 * names are. Their provenance differs, though, and it is worth stating exactly, because "core owns
 * it" is a stronger claim than the evidence supports for most of them:
 *
 *   - `p-lang-btn`, the button's id, was introduced by the Vector 2022 skin, not by core. Core's
 *     own id for the language portlet is `p-lang` (see `BaseTemplate`); the `-btn` form is the one
 *     Vector emits for the button treatment, and gadgets and user scripts have been written
 *     against it ever since.
 *   - `mw-portlet-lang-heading-empty` and `mw-portlet-lang-heading-<count>`, and the layout hook
 *     `mw-portlet-lang-icon-only`, are likewise Vector's. They appear nowhere in core.
 *   - `mw-interlanguage-selector` and `mw-interlanguage-selector-empty` belong to
 *     Extension:UniversalLanguageSelector, whose `ext.uls.interface` module attaches its click
 *     handler to them. These are the genuinely external ones.
 *
 * What all of them share is the reason they are kept: each is a de facto compatibility contract
 * that ULS, gadgets and user scripts select on today. Renaming any one would silently break
 * language switching for those consumers, which is why this class deliberately contains no
 * skin-prefixed string at all.
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
	 *   portlet. The quiet variant adds the `mw-portlet-lang-icon-only` layout hook to it, and that
	 *   addition is made on a local copy inside `getTemplateData()` rather than on this property:
	 *   appending to the property itself would make the method non-idempotent, so a second call --
	 *   which a caller assembling both the in-page control and its sticky-header clone from one
	 *   component would make -- would emit the hook twice, and the component's snapshot would
	 *   depend on how many times it had been asked. The property is therefore readonly, and the
	 *   method is a pure function of the constructor arguments.
	 * @param int $numLanguages number of interlanguage links available for the page. Used twice
	 *   and for two different purposes: as the guard deciding whether a special page shows its
	 *   links, and as the count baked into the `mw-portlet-lang-heading-<count>` class that
	 *   stylesheets and extensions select on.
	 * @param string $itemHTML the HTML of the list e.g. `<li>...</li>`. Emitted unescaped by
	 *   `>MenuContents`, so the caller owns its escaping. Pre-rendered HTML is the finished
	 *   contract here, not a placeholder for a menu-contents class: it is exactly what core's
	 *   `data-languages` portlet hands over, so interposing a menu-contents component would
	 *   re-parse markup core has already assembled and would change the eight-argument positional
	 *   contract `SkinNotion` calls through, for no gain in what reaches the template.
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
		private readonly string $class,
		private readonly int $numLanguages,
		// Pre-rendered list HTML, for the reason given in the parameter documentation above.
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
		// The portlet class is composed locally rather than by appending to the promoted property.
		// `getTemplateData()` has to be a pure function of the constructor arguments: it is called
		// once per render today, but a component that rewrote its own state would append the
		// icon-only class again on a second call, and its snapshot test would depend on how many
		// times it had been asked. The property is `readonly` so the language enforces that.
		$class = $this->class;
		if ( !$isSubjectPage ) {
			$icon = 'language';
			$class .= ' mw-portlet-lang-icon-only';
			// Genuinely icon-only here: there is no visible label to sit beside the glyph.
			$iconOnly = true;
			$labelClass = 'mw-portlet-lang-heading-empty';
			$checkboxClass = 'mw-interlanguage-selector-empty';
		} else {
			$icon = 'language-progressive';
			// An icon AND a visible language-count label, so explicitly not icon-only. Saying so
			// at construction is what removed the old post-construction `label-class` rewrite.
			$iconOnly = false;
			$labelClass = 'cdx-button--action-progressive'
				. ' mw-portlet-lang-heading-' . strval( $this->numLanguages );
			$checkboxClass = 'mw-interlanguage-selector';
		}
		// Everything this control needs is declared at construction, so nothing has to be patched
		// onto the returned array afterwards. This class is the only one that knows which of the
		// two language glyphs the page context calls for and whether the resulting control carries
		// a visible label beside it, so it says both here. The Codex `cdx-button …` composition is
		// no longer restated by this class either: `$labelClass` contributes only the classes this
		// control owns -- the progressive action and the `mw-portlet-lang-heading-<count>` hook --
		// and NotionComponentDropdown appends them to its own handle classes. The `checkbox-class`
		// value is core/extension-owned -- ext.uls.interface binds its click handler to that
		// selector -- so it is passed through verbatim rather than renamed.
		$dropdown = new NotionComponentDropdown(
			'p-lang-btn',
			$this->label,
			$class,
			$icon,
			'',
			$iconOnly,
			$labelClass,
			$checkboxClass
		);
		$dropdownData = $dropdown->getTemplateData();
		// `aria-description` is not part of the dropdown's ten-key contract, so it stays an
		// addition by this consumer rather than a widening of that contract for every other one.
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
