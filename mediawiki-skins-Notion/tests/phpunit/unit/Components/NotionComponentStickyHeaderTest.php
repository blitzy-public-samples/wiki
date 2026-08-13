<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * @file
 * @since 1.47
 */
namespace MediaWiki\Skins\Notion\Tests\Unit\Components;

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponent;
use MediaWiki\Skins\Notion\Components\NotionComponentStickyHeader;
use MediaWikiUnitTestCase;

/**
 * Isolated unit tests for the Notion skin's sticky header view model.
 *
 * The sticky header is the slim bar the skin reveals once the reader has scrolled away from the
 * top of a page. It duplicates the controls a reader reaches for mid-article and adds a second,
 * independent search box. Its template data is a contract with three separate consumers, and this
 * test exists because two of them fail *silently* when it is broken:
 *
 *   - `StickyHeader.mustache` renders the four top-level keys asserted below. A renamed key
 *     leaves a section of the bar simply empty rather than raising an error.
 *   - `resources/skins.notion.js/stickyHeader.js` locates every control by `id` -- it queries
 *     `#ca-talk-sticky-header`, `#ca-subject-sticky-header`, `#ca-history-sticky-header`,
 *     `#ca-watchstar-sticky-header`, `#ca-bookmark-sticky-header`, `#ca-ve-edit-sticky-header`,
 *     `#ca-edit-sticky-header`, `#ca-viewsource-sticky-header` and
 *     `#ca-addsection-sticky-header`, and finds the search toggle by the
 *     `.notion-sticky-header-search-toggle` class. Those `-sticky-header` ids are not core's: the
 *     `ca-` prefix is core's content-navigation convention and core emits the base ids such as
 *     `ca-talk` and `ca-history`, but the suffixed clones are created by this bar, following the
 *     reference skin that introduced them, so a duplicated control does not collide with the id it
 *     copies. `mw-watchlink` genuinely is core's, and `reading-lists-bookmark` belongs to
 *     Extension:ReadingLists. None of them may be given a `notion-` prefix, whoever owns them,
 *     because scripts written for the reference skin already target these exact names. The
 *     assertions below are byte-exact on purpose: they are what turns a well-intentioned rename
 *     into a failing test instead of a dead button.
 *   - The instrumentation pipeline consumes `data-event-name`, which is why each one is asserted
 *     by value rather than merely checked for existence.
 *
 * Two further properties are locked here. Every control carries `tabindex="-1"`, because the bar
 * duplicates content that is already in the page's tab order and the template hides it from
 * assistive technology (https://phabricator.wikimedia.org/T290201); dropping that attribute would
 * make readers tab through every control twice. And both edit buttons are always emitted, in an
 * order that depends only on which edit tab the page shows first, because the client script picks
 * which of the pair to reveal -- collapsing them into one conditional would leave an editing route
 * permanently unreachable from the bar.
 *
 * Nothing here touches a service, a global, a database or the localisation cache. The component
 * imports only `MessageLocalizer` and `Message`, and its two component parameters are typed
 * against the `NotionComponent` interface, so a mocked localizer plus interface stubs exercise
 * `getTemplateData()` in full and `MediaWikiUnitTestCase` is the correct base class. In particular
 * no real search box is constructed: doing so would drag `Title` and `Linker` service access into
 * a unit test.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentStickyHeader
 */
class NotionComponentStickyHeaderTest extends MediaWikiUnitTestCase {

	/**
	 * Suffix the mocked localizer appends to every key it is asked to resolve.
	 *
	 * Keeping the key as a prefix of the mocked output is what lets the assertions below prove
	 * *which* message produced each label, rather than merely that some message did.
	 */
	private const MOCK_SUFFIX = '-mocked-label';

	/**
	 * Separator the mocked localizer uses when it is handed a fallback sequence.
	 *
	 * `MessageLocalizer::msg()` accepts either a single key or an array of keys, of which core
	 * resolves the first that exists. The sticky header uses both forms, so the mock joins an
	 * array into one string; that keeps the whole chain, and its order, visible in assertions.
	 */
	private const FALLBACK_SEPARATOR = '|';

