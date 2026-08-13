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

use MediaWiki\Skins\Notion\Components\NotionComponentLanguageDropdown;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's interlanguage-links dropdown component.
 *
 * `NotionComponentLanguageDropdown` turns eight constructor arguments into the array that
 * `LanguageDropdown.mustache` renders. It resolves no message, reads no service, no global and no
 * database, so this test extends `MediaWikiUnitTestCase` directly rather than the skin's snapshot
 * base class: the component's contract is small and exactly specified, so it is asserted
 * explicitly instead of being frozen into a fixture.
 *
 * Three properties are asserted.
 *
 *  1. **Variant selection.** One set of inputs produces either a prominent, progressive handle
 *     that shows the language count as its label, or a quiet icon-only handle with no visible
 *     label. The prominent variant is used on subject pages that exist and — since T389192 — on
 *     special pages that do have interlanguage links; the quiet variant is used everywhere else,
 *     most commonly on talk pages and on pages that do not exist yet (T316559).
 *  2. **Pass-through.** Everything the caller supplies reaches the template unchanged: the label,
 *     the accessible description, the caller's classes, the pre-rendered list items and the two
 *     portlet HTML strings, one of which is Extension:ULS's documented insertion point.
 *  3. **Borrowed names survive the restyle.** This skin renames only its own presentational
 *     classes; none of the identifiers asserted below is its own, so each is asserted verbatim and
 *     never under this skin's `notion-` prefix. Their provenance is not uniform, and the
 *     assertions should not be read as claiming core owns them all:
 *       - `p-lang-btn`, `mw-portlet-lang-heading-empty`, `mw-portlet-lang-heading-<count>` and
 *         `mw-portlet-lang-icon-only` originate in the Vector 2022 skin and appear nowhere in
 *         core. Core's own language portlet id is `p-lang`.
 *       - `mw-interlanguage-selector` and `mw-interlanguage-selector-empty` belong to
 *         Extension:UniversalLanguageSelector, whose `ext.uls.interface` module attaches its click
 *         handler to them.
 *     What they share is that ULS, gadgets, user scripts and stylesheets select on them today. A
 *     rename of any one would silently stop those consumers binding rather than raise an error,
 *     which is precisely what these assertions exist to catch.
 *
 * Titles are always test doubles. `Title::newFromText()` would reach the `TitleParser` service,
 * which `MediaWikiUnitTestCase` forbids, so `createMock()` is used and only the methods the
 * production code actually calls are stubbed.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentLanguageDropdown
 */
class NotionComponentLanguageDropdownTest extends MediaWikiUnitTestCase {

	/**
	 * Codex classes that both variants of the dropdown handle must carry.
	 *
	 * The handle is a Mustache `<label>` rather than a `<button>`, because disclosure runs through
	 * the checkbox hack so that the dropdown still opens with JavaScript disabled. Codex styling
	 * therefore arrives entirely through this "fake button" class list, which is why its
	 * composition is asserted rather than assumed.
	 */
	private const HANDLE_BASE_CLASSES = [
		'cdx-button',
		'cdx-button--fake-button',
		'cdx-button--fake-button--enabled',
		'cdx-button--weight-quiet',
	];

	/**
	 * Every key the component is required to emit.
	 *
	 * The first ten come from `NotionComponentDropdown`, three of which this component
	 * overrides; `aria-description` and `is-language-selector-empty` are added by this component;
	 * the last three are the menu contents unioned in from the constructor arguments.
	 */
	private const EXPECTED_TEMPLATE_KEYS = [
		'id',
		'label',
		'label-class',
		'icon',
		'html-notion-menu-label-attributes',
		'html-notion-menu-checkbox-attributes',
		'class',
		'html-tooltip',
		'checkbox-class',
		'is-expanded',
		'aria-description',
		'is-language-selector-empty',
		'html-items',
		'html-before-portal',
		'html-after-portal',
	];

