<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Config\Config;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Linker\Linker;
use MediaWiki\Title\Title;

/**
 * NotionComponentSearchBox component
 *
 * Assembles the template data that `includes/templates/SearchBox.mustache` renders for the
 * skin's search field. The class takes the search-box data core already produced -- the form
 * action, the input attributes, the search page title -- and augments it with the presentation
 * flags and marker classes the skin's own markup and stylesheets key off.
 *
 * The component is constructed twice per request by `includes/SkinNotion.php`, and the two
 * constructions differ only in their flags: the primary field in the fixed header is
 * collapsible, primary and width-auto-expanding under the form id `searchform`, while the
 * sticky header's second, independent field is none of those and uses the form id
 * `notion-sticky-search-form`. Both pass `Constants::SEARCH_BOX_INPUT_LOCATION_MOVED`. That is
 * precisely why every flag is a constructor parameter rather than something derived here: one
 * class serves both call sites, and the caller -- which alone knows which of the two boxes it
 * is building -- decides. The eight parameters are consumed positionally by both call sites, so
 * their order is a hard contract; reordering them would be a silent behavioural change rather
 * than a fatal error.
 *
 * The typeahead itself remains a Codex component and is deliberately not reimplemented. The
 * template emits Codex's own `cdx-search-input`, `cdx-text-input` and `cdx-typeahead-search`
 * markup, which the `skins.notion.search.codex.styles` module styles, and Notion's look reaches
 * it through the skin's design-token layer plus the Codex `skinStyles` override -- the warm
 * off-white field fill, the hairline border, the surface radius, the inset magnifier and the
 * softly raised results panel are all decided there. Consequently this class contributes marker
 * class names and data only: no colour, spacing, radius, shadow or font literal, and no inline
 * `style` attribute, may ever appear here, because the token layer is the skin's single source
 * of visual truth and a literal emitted from PHP would sit outside it.
 *
 * Four class names are owned by this component and the values defined below are the contract
 * that `resources/skins.notion.styles/components/SearchBox.less` keys off:
 *
 *   - `notion-search-box-vue`, always present, which pairs with the `skin-notion-search-vue`
 *     body class declared in `skin.json`;
 *   - `notion-search-box-collapses`, when the field may collapse to its toggle button;
 *   - `notion-search-box-show-thumbnail`, when the typeahead shows result thumbnails;
 *   - `notion-search-box-auto-expand-width`, when the field widens as results appear.
 *
 * Search works with JavaScript disabled. The collapsed-state button is built unconditionally
 * and given a real `href` -- the local URL of the search page core named in the data -- so at
 * narrow viewports, where the input is hidden, the affordance is still a working link to the
 * search page rather than a dead control awaiting a script. Its `title` and `accesskey` come
 * from `Linker::tooltipAndAccesskeyAttribs( 'search' )` and are forwarded to the button
 * untouched, which is what keeps the skin's tooltip and access-key behaviour identical to every
 * other MediaWiki skin.
 *
 * Exactly one message key is resolved here and it is MediaWiki core's own, reproduced verbatim:
 * `search`. It is used twice -- once for the button's label and once, through
 * `Linker::tooltipAndAccesskeyAttribs()`, for its tooltip and access key. It is deliberately not
 * renamed to a `notion-` key and deliberately not added to the skin's `i18n/en.json`, because
 * core already defines it and duplicating it would only risk the two drifting apart.
 *
 * The returned structure is plain data -- arrays, strings and booleans only, never a component
 * object -- because Mustache can traverse nothing else and because the component snapshots are
 * JSON. The collapsed-state button is therefore reduced through `getTemplateData()` before it
 * is stored.
 *
 * @internal
 */
class NotionComponentSearchBox implements NotionComponent {
	private const SEARCH_COLLAPSIBLE_CLASS = 'notion-search-box-collapses';
	private const SEARCH_SHOW_THUMBNAIL_CLASS = 'notion-search-box-show-thumbnail';
	private const SEARCH_AUTO_EXPAND_WIDTH_CLASS = 'notion-search-box-auto-expand-width';

	/**
	 * Configuration the component reads its typeahead options from.
	 */
	private function getConfig(): Config {
		return $this->config;
	}

	/**
	 * Returns `true` if Vue search is enabled to show thumbnails and `false` otherwise.
	 * Note this is only relevant for Vue search experience (not legacy search).
	 */
	private function doesSearchHaveThumbnails(): bool {
		$searchOptions = $this->getConfig()->get( 'NotionTypeahead' )['options'];
		return $searchOptions['showThumbnail'];
	}