	/**
	 * Form id the stubbed search box reports.
	 *
	 * The search toggle composes its instrumentation name from this value, which is how the
	 * bar's search events stay distinct from those of the search box in the fixed header.
	 */
	private const SEARCH_FORM_ID = 'notion-sticky-search-form';

	/**
	 * The exact set of keys, in order, that `StickyHeader.mustache` renders against.
	 */
	private const EXPECTED_TOP_LEVEL_KEYS = [
		'array-icon-buttons',
		'array-buttons',
		'data-button-start',
		'data-search',
	];

	/**
	 * The keys every button emits, in the order `NotionComponentButton` returns them.
	 */
	private const EXPECTED_BUTTON_KEYS = [
		'label',
		'icon',
		'id',
		'class',
		'href',
		'array-attributes',
	];

	/**
	 * Codex classes shared by all seven icon buttons.
	 *
	 * The fake-button pair is present because every icon button is given an `href`, so it renders
	 * as an anchor styled as a button rather than as a real `<button>`.
	 */
	private const ICON_BUTTON_CLASSES = 'cdx-button cdx-button--fake-button ' .
		'cdx-button--fake-button--enabled cdx-button--weight-quiet cdx-button--icon-only';

	/**
	 * Number of icon buttons the bar always emits.
	 */
	private const ICON_BUTTON_COUNT = 7;

	/**
	 * A `MessageLocalizer` that echoes back whichever key, or chain of keys, it is handed.
	 *
	 * The component only ever calls `Message::text()` on what it receives, so `text()` is the one
	 * method that needs a return value. `__toString()` is configured alongside it so a stringified
	 * message can never leak a bare mock into an assertion.
	 *
	 * @return MessageLocalizer
	 */
	private function newLocalizer(): MessageLocalizer {
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback( function ( $key ) {
			$label = self::flattenMessageKey( $key ) . self::MOCK_SUFFIX;
			return $this->createConfiguredMock( Message::class, [
				// Simulated localization output.
				'text' => $label,
				'__toString' => $label,
			] );
		} );

		return $localizer;
	}

	/**
	 * As newLocalizer(), but additionally records every key argument it is called with.
	 *
	 * Recording the raw argument is the only way to assert that a *fallback chain* was requested
	 * as an array, in the right order, rather than inferring it from the flattened label.
	 *
	 * @param array &$requestedKeys Receives one entry per `msg()` call, each either a string key
	 *   or the array of keys describing a fallback sequence.
	 * @return MessageLocalizer
	 */
	private function newRecordingLocalizer( array &$requestedKeys ): MessageLocalizer {
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback(
			function ( $key ) use ( &$requestedKeys ) {
				$requestedKeys[] = $key;
				$label = self::flattenMessageKey( $key ) . self::MOCK_SUFFIX;
				return $this->createConfiguredMock( Message::class, [
					'text' => $label,
					'__toString' => $label,
				] );
			}
		);

		return $localizer;
	}

	/**
	 * Render a message key argument as a single predictable string.
	 *
	 * Handling both shapes is essential rather than defensive: the add-section label is requested
	 * as an array, so a callback that assumed a string would hand the component a null label and
	 * turn a clear assertion failure into a confusing one.
	 *
	 * @param string|string[] $key Single message key, or a fallback sequence of keys.
	 * @return string
	 */
	private static function flattenMessageKey( $key ): string {
		return is_array( $key ) ? implode( self::FALLBACK_SEPARATOR, $key ) : (string)$key;
	}

	/**
	 * A stub standing in for any collaborating component.
	 *
	 * Both component parameters of the sticky header are typed against the `NotionComponent`
	 * interface, whose whole surface is `getTemplateData()`, so an interface stub is a complete
	 * and honest substitute for the real search box or language button.
	 *
	 * @param array $templateData Data the stub reports.
	 * @return NotionComponent
	 */
	private function newComponentStub( array $templateData ): NotionComponent {
		return $this->createConfiguredMock( NotionComponent::class, [
			'getTemplateData' => $templateData,
		] );
	}

