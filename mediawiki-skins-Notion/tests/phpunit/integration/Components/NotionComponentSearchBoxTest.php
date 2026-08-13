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

namespace MediaWiki\Skins\Notion\Tests\Integration\Components;

use MediaWiki\Config\HashConfig;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Linker\Linker;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentSearchBox;
use MediaWiki\Skins\Notion\Constants;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * Tests for the Notion skin's search-box component.
 *
 * This is an integration test rather than a unit test, and the reason is a property of the
 * component rather than a convenience: `::getTemplateData()` reaches core statics that need the
 * service container and the main request context. `Title::newFromText()` parses the search page
 * title through the title parser, and `Linker::tooltipAndAccesskeyAttribs( 'search' )` is called
 * without a localizer argument, so it resolves `tooltip-search`, `accesskey-search`,
 * `word-separator` and `brackets` against `RequestContext::getMain()`. Both are deliberate — the
 * component reproduces `VectorComponentSearchBox` call for call, which is the parity the skin is
 * specified against — so the test meets the component where it lives instead of asking production
 * code to change shape for the benefit of a test double.
 *
 * What is asserted is the whole of the component's own contribution, since core's search-box data
 * arrives already assembled:
 *
 *  1. The four marker classes this component owns, and the exact conditions each appears under.
 *     `notion-search-box-vue` is unconditional; the other three are gated, and the auto-expand
 *     gate is a conjunction — the caller's preference is necessary but not sufficient, because
 *     widening the field only makes sense when the results carry thumbnails.
 *  2. The six presentation flags, with their types. `is-collapsible`, `is-thumbnail`,
 *     `is-auto-expand` and `is-primary` must be real booleans, not truthy values, because the
 *     template drives Mustache sections off them.
 *  3. That core's data is augmented rather than replaced: keys the skin does not care about reach
 *     the template intact and the caller's array is never mutated in place.
 *  4. The collapsed-state button, which is what keeps search usable with JavaScript disabled: it
 *     is reduced to plain data, it carries a real local URL for the search page core named, and
 *     it forwards `Linker`'s tooltip and access-key attributes untouched.
 *  5. That the two call sites `SkinNotion` constructs — the header's primary field and the sticky
 *     header's second, independent one — are distinguishable in the emitted data, since the two
 *     share one class and differ only in the arguments they pass.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentSearchBox
 */
class NotionComponentSearchBoxTest extends MediaWikiIntegrationTestCase {

	/** The always-present class that pairs with the `skin-notion-search-vue` body class. */
	private const VUE_CLASS = 'notion-search-box-vue';
	private const COLLAPSIBLE_CLASS = 'notion-search-box-collapses';
	private const THUMBNAIL_CLASS = 'notion-search-box-show-thumbnail';
	private const AUTO_EXPAND_CLASS = 'notion-search-box-auto-expand-width';

	/** Label the stubbed localizer resolves for core's `search` message. */
	private const SEARCH_LABEL = 'Search (label under test)';

	/**
	 * A page that certainly exists in a freshly installed test wiki, used as the search page so
	 * that `Title::newFromText()->getLocalURL()` has something real to resolve.
	 */
	private const SEARCH_PAGE = 'Special:Search';

	/**
	 * `Linker::accesskey()` memoises per hint in a public static that nothing scopes to a test, so
	 * it is cleared symmetrically around every test in this class. The component always uses the
	 * `search` hint, so a value cached by an unrelated test would otherwise decide what this one
	 * observes.
	 */
	protected function setUp(): void {
		parent::setUp();
		Linker::$accesskeycache = [];
	}

	protected function tearDown(): void {
		Linker::$accesskeycache = [];
		parent::tearDown();
	}

	/**
	 * A localizer that resolves every key to a fixed, recognisable string.
	 *
	 * Only `search` is looked up through the localizer (for the button's label); `Linker` resolves
	 * its own messages against the main context, so this double deliberately does not try to
	 * intercept those.
	 *
	 * @return MessageLocalizer
	 */
	private function newLocalizer(): MessageLocalizer {
		$message = $this->createMock( Message::class );
		$message->method( 'text' )->willReturn( self::SEARCH_LABEL );

		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturn( $message );

		return $localizer;
	}

