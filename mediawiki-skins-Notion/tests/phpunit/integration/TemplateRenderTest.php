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

namespace MediaWiki\Skins\Notion\Tests\Integration;

use MediaWiki\Html\TemplateParser;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentButton;
use MediaWiki\Skins\Notion\Components\NotionComponentDropdown;
use MediaWiki\Skins\Notion\Components\NotionComponentLink;
use MediaWiki\Skins\Notion\Components\NotionComponentPinnableContainer;
use MediaWiki\Skins\Notion\Components\NotionComponentPinnableElement;
use MediaWiki\Skins\Notion\Components\NotionComponentPinnableHeader;
use MediaWikiIntegrationTestCase;
use UnexpectedValueException;

/**
 * Renders every Mustache template the skin currently ships and asserts the markup that comes out.
 *
 * The component tests assert the data the PHP side emits; this asserts what the templates do with
 * it. The gap between the two is not theoretical: a template is compiled by LightnCandy with
 * `FLAG_ERROR_EXCEPTION`, so a malformed section or an unresolvable partial is a fatal error on the
 * first page that renders it, and a mis-typed attribute name or a `{{ }}` where `{{{ }}}` was meant
 * produces a page that renders but is wrong — double-escaped markup, a checkbox the label does not
 * address, an aria attribute that never appears.
 *
 * The renderer used here is the one that ships: core's `TemplateParser` over the skin's own
 * `templateDirectory`, with recursive partials enabled exactly as `SkinMustache::getTemplateParser()`
 * configures it. Data comes from the skin's own component classes wherever one exists, so a template
 * and its component are exercised against each other rather than against a fixture that agrees with
 * neither.
 *
 * Three properties are asserted throughout, because each is a contract the skin has committed to:
 *
 *  1. **The checkbox hack.** Disclosure is a `<label for>` pointing at a real `<input
 *     type="checkbox">`. If the two ids ever stop agreeing, every dropdown in the skin stops opening
 *     for a reader with JavaScript disabled, and nothing else fails.
 *  2. **Escaping.** Interpolations that carry text are escaped and the ones that carry core's
 *     pre-built markup are raw. Getting either backwards is a security or a rendering defect, and
 *     both are invisible until a value contains a quote or an angle bracket.
 *  3. **Core-owned identifiers survive.** `#footer`, `.mw-footer`, `#siteSub`, `.mw-indicators`,
 *     `.mw-logo*`, `.skin-invert` and `data-mw-interface` are targeted by core, by gadgets and by
 *     the content contract, so a restyle may add classes around them but may not replace them.
 *
 * @group Notion
 * @group Templates
 * @coversNothing
 */
class TemplateRenderTest extends MediaWikiIntegrationTestCase {

	/**
	 * Every template this skin ships at present.
	 *
	 * Listed rather than discovered so that a template added by later work does not silently fail a
	 * test it was never written against; the list is asserted to be complete for the files that do
	 * exist, and anyone adding a template is expected to extend it along with a render case below.
	 */
	private const TEMPLATES = [
		'BeforeContent',
		'BottomDock',
		'Button',
		'Dropdown/Close',
		'Dropdown/Open',
		'Footer',
		'Footer__row',
		'Icon',
		'Indicators',
		'Link',
		'Logo',
		'PinnableContainer/Close',
		'PinnableContainer/Pinned/Open',
		'PinnableContainer/Unpinned/Open',
		'PinnableElement/Close',
		'PinnableElement/Open',
		'PinnableHeader',
	];

