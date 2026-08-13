<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Language\Language;
use MediaWiki\Language\LanguageConverterFactory;
use MediaWiki\StubObject\StubUserLang;

/**
 * NotionComponentVariants component
 *
 * Assembles the template data for the language-variant switcher that
 * `includes/templates/Variants.mustache` renders. The control only appears on wikis whose content
 * language has script or spelling variants -- zh, sr, kk and the like -- where it lets the reader
 * choose which variant the page is converted to.
 *
 * The switcher is a composition rather than a component in its own right: a
 * NotionComponentDropdown supplies the disclosure handle and a NotionComponentMenu supplies the
 * list of variants inside it, which is exactly the pair of partials `Variants.mustache` includes
 * as `>Dropdown/Open`, `>Menu` and `>Dropdown/Close`. Both sub-components are constructed here and
 * their emitted arrays are then adjusted, because neither can be configured for this use through
 * its constructor alone:
 *
 *   - the dropdown gains an `aria-label`. NotionComponentDropdown deliberately emits a plain,
 *     mutable array without that key so that consumers needing assistive text can add it, and the
 *     dropdown templates render it on the semantic checkbox in place of the visible label text.
 *   - the menu loses its heading. The dropdown handle already displays the active variant, so
 *     repeating the portlet's own label inside the menu would announce and show the same thing
 *     twice.
 *
 * Two details of that adjustment are load-bearing and neither is interchangeable with the obvious
 * alternative. The `aria-label` is added *after* the dropdown has produced its data, since the
 * component has no constructor parameter for it. The menu label is nulled *before* the menu is
 * constructed, since NotionComponentMenu normalises and fills in its data at construction time --
 * with `+=`, which touches only absent keys, so an explicit null survives the defaulting intact
 * where a later assignment would arrive too late to matter.
 *
 * The handle's text is resolved, not localised here: the reader's preferred variant is read from
 * the language converter and turned into that variant's own name. This class therefore looks up no
 * message of its own at all -- the accessible label arrives already localised from `SkinNotion`,
 * which resolves the declared `notion-language-variant-switcher-label` message -- which keeps the
 * class a deterministic transformation of its constructor arguments and keeps the skin's declared
 * message set unchanged.
 *
 * Every identifier below that belongs to MediaWiki core is reproduced verbatim: `emptyPortlet` is
 * core's own class for a portlet with nothing in it, and it is what hides the whole control on the
 * overwhelming majority of wikis, which have no variants. Only `notion-variants-dropdown`, the
 * dropdown's element id, is owned by this skin, and the value defined here is the contract that
 * `includes/templates/Variants.mustache` and the skin's `components/Dropdown.less` and
 * `components/Menu.less` stylesheets key off.
 *
 * No appearance is decided in this file. The switcher inherits its Codex button classes from
 * NotionComponentDropdown and its row styling from those two stylesheets on top of the skin's
 * design-token layer, so no colour, spacing, radius, shadow or inline style may ever appear here.
 * Disclosure is the checkbox hack the dropdown templates implement, which leaves the control fully
 * usable with JavaScript disabled.
 *
 * The returned structure is plain data -- arrays, strings and null only, never a component object
 * -- because Mustache can traverse nothing else and because the component snapshots are JSON.
 */
class NotionComponentVariants implements NotionComponent {
	/**
	 * Language of the page whose variants are being offered.
	 *
	 * Declared here with a union annotation instead of being promoted in the constructor: the
	 * value may be a fully realised Language or the StubUserLang placeholder that stands in for
	 * the user language until it is first touched, and PHP cannot express that union as a native
	 * property type. Narrowing it to Language would break the lazy-initialisation path rather
	 * than tidy it up.
	 *
	 * @var Language|StubUserLang
	 */
	private $pageLang;

	/**
	 * @param LanguageConverterFactory $languageConverterFactory supplies the converter for
	 *   $pageLang, which is what knows the reader's currently preferred variant.
	 * @param array $menuData core's `data-variants` portlet data. Not readonly because
	 *   ::getMenuDropdownData() suppresses the menu heading in place before handing the data to
	 *   NotionComponentMenu.
	 * @param Language|StubUserLang $pageLang language of the page being rendered, normally
	 *   `Title::getPageLanguage()`. Untyped and not promoted on purpose; see ::$pageLang.
	 * @param string $ariaLabel accessible name for the dropdown, already localised by
	 *   `SkinNotion` from `notion-language-variant-switcher-label`. It is what assistive
	 *   technology announces, since the visible handle text is a bare variant name that carries
	 *   no indication of what it controls.
	 */
	public function __construct(
		private readonly LanguageConverterFactory $languageConverterFactory,
		private array $menuData,
		$pageLang,
		private readonly string $ariaLabel,
	) {
		$this->pageLang = $pageLang;
	}

	/**
	 * Use the selected variant for the dropdown label
	 *
	 * The handle shows the variant the reader is currently viewing -- not a static "Variants"
	 * caption -- so that the control reads as a statement of the current state. The preferred
	 * variant code comes from the converter and is turned into that variant's own autonym by the
	 * page language, which is why this component needs the converter factory at all and why the
	 * label needs no message key of its own.
	 */
	private function getDropdownLabel(): string {
		$converter = $this->languageConverterFactory->getLanguageConverter( $this->pageLang );
		return $this->pageLang->getVariantname(
			$converter->getPreferredVariant()
		);
	}

	/**
	 * Get the variants dropdown data
	 *
	 * `aria-label` is assigned after the dropdown has produced its array: NotionComponentDropdown
	 * has no parameter for it and never emits the key, leaving consumers to supply their own
	 * accessible name. Without it the dropdown templates fall back to announcing the visible
	 * variant name, which says nothing about what the control does.
	 *
	 * @return array
	 */
	private function getDropdownData() {
		$dropdown = new NotionComponentDropdown(
			'notion-variants-dropdown',
			$this->getDropdownLabel(),
			// Hide dropdown if menu is empty
			$this->menuData[ 'is-empty' ] ? 'emptyPortlet' : ''
		);
		$dropdownData = $dropdown->getTemplateData();
		$dropdownData['aria-label'] = $this->ariaLabel;
		return $dropdownData;
	}

	/**
	 * Get the variants menu data
	 *
	 * The label is removed before construction rather than after, because NotionComponentMenu
	 * fills its defaults in the constructor: an explicit null passed in survives that defaulting,
	 * whereas nulling the key on an already constructed menu would have no effect on the data it
	 * has by then normalised.
	 *
	 * @return array
	 */
	private function getMenuDropdownData() {
		// Remove label from variants menu
		$this->menuData['label'] = null;
		$menu = new NotionComponentMenu( $this->menuData );
		return $menu->getTemplateData();
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		return [
			'data-variants-dropdown' => $this->getDropdownData(),
			'data-variants-menu' => $this->getMenuDropdownData()
		];
	}
}