	/**
	 * Config in the shape `skin.json` declares for `NotionTypeahead`.
	 *
	 * @param bool $showThumbnail
	 * @return HashConfig
	 */
	private function newConfig( bool $showThumbnail ): HashConfig {
		return new HashConfig( [
			'NotionTypeahead' => [
				'apiUrl' => null,
				'recommendationApiUrl' => null,
				'options' => [
					'showThumbnail' => $showThumbnail,
					'showDescription' => true,
				],
			],
		] );
	}

	/**
	 * Core's search-box data, carrying one key the skin has no interest in so that pass-through
	 * can be observed.
	 *
	 * @return array
	 */
	private function newSearchBoxData(): array {
		return [
			'form-action' => '/w/index.php',
			'html-input-attributes' => ' placeholder="Search MediaWiki"',
			'page-title' => self::SEARCH_PAGE,
			'msg-sitesubtitle' => 'From MediaWiki',
		];
	}

	/**
	 * @param array $overrides keyed by constructor parameter name
	 * @return array template data
	 */
	private function buildTemplateData( array $overrides = [] ): array {
		$args = $overrides + [
			'searchBoxData' => $this->newSearchBoxData(),
			'isCollapsible' => true,
			'isPrimary' => true,
			'formId' => 'searchform',
			'autoExpandWidth' => true,
			'showThumbnail' => true,
			'location' => Constants::SEARCH_BOX_INPUT_LOCATION_MOVED,
		];

		return ( new NotionComponentSearchBox(
			$args['searchBoxData'],
			$args['isCollapsible'],
			$args['isPrimary'],
			$args['formId'],
			$args['autoExpandWidth'],
			$this->newConfig( $args['showThumbnail'] ),
			$args['location'],
			$this->newLocalizer()
		) )->getTemplateData();
	}

	/**
	 * The four marker classes, and the exact condition each is emitted under.
	 *
	 * The auto-expand row pairs are the substance of this provider: a caller asking for
	 * auto-expansion on a typeahead that shows no thumbnails must not get it, because there is
	 * nothing to widen the field for.
	 *
	 * @return array
	 */
	public static function provideClassComposition(): array {
		return [
			'header field: collapsible, thumbnails, auto-expanding' => [
				true, true, true,
				[ self::VUE_CLASS, self::COLLAPSIBLE_CLASS, self::THUMBNAIL_CLASS, self::AUTO_EXPAND_CLASS ],
			],
			'sticky field: none of the three' => [
				false, false, false,
				[ self::VUE_CLASS ],
			],
			'collapsible only, thumbnails off' => [
				true, false, false,
				[ self::VUE_CLASS, self::COLLAPSIBLE_CLASS ],
			],
			'auto-expand asked for but no thumbnails to expand for' => [
				false, true, false,
				[ self::VUE_CLASS ],
			],
			'thumbnails without auto-expand' => [
				false, false, true,
				[ self::VUE_CLASS, self::THUMBNAIL_CLASS ],
			],
			'auto-expand with thumbnails, not collapsible' => [
				false, true, true,
				[ self::VUE_CLASS, self::THUMBNAIL_CLASS, self::AUTO_EXPAND_CLASS ],
			],
		];
	}