	/**
	 * A parser configured the way `SkinMustache` configures its own.
	 *
	 * One deviation is necessary and it is confined to the test. Core's
	 * `TemplateParser::getTemplateFilename()` rejects `/` in a template name as path-traversal
	 * paranoia, while partial names are resolved by a separate callback that allows it — which is
	 * why `{{>Dropdown/Open}}` works in production but `processTemplate( 'Dropdown/Open' )` cannot.
	 * The subclass below therefore permits the directory separator while keeping every other
	 * character core forbids, so the templates in sub-directories can be addressed directly instead
	 * of being reachable only through a parent this skin has not authored yet.
	 *
	 * @return TemplateParser
	 */
	private function newParser(): TemplateParser {
		$directory = dirname( __DIR__, 3 ) . '/includes/templates';

		$parser = new class( $directory ) extends TemplateParser {
			/** @inheritDoc */
			protected function getTemplateFilename( $templateName ) {
				// Core's guard, minus the forward slash, plus an explicit refusal of the traversal
				// the guard exists to prevent.
				if ( strcspn( $templateName, ":\\\000&<>'\"%" ) !== strlen( $templateName )
					|| str_contains( $templateName, '..' )
				) {
					throw new UnexpectedValueException( "Malformed \$templateName: $templateName" );
				}

				return dirname( __DIR__, 3 ) . "/includes/templates/{$templateName}.mustache";
			}
		};
		$parser->enableRecursivePartials( true );

		return $parser;
	}

	/**
	 * @param string $template
	 * @param mixed $data
	 * @return string rendered markup
	 */
	private function render( string $template, $data ): string {
		return $this->newParser()->processTemplate( $template, $data );
	}

	/**
	 * A localizer that echoes the key it is asked for, with any parameters appended.
	 *
	 * Echoing rather than translating keeps the assertions about markup: a rendered string that
	 * contains the key proves the value reached the element it belongs in, whatever a wiki would
	 * translate it to.
	 *
	 * @return MessageLocalizer
	 */
	private function newLocalizer(): MessageLocalizer {
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback( function ( $key, ...$params ) {
			$message = $this->createMock( Message::class );
			$text = $params ? $key . '[' . implode( ',', $params ) . ']' : $key;
			$message->method( 'text' )->willReturn( $text );
			$message->method( 'escaped' )->willReturn( $text );
			$message->method( '__toString' )->willReturn( $text );
			$message->method( 'rawParams' )->willReturnSelf();
			return $message;
		} );

		return $localizer;
	}