	/**
	 * The search box stub, reporting the one key the search toggle reads from it.
	 *
	 * @return NotionComponent
	 */
	private function newSearchStub(): NotionComponent {
		return $this->newComponentStub( [ 'form-id' => self::SEARCH_FORM_ID ] );
	}

	/**
	 * Build a sticky header with the default switch settings and a stubbed search box.
	 *
	 * @param NotionComponent|null $langButton Language button, or null when languages are hidden.
	 * @param bool $visualEditorTabPositionFirst Whether the visual editor tab comes first.
	 * @param bool $isReadingListsEnabled Whether Extension:ReadingLists is contributing.
	 * @return NotionComponentStickyHeader
	 */
	private function newStickyHeader(
		?NotionComponent $langButton = null,
		bool $visualEditorTabPositionFirst = false,
		bool $isReadingListsEnabled = false
	): NotionComponentStickyHeader {
		return new NotionComponentStickyHeader(
			$this->newLocalizer(),
			$this->newSearchStub(),
			$langButton,
			$visualEditorTabPositionFirst,
			$isReadingListsEnabled
		);
	}

	/**
	 * The component is usable wherever the skin expects a `NotionComponent`.
	 *
	 * `SkinNotion` collects components polymorphically, so satisfying the interface is a real
	 * requirement rather than a formality: the skin would fail at construction time otherwise.
	 *
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$stickyHeader = $this->newStickyHeader();

		$this->assertInstanceOf(
			NotionComponent::class,
			$stickyHeader,
			'The sticky header must satisfy the component interface the skin collects it through.'
		);
	}

	/**
	 * The four top-level keys, and the shape of everything hanging off them.
	 *
	 * Asserting the whole key set -- and its order -- rather than the presence of individual keys
	 * is what turns a renamed, added or silently dropped key into a failing test instead of an
	 * empty region in the rendered bar.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataStructure() {
		$searchData = [ 'form-id' => self::SEARCH_FORM_ID ];
		$stickyHeader = new NotionComponentStickyHeader(
			$this->newLocalizer(),
			$this->newComponentStub( $searchData )
		);

		$templateData = $stickyHeader->getTemplateData();

		$this->assertSame(
			self::EXPECTED_TOP_LEVEL_KEYS,
			array_keys( $templateData ),
			'The template contract is exactly these four keys, in this order.'
		);

		// The search box's own data is forwarded untouched: the sticky header composes a button
		// from it but must never rewrite it, or the second search box would stop matching the
		// component that actually renders it.
		$this->assertSame(
			$searchData,
			$templateData['data-search'],
			'The search box component data must be passed through verbatim.'
		);

		$iconButtons = $templateData['array-icon-buttons'];
		$this->assertCount(
			self::ICON_BUTTON_COUNT,
			$iconButtons,
			'The bar always emits seven icon buttons, whatever the switch settings.'
		);
		$this->assertSame(
			[
				'ca-talk-sticky-header',
				'ca-subject-sticky-header',
				'ca-history-sticky-header',
				'ca-watchstar-sticky-header',
				'ca-edit-sticky-header',
				'ca-ve-edit-sticky-header',
				'ca-viewsource-sticky-header',
			],
			array_column( $iconButtons, 'id' ),
			'Default icon order: talk, subject, history, watchstar, wikitext edit, visual edit, view source.'
		);

		foreach ( $iconButtons as $index => $iconButton ) {
			$this->assertSame(
				self::EXPECTED_BUTTON_KEYS,
				array_keys( $iconButton ),
				"Icon button $index must emit the full button key set."
			);
			// Icon buttons are label-less on the server; stickyHeader.js fills the labels in once
			// it knows which controls the page actually offers.
			$this->assertSame(
				'',
				$iconButton['label'],
				"Icon button $index carries no server-rendered label."
			);
			$this->assertStringContainsString(
				'cdx-button--icon-only',
				$iconButton['class'],
				"Icon button $index must be a Codex icon-only button."
			);
			$this->assertStringContainsString(
				'cdx-button--weight-quiet',
				$iconButton['class'],
				"Icon button $index must use the quiet weight so the bar stays visually calm."
			);
			$this->assertSame(
				'#',
				$iconButton['href'],
				"Icon button $index renders as an anchor, so it needs a placeholder href."
			);

			$attributes = $this->indexAttributes( $iconButton['array-attributes'] );
			$this->assertSame(
				'-1',
				$attributes['tabindex'] ?? null,
				"Icon button $index must stay out of the tab order (T290201)."
			);
			$this->assertNotSame(
				'',
				$attributes['data-event-name'] ?? '',
				"Icon button $index must carry an instrumentation name."
			);
		}

		$searchButton = $templateData['data-button-start'];
		$this->assertStringContainsString(
			'notion-sticky-header-search-toggle',
			$searchButton['class'],
			'The search toggle carries the one genuinely skin-owned class in this component; ' .
				'stickyHeader.js finds the toggle by exactly this class.'
		);
		$searchAttributes = $this->indexAttributes( $searchButton['array-attributes'] );
		$this->assertSame(
			'ui.' . self::SEARCH_FORM_ID . '.icon',
			$searchAttributes['data-event-name'] ?? null,
			'The toggle composes its event name from the form id of its own search box.'
		);
	}

	/**
	 * One icon button, asserted in full, so nothing can drift unnoticed.
	 *
	 * The per-button loop above proves the properties every icon shares; this proves the exact
	 * bytes of one of them, including the complete Codex class string and the attribute records
	 * in the order `NotionComponentButton` emits them.
	 *
	 * @covers ::getIconButtons
	 */
	public function testTalkIconButtonDataInFull() {
		$templateData = $this->newStickyHeader()->getTemplateData();

		$this->assertSame(
			[
				'label' => '',
				'icon' => 'speechBubbles',
				'id' => 'ca-talk-sticky-header',
				'class' => self::ICON_BUTTON_CLASSES,
				'href' => '#',
				'array-attributes' => [
					[ 'key' => 'tabindex', 'value' => '-1' ],
					[ 'key' => 'data-event-name', 'value' => 'talk-sticky-header' ],
				],
			],
			$templateData['array-icon-buttons'][0],
			'The talk icon button must be emitted exactly as the template and the client script expect.'
		);
	}

