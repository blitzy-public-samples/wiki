<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;

/**
 * NotionComponentStickyHeader component
 *
 * Server-rendered view model for the slim bar the Notion skin reveals once the reader has
 * scrolled away from the top of a page. The bar duplicates the controls a reader reaches for
 * mid-article -- talk, subject, history, watch or bookmark, edit, view source and add section --
 * alongside a second, independent search box, so that none of them requires scrolling back up.
 *
 * `StickyHeader.mustache` consumes the four keys this class emits. Two further keys the same
 * template reads, the table of contents and its dropdown, are supplied by the skin class rather
 * than here, because the sticky table of contents is *moved* into the bar from the sidebar
 * instead of being duplicated into it.
 *
 * Four properties of this class are load-bearing and must survive any later edit.
 *
 * **The emitted data is already complete for the unscrolled state.** The client script only adds
 * the scrolled state and fills in the icon-button labels; it never supplies data this class
 * failed to emit. That is what keeps the bar correct with scripts disabled, and it is why every
 * button is constructed here rather than in the browser.
 *
 * **Every borrowed name below is reproduced exactly, and their ownership is not uniform.** Getting
 * this right matters, because the wrong attribution invites the wrong kind of edit.
 *
 *   - The `ca-` prefix is core's convention: `SkinTemplate` builds a content-navigation id by
 *     prepending it, which is where the base ids `ca-talk`, `ca-history`, `ca-edit` and
 *     `ca-viewsource` come from. Core emits those on the page itself.
 *   - The `-sticky-header` suffixed ids this class emits are NOT core's. They are clones this bar
 *     creates -- following the reference skin, which introduced them -- so that a duplicated
 *     control does not collide with the original id it copies. No `-sticky-header` id appears
 *     anywhere in core.
 *   - `mw-watchlink` genuinely is core's: `SkinTemplate` composes it for the watch link, and
 *     core's `mediawiki.page.watch.ajax` module selects on it.
 *   - `reading-lists-bookmark` belongs to Extension:ReadingLists and appears nowhere in core.
 *
 * All of them are reproduced verbatim and deliberately *not* given a `notion-` prefix, because
 * gadgets, extensions and the skin's own script locate these controls by exactly these names --
 * the clones included, since scripts written for the reference skin already target them. Exactly
 * one genuinely skin-owned presentational name appears here, and it alone carries the prefix:
 * `notion-sticky-header-search-toggle`.
 *
 * **Every button is removed from sequential keyboard navigation with `tabindex="-1"`.** This is a
 * deliberate accessibility decision, not an oversight: the bar is a duplicate of controls that
 * already exist in the page's tab order, and the template additionally hides it from assistive
 * technology with `aria-hidden`. Leaving the copies focusable would make readers tab through
 * every control twice and would focus content inside an `aria-hidden` subtree. See
 * https://phabricator.wikimedia.org/T290201 for the full rationale.
 *
 * **Both component parameters are typed against the `NotionComponent` interface on purpose.** The
 * skin class hands `$search` a search-box component and `$langButton` a button component -- two
 * unrelated concrete types whose only shared ancestor is the interface. Narrowing either
 * parameter to a concrete class would break the skin class at construction time.
 *
 * Nothing about the bar's appearance is decided here. Its stylesheet sits beside its script at
 * `resources/skins.notion.js/stickyHeader.less`, which is where the solid base surface, the
 * hairline bottom border and the drop shadow that appears only once the page is scrolled are
 * expressed, all of them through design tokens. Accordingly no colour, spacing, radius, shadow or
 * font literal, and no inline `style` attribute, may ever appear in this class.
 *
 * @internal
 */
