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

use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Language\Language;
use MediaWiki\Language\LanguageConverterFactory;
use MediaWiki\Skins\Notion\Components\NotionComponentDropdown;
use MediaWiki\Skins\Notion\Components\NotionComponentVariants;
use MediaWiki\StubObject\StubUserLang;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's language-variant switcher component.
 *
 * `NotionComponentVariants` is a composition rather than a leaf component: it builds a
 * `NotionComponentDropdown` for the disclosure handle and a `NotionComponentMenu` for the list of
 * variants, then adjusts both emitted arrays because neither sub-component can be configured for
 * this use through its constructor alone. The adjustments are the substance of the class, so they
 * are what this test asserts, and each is asserted in a way that fails if the adjustment is
 * removed or moved:
 *
 *  1. The handle's visible text is the *current* variant's own name, resolved through the language
 *     converter rather than through a message. The converter is obtained for the page language and
 *     its preferred variant code is passed to `Language::getVariantname()`, so both hand-offs are
 *     asserted on the collaborators, not merely on the resulting string.
 *  2. `aria-label` is added to the dropdown array *after* construction, because
 *     `NotionComponentDropdown` never emits that key. The test asserts the value arrives and,
 *     separately, that the dropdown's own ten-key contract is otherwise untouched — a change that
 *     dropped the assignment would leave assistive technology announcing a bare variant name.
 *  3. The menu heading is nulled *before* the menu is constructed. `NotionComponentMenu` fills its
 *     defaults with `+=`, which only touches absent keys, so an explicit null survives; nulling
 *     after construction would arrive too late and the portlet's label would be announced and
 *     shown twice. Asserting `null` — not `''` — for a menu whose input carried a real label is
 *     what distinguishes the two orderings.
 *  4. `emptyPortlet` is applied to the dropdown's `class` exactly when core reports the portlet as
 *     empty, which is what hides the whole control on the overwhelming majority of wikis.
 *
 * The class reads no service beyond the injected factory, resolves no message and touches no
 * global, so this extends `MediaWikiUnitTestCase` directly.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentVariants
 */
class NotionComponentVariantsTest extends MediaWikiUnitTestCase {

	/** The element id this skin owns and that `Variants.mustache` and `Dropdown.less` key off. */
	private const DROPDOWN_ID = 'notion-variants-dropdown';

	/** Localised accessible name, as `SkinNotion` would already have resolved it. */
	private const ARIA_LABEL = 'Change language variant';

	/**
	 * A page language whose converter reports $preferredVariant and which names it $variantName.
	 *
	 * Both expectations are asserted rather than merely stubbed: the component is required to ask
	 * the factory for the converter of *this* language, and to name the variant the converter
	 * actually prefers. Stubbing without expectations would let a hardcoded label or a converter
	 * fetched for the wrong language pass.
	 *
	 * @param string $preferredVariant
	 * @param string $variantName
	 * @return array{0:Language,1:LanguageConverterFactory} the language and the factory built for it
	 */
	private function newPageLanguage( string $preferredVariant, string $variantName ): array {
		$pageLang = $this->createMock( Language::class );
		$pageLang->expects( $this->once() )
			->method( 'getVariantname' )
			->with( $preferredVariant )
			->willReturn( $variantName );

		$converter = $this->createMock( ILanguageConverter::class );
		$converter->expects( $this->once() )
			->method( 'getPreferredVariant' )
			->willReturn( $preferredVariant );

		$factory = $this->createMock( LanguageConverterFactory::class );
		$factory->expects( $this->once() )
			->method( 'getLanguageConverter' )
			->with( $this->identicalTo( $pageLang ) )
			->willReturn( $converter );

		return [ $pageLang, $factory ];
	}

	/**
	 * Core's `data-variants` portlet, in the shape `SkinTemplate` hands it over.
	 *
	 * The label is deliberately non-empty so that the "menu heading is nulled" assertions can
	 * tell a suppressed label apart from a label that was never supplied.
	 *
	 * @param bool $isEmpty
	 * @return array
	 */
	private function newMenuData( bool $isEmpty = false ): array {
		return [
			'id' => 'p-variants',
			'label' => 'Variants',
			'is-empty' => $isEmpty,
			// Core keys every portlet item carries. `id` in particular is not optional:
			// NotionComponentMenu::updateMenuItemStyles() reads it to look up per-item style
			// overrides, so a fixture without it would exercise a shape core never produces.
			'array-list-items' => [
				[
					'name' => 'zh-hans',
					'id' => 'ca-varlang-0',
					'class' => '',
					'array-links' => [ [ 'href' => '/zh-hans/Foo', 'text' => 'Simplified' ] ],
				],
				[
					'name' => 'zh-hant',
					'id' => 'ca-varlang-1',
					'class' => '',
					'array-links' => [ [ 'href' => '/zh-hant/Foo', 'text' => 'Traditional' ] ],
				],
			],
		];
	}