	/**
	 * Every template named above exists, and every template that exists is named.
	 *
	 * The second half is what stops this file from quietly covering less than it claims: a template
	 * present in the tree but absent from the list would be an untested template, and the list is
	 * the only place that fact is visible.
	 */
	public function testTheListedTemplatesAreTheTemplatesThatExist() {
		$directory = dirname( __DIR__, 3 ) . '/includes/templates';

		$found = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'mustache' ) {
				$relative = substr( $file->getPathname(), strlen( $directory ) + 1 );
				$found[] = substr( $relative, 0, -strlen( '.mustache' ) );
			}
		}
		sort( $found );
		$listed = self::TEMPLATES;
		sort( $listed );

		$this->assertSame( $listed, $found,
			'Every template in the tree must be named in self::TEMPLATES, and a template added '
				. 'without one is a template whose markup nothing checks. Adding the name is enough '
				. 'to give it the compile-and-no-leftover-tag case below; a render case asserting '
				. 'its own markup is what the template then deserves.' );
	}

	/**
	 * @dataProvider provideTemplateNames
	 * @param string $template
	 * @param mixed $emptyContext the emptiest context this template can legitimately be given
	 */
	public function testEveryTemplateCompiles( string $template, $emptyContext ) {
		// Compilation is where a malformed section, an unbalanced delimiter or a partial that
		// resolves to nothing is discovered, and LightnCandy raises rather than degrading. Rendering
		// with no data at all is therefore a meaningful check on its own: it must not throw, and a
		// template whose every value is absent must not emit the literal text of its placeholders.
		$output = $this->render( $template, $emptyContext );

		$this->assertStringNotContainsString( '{{', $output,
			"$template left an uninterpolated Mustache tag in its output." );
		$this->assertStringNotContainsString( 'Array', $output,
			"$template rendered an array where a scalar belongs." );
	}

	public static function provideTemplateNames(): array {
		return array_map(
			static function ( $name ) {
				// Every template is given an empty array except Icon, whose whole context is the
				// icon name: it is included as `{{#icon}}{{>Icon}}{{/icon}}`, so `{{.}}` inside it
				// resolves against a string rather than a record.
				return [ $name, $name === 'Icon' ? '' : [] ];
			},
			self::TEMPLATES
		);
	}

	public function testButtonRendersAnAnchorWhenItHasAHrefAndAButtonWhenItDoesNot() {
		$link = $this->render( 'Button', ( new NotionComponentButton(
			'Search', 'search', 'n-search', 'search-toggle',
			[ 'title' => 'Search this wiki', 'accesskey' => 'f' ],
			'quiet', 'default', true, '/wiki/Special:Search'
		) )->getTemplateData() );

		$this->assertStringContainsString( '<a href="/wiki/Special:Search"', $link );
		$this->assertStringContainsString( 'id="n-search"', $link );
		$this->assertStringContainsString( 'title="Search this wiki"', $link );
		$this->assertStringContainsString( 'accesskey="f"', $link );
		$this->assertStringContainsString( '<span>Search</span>', $link );
		$this->assertStringContainsString( '</a>', $link );
		$this->assertStringNotContainsString( '<button', $link );
		// The icon partial has to be reached from inside the button, or an icon-only control renders
		// as an empty box.
		$this->assertStringContainsString( 'mw-ui-icon-wikimedia-search', $link );
		$this->assertStringContainsString( 'cdx-button--fake-button', $link,
			'Codex styles an anchor as a button only when it carries the fake-button classes.' );

		$button = $this->render( 'Button', ( new NotionComponentButton( 'Pin' ) )->getTemplateData() );
		$this->assertStringContainsString( '<button', $button );
		$this->assertStringContainsString( '</button>', $button );
		$this->assertStringNotContainsString( '<a href', $button );
		$this->assertStringNotContainsString( 'cdx-button--fake-button', $button,
			'A real button must not claim to be a fake one.' );
	}

	public function testButtonEscapesEverythingItInterpolates() {
		$output = $this->render( 'Button', ( new NotionComponentButton(
			'5 < 6 & "quoted"', null, 'id"break', null,
			[ 'title' => 'He said "hi" & <left>' ], 'normal', 'default', false, '/a?b=1&c=2'
		) )->getTemplateData() );

		$this->assertStringContainsString( '5 &lt; 6 &amp; &quot;quoted&quot;', $output );
		$this->assertStringContainsString( 'He said &quot;hi&quot; &amp; &lt;left&gt;', $output );
		$this->assertStringContainsString( 'href="/a?b=1&amp;c=2"', $output );
		$this->assertStringNotContainsString( '<left>', $output,
			'An attribute value that closed its own tag would be an injection, not a typo.' );
	}

	public function testIconEmitsBothIconClassFormsCoreProvides() {
		$output = $this->render( 'Icon', 'ellipsis' );

		$this->assertStringContainsString( 'notion-icon', $output );
		$this->assertStringContainsString( 'mw-ui-icon-ellipsis', $output );
		$this->assertStringContainsString( 'mw-ui-icon-wikimedia-ellipsis', $output,
			'The OOUIIconPackModule selector is `.mw-ui-icon-wikimedia-<name>`, so the icon is '
				. 'invisible without this class.' );
		$this->assertStringContainsString( 'cdx-button__icon', $output );
	}

	public function testLinkRendersCoresPortletRecord() {
		// Link.mustache is authored against the record core's SkinComponentLink::makeLink() produces
		// — `array-attributes`, `icon`, `text` — which is why the attributes arrive as key/value
		// pairs rather than as a pre-built string.
		$output = $this->render( 'Link', [
			'array-attributes' => [
				[ 'key' => 'href', 'value' => '/wiki/Main_Page' ],
				[ 'key' => 'class', 'value' => 'mw-list-item' ],
				[ 'key' => 'title', 'value' => 'Visit the "main" page' ],
			],
			'icon' => 'home',
			'text' => 'Main page & more',
		] );

		$this->assertStringContainsString( '<a data-mw-interface', $output,
			'Core marks interface links with this attribute; content links must stay distinguishable '
				. 'from them.' );
		$this->assertStringContainsString( 'href="/wiki/Main_Page"', $output );
		$this->assertStringContainsString( 'class="mw-list-item"', $output );
		$this->assertStringContainsString( 'title="Visit the &quot;main&quot; page"', $output );
		$this->assertStringContainsString( 'mw-ui-icon-wikimedia-home', $output );
		$this->assertStringContainsString( '<span>Main page &amp; more</span>', $output );
	}

	/**
	 * `NotionComponentLink` speaks the same shape `Link.mustache` reads.
	 *
	 * The component has no call site in the rendering path yet -- `MenuListItem.mustache` and
	 * `MenuContents.mustache` are still to be written, and today's menus carry core's own portlet
	 * records -- but it emits core's canonical record shape (`icon`, `text`, `array-attributes`),
	 * so its output renders as a working anchor rather than as an anchor with no destination. This
	 * test is what stops the two drifting apart again: if either side reverts to a pre-serialised
	 * attribute string beside a separate `href`, the anchor below silently loses its `href` and
	 * this fails.
	 */
	public function testNotionComponentLinkOutputIsWhatLinkMustacheReads() {
		$data = ( new NotionComponentLink( '/wiki/Foo', 'Foo', 'article' ) )->getTemplateData();
		$this->assertSame( [ 'icon', 'text', 'array-attributes' ], array_keys( $data ),
			'The component emits core\'s canonical portlet-link record and nothing else.' );
		$this->assertSame(
			[ [ 'key' => 'href', 'value' => '/wiki/Foo' ] ],
			$data['array-attributes'],
			'With no localizer there is nothing to localise, so href is the only attribute.'
		);

		$output = $this->render( 'Link', $data );
		$this->assertStringContainsString( '<a data-mw-interface', $output );
		$this->assertStringContainsString( 'href="/wiki/Foo"', $output,
			'The template expands `array-attributes`, so the component\'s href reaches the anchor: '
				. 'this is the unified shape, and losing it would take navigation with it.' );
		$this->assertStringContainsString( 'mw-ui-icon-wikimedia-article', $output );
		$this->assertStringContainsString( '<span>Foo</span>', $output );
	}

	public function testDropdownIsUsableWithoutJavaScript() {
		$data = ( new NotionComponentDropdown(
			'notion-page-tools-dropdown', 'Tools', 'notion-page-tools', 'ellipsis',
			' title="Page tools"'
		) )->getTemplateData();
		$output = $this->render( 'Dropdown/Open', $data ) . $this->render( 'Dropdown/Close', [] );

		$this->assertStringContainsString( 'id="notion-page-tools-dropdown"', $output );
		$this->assertStringContainsString( 'class="notion-dropdown notion-page-tools"', $output );
		$this->assertStringContainsString( 'type="checkbox" id="notion-page-tools-dropdown-checkbox"',
			$output );
		$this->assertStringContainsString( 'for="notion-page-tools-dropdown-checkbox"', $output,
			'The label must address the checkbox by id; if the two disagree the dropdown stops '
				. 'opening for every reader without JavaScript and nothing else breaks visibly.' );
		$this->assertStringContainsString( 'role="button"', $output );
		$this->assertStringContainsString( 'aria-haspopup="true"', $output );
		$this->assertStringContainsString( 'aria-label="Tools"', $output,
			'With no explicit aria-label the visible label is announced instead of nothing.' );
		$this->assertStringContainsString( 'title="Page tools"', $output,
			'`html-tooltip` is core\'s pre-built attribute string and must be interpolated raw.' );
		$this->assertStringContainsString( 'mw-ui-icon-wikimedia-ellipsis', $output );
		// Open contributes the dropdown wrapper and the content wrapper; Close closes both. The two
		// templates are authored as a pair and are always rendered as one, so an imbalance between
		// them would nest every following region inside the dropdown.
		$this->assertSame( 2, substr_count( $output, '<div' ) );
		$this->assertSame(
			substr_count( $output, '<div' ),
			substr_count( $output, '</div>' ),
			'Open and Close are authored as a pair, so together they must balance.'
		);
	}

	public function testDropdownPrefersAnExplicitAriaLabelOverTheVisibleOne() {
		$data = ( new NotionComponentDropdown( 'notion-variants-dropdown', '简体', '' ) )
			->getTemplateData();
		$data['aria-label'] = 'Change language variant';

		$output = $this->render( 'Dropdown/Open', $data );

		$this->assertStringContainsString( 'aria-label="Change language variant"', $output );
		$this->assertStringNotContainsString( 'aria-label="简体"', $output,
			'A control whose visible text is a bare variant name needs the explicit name instead, '
				. 'and the two must not both be emitted.' );
		$this->assertStringContainsString( '>简体<', $output,
			'The visible label is still shown.' );
	}

	/**
	 * @dataProvider providePinnedState
	 * @param bool $pinned
	 * @param string $expectedClass
	 * @param string $forbiddenClass
	 */
	public function testPinnableHeaderRendersBothStatesAndBothControls(
		bool $pinned, string $expectedClass, string $forbiddenClass
	) {
		$data = ( new NotionComponentPinnableHeader(
			$this->newLocalizer(), $pinned, 'notion-toc', 'toc-pinned',
			'notion-unpin-element-aria-label', 'notion-pin-element-aria-label', 'h2'
		) )->getTemplateData();
		$output = $this->render( 'PinnableHeader', $data );

		$this->assertStringContainsString( $expectedClass, $output );
		$this->assertStringNotContainsString( $forbiddenClass, $output );
		$this->assertStringContainsString( '<h2 class="notion-pinnable-header-label">', $output,
			'The label element is a constructor decision -- a plain `div` for a header that carries '
				. 'no outline weight, `h2` where it belongs in the document outline -- and those two '
				. 'are the only values the component accepts.' );
		$this->assertStringContainsString( 'data-feature-name="toc-pinned"', $output );
		$this->assertStringContainsString( 'data-pinnable-element-id="notion-toc"', $output );
		$this->assertStringContainsString( 'data-pinned-container-id="notion-toc-pinned-container"',
			$output );
		$this->assertStringContainsString(
			'data-unpinned-container-id="notion-toc-unpinned-container"', $output );

		// Both controls are always present; which one is reachable is a styling decision, so the
		// markup must not omit either.
		$this->assertStringContainsString( 'data-event-name="pinnable-header.notion-toc.pin"',
			$output );
		$this->assertStringContainsString( 'data-event-name="pinnable-header.notion-toc.unpin"',
			$output );
		$this->assertStringContainsString(
			'aria-label="notion-pin-element-aria-label[notion-toc-label]"', $output,
			'The region name is passed as the message parameter, so a screen reader hears which '
				. 'region is being pinned rather than a bare "Pin".' );
		$this->assertStringContainsString(
			'aria-label="notion-unpin-element-aria-label[notion-toc-label]"', $output );
	}

	public static function providePinnedState(): array {
		return [
			'pinned' => [ true, 'notion-pinnable-header-pinned', 'notion-pinnable-header-unpinned' ],
			'unpinned' => [ false, 'notion-pinnable-header-unpinned', 'notion-pinnable-header-pinned' ],
		];
	}

	public function testPinnableElementWrapsItsHeaderAndBalances() {
		$data = ( new NotionComponentPinnableElement( 'notion-main-menu' ) )->getTemplateData();
		$data['data-pinnable-header'] = ( new NotionComponentPinnableHeader(
			$this->newLocalizer(), true, 'notion-main-menu', 'main-menu-pinned',
			'notion-unpin-element-aria-label', 'notion-pin-element-aria-label'
		) )->getTemplateData();

		$output = $this->render( 'PinnableElement/Open', $data )
			. $this->render( 'PinnableElement/Close', [] );

		$this->assertStringContainsString(
			'<div id="notion-main-menu" class="notion-main-menu notion-pinnable-element">', $output,
			'The id doubles as a class so the stylesheets can select the element without an id '
				. 'selector, which would raise specificity for every rule inside it.' );
		$this->assertStringContainsString( 'notion-pinnable-header', $output );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ) );

		$withoutHeader = $this->render( 'PinnableElement/Open',
			( new NotionComponentPinnableElement( 'notion-main-menu' ) )->getTemplateData() );
		$this->assertStringNotContainsString( 'notion-pinnable-header', $withoutHeader,
			'An element with no header must not emit an empty one; the section is conditional.' );
	}

	public function testPinnableContainersNameTheirStateInTheirId() {
		$pinned = ( new NotionComponentPinnableContainer( 'notion-toc', true ) )->getTemplateData();
		$unpinned = ( new NotionComponentPinnableContainer( 'notion-toc', false ) )
			->getTemplateData();

		$this->assertStringContainsString(
			'<div id="notion-toc-pinned-container" class="notion-pinned-container">',
			$this->render( 'PinnableContainer/Pinned/Open', $pinned )
		);
		$this->assertStringContainsString(
			'<div id="notion-toc-unpinned-container" class="notion-unpinned-container">',
			$this->render( 'PinnableContainer/Unpinned/Open', $unpinned )
		);
		// The pinning script moves an element between the two containers by id, so the two ids must
		// stay distinct and derived from the same element id.
		$this->assertSame( '</div>',
			trim( $this->render( 'PinnableContainer/Close', [] ) ) );
	}

	public function testFooterRendersEveryPortletRowAndKeepsCoresIdentifiers() {
		$row = static function ( string $id, array $items ): array {
			return [ 'id' => $id, 'class' => 'mw-footer-list', 'array-items' => $items ];
		};
		$output = $this->render( 'Footer', [
			'html-user-language-attributes' => ' lang="en" dir="ltr"',
			'data-portlets' => [
				'data-footer-info' => $row( 'footer-info', [
					[ 'id' => 'footer-info-lastmod', 'html' => 'This page was last edited&hellip;' ],
				] ),
				'data-footer-places' => $row( 'footer-places', [
					[ 'id' => 'footer-places-privacy', 'html' => '<a href="/privacy">Privacy</a>' ],
				] ),
				'data-footer-icons' => $row( 'footer-icons', [
					[ 'id' => 'footer-poweredbyico', 'html' => '<img src="/mw.png" alt="">' ],
				] ),
			],
		] );

		$this->assertStringContainsString( '<footer id="footer" class="mw-footer"', $output,
			'Core and gadgets target #footer and .mw-footer; a restyle may not rename them.' );
		$this->assertStringContainsString( 'lang="en" dir="ltr"', $output );
		foreach ( [ 'footer-info', 'footer-places', 'footer-icons' ] as $id ) {
			$this->assertStringContainsString( 'id="' . $id . '"', $output,
				"The $id row must be rendered; each is a separate portlet core builds." );
		}
		$this->assertStringContainsString( '<a href="/privacy">Privacy</a>', $output,
			'Portlet item HTML is built by core and must be interpolated raw, or the reader sees '
				. 'the markup instead of the link.' );
		$this->assertStringContainsString( 'id="footer-poweredbyico"', $output );
	}

	public function testFooterRowOmitsTheClassAttributeWhenThereIsNoClass() {
		$output = $this->render( 'Footer__row', [
			'id' => 'footer-info',
			'array-items' => [ [ 'id' => 'footer-info-copyright', 'html' => 'CC BY-SA' ] ],
		] );

		$this->assertStringContainsString( '<ul id="footer-info">', $output );
		$this->assertStringNotContainsString( 'class=""', $output,
			'An empty class attribute is emitted by a template that interpolates unconditionally; '
				. 'this one is section-guarded.' );
		$this->assertStringContainsString( '<li id="footer-info-copyright">CC BY-SA</li>', $output );
	}

	public function testIndicatorsRenderCoresMarkupInCoresContainer() {
		$output = $this->render( 'Indicators', [
			'array-indicators' => [
				[
					'id' => 'mw-indicator-good-article',
					'class' => 'mw-indicator',
					'html' => '<a href="/good"><img src="/star.png" alt="Good article"></a>',
				],
			],
		] );

		$this->assertStringContainsString( '<div class="mw-indicators">', $output,
			'Extensions place indicators by targeting this container.' );
		$this->assertStringContainsString( 'id="mw-indicator-good-article"', $output );
		$this->assertStringContainsString( '<img src="/star.png" alt="Good article">', $output );

		$empty = $this->render( 'Indicators', [ 'array-indicators' => [] ] );
		$this->assertStringContainsString( 'mw-indicators', $empty,
			'The container is emitted even when empty, because core styles it away rather than '
				. 'expecting the skin to omit it.' );
	}

	public function testBeforeContentGatesBothOfItsRegions() {
		$full = $this->render( 'BeforeContent', [
			'has-buttons-in-content-top' => true,
			'is-article' => true,
			'msg-tagline' => 'From MediaWiki, the free wiki',
			'array-indicators' => [
				[ 'id' => 'mw-indicator-x', 'class' => 'mw-indicator', 'html' => 'x' ],
			],
		] );

		$this->assertStringContainsString( 'notion-body-before-content', $full );
		$this->assertStringContainsString( 'mw-indicators', $full );
		$this->assertStringContainsString(
			'<div id="siteSub" class="noprint">From MediaWiki, the free wiki</div>', $full,
			'#siteSub is core\'s own id and the tagline is only shown on articles.' );

		$bare = $this->render( 'BeforeContent', [
			'has-buttons-in-content-top' => false,
			'is-article' => false,
			'msg-tagline' => 'From MediaWiki, the free wiki',
		] );
		$this->assertStringContainsString( 'notion-body-before-content', $bare );
		$this->assertStringNotContainsString( 'mw-indicators', $bare,
			'A page with no indicators must not emit their container here.' );
		$this->assertStringNotContainsString( 'siteSub', $bare,
			'A non-article must not carry the site tagline.' );
	}

	public function testBottomDockRendersNothingWithoutItsPortlet() {
		$this->assertSame( '', trim( $this->render( 'BottomDock', [ 'data-portlets' => [] ] ) ),
			'The dock is a whole region; with no portlet it must contribute no markup at all, not '
				. 'an empty list the stylesheets would still lay out.' );

		$output = $this->render( 'BottomDock', [
			'data-portlets' => [
				'data-dock-bottom' => [
					'id' => 'p-dock-bottom',
					'class' => 'mw-portlet notion-dock-bottom',
					'array-items' => [ [ 'html-item' => '<li id="dock-item"><a href="/x">X</a></li>' ] ],
				],
			],
		] );
		$this->assertStringContainsString( 'id="p-dock-bottom"', $output );
		$this->assertStringContainsString( 'class="mw-portlet notion-dock-bottom"', $output );
		$this->assertStringContainsString( '<li id="dock-item"><a href="/x">X</a></li>', $output,
			'`html-item` is a complete list item built by core, so it is interpolated raw.' );
	}

	public function testLogoKeepsTheContentContractAndFallsBackToText() {
		$withImages = $this->render( 'Logo', [
			'link-mainpage' => '/wiki/Main_Page',
			'icon' => '/images/icon.png',
			'msg-sitetitle' => 'MediaWiki',
			'msg-sitesubtitle' => 'The free wiki',
			'wordmark' => [ 'src' => '/images/wordmark.svg', 'style' => 'width: 7.5em;' ],
			'tagline' => [
				'src' => '/images/tagline.svg', 'width' => 120, 'height' => 13, 'style' => '',
			],
		] );

		$this->assertStringContainsString( '<a href="/wiki/Main_Page" class="mw-logo">', $withImages );
		$this->assertStringContainsString( 'class="mw-logo-icon" src="/images/icon.png"', $withImages );
		$this->assertStringContainsString( 'aria-hidden="true"', $withImages,
			'The icon is decorative beside the wordmark, so it is hidden from assistive technology '
				. 'rather than announced twice.' );
		$this->assertStringContainsString( 'skin-invert', $withImages,
			'`.skin-invert` is part of the public content contract and is what lets a dark theme '
				. 'invert the logo; removing it is a visible regression in night mode only.' );
		$this->assertStringContainsString( 'class="mw-logo-wordmark" alt="MediaWiki"', $withImages );
		$this->assertStringContainsString( 'class="mw-logo-tagline"', $withImages );
		$this->assertStringContainsString( 'width="120" height="13"', $withImages );

		$textOnly = $this->render( 'Logo', [
			'link-mainpage' => '/wiki/Main_Page',
			'msg-sitetitle' => 'MediaWiki',
		] );
		$this->assertStringContainsString( '<strong class="mw-logo-wordmark">MediaWiki</strong>',
			$textOnly,
			'A wiki with no wordmark image must still show its name, in the same element class.' );
		$this->assertStringNotContainsString( '<img', $textOnly,
			'No image may be emitted with no source; a broken image is worse than text.' );
	}
}