	/**
	 * Gets the value of the "input-location" parameter for the SearchBox Mustache template.
	 *
	 * @return string Either `Constants::SEARCH_BOX_INPUT_LOCATION_DEFAULT` or
	 *  `Constants::SEARCH_BOX_INPUT_LOCATION_MOVED`
	 */
	private function getSearchBoxInputLocation(): string {
		return $this->location;
	}

	/**
	 * @param array $searchBoxData Search-box data from core, carrying `form-action`,
	 *   `html-input-attributes` and `page-title` among others. It is copied and augmented
	 *   rather than replaced, so keys the skin does not care about reach the template intact.
	 * @param bool $isCollapsible Whether the field may collapse to its toggle button at narrow
	 *   viewports. True for the header's primary field, false for the sticky header's, whose
	 *   own collapse is handled by the sticky bar itself.
	 * @param bool $isPrimary Whether this is the page's primary search field. The template
	 *   gives the primary one core's `p-search`, `simpleSearch` and `searchInput` ids and
	 *   core's input attributes, so exactly one box per page may be primary.
	 * @param string $formId Value of the form's `id` attribute, `searchform` for the primary
	 *   field and `notion-sticky-search-form` for the sticky header's. The two must differ:
	 *   ids are unique per document and the value also names the box in instrumentation.
	 * @param bool $autoExpandWidth Whether the field may widen as results appear. Only
	 *   honoured when thumbnails are shown; see ::getTemplateData().
	 * @param Config $config Supplies the `NotionTypeahead` options.
	 * @param string $location Either `Constants::SEARCH_BOX_INPUT_LOCATION_DEFAULT` or
	 *   `Constants::SEARCH_BOX_INPUT_LOCATION_MOVED`. Passed in rather than decided here
	 *   because it is instrumentation as well as layout: the template writes it out as the
	 *   `data-search-loc` attribute.
	 * @param MessageLocalizer $localizer Resolves the collapsed-state button's label.
	 */
	public function __construct(
		private readonly array $searchBoxData,
		private readonly bool $isCollapsible,
		private readonly bool $isPrimary,
		private readonly string $formId,
		private readonly bool $autoExpandWidth,
		private readonly Config $config,
		private readonly string $location,
		private readonly MessageLocalizer $localizer,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		$searchBoxData = $this->searchBoxData;
		$isCollapsible = $this->isCollapsible;
		$isThumbnail = $this->doesSearchHaveThumbnails();
		// Widening the field as results appear only makes sense when those results carry
		// thumbnails, so the caller's preference is a necessary but not a sufficient condition.
		$isAutoExpand = $isThumbnail && $this->autoExpandWidth;
		$isPrimary = $this->isPrimary;
		$formId = $this->formId;

		$searchClass = 'notion-search-box-vue ';
		$searchClass .= $isCollapsible ? ' ' . self::SEARCH_COLLAPSIBLE_CLASS : '';
		$searchClass .= $isThumbnail ? ' ' . self::SEARCH_SHOW_THUMBNAIL_CLASS : '';
		$searchClass .= $isAutoExpand ? ' ' . self::SEARCH_AUTO_EXPAND_WIDTH_CLASS : '';

		// Annotate search box with a component class.
		$searchBoxData['class'] = trim( $searchClass );
		$searchBoxData['is-collapsible'] = $isCollapsible;
		$searchBoxData['is-thumbnail'] = $isThumbnail;
		$searchBoxData['is-auto-expand'] = $isAutoExpand;
		$searchBoxData['is-primary'] = $isPrimary;
		$searchBoxData['form-id'] = $formId;
		$searchBoxData['input-location'] = $this->getSearchBoxInputLocation();

		// At lower resolutions the search input is hidden and only the submit button is shown.
		// It therefore behaves like a form submit link (e.g. submit the form with no input
		// value) by pointing at the search page core named in the data, which is what keeps
		// search usable with JavaScript disabled. Upstream tracks this behaviour as T284242.
		$collapseIconAttrs = Linker::tooltipAndAccesskeyAttribs( 'search' );
		$searchButton = new NotionComponentButton(
			$this->localizer->msg( 'search' )->text(),
			'search',
			null,
			'search-toggle',
			$collapseIconAttrs,
			'quiet',
			'default',
			true,
			Title::newFromText( $searchBoxData['page-title'] )->getLocalURL()
		);
		$searchBoxData['data-collapsed-search-button'] = $searchButton->getTemplateData();

		return $searchBoxData;
	}
}