	/**
	 * @covers ::getTemplateData
	 * @covers ::getDropdownData
	 * @covers ::getDropdownLabel
	 */
	public function testDropdownHandleShowsTheCurrentVariantName() {
		[ $pageLang, $factory ] = $this->newPageLanguage( 'zh-hant', '繁體' );

		$data = ( new NotionComponentVariants(
			$factory, $this->newMenuData(), $pageLang, self::ARIA_LABEL
		) )->getTemplateData();

		$dropdown = $data['data-variants-dropdown'];
		$this->assertSame( '繁體', $dropdown['label'],
			'The handle must show the variant the reader is currently viewing, not a static caption.' );
		$this->assertSame( self::DROPDOWN_ID, $dropdown['id'],
			'The dropdown id is this skin\'s own contract with Variants.mustache and Dropdown.less.' );
	}

	/**
	 * The accessible name is added to the dropdown array after construction.
	 *
	 * `NotionComponentDropdown` emits ten keys and `aria-label` is not among them, so this
	 * asserts both halves of the adjustment: the label arrives, and it arrives as an addition —
	 * the eleven emitted keys are the dropdown's own ten plus exactly this one.
	 *
	 * @covers ::getDropdownData
	 */
	public function testAriaLabelIsAddedToTheDropdownWithoutDisturbingItsContract() {
		[ $pageLang, $factory ] = $this->newPageLanguage( 'zh-hans', '简体' );

		$dropdown = ( new NotionComponentVariants(
			$factory, $this->newMenuData(), $pageLang, self::ARIA_LABEL
		) )->getTemplateData()['data-variants-dropdown'];

		$this->assertSame( self::ARIA_LABEL, $dropdown['aria-label'],
			'Without an accessible name the control announces a bare variant name and says nothing '
				. 'about what it does.' );
		// Derive the expectation from NotionComponentDropdown itself rather than restating its key
		// list here: the claim under test is "the dropdown's own contract, plus exactly aria-label,
		// appended last", and expressing it that way keeps this test honest if the dropdown's
		// contract legitimately changes while still failing if the assignment is dropped, renamed,
		// or starts overwriting a key the dropdown already emits.
		$ownKeys = array_keys(
			( new NotionComponentDropdown( self::DROPDOWN_ID, '简体', '' ) )->getTemplateData()
		);
		$this->assertSame(
			array_merge( $ownKeys, [ 'aria-label' ] ),
			array_keys( $dropdown ),
			'aria-label must be an addition to the dropdown contract, appended after construction.'
		);
	}

	/**
	 * @covers ::getMenuDropdownData
	 */
	public function testMenuHeadingIsSuppressedBeforeTheMenuIsConstructed() {
		[ $pageLang, $factory ] = $this->newPageLanguage( 'zh-hant', '繁體' );
		$menuData = $this->newMenuData();

		$menu = ( new NotionComponentVariants(
			$factory, $menuData, $pageLang, self::ARIA_LABEL
		) )->getTemplateData()['data-variants-menu'];

		$this->assertArrayHasKey( 'label', $menu );
		$this->assertNull( $menu['label'],
			'The handle already displays the active variant, so the portlet label must be removed. '
				. 'A null here — rather than the constructor default of an empty string — is also '
				. 'what proves the key was nulled before NotionComponentMenu applied its defaults.'
		);
		$this->assertSame( 'Variants', $menuData['label'],
			'The caller\'s array is passed by value, so suppressing the heading must not reach back '
				. 'into core\'s portlet data.' );
	}

	/**
	 * @covers ::getMenuDropdownData
	 */
	public function testVariantItemsReachTheMenuNormalisedAndIntact() {
		[ $pageLang, $factory ] = $this->newPageLanguage( 'zh-hans', '简体' );

		$menu = ( new NotionComponentVariants(
			$factory, $this->newMenuData(), $pageLang, self::ARIA_LABEL
		) )->getTemplateData()['data-variants-menu'];

		$this->assertSame( 'p-variants', $menu['id'],
			'Core owns the portlet id; the component must pass it through untouched.' );
		$this->assertCount( 2, $menu['array-list-items'] );
		$this->assertSame(
			[ 'zh-hans', 'zh-hant' ],
			array_column( $menu['array-list-items'], 'name' ),
			'Both variants must survive the hand-off to NotionComponentMenu, in order.'
		);
		// NotionComponentMenu normalises each entry, so the items gain the keys its template
		// contract requires while keeping the link data the caller supplied.
		$this->assertSame(
			'/zh-hans/Foo',
			$menu['array-list-items'][0]['array-links'][0]['href'],
			'Normalisation must not rewrite the variant links themselves.'
		);
		$this->assertSame( '', $menu['html-items'],
			'The structured list is in use, so the raw-HTML mode must stay empty.' );
	}