	/**
	 * The search toggle, asserted in full.
	 *
	 * Two of its properties are easy to break by copying an icon button: it has no `id`, because
	 * the script finds it by class, and it has no `href`, so it must render as a real `<button>`
	 * and must *not* claim the Codex fake-button classes.
	 *
	 * @covers ::getSearchButton
	 */
	public function testSearchButtonDataInFull() {
		$templateData = $this->newStickyHeader()->getTemplateData();

		$this->assertSame(
			[
				'label' => 'search' . self::MOCK_SUFFIX,
				'icon' => 'search',
				'id' => null,
				'class' => 'cdx-button cdx-button--weight-quiet cdx-button--icon-only ' .
					'notion-sticky-header-search-toggle',
				'href' => null,
				'array-attributes' => [
					[ 'key' => 'tabindex', 'value' => '-1' ],
					[
						'key' => 'data-event-name',
						'value' => 'ui.' . self::SEARCH_FORM_ID . '.icon',
					],
				],
			],
			$templateData['data-button-start'],
			'The search toggle must be a real quiet icon-only button with no id and no href.'
		);
	}

	/**
	 * Every combination of the two switches, with the exact icon table each one must produce.
	 *
	 * Each expected entry is an `[ id, icon, data-event-name ]` triple, so one row locks all
	 * three fields of all seven slots at once. Between them the four rows exercise both booleans
	 * in both states, which reaches every one of the component's eight icon descriptors -- the
	 * fourth slot and the two edit slots being the only positions either switch can move.
	 *
	 * @return array[]
	 */
	public static function provideIconButtonOrder(): array {
		$talk = [ 'ca-talk-sticky-header', 'speechBubbles', 'talk-sticky-header' ];
		$subject = [ 'ca-subject-sticky-header', 'article', 'subject-sticky-header' ];
		$history = [ 'ca-history-sticky-header', 'wikimedia-history', 'history-sticky-header' ];
		$watchstar = [ 'ca-watchstar-sticky-header', 'wikimedia-star', 'watch-sticky-header' ];
		$bookmark = [
			'ca-bookmark-sticky-header',
			// Ships with Extension:ReadingLists rather than with the skin's own icon packs,
			// consistent with this descriptor only being reachable when that extension is present.
			'wikimedia-bookmarkOutline',
			'watch-sticky-bookmark',
		];
		$wikitextEdit = [
			'ca-edit-sticky-header',
			'wikimedia-wikiText',
			'wikitext-edit-sticky-header',
		];
		$visualEdit = [ 'ca-ve-edit-sticky-header', 'wikimedia-edit', 've-edit-sticky-header' ];
		$viewSource = [
			'ca-viewsource-sticky-header',
			'wikimedia-editLock',
			've-edit-protected-sticky-header',
		];

		return [
			'Wikitext tab first, watchstar' => [
				'visualEditorTabPositionFirst' => false,
				'isReadingListsEnabled' => false,
				'expectedButtons' => [
					$talk, $subject, $history, $watchstar, $wikitextEdit, $visualEdit, $viewSource,
				],
				// Owned by MediaWiki core, not by this skin: the watchstar markup in the sticky
				// header applies the class core normally puts on the parent <li> directly to the
				// watch link. It must never be renamed or given a notion- prefix.
				'expectedWatchClass' => 'mw-watchlink',
			],
			'Visual editor tab first, watchstar' => [
				'visualEditorTabPositionFirst' => true,
				'isReadingListsEnabled' => false,
				'expectedButtons' => [
					$talk, $subject, $history, $watchstar, $visualEdit, $wikitextEdit, $viewSource,
				],
				'expectedWatchClass' => 'mw-watchlink',
			],
			'Wikitext tab first, reading lists bookmark' => [
				'visualEditorTabPositionFirst' => false,
				'isReadingListsEnabled' => true,
				'expectedButtons' => [
					$talk, $subject, $history, $bookmark, $wikitextEdit, $visualEdit, $viewSource,
				],
				// Owned by Extension:ReadingLists, which is the only reason this descriptor is
				// ever reachable. Renaming it would orphan the extension's own styling.
				'expectedWatchClass' => 'reading-lists-bookmark',
			],
			'Visual editor tab first, reading lists bookmark' => [
				'visualEditorTabPositionFirst' => true,
				'isReadingListsEnabled' => true,
				'expectedButtons' => [
					$talk, $subject, $history, $bookmark, $visualEdit, $wikitextEdit, $viewSource,
				],
				'expectedWatchClass' => 'reading-lists-bookmark',
			],
		];
	}