	/**
	 * Every combination of page state the component branches on, with the variant each implies.
	 *
	 * The three title predicates are supplied independently and stubbed independently. Deriving
	 * one from another - `isTalkPage` from `!$titleExists`, say - would make the cases cheaper to
	 * write and worthless to run: with no case where a page both exists and is a talk page, the
	 * `!$title->isTalkPage()` conjunct could be deleted from the production predicate and every
	 * assertion would still pass, because the first conjunct alone would already have decided
	 * each case. The two existing-talk-page cases below are the ones that make that conjunct
	 * load-bearing, and they are also the realistic ones: a talk page with interlanguage links is
	 * ordinary on a multilingual wiki, and its handle must stay quiet (T316559).
	 *
	 * @return array[]
	 */
	public static function provideLanguageDropdownData(): array {
		return [
			'Existing subject page with languages' => [
				'label' => '5 languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 5,
				'itemHTML' => '<li>Language Mock</li>',
				'titleExists' => true,
				'isTalkPage' => false,
				'isSpecialPage' => false,
				'expectedIcon' => 'language-progressive',
				'isSubjectPage' => true,
			],
			'Existing subject page without languages' => [
				'label' => 'Add languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 0,
				'itemHTML' => '',
				'titleExists' => true,
				'isTalkPage' => false,
				'isSpecialPage' => false,
				'expectedIcon' => 'language-progressive',
				'isSubjectPage' => true,
			],
			// The two cases that keep `!$title->isTalkPage()` alive: the page exists, so the
			// first half of the conjunction is satisfied and only the talk check can demote it.
			'Existing talk page with languages' => [
				'label' => '5 languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 5,
				'itemHTML' => '<li>Language Mock</li>',
				'titleExists' => true,
				'isTalkPage' => true,
				'isSpecialPage' => false,
				'expectedIcon' => 'language',
				'isSubjectPage' => false,
			],
			'Existing talk page without languages' => [
				'label' => 'Add languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 0,
				'itemHTML' => '',
				'titleExists' => true,
				'isTalkPage' => true,
				'isSpecialPage' => false,
				'expectedIcon' => 'language',
				'isSubjectPage' => false,
			],
			// A page that does not exist yet: a red-linked article, or a talk page nobody has
			// started. Neither shows a prominent handle, whatever the language count claims.
			'Missing subject page with languages' => [
				'label' => '5 languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 5,
				'itemHTML' => '<li>Language Mock</li>',
				'titleExists' => false,
				'isTalkPage' => false,
				'isSpecialPage' => false,
				'expectedIcon' => 'language',
				'isSubjectPage' => false,
			],
			'Missing talk page without languages' => [
				'label' => 'Add languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 0,
				'itemHTML' => '',
				'titleExists' => false,
				'isTalkPage' => true,
				'isSpecialPage' => false,
				'expectedIcon' => 'language',
				'isSubjectPage' => false,
			],
		];
	}

