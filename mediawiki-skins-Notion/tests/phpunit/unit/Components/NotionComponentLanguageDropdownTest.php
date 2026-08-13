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
 * http://www.gnu.org/copyleft/gpl.html
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
 *  3. **Core-owned and extension-owned names survive the restyle.** This skin renames only its own
 *     presentational classes; every identifier below belongs to MediaWiki core or to
 *     Extension:UniversalLanguageSelector and is therefore asserted verbatim, never under this
 *     skin's `notion-` prefix — the portlet id `p-lang-btn`, the heading classes
 *     `mw-portlet-lang-heading-empty` and `mw-portlet-lang-heading-<count>`, the layout hook
 *     `mw-portlet-lang-icon-only`, and the checkbox classes `mw-interlanguage-selector` and
 *     `mw-interlanguage-selector-empty` that `ext.uls.interface` attaches its click handler to.
 *     A rename of any of them would silently stop ULS, gadgets and user scripts from binding
 *     rather than raise an error, which is precisely what these assertions exist to catch.
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
	 * The first nine come from `NotionComponentDropdown`, three of which this component
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
		'aria-description',
		'is-language-selector-empty',
		'html-items',
		'html-before-portal',
		'html-after-portal',
	];

	/**
	 * Ordinary pages, covering both variants with and without interlanguage links.
	 *
	 * `titleExists` drives the mocked title while `isSubjectPage` states the variant expected of
	 * it. The two coincide in every case here because a mocked `isSpecialPage()` returns false by
	 * default, so the predicate reduces to "exists and is not a talk page"; they diverge in
	 * provideSpecialPageData(), which is why both are supplied separately rather than derived.
	 *
	 * @return array[]
	 */
	public static function provideLanguageDropdownData(): array {
		return [
			'Subject page with languages' => [
				'label' => 'Languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 5,
				'itemHTML' => '<li>Language Mock</li>',
				'titleExists' => true,
				'expectedIcon' => 'language-progressive',
				'isSubjectPage' => true,
			],
			'Talk page without languages' => [
				'label' => 'Languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 0,
				'itemHTML' => '',
				'titleExists' => false,
				'expectedIcon' => 'language',
				'isSubjectPage' => false,
			],
			'Subject page without languages' => [
				'label' => 'Languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 0,
				'itemHTML' => '',
				'titleExists' => true,
				'expectedIcon' => 'language-progressive',
				'isSubjectPage' => true,
			],
			'Talk page with languages' => [
				'label' => 'Languages',
				'ariaLabel' => 'Choose language',
				'class' => 'some-class',
				'numLanguages' => 5,
				'itemHTML' => '<li>Language Mock</li>',
				'titleExists' => false,
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
	 * @param bool $titleExists Whether the mocked title exists; a title that does not exist is
	 *   mocked as a talk page, so one flag drives both stubs.
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
		string $expectedIcon,
		bool $isSubjectPage
	): void {
		// Mock Title. createMock() never runs the real constructor, so no service, no global
		// configuration and no database is touched — MediaWikiUnitTestCase would refuse all three.
		$titleMock = $this->createMock( Title::class );
		// Mock Title methods. Only the two the production code calls on an ordinary page are
		// stubbed; isSpecialPage() is deliberately left at its default return of false, which is
		// what keeps these four cases on the "exists and is not a talk page" half of the
		// predicate. The special-page half is exercised by testGetTemplateDataForSpecialPage().
		$titleMock->method( 'exists' )->willReturn( $titleExists );
		$titleMock->method( 'isTalkPage' )->willReturn( !$titleExists );

		// Create a new NotionComponentLanguageDropdown object. The two empty strings are the
		// before- and after-portlet HTML, which testMenuContentsAndPortletHtmlAreForwarded()
		// covers with real values.
		$languageDropdown = new NotionComponentLanguageDropdown(
			$label, $ariaLabel, $class, $numLanguages, $itemHTML, '', '', $titleMock
		);

		// Call the getTemplateData method. Called exactly once per instance on purpose: the
		// component appends to its own `class` property when it selects the quiet variant, so a
		// second call on the same instance would append the same layout hook twice.
		$templateData = $languageDropdown->getTemplateData();

		// Verifying that the template data is constructed as expected. assertSame rather than
		// assertEquals throughout, because `is-language-selector-empty` must be a real boolean for
		// the template's `{{#is-language-selector-empty}}` section to behave, and the strings must
		// not merely be loosely equal.
		//
		// The dropdown id is core's own portlet id and is deliberately NOT renamed to a
		// `notion-` prefix: stylesheets, gadgets and Extension:ULS all select on `p-lang-btn`,
		// and the dropdown templates derive the checkbox and label ids from it.
		$this->assertSame( 'p-lang-btn', $templateData['id'],
			'The dropdown must keep core\'s portlet id so ULS, gadgets and CSS still match it.' );
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

		// The optional constructor arguments default to empty strings rather than null, so that
		// >MenuContents renders nothing at all instead of the literal text "null".
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
		// again so that nothing can render as the literal text "null".
		$this->assertSame( '', $templateData['html-tooltip'],
			'No tooltip is contributed, so the tooltip attribute string must stay empty.' );
		$this->assertSame( '', $templateData['html-notion-menu-label-attributes'],
			'No extra label attributes are contributed by this component.' );
		$this->assertSame( '', $templateData['html-notion-menu-checkbox-attributes'],
			'No extra checkbox attributes are contributed by this component.' );
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
		// Core's heading class carries the count so that stylesheets and extensions can select on
		// it. It keeps the `mw-portlet-lang-heading-` prefix rather than gaining a `notion-` one.
		$this->assertStringContainsString( "mw-portlet-lang-heading-$numLanguages", $labelClass,
			'The heading class must carry the language count core and extensions select on.' );
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