	/**
	 * Both switches move exactly the slots they are supposed to move, and nothing else.
	 *
	 * Beyond the ordering, this locks each slot's icon name and instrumentation name by value.
	 * A wrong icon name renders an invisible glyph -- the mask-image simply fails to resolve --
	 * and a wrong event name silently detaches the control from the instrumentation pipeline.
	 * Neither failure mode raises an error anywhere else in the stack.
	 *
	 * @covers ::getIconButtons
	 * @covers ::getTemplateData
	 * @dataProvider provideIconButtonOrder
	 * @param bool $visualEditorTabPositionFirst Whether the visual editor tab comes first.
	 * @param bool $isReadingListsEnabled Whether Extension:ReadingLists is contributing.
	 * @param array[] $expectedButtons One `[ id, icon, data-event-name ]` triple per slot, in
	 *   render order.
	 * @param string $expectedWatchClass Class the fourth button must carry.
	 */
	public function testIconButtonOrder(
		bool $visualEditorTabPositionFirst,
		bool $isReadingListsEnabled,
		array $expectedButtons,
		string $expectedWatchClass
	) {
		$stickyHeader = $this->newStickyHeader(
			null,
			$visualEditorTabPositionFirst,
			$isReadingListsEnabled
		);

		$iconButtons = $stickyHeader->getTemplateData()['array-icon-buttons'];

		$actualButtons = [];
		foreach ( $iconButtons as $iconButton ) {
			$actualButtons[] = [
				$iconButton['id'],
				$iconButton['icon'],
				$this->indexAttributes( $iconButton['array-attributes'] )['data-event-name'] ?? null,
			];
		}

		$this->assertSame(
			$expectedButtons,
			$actualButtons,
			'Each slot must carry its own id, icon and event name, in the order the client ' .
				'script and the bar layout expect.'
		);
		// Both edit routes are always present. The client script decides which of the pair to
		// reveal, so emitting only one would leave an editing route unreachable from the bar.
		$emittedIds = array_column( $actualButtons, 0 );
		$this->assertContains(
			'ca-edit-sticky-header',
			$emittedIds,
			'The wikitext edit button is emitted regardless of tab order.'
		);
		$this->assertContains(
			'ca-ve-edit-sticky-header',
			$emittedIds,
			'The visual editor button is emitted regardless of tab order.'
		);
		$this->assertSame(
			self::ICON_BUTTON_CLASSES . ' ' . $expectedWatchClass,
			$iconButtons[3]['class'],
			'The watch affordance keeps the class its owning component looks for.'
		);
	}