	/**
	 * @covers ::getTemplateData
	 * @dataProvider provideLanguageDropdownData
	 * @param string $label Already-localised handle text, typically the language count.
	 * @param string $ariaLabel Already-localised accessible description.
	 * @param string $class Classes core's `data-languages` portlet contributes.
	 * @param int $numLanguages Number of interlanguage links available for the page.
	 * @param string $itemHTML Pre-rendered `<li>` list of language links.
	 * @param bool $titleExists Whether the mocked page exists.
	 * @param bool $isTalkPage Whether the mocked title is a talk page, stubbed independently of
	 *   whether it exists so that an existing talk page is a case in its own right.
	 * @param bool $isSpecialPage Whether the mocked title is a special page.
	 * @param string $expectedIcon Icon name expected for the selected variant.
	 * @param bool $isSubjectPage Whether the prominent variant is expected.
	 */
	public function testGetTemplateData(
		string $label,
		string $ariaLabel,
		string $class,
		int $numLanguages,
		string $itemHTML,
		bool $titleExists,
		bool $isTalkPage,
		bool $isSpecialPage,
		string $expectedIcon,
		bool $isSubjectPage
	): void {
		// Mock Title. createMock() never runs the real constructor, so no service, no global
		// configuration and no database is touched — MediaWikiUnitTestCase would refuse all three.
		$titleMock = $this->createMock( Title::class );
		// All three predicates the production code consults are stubbed from their own provider
		// value. None is derived from another, so no case can satisfy the expected variant by
		// accident, and each conjunct of the predicate has at least one case that depends on it.
		$titleMock->method( 'exists' )->willReturn( $titleExists );
		$titleMock->method( 'isTalkPage' )->willReturn( $isTalkPage );
		$titleMock->method( 'isSpecialPage' )->willReturn( $isSpecialPage );

		// Create a new NotionComponentLanguageDropdown object. The two empty strings are the
		// before- and after-portlet HTML, which testMenuContentsAndPortletHtmlAreForwarded()
		// covers with real values.
		$languageDropdown = new NotionComponentLanguageDropdown(
			$label, $ariaLabel, $class, $numLanguages, $itemHTML, '', '', $titleMock
		);

		// Called twice on the same instance on purpose. The quiet variant adds the
		// `mw-portlet-lang-icon-only` layout hook to the portlet class, and it does so in a local
		// variable rather than by appending to the component's own property, so asking twice must
		// return exactly the same data. An earlier revision mutated the property and could only be
		// asked once; this assertion is what stops that returning. `assertSame` compares with
		// `===`, which for arrays is sensitive to key order, value types and contents alike.
		// A second call is not hypothetical: a caller may assemble the in-page control and its
		// sticky-header clone from one component.
		$templateData = $languageDropdown->getTemplateData();
		$this->assertSame(
			$templateData,
			$languageDropdown->getTemplateData(),
			'getTemplateData() is a pure function of the constructor arguments: calling it again '
				. 'must not accumulate classes or change anything else.'
		);

		// Verifying that the template data is constructed as expected. assertSame rather than
		// assertEquals throughout, because `is-language-selector-empty` must be a real boolean for
		// the template's `{{#is-language-selector-empty}}` section to behave, and the strings must
		// not merely be loosely equal.
		//
		// The dropdown id is the one Vector 2022 established — core's own language portlet id is
		// `p-lang` — and it is deliberately NOT renamed to a `notion-` prefix: stylesheets, gadgets
		// and Extension:ULS all select on `p-lang-btn`, and the dropdown templates derive the
		// checkbox and label ids from it.
		$this->assertSame( 'p-lang-btn', $templateData['id'],
			'The dropdown must keep the established portlet id so ULS, gadgets and CSS still match it.' );
		$this->assertSame( $label, $templateData['label'],
			'The already-localised label must reach the template verbatim.' );
		$this->assertSame( $ariaLabel, $templateData['aria-description'],
			'The accessible description is the quiet variant\'s only label, so it must be exact.' );
		$this->assertSame( $expectedIcon, $templateData['icon'],
			'The page context must select the progressive or the plain language glyph.' );
		$this->assertSame( $itemHTML, $templateData['html-items'],
			'The pre-rendered language list must be forwarded to >MenuContents unchanged.' );
		// Expressed as the negation of the expected variant rather than of `titleExists`. The two
		// are the same value for every case in this provider — see its documentation — but the
		// variant is what the flag actually describes, and it stays correct for a special page.
		$this->assertSame( !$isSubjectPage, $templateData['is-language-selector-empty'],
			'The template branches on this flag to render the empty-selector body instead of the list.' );

		// Every variant of the handle is a Codex quiet fake button; only the modifiers differ.
		foreach ( self::HANDLE_BASE_CLASSES as $handleClass ) {
			$this->assertStringContainsString( $handleClass, $templateData['label-class'],
				"The dropdown handle must carry the Codex class $handleClass in every variant." );
		}

		// `mw-interlanguage-selector` is the selector ext.uls.interface binds its click handler
		// to. Both variants satisfy this assertion — the quiet variant emits the
		// `mw-interlanguage-selector-empty` refinement, which contains the base name — so ULS
		// keeps working on talk pages and on pages that do not exist yet.
		$this->assertStringContainsString( 'mw-interlanguage-selector', $templateData['checkbox-class'],
			'Extension:ULS binds to this class name, so it must never be renamed or dropped.' );

		// Verifying that the variant-specific data is constructed as expected.
		if ( $isSubjectPage ) {
			$this->assertProminentVariant( $templateData, $class, $numLanguages );
		} else {
			$this->assertQuietVariant( $templateData, $class, $numLanguages );
		}
	}