class NotionComponentStickyHeader implements NotionComponent {
	private const TALK_ICON = [
		'icon' => 'speechBubbles',
		'id' => 'ca-talk-sticky-header',
		'event' => 'talk-sticky-header',
		'class' => ''
	];
	private const SUBJECT_ICON = [
		'icon' => 'article',
		'id' => 'ca-subject-sticky-header',
		'event' => 'subject-sticky-header',
		'class' => ''
	];
	private const HISTORY_ICON = [
		'icon' => 'wikimedia-history',
		'id' => 'ca-history-sticky-header',
		'event' => 'history-sticky-header',
		'class' => ''
	];
	// Event and icon will be updated depending on watchstar state
	private const WATCHSTAR_ICON = [
		'id' => 'ca-watchstar-sticky-header',
		'event' => 'watch-sticky-header',
		'icon' => 'wikimedia-star',
		// The 'is-quiet' and 'tabindex' keys carried by this descriptor and by BOOKMARK_ICON are
		// vestigial: getIconButtons() passes the 'quiet' weight and a fresh tabindex attribute for
		// every icon regardless of what its descriptor holds. They are retained so the descriptors
		// stay faithful to the reference implementation; removing them is a separate, deliberate
		// change rather than a side effect of this one.
		'is-quiet' => true,
		'tabindex' => '-1',
		// With the original watchstar, this class is applied to the <li> element
		// thats the parent of the actual watchlink. In the sticky header we dont use
		// the same markup, so its directly applied to the watchlink element
		'class' => 'mw-watchlink'
	];
	// Event and icon will be updated depending on saved state
	private const BOOKMARK_ICON = [
		'id' => 'ca-bookmark-sticky-header',
		'event' => 'watch-sticky-bookmark',
		// This icon ships with Extension:ReadingLists rather than with the skin's own icon packs,
		// which is consistent with the descriptor only ever being reachable when that extension is
		// installed and has contributed a reading-lists entry to the personal toolbar.
		'icon' => 'wikimedia-bookmarkOutline',
		'is-quiet' => true,
		'tabindex' => '-1',
		'class' => 'reading-lists-bookmark'
	];
	private const EDIT_VE_ICON = [
		'id' => 'ca-ve-edit-sticky-header',
		'event' => 've-edit-sticky-header',
		'icon' => 'wikimedia-edit',
		'class' => ''
	];
	private const EDIT_WIKITEXT_ICON = [
		'id' => 'ca-edit-sticky-header',
		'event' => 'wikitext-edit-sticky-header',
		'icon' => 'wikimedia-wikiText',
		'class' => ''
	];
	private const EDIT_PROTECTED_ICON = [
		'href' => '#',
		'id' => 'ca-viewsource-sticky-header',
		'event' => 've-edit-protected-sticky-header',
		'icon' => 'wikimedia-editLock',
		'class' => ''
	];

	/**
	 * @param MessageLocalizer $localizer Resolves the add-section and search labels.
	 * @param NotionComponent $search The bar's own search box. This is a second, independent
	 *   search component rather than a reference to the one in the fixed header, so that the two
	 *   carry distinct form ids and neither interferes with the other. Typed against the
	 *   interface because the skin class supplies a search-box component here.
	 * @param NotionComponent|null $langButton Language button, present only when the Universal
	 *   Language Selector is enabled and languages are not hidden, and null otherwise. Typed
	 *   against the interface because the skin class supplies a button component here, which
	 *   shares no concrete ancestor with the search box above.
	 * @param bool $visualEditorTabPositionFirst Whether the visual editor tab precedes the
	 *   wikitext editor tab on this page. This selects the order of the two edit icons; both are
	 *   always emitted regardless.
	 * @param bool $isReadingListsEnabled Whether Extension:ReadingLists contributed a reading-list
	 *   entry to the personal toolbar. Selects the bookmark affordance in place of the watchstar.
	 */
	public function __construct(
		private readonly MessageLocalizer $localizer,
		private readonly NotionComponent $search,
		private readonly ?NotionComponent $langButton = null,
		private readonly bool $visualEditorTabPositionFirst = false,
		private readonly bool $isReadingListsEnabled = false,
	) {
	}

	/**
	 * Resolve an interface message for this component.
	 *
	 * The parameter is annotated loosely because callers pass either a single key or an array
	 * describing a fallback sequence, of which core resolves the first key that actually exists.
	 *
	 * @param mixed $key
	 * @return Message
	 */
	private function msg( $key ): Message {
		return $this->localizer->msg( $key );
	}