	/**
	 * @dataProvider provideClassComposition
	 * @covers ::getTemplateData
	 * @covers ::doesSearchHaveThumbnails
	 * @param bool $isCollapsible
	 * @param bool $autoExpandWidth
	 * @param bool $showThumbnail
	 * @param string[] $expectedClasses
	 */
	public function testMarkerClassesAndFlagsAgree(
		bool $isCollapsible, bool $autoExpandWidth, bool $showThumbnail, array $expectedClasses
	) {
		$data = $this->buildTemplateData( [
			'isCollapsible' => $isCollapsible,
			'autoExpandWidth' => $autoExpandWidth,
			'showThumbnail' => $showThumbnail,
		] );

		$this->assertSame(
			$expectedClasses,
			preg_split( '/\s+/', $data['class'], -1, PREG_SPLIT_NO_EMPTY ),
			'`components/SearchBox.less` keys off exactly these class names, in this order.'
		);

		// The flags and the classes are two views of the same three decisions, so they must never
		// disagree: the template uses the flags for Mustache sections and the stylesheet uses the
		// classes, and a page where one said yes and the other no would render inconsistently.
		$this->assertSame( $isCollapsible, $data['is-collapsible'] );
		$this->assertSame( $showThumbnail, $data['is-thumbnail'] );
		$this->assertSame( $showThumbnail && $autoExpandWidth, $data['is-auto-expand'],
			'Widening the field is conditional on there being thumbnails to widen for.' );
		$this->assertSame(
			in_array( self::COLLAPSIBLE_CLASS, $expectedClasses, true ), $data['is-collapsible'] );
		$this->assertSame(
			in_array( self::THUMBNAIL_CLASS, $expectedClasses, true ), $data['is-thumbnail'] );
		$this->assertSame(
			in_array( self::AUTO_EXPAND_CLASS, $expectedClasses, true ), $data['is-auto-expand'] );
	}

	/**
	 * The class attribute's literal value, one separator between any two classes.
	 *
	 * The component collects its classes as tokens and joins them once, so exactly one space
	 * separates any two of them however many of the three optional flags apply. Concatenating
	 * fragments that each carry their own separator is what puts doubled spaces into the class
	 * attribute of every rendered page, and a trailing `trim()` only hides the ones at the edges.
	 * The byte sequence is what reaches the page, so it is asserted literally here: a change to the
	 * composition shows up as this one failing assertion rather than as a silent diff in every
	 * rendered page.
	 *
	 * @covers ::getTemplateData
	 */
	public function testClassAttributeCarriesExactlyOneSeparatorBetweenClasses() {
		$data = $this->buildTemplateData();

		$this->assertSame(
			'notion-search-box-vue notion-search-box-collapses notion-search-box-show-thumbnail'
				. ' notion-search-box-auto-expand-width',
			$data['class']
		);
		$this->assertStringNotContainsString( '  ', $data['class'],
			'Doubled whitespace must be structurally impossible, not merely absent today.' );

		// The single-token case is the sticky header's configuration, and it is where a stray
		// separator would previously have surfaced as a trailing space. Asserting it here — and not
		// merely in the fully flagged case above — is what keeps the join load-bearing.
		$sticky = $this->buildTemplateData( [
			'isCollapsible' => false,
			'autoExpandWidth' => false,
			'showThumbnail' => false,
		] );
		$this->assertSame( self::VUE_CLASS, $sticky['class'],
			'With no flags set the base class stands alone, with no separator of its own.' );
		$this->assertSame( trim( $sticky['class'] ), $sticky['class'] );
	}

	/**
	 * @covers ::getTemplateData
	 * @covers ::getSearchBoxInputLocation
	 */
	public function testCoreDataIsAugmentedAndTheCallerSArrayIsNotMutated() {
		$input = $this->newSearchBoxData();
		$data = $this->buildTemplateData( [ 'searchBoxData' => $input ] );

		foreach ( $input as $key => $value ) {
			$this->assertSame( $value, $data[$key],
				"Core's `$key` must reach the template unchanged." );
		}
		$this->assertSame( $this->newSearchBoxData(), $input,
			'The component must copy rather than modify the array core gave it.' );
		$this->assertArrayNotHasKey( 'data-collapsed-search-button', $input );
	}