	/**
	 * The add-section button, including the fallback chain its label resolves through.
	 *
	 * The chain matters: the skin-specific key is an optional on-wiki override that this skin
	 * deliberately does not ship in `i18n/en.json`, so core's own key is what supplies the label
	 * on a default install. Requesting the keys in the wrong order, or requesting only one of
	 * them, would render a bare message name in the bar.
	 *
	 * @covers ::getAddSectionButton
	 * @covers ::msg
	 */
	public function testAddSectionButton() {
		$requestedKeys = [];
		$stickyHeader = new NotionComponentStickyHeader(
			$this->newRecordingLocalizer( $requestedKeys ),
			$this->newSearchStub()
		);

		$buttons = $stickyHeader->getTemplateData()['array-buttons'];

		$this->assertCount(
			1,
			$buttons,
			'With no language button, add section is the only button in the group.'
		);
		$addSection = $buttons[0];
		$this->assertSame(
			'ca-addsection-sticky-header',
			$addSection['id'],
			'stickyHeader.js locates the add section button by exactly this id, a clone the bar ' .
				'creates rather than one core emits.'
		);
		$this->assertSame(
			'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled ' .
				'cdx-button--weight-quiet cdx-button--action-progressive',
			$addSection['class'],
			'Add section is the one progressive action in the bar, and it is not icon-only.'
		);
		$this->assertSame(
			'speechBubbleAdd-progressive',
			$addSection['icon'],
			'Add section uses the progressive variant of the speech bubble icon.'
		);
		// The href is what makes this an anchor rather than a button, and `#` is what makes it a
		// no-op until stickyHeader.js rebinds it to the real add-topic action of the page below.
		// It also decides the styling: NotionComponentButton only adds the fake-button pair for a
		// truthy href, so the classes asserted above are only correct while this is non-empty. A
		// real URL here would navigate away from the article on click, and an empty one would
		// silently drop those two classes, so the exact value is pinned rather than merely
		// asserted to be present.
		$this->assertSame(
			'#',
			$addSection['href'],
			'Add section links to the fragment placeholder the sticky-header script rebinds.'
		);
		$attributes = $this->indexAttributes( $addSection['array-attributes'] );
		$this->assertSame(
			'addsection-sticky-header',
			$attributes['data-event-name'] ?? null,
			'Add section must report its own instrumentation name.'
		);
		$this->assertSame(
			'-1',
			$attributes['tabindex'] ?? null,
			'Add section stays out of the tab order like every other control in the bar.'
		);

		$this->assertContains(
			[ 'notion-action-addsection', 'skin-action-addsection' ],
			$requestedKeys,
			'The label must be requested as a fallback chain, skin override first, core key second.'
		);
		$this->assertSame(
			'notion-action-addsection' . self::FALLBACK_SEPARATOR . 'skin-action-addsection' .
				self::MOCK_SUFFIX,
			$addSection['label'],
			'The resolved chain is what becomes the visible label.'
		);
	}