	/**
	 * Repeated calls on the quiet variant emit the layout hook exactly once.
	 *
	 * The quiet variant is the only branch that touches the class list, so it is the branch where
	 * a mutating implementation shows: `some-class mw-portlet-lang-icon-only` would become
	 * `some-class mw-portlet-lang-icon-only mw-portlet-lang-icon-only`. A duplicated class is not
	 * a rendering error, which is exactly why it needs a test - the browser accepts it, the
	 * stylesheet still matches, and the only visible trace is in the markup.
	 *
	 * @covers ::getTemplateData
	 */
	public function testQuietVariantIsIdempotent(): void {
		$titleMock = $this->createMock( Title::class );
		$titleMock->method( 'exists' )->willReturn( true );
		$titleMock->method( 'isTalkPage' )->willReturn( true );
		$titleMock->method( 'isSpecialPage' )->willReturn( false );

		$languageDropdown = new NotionComponentLanguageDropdown(
			'Add languages', 'Choose language', 'some-class', 0, '', '', '', $titleMock
		);

		$first = $languageDropdown->getTemplateData();
		$second = $languageDropdown->getTemplateData();
		$third = $languageDropdown->getTemplateData();

		$this->assertSame(
			'some-class mw-portlet-lang-icon-only',
			$first['class'],
			'The quiet variant adds the icon-only layout hook once.'
		);
		$this->assertSame( $first, $second, 'A second call must emit exactly the same data.' );
		$this->assertSame( $first, $third, 'And so must a third.' );
		$this->assertSame(
			1,
			substr_count( $third['class'], 'mw-portlet-lang-icon-only' ),
			'The layout hook must never accumulate across calls.'
		);
	}

	/**
	 * Special pages, the one case the "exists and is not a talk page" half cannot express.
	 *
	 * @return array[]
	 */
	public static function provideSpecialPageData(): array {
		return [
			'Special page with languages' => [
				'numLanguages' => 5,
				'expectedIcon' => 'language-progressive',
				'isSubjectPage' => true,
			],
			'Special page without languages' => [
				'numLanguages' => 0,
				'expectedIcon' => 'language',
				'isSubjectPage' => false,
			],
		];
	}

	/**
	 * A special page gets the prominent variant if, and only if, it has interlanguage links.
	 *
	 * Special pages are not rows in the page table, so `exists()` is false for them and the first
	 * half of the component's predicate never fires. Left at that, a special page carrying
	 * interlanguage links would be demoted to the quiet variant and its links would go unseen,
	 * which is the regression T389192 records. The second half of the predicate is what prevents
	 * it, and this test is what keeps that half alive: none of the cases in
	 * provideLanguageDropdownData() can reach it, because a mocked `isSpecialPage()` returns false.
	 *
	 * @covers ::getTemplateData
	 * @dataProvider provideSpecialPageData
	 * @param int $numLanguages Number of interlanguage links available for the special page.
	 * @param string $expectedIcon Icon name expected for the selected variant.
	 * @param bool $isSubjectPage Whether the prominent variant is expected.
	 */
	public function testGetTemplateDataForSpecialPage(
		int $numLanguages,
		string $expectedIcon,
		bool $isSubjectPage
	): void {
		$class = 'mw-portlet mw-portlet-lang';
		$titleMock = $this->createMock( Title::class );
		// A special page is neither an existing page nor a talk page, so only the interlanguage
		// link count decides the outcome below.
		$titleMock->method( 'exists' )->willReturn( false );
		$titleMock->method( 'isTalkPage' )->willReturn( false );
		$titleMock->method( 'isSpecialPage' )->willReturn( true );

		$languageDropdown = new NotionComponentLanguageDropdown(
			'Languages',
			'Choose language',
			$class,
			$numLanguages,
			'<li>Language Mock</li>',
			'',
			'',
			$titleMock
		);
		$templateData = $languageDropdown->getTemplateData();

		$this->assertSame( $expectedIcon, $templateData['icon'],
			'A special page with interlanguage links must keep the progressive glyph (T389192).' );
		$this->assertSame( !$isSubjectPage, $templateData['is-language-selector-empty'],
			'A special page with interlanguage links must not report an empty language selector.' );

		if ( $isSubjectPage ) {
			$this->assertProminentVariant( $templateData, $class, $numLanguages );
		} else {
			$this->assertQuietVariant( $templateData, $class, $numLanguages );
		}
	}