	/**
	 * Creates array of Button components in the sticky header
	 *
	 * Seven icons are emitted, in a fixed order: talk, subject and history; then either the
	 * bookmark or the watchstar; then *both* edit icons, whose relative order alone depends on
	 * $visualEditorTabPositionFirst; then the protected-page view-source icon. Emitting both edit
	 * icons unconditionally is required, because the client script decides which of the pair to
	 * reveal from the edit tabs the page actually offers. Collapsing them into a single conditional
	 * would leave one editing route permanently unreachable from the bar.
	 *
	 * @return array of NotionComponentButton, one per icon, in render order
	 */
	private function getIconButtons() {
		$icons = [
			self::TALK_ICON,
			self::SUBJECT_ICON,
			self::HISTORY_ICON
		];
		$icons[] = $this->isReadingListsEnabled ? self::BOOKMARK_ICON : self::WATCHSTAR_ICON;
		$icons[] = $this->visualEditorTabPositionFirst ? self::EDIT_VE_ICON : self::EDIT_WIKITEXT_ICON;
		$icons[] = $this->visualEditorTabPositionFirst ? self::EDIT_WIKITEXT_ICON : self::EDIT_VE_ICON;
		$icons[] = self::EDIT_PROTECTED_ICON;
		$iconButtons = [];
		foreach ( $icons as $icon ) {
			$iconButtons[] = new NotionComponentButton(
				// Button labels will be populated in stickyHeader.js
				"",
				$icon['icon'],
				$icon['id'],
				$icon['class'],
				[
					'tabindex' => '-1',
					'data-event-name' => $icon['event'],
				],
				'quiet',
				'default',
				true,
				'#'
			);
		}
		return $iconButtons;
	}

	/**
	 * Creates button data for the "Add section" button in the sticky header
	 *
	 * The label resolves through a fallback sequence. The skin-specific key is an optional
	 * override that a wiki may create on-wiki; when it does not exist, core's own key supplies
	 * the label, so the button never renders a bare message name.
	 *
	 * @return NotionComponentButton
	 */
	private function getAddSectionButton() {
		return new NotionComponentButton(
			$this->msg( [ 'notion-action-addsection', 'skin-action-addsection' ] )->text(),
			'speechBubbleAdd-progressive',
			'ca-addsection-sticky-header',
			'',
			[
				'tabindex' => '-1',
				'data-event-name' => 'addsection-sticky-header'
			],
			'quiet',
			'progressive',
			false,
			'#'
		);
	}

	/**
	 * Creates button data for the "search" button in the sticky header
	 *
	 * This is the toggle that expands the bar's collapsed search field, so it is the one control
	 * here that carries a skin-owned class. Its instrumentation name is composed from the form id
	 * of the search box it belongs to, which is what keeps the bar's search events distinct from
	 * those of the search box in the fixed header.
	 *
	 * @param array $searchBoxData
	 * @return NotionComponentButton
	 */
	private function getSearchButton( $searchBoxData ) {
		return new NotionComponentButton(
			$this->msg( 'search' )->text(),
			'search',
			null,
			'notion-sticky-header-search-toggle',
			[
				'tabindex' => '-1',
				'data-event-name' => 'ui.' . $searchBoxData['form-id'] . '.icon'
			],
			'quiet',
			'default',
			true
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Emits `array-icon-buttons`, `array-buttons`, `data-button-start` and `data-search`. Every
	 * value is reduced to plain template data before it is returned -- the button components are
	 * mapped through `getTemplateData()` rather than emitted as objects -- so the result stays
	 * deterministic and fully JSON-encodable for snapshot assertions.
	 *
	 * The language button, when present, is deliberately placed ahead of the add-section button in
	 * `array-buttons`, and is simply omitted when the skin class passed none.
	 */
	public function getTemplateData(): array {
		$iconButtonData = array_map( static function ( $btn ) {
			return $btn->getTemplateData();
		}, $this->getIconButtons() );
		$buttonData = $this->langButton ? [ $this->langButton->getTemplateData() ] : [];
		$buttonData[] = $this->getAddSectionButton()->getTemplateData();
		$searchBoxData = $this->search->getTemplateData();
		$searchButtonData = $this->getSearchButton( $searchBoxData )->getTemplateData();
		return [
			'array-icon-buttons' => $iconButtonData,
			'array-buttons' => $buttonData,
			'data-button-start' => $searchButtonData,
			'data-search' => $searchBoxData,
		];
	}
}