	/**
	 * The language button is optional, and when present it comes first.
	 *
	 * @return array[]
	 */
	public static function provideLanguageButtonPlacement(): array {
		return [
			'Languages hidden or ULS disabled' => [
				'hasLangButton' => false,
				'expectedIds' => [ 'ca-addsection-sticky-header' ],
			],
			'Language button supplied by the skin' => [
				'hasLangButton' => true,
				'expectedIds' => [
					'p-lang-btn-sticky-header',
					'ca-addsection-sticky-header',
				],
			],
		];
	}

	/**
	 * `array-buttons` holds the language button first, then add section, and omits the former
	 * entirely when the skin passed none.
	 *
	 * @covers ::getTemplateData
	 * @dataProvider provideLanguageButtonPlacement
	 * @param bool $hasLangButton Whether a language button component is supplied.
	 * @param string[] $expectedIds Ids of the emitted buttons, in order.
	 */
	public function testLanguageButtonPlacement( bool $hasLangButton, array $expectedIds ) {
		$langButtonData = [
			'id' => 'p-lang-btn-sticky-header',
			'label' => 'Languages',
		];
		$langButton = $hasLangButton ? $this->newComponentStub( $langButtonData ) : null;

		$buttons = $this->newStickyHeader( $langButton )->getTemplateData()['array-buttons'];

		$this->assertSame(
			$expectedIds,
			array_column( $buttons, 'id' ),
			'The language button precedes add section, and is absent rather than empty when unset.'
		);
		if ( $hasLangButton ) {
			// The language button is a component in its own right, so its data is forwarded
			// untouched rather than rebuilt here.
			$this->assertSame(
				$langButtonData,
				$buttons[0],
				'The language button component data must be passed through verbatim.'
			);
		}
	}

	/**
	 * Every control in the bar is removed from sequential keyboard navigation.
	 *
	 * The template hides the bar from assistive technology with `aria-hidden`, so a focusable
	 * control here would both duplicate the page's tab order and place focus inside an
	 * `aria-hidden` subtree. See https://phabricator.wikimedia.org/T290201.
	 *
	 * @covers ::getTemplateData
	 */
	public function testEveryControlIsRemovedFromTabOrder() {
		$stickyHeader = $this->newStickyHeader(
			$this->newComponentStub( [ 'id' => 'p-lang-btn-sticky-header' ] )
		);
		$templateData = $stickyHeader->getTemplateData();

		$controls = array_merge(
			$templateData['array-icon-buttons'],
			// The supplied language button is another component's data and carries no attributes
			// of its own, so only the buttons this component builds are asserted here.
			[ end( $templateData['array-buttons'] ) ],
			[ $templateData['data-button-start'] ]
		);

		$this->assertCount(
			self::ICON_BUTTON_COUNT + 2,
			$controls,
			'Seven icon buttons plus add section plus the search toggle are built by this component.'
		);
		foreach ( $controls as $index => $control ) {
			$this->assertSame(
				'-1',
				$this->indexAttributes( $control['array-attributes'] )['tabindex'] ?? null,
				"Control $index must carry tabindex=\"-1\" (T290201)."
			);
		}
	}

	/**
	 * Turn the emitted attribute records into a plain map for readable assertions.
	 *
	 * `NotionComponentButton` emits `array-attributes` as a list of `[ 'key' => ..., 'value' =>
	 * ... ]` records because the Mustache template iterates it as a section. Tests read it by
	 * name, so it is re-keyed here rather than in nine separate places.
	 *
	 * @param array $attributeRecords Emitted `array-attributes` value.
	 * @return array Attribute values keyed by attribute name.
	 */
	private function indexAttributes( array $attributeRecords ): array {
		return array_column( $attributeRecords, 'value', 'key' );
	}
}