	/**
	 * @dataProvider provideEmptiness
	 * @covers ::getDropdownData
	 * @param bool $isEmpty
	 * @param string $expectedClass
	 */
	public function testEmptyPortletHidesTheWholeControl( bool $isEmpty, string $expectedClass ) {
		[ $pageLang, $factory ] = $this->newPageLanguage( 'en', 'English' );

		$dropdown = ( new NotionComponentVariants(
			$factory, $this->newMenuData( $isEmpty ), $pageLang, self::ARIA_LABEL
		) )->getTemplateData()['data-variants-dropdown'];

		$this->assertSame( $expectedClass, $dropdown['class'],
			'`emptyPortlet` is core\'s own class for a portlet with nothing in it, and it is what '
				. 'hides the switcher on the wikis — the overwhelming majority — that have no variants.'
		);
	}

	public static function provideEmptiness(): array {
		return [
			'a wiki with variants renders the control' => [ false, '' ],
			'a wiki without variants hides it' => [ true, 'emptyPortlet' ],
		];
	}

	/**
	 * @covers ::getTemplateData
	 */
	public function testEmittedStructureIsExactlyTheTwoTemplateKeysAndIsPlainData() {
		[ $pageLang, $factory ] = $this->newPageLanguage( 'zh-hant', '繁體' );

		$data = ( new NotionComponentVariants(
			$factory, $this->newMenuData(), $pageLang, self::ARIA_LABEL
		) )->getTemplateData();

		$this->assertSame(
			[ 'data-variants-dropdown', 'data-variants-menu' ],
			array_keys( $data ),
			'Variants.mustache reads exactly these two keys.'
		);
		// Mustache can traverse nothing but plain data, and the component snapshots are JSON, so a
		// component object leaking into the output would be a rendering failure rather than a
		// stylistic lapse.
		$this->assertIsArray( $data['data-variants-dropdown'] );
		$this->assertIsArray( $data['data-variants-menu'] );
		$this->assertSame(
			json_decode( json_encode( $data, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR ),
			$data,
			'The emitted structure must survive a JSON round trip unchanged, which it can only do '
				. 'if it holds scalars, arrays and null exclusively.'
		);
	}

	/**
	 * The `Language|StubUserLang` union the class declares is real, not aspirational.
	 *
	 * `::$pageLang` is natively typed as that union rather than narrowed to `Language`, because the
	 * user language can still arrive as the stub that stands in for it until first use. Narrowing
	 * the property would break that lazy-initialisation path, so this asserts the class accepts a
	 * page language that is not a `Language` at all, and that the preferred variant code reaches it
	 * verbatim through the stub's own `__call` forwarding. A mock of the stub is used rather than a
	 * real one because a real `StubUserLang` unstubs itself through `RequestContext::getMain()` on
	 * first call, which a unit test may not reach.
	 *
	 * @covers ::__construct
	 * @covers ::getDropdownLabel
	 */
	public function testPageLanguageIsAcceptedWithoutBeingNarrowedToLanguage() {
		$forwarded = [];
		$pageLang = $this->createMock( StubUserLang::class );
		$pageLang->method( '__call' )->willReturnCallback(
			static function ( $name, $args ) use ( &$forwarded ) {
				$forwarded[] = [ $name, $args ];
				return 'stubbed variant';
			}
		);

		$converter = $this->createMock( ILanguageConverter::class );
		$converter->method( 'getPreferredVariant' )->willReturn( 'sr-ec' );
		$factory = $this->createMock( LanguageConverterFactory::class );
		$factory->expects( $this->once() )
			->method( 'getLanguageConverter' )
			->with( $this->identicalTo( $pageLang ) )
			->willReturn( $converter );

		$dropdown = ( new NotionComponentVariants(
			$factory, $this->newMenuData(), $pageLang, self::ARIA_LABEL
		) )->getTemplateData()['data-variants-dropdown'];

		$this->assertSame(
			[ [ 'getVariantname', [ 'sr-ec' ] ] ],
			$forwarded,
			'The preferred variant code must be handed to the page language verbatim, through the '
				. 'stub forwarding the union exists to admit.'
		);
		$this->assertSame( 'stubbed variant', $dropdown['label'] );
	}
}