	/**
	 * Without a title the quiet variant is selected, whatever the language count claims.
	 *
	 * Both halves of the predicate are guarded on the title being non-null, because the control
	 * may be assembled before a title is available. This test pins that guard and, by omitting the
	 * two optional portlet arguments, the constructor defaults as well: a count on its own must
	 * never promote the handle, since there is no page whose languages it could describe.
	 *
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testMissingTitleSelectsTheQuietVariant(): void {
		$class = 'mw-portlet mw-portlet-lang';
		$numLanguages = 3;

		$languageDropdown = new NotionComponentLanguageDropdown(
			'Languages', 'Choose language', $class, $numLanguages, ''
		);
		$templateData = $languageDropdown->getTemplateData();

		$this->assertSame( 'language', $templateData['icon'],
			'With no title to describe, the handle must fall back to the plain language glyph.' );
		$this->assertTrue( $templateData['is-language-selector-empty'],
			'With no title, the template must render the empty-selector body, not a link list.' );
		$this->assertQuietVariant( $templateData, $class, $numLanguages );

		// The optional constructor arguments default to empty strings rather than null. Not because
		// null would print — LightnCandy renders a null interpolation as the empty string, never as
		// the literal text "null" — but because a null value is looked up as though the key were
		// absent, so the lookup escapes to the enclosing context and an outer key of the same name
		// would be rendered in its place. The empty string blocks that while still being falsy.
		$this->assertSame( '', $templateData['html-items'],
			'An empty item list must be forwarded as an empty string.' );
		$this->assertSame( '', $templateData['html-before-portal'],
			'The before-portlet default must be an empty string.' );
		$this->assertSame( '', $templateData['html-after-portal'],
			'The after-portlet default must be an empty string.' );
	}

	/**
	 * The menu contents and both portlet HTML strings reach the template verbatim.
	 *
	 * `html-after-portal` is Extension:ULS's documented insertion point, so any mangling here
	 * would break language switching without breaking a page. The array the component returns is
	 * a left-biased union of the dropdown data and the menu contents, so this also proves the
	 * three menu-contents keys survive that union instead of being shadowed, while the dropdown's
	 * own untouched defaults still come through.
	 *
	 * @covers ::getTemplateData
	 */
	public function testMenuContentsAndPortletHtmlAreForwarded(): void {
		// Shaped like a real interlanguage link, markup and all, because the component must forward
		// it untouched rather than parse or sanitise it: the caller owns its escaping.
		$itemHTML = '<li class="interlanguage-link interwiki-de">'
			. '<a href="https://de.example.org/wiki/Notion" lang="de">Deutsch</a></li>';
		$beforePortlet = '<div class="notion-language-before-portlet">before</div>';
		$afterPortlet = '<div class="uls-language-after-portlet">after</div>';

		$titleMock = $this->createMock( Title::class );
		$titleMock->method( 'exists' )->willReturn( true );
		$titleMock->method( 'isTalkPage' )->willReturn( false );

		$languageDropdown = new NotionComponentLanguageDropdown(
			'1 language',
			'Choose language',
			'mw-portlet mw-portlet-lang',
			1,
			$itemHTML,
			$beforePortlet,
			$afterPortlet,
			$titleMock
		);
		$templateData = $languageDropdown->getTemplateData();

		$this->assertSame( $itemHTML, $templateData['html-items'],
			'The pre-rendered language links must not be altered on their way to the template.' );
		$this->assertSame( $beforePortlet, $templateData['html-before-portal'],
			'The before-portlet HTML must be forwarded verbatim.' );
		$this->assertSame( $afterPortlet, $templateData['html-after-portal'],
			'Extension:ULS injects through the after-portlet HTML, so it must survive verbatim.' );

		foreach ( self::EXPECTED_TEMPLATE_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $templateData,
				"LanguageDropdown.mustache and its partials read the $key key." );
		}