	/**
	 * @covers ::getTemplateData
	 */
	public function testFormIdAndInputLocationArePassedThroughForBothCallSites() {
		$header = $this->buildTemplateData( [
			'formId' => 'searchform',
			'isPrimary' => true,
			'location' => Constants::SEARCH_BOX_INPUT_LOCATION_MOVED,
		] );
		$sticky = $this->buildTemplateData( [
			'formId' => 'notion-sticky-search-form',
			'isPrimary' => false,
			'isCollapsible' => false,
			'autoExpandWidth' => false,
			'location' => Constants::SEARCH_BOX_INPUT_LOCATION_DEFAULT,
		] );

		$this->assertSame( 'searchform', $header['form-id'] );
		$this->assertSame( 'notion-sticky-search-form', $sticky['form-id'] );
		$this->assertNotSame( $header['form-id'], $sticky['form-id'],
			'Element ids are unique per document, so the two boxes must not share one.' );

		$this->assertTrue( $header['is-primary'] );
		$this->assertFalse( $sticky['is-primary'],
			'Exactly one box per page may claim core\'s p-search, simpleSearch and searchInput ids.' );

		$this->assertSame( 'header-moved', $header['input-location'] );
		$this->assertSame( 'header-navigation', $sticky['input-location'] );
		$this->assertSame( Constants::SEARCH_BOX_INPUT_LOCATION_MOVED, $header['input-location'],
			'The value is written out as the data-search-loc attribute, so it is instrumentation '
				. 'as well as layout and must not be reinterpreted here.' );
	}

	/**
	 * The collapsed-state button is what makes search work without JavaScript.
	 *
	 * @covers ::getTemplateData
	 */
	public function testCollapsedSearchButtonIsAWorkingLinkToTheSearchPage() {
		$data = $this->buildTemplateData();
		$button = $data['data-collapsed-search-button'];

		$this->assertIsArray( $button,
			'The button must be reduced to plain data; Mustache cannot traverse an object and the '
				. 'component snapshots are JSON.' );
		$this->assertSame(
			[ 'label', 'icon', 'id', 'class', 'href', 'array-attributes' ],
			array_keys( $button ),
			'The button contract is NotionComponentButton\'s, unmodified.'
		);

		$this->assertSame( self::SEARCH_LABEL, $button['label'],
			'The label is core\'s own `search` message, resolved through the injected localizer.' );
		$this->assertSame( 'search', $button['icon'] );
		$this->assertNull( $button['id'],
			'The button carries no id of its own; the form and input own the ids core defines.' );

		$expectedHref = Title::newFromText( self::SEARCH_PAGE )->getLocalURL();
		$this->assertSame( $expectedHref, $button['href'] );
		$this->assertNotSame( '', $button['href'],
			'At narrow viewports the input is hidden, so a dead control here would leave no way to '
				. 'search without JavaScript (T284242).' );

		// Codex only styles an anchor as a button when it really is a link, so the fake-button pair
		// appearing here is the observable consequence of the href being real.
		$this->assertSame(
			'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled'
				. ' cdx-button--weight-quiet cdx-button--icon-only search-toggle',
			$button['class']
		);
	}

	/**
	 * `Linker`'s tooltip and access-key attributes are forwarded untouched.
	 *
	 * The point of the assertion is that the component does not invent, filter or rename them:
	 * whatever `Linker::tooltipAndAccesskeyAttribs( 'search' )` produces for this wiki is what the
	 * button carries, which is what keeps tooltip and access-key behaviour identical to every
	 * other MediaWiki skin.
	 *
	 * @covers ::getTemplateData
	 */
	public function testTooltipAndAccessKeyAttributesComeFromLinkerUnaltered() {
		$data = $this->buildTemplateData();
		$attributes = $data['data-collapsed-search-button']['array-attributes'];

		Linker::$accesskeycache = [];
		$expected = [];
		foreach ( Linker::tooltipAndAccesskeyAttribs( 'search' ) as $key => $value ) {
			if ( $value !== null && !in_array( $key, [ 'id', 'class', 'href' ], true ) ) {
				$expected[] = [ 'key' => $key, 'value' => $value ];
			}
		}

		$this->assertSame( $expected, $attributes );
		$this->assertNotSame( [], $attributes,
			'Linker produces at least a title for the `search` hint on a default wiki; an empty '
				. 'list here would mean the attributes never reached the button.' );
		$this->assertContains( 'title', array_column( $attributes, 'key' ) );
	}

	/**
	 * @covers ::getTemplateData
	 */
	public function testEmittedStructureIsPlainData() {
		$data = $this->buildTemplateData();

		$this->assertSame(
			json_decode( json_encode( $data, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR ),
			$data,
			'The structure must survive a JSON round trip unchanged, which it can only do if it '
				. 'holds scalars, arrays and null exclusively.'
		);
	}
}