		// Keys this component leaves at the dropdown's defaults. Empty strings rather than null,
		// again so that no lookup can escape to an enclosing context — see the note above.
		$this->assertSame( '', $templateData['html-tooltip'],
			'No tooltip is contributed, so the tooltip attribute string must stay empty.' );
		$this->assertSame( '', $templateData['html-notion-menu-label-attributes'],
			'No extra label attributes are contributed by this component.' );
		$this->assertSame( '', $templateData['html-notion-menu-checkbox-attributes'],
			'No extra checkbox attributes are contributed by this component.' );
		$this->assertFalse( $templateData['is-expanded'],
			'The language menu is served closed, so its checkbox renders unchecked and '
				. 'aria-expanded="false".' );
	}

	/**
	 * Assert the prominent variant: a progressive handle labelled with the language count.
	 *
	 * @param array $templateData Data the component returned.
	 * @param string $expectedClass Classes the caller supplied to the constructor.
	 * @param int $numLanguages Number of interlanguage links available for the page.
	 */
	private function assertProminentVariant(
		array $templateData,
		string $expectedClass,
		int $numLanguages
	): void {
		$labelClass = $templateData['label-class'];
		$checkboxClass = $templateData['checkbox-class'];

		$this->assertStringContainsString( 'cdx-button--action-progressive', $labelClass,
			'The prominent handle must use the progressive Codex button action.' );
		// The heading class carries the count so that stylesheets and extensions can select on it.
		// It is Vector's class, not core's, and keeps the `mw-portlet-lang-heading-` prefix rather
		// than gaining a `notion-` one.
		$this->assertStringContainsString( "mw-portlet-lang-heading-$numLanguages", $labelClass,
			'The heading class must carry the language count stylesheets and extensions select on.' );
		$this->assertStringNotContainsString( 'cdx-button--icon-only', $labelClass,
			'The prominent handle renders a visible label, so it is not an icon-only button.' );
		$this->assertStringNotContainsString( 'mw-portlet-lang-heading-empty', $labelClass,
			'A handle that carries a language count must not also be marked as empty.' );
		$this->assertSame( 'mw-interlanguage-selector', $checkboxClass,
			'ULS binds to the unqualified selector class where languages are shown.' );
		$this->assertStringNotContainsString( 'mw-interlanguage-selector-empty', $checkboxClass,
			'The empty-selector refinement belongs to the quiet variant alone.' );
		$this->assertSame( $expectedClass, $templateData['class'],
			'The prominent variant must forward the caller\'s classes untouched.' );
		$this->assertStringNotContainsString( 'mw-portlet-lang-icon-only', $templateData['class'],
			'The icon-only layout hook belongs to the quiet variant alone.' );
	}

	/**
	 * Assert the quiet variant: an icon-only handle with no visible label and no count.
	 *
	 * @param array $templateData Data the component returned.
	 * @param string $expectedClass Classes the caller supplied to the constructor.
	 * @param int $numLanguages Number of interlanguage links available for the page, which this
	 *   variant must suppress rather than expose.
	 */
	private function assertQuietVariant(
		array $templateData,
		string $expectedClass,
		int $numLanguages
	): void {
		$labelClass = $templateData['label-class'];

		$this->assertStringContainsString( 'cdx-button--icon-only', $labelClass,
			'The quiet handle shows no label, so it must be styled as an icon-only button.' );
		$this->assertStringContainsString( 'mw-portlet-lang-heading-empty', $labelClass,
			'Core styles the label-less handle through the empty heading class (T316559).' );
		$this->assertStringNotContainsString( 'cdx-button--action-progressive', $labelClass,
			'The quiet handle must stay quiet rather than adopting the progressive action.' );
		$this->assertStringNotContainsString( "mw-portlet-lang-heading-$numLanguages", $labelClass,
			'The quiet handle has no visible label, so it must not advertise a language count.' );
		$this->assertSame( 'mw-interlanguage-selector-empty', $templateData['checkbox-class'],
			'ULS distinguishes the empty selector by this exact class name.' );
		$this->assertSame( "$expectedClass mw-portlet-lang-icon-only", $templateData['class'],
			'The quiet variant must keep the caller\'s classes and add the icon-only hook once.' );
	}
}
