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

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentPinnableHeader;
use MediaWikiUnitTestCase;

/**
 * Isolated unit tests for the Notion skin's pinnable header component.
 *
 * The header is the strip of chrome that lets a reader move the main menu, the table of
 * contents, the page tools and the appearance panel between the sidebar and their in-page
 * dropdown. Its template data is therefore a contract with three separate consumers:
 * `PinnableHeader.mustache` reads the eleven keys asserted below, `pinnableElement.js`
 * reads the `data-*` keys to find the containers it swaps the element between, and the
 * `notion-<region>-label` messages supply the human readable region name.
 *
 * Every message here resolves through a mocked `MessageLocalizer`, so no localisation
 * cache, service container, global or database is touched.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentPinnableHeader
 */
class NotionComponentPinnableHeaderTest extends MediaWikiUnitTestCase {

	/**
	 * Suffix the mocked localizer appends to every key it is asked to resolve.
	 *
	 * Keeping the key as a prefix of the mocked output is what lets the assertions below
	 * prove *which* message produced each string, rather than merely that some message did.
	 */
	private const MOCK_SUFFIX = '-mocked-label';

	/**
	 * Location of the skin's English message file, relative to this test's own directory.
	 *
	 * `__DIR__` is `tests/phpunit/unit/Components`, so four levels up is the skin root.
	 */
	private const MESSAGE_FILE = '/../../../../i18n/en.json';

	/**
	 * The exact set of keys, in order, that `PinnableHeader.mustache` renders against.
	 *
	 * Asserting the whole set - and not merely the presence of individual keys - is what
	 * turns a renamed, added or silently dropped key into a failing test instead of an
	 * empty attribute in the rendered markup.
	 */
	private const EXPECTED_KEYS = [
		'is-pinned',
		'label',
		'label-tag-name',
		'pin-label',
		'unpin-label',
		'pin-aria-label',
		'unpin-aria-label',
		'data-pinnable-element-id',
		'data-feature-name',
		'data-unpinned-container-id',
		'data-pinned-container-id',
	];

	/**
	 * The two fixed message keys the component resolves without being told to.
	 */
	private const BUTTON_LABEL_KEYS = [
		'notion-pin-element-label',
		'notion-unpin-element-label',
	];

	/**
	 * The aria-label keys every production caller hands to the component.
	 */
	private const BUTTON_ARIA_LABEL_KEYS = [
		'notion-pin-element-aria-label',
		'notion-unpin-element-aria-label',
	];

	/**
	 * This method provides different sets of parameters for tests, simulating different scenarios.
	 * @return array[]
	 */
	public static function provideTestCases(): array {
		return [
			// Test case with the header element intended to be movable.
			'Pinnable Header With Moving Element' => [
				// The header should start in a "pinned" state.
				'pinned' => true,
				// ID of the pinnable header element.
				'id' => 'notion-example',
				// Feature name for persistent state tracking.
				'featureName' => 'example-pinned',
				// i18n message key for unpin aria-label.
				'unpinAriaLabel' => 'notion-unpin-element-aria-label',
				// i18n message key for pin aria-label.
				'pinAriaLabel' => 'notion-pin-element-aria-label',
				// The type of label tag to be used.
				'labelTagName' => 'div'
			],
			// Test case with the header element fixed and not intended to be moved.
			'Pinnable Header Without Moving Element' => [
				// The header should start in an "unpinned" state.
				'pinned' => false,
				// ID of the pinnable header element.
				'id' => 'notion-another-example',
				// Feature name for tracking state.
				'featureName' => 'another-example-pinned',
				// i18n message key for unpin aria-label.
				'unpinAriaLabel' => 'notion-unpin-element-aria-label',
				// i18n message key for pin aria-label.
				'pinAriaLabel' => 'notion-pin-element-aria-label',
				// The type of label tag to be used, in this case, an h2.
				'labelTagName' => 'h2'
			],
		];
	}

	/**
	 * Tests that the getTemplateData method returns the correct data.
	 * Uses data provided by provideTestCases to run the same test with different configurations.
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 * @dataProvider provideTestCases
	 * @param bool $pinned Whether the header starts pinned.
	 * @param string $id Pinnable element id, carrying the `notion-` prefix.
	 * @param string $featureName Feature name used to persist the pinned state.
	 * @param string $unpinAriaLabel i18n message key for the unpin button's aria-label.
	 * @param string $pinAriaLabel i18n message key for the pin button's aria-label.
	 * @param string $labelTagName Element type of the label, a 'div' or an 'h2'.
	 */
	public function testGetTemplateData(
		bool $pinned,
		string $id,
		string $featureName,
		string $unpinAriaLabel,
		string $pinAriaLabel,
		string $labelTagName
	) {
		// Instantiating the component with the provided test parameters.
		$pinnableHeader = new NotionComponentPinnableHeader(
			$this->createMessageLocalizerMock(),
			$pinned,
			$id,
			$featureName,
			$unpinAriaLabel,
			$pinAriaLabel,
			$labelTagName
		);

		// Acquiring the template data from the component.
		$templateData = $pinnableHeader->getTemplateData();

		// The template contract: exactly these keys, and nothing else.
		$this->assertSame(
			self::EXPECTED_KEYS,
			array_keys( $templateData ),
			'PinnableHeader.mustache and pinnableElement.js consume exactly these keys.'
		);

		// Assertions to verify each piece of expected template data.
		$this->assertSame( $pinned, $templateData['is-pinned'] );
		$this->assertStringEndsWith( self::MOCK_SUFFIX, $templateData['label'] );
		$this->assertSame( $labelTagName, $templateData['label-tag-name'] );
		$this->assertStringEndsWith( self::MOCK_SUFFIX, $templateData['pin-label'] );
		$this->assertStringEndsWith( self::MOCK_SUFFIX, $templateData['unpin-label'] );
		$this->assertStringEndsWith( self::MOCK_SUFFIX, $templateData['pin-aria-label'] );
		$this->assertStringEndsWith( self::MOCK_SUFFIX, $templateData['unpin-aria-label'] );
		$this->assertSame( $id, $templateData['data-pinnable-element-id'] );
		$this->assertSame( $featureName, $templateData['data-feature-name'] );
		$this->assertSame( $id . '-unpinned-container', $templateData['data-unpinned-container-id'] );
		$this->assertSame( $id . '-pinned-container', $templateData['data-pinned-container-id'] );

		// The label is not passed in, it is looked up as `<id>-label`. Pinning that derivation
		// down is what ties each caller's id to the message it is obliged to declare.
		$this->assertStringStartsWith(
			$id . '-label',
			$templateData['label'],
			'The region label must be resolved from the element id plus `-label`.'
		);

		// The pin and unpin button labels come from fixed keys the component owns itself,
		// while both aria-labels come from the keys the caller supplied.
		$this->assertStringStartsWith(
			self::BUTTON_LABEL_KEYS[0],
			$templateData['pin-label'],
			'The pin button label must come from ' . self::BUTTON_LABEL_KEYS[0] . '.'
		);
		$this->assertStringStartsWith(
			self::BUTTON_LABEL_KEYS[1],
			$templateData['unpin-label'],
			'The unpin button label must come from ' . self::BUTTON_LABEL_KEYS[1] . '.'
		);
		$this->assertStringStartsWith(
			$pinAriaLabel,
			$templateData['pin-aria-label'],
			'The pin aria-label must come from the caller supplied key.'
		);
		$this->assertStringStartsWith(
			$unpinAriaLabel,
			$templateData['unpin-aria-label'],
			'The unpin aria-label must come from the caller supplied key.'
		);
	}

	/**
	 * The label tag name is optional and documented to fall back to a 'div', which is what
	 * every pinnable region except the table of contents relies on.
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testLabelTagNameDefaultsToDiv() {
		$pinnableHeader = new NotionComponentPinnableHeader(
			$this->createMessageLocalizerMock(),
			true,
			'notion-example',
			'example-pinned',
			self::BUTTON_ARIA_LABEL_KEYS[1],
			self::BUTTON_ARIA_LABEL_KEYS[0]
		);

		$this->assertSame(
			'div',
			$pinnableHeader->getTemplateData()['label-tag-name'],
			'Omitting the label tag name must yield a div.'
		);
	}

	/**
	 * The four pinnable regions the skin ships, with the arguments their callers pass.
	 *
	 * These are the ids that reach the component in production, which makes them the ids
	 * whose `<id>-label` message must exist in i18n/en.json.
	 * @return array[]
	 */
	public static function provideProductionPinnableHeaders(): array {
		return [
			// The main menu occupies the sidebar's first region.
			'Main menu' => [
				'id' => 'notion-main-menu',
				'featureName' => 'main-menu-pinned',
				'labelTagName' => 'div'
			],
			// The table of contents labels itself with an h2 so the outline stays navigable
			// to assistive technology when it is pinned beside the article.
			'Table of contents' => [
				'id' => 'notion-toc',
				'featureName' => 'toc-pinned',
				'labelTagName' => 'h2'
			],
			// Page tools sit in the end column when pinned.
			'Page tools' => [
				'id' => 'notion-page-tools',
				'featureName' => 'page-tools-pinned',
				'labelTagName' => 'div'
			],
			// The appearance panel hosts the client preferences.
			'Appearance' => [
				'id' => 'notion-appearance',
				'featureName' => 'appearance-pinned',
				'labelTagName' => 'div'
			],
		];
	}

	/**
	 * Tests the component against the real arguments its production callers pass, and asserts
	 * that every message key the resulting template data names is declared by the skin.
	 *
	 * A caller whose id constant drifts from its declared message - or a message that is
	 * renamed without its caller - surfaces here as a failure rather than as an empty label
	 * in the rendered sidebar.
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 * @dataProvider provideProductionPinnableHeaders
	 * @param string $id Pinnable element id used in production.
	 * @param string $featureName Feature name used to persist the pinned state.
	 * @param string $labelTagName Element type of the label.
	 */
	public function testProductionPinnableHeadersResolveDeclaredMessages(
		string $id,
		string $featureName,
		string $labelTagName
	) {
		$pinnableHeader = new NotionComponentPinnableHeader(
			$this->createMessageLocalizerMock(),
			true,
			$id,
			$featureName,
			self::BUTTON_ARIA_LABEL_KEYS[1],
			self::BUTTON_ARIA_LABEL_KEYS[0],
			$labelTagName
		);

		$templateData = $pinnableHeader->getTemplateData();

		$this->assertSame( self::EXPECTED_KEYS, array_keys( $templateData ) );
		$this->assertSame( $labelTagName, $templateData['label-tag-name'] );
		$this->assertSame( $featureName, $templateData['data-feature-name'] );
		$this->assertStringStartsWith( $id . '-label', $templateData['label'] );

		// The pinned and unpinned container ids are derived, not configured, and the layout
		// and the pinning script both look elements up by exactly these names.
		$this->assertSame( $id . '-unpinned-container', $templateData['data-unpinned-container-id'] );
		$this->assertSame( $id . '-pinned-container', $templateData['data-pinned-container-id'] );

		$declaredKeys = $this->getDeclaredMessageKeys();
		$expectedKeys = array_merge(
			[ $id . '-label' ],
			self::BUTTON_LABEL_KEYS,
			self::BUTTON_ARIA_LABEL_KEYS
		);
		foreach ( $expectedKeys as $messageKey ) {
			$this->assertContains(
				$messageKey,
				$declaredKeys,
				"The $messageKey message must be declared in the skin's i18n/en.json."
			);
		}
	}

	/**
	 * A MessageLocalizer that echoes back whichever key it is handed.
	 *
	 * The component only ever calls `Message::text()` on what it receives - message
	 * parameters are passed to `MessageLocalizer::msg()` as further arguments rather than
	 * through `Message::params()` - so `text()` is the single method that needs a return
	 * value. `__toString()` is configured alongside it so that a stringified message can
	 * never leak a bare mock into an assertion.
	 *
	 * @return MessageLocalizer
	 */
	private function createMessageLocalizerMock(): MessageLocalizer {
		// Mocking the MessageLocalizer to provide predictable responses for given message keys.
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback( function ( $key ) {
			return $this->createConfiguredMock( Message::class, [
				// Simulated localization output.
				'text' => $key . self::MOCK_SUFFIX,
				'__toString' => $key . self::MOCK_SUFFIX,
			] );
		} );

		return $localizer;
	}

	/**
	 * Every message key the skin declares in English.
	 *
	 * Read straight from the JSON file rather than through a message localizer, so that this
	 * remains a unit test: no localisation cache, no services and no globals are involved.
	 * The `@metadata` block is dropped because it records authorship instead of declaring a
	 * message.
	 *
	 * @return string[] Declared message keys.
	 */
	private function getDeclaredMessageKeys(): array {
		$path = __DIR__ . self::MESSAGE_FILE;
		$this->assertFileExists( $path, "The skin's English message file must exist." );

		$messages = json_decode( (string)file_get_contents( $path ), true );
		$this->assertIsArray( $messages, "The skin's English message file must be valid JSON." );

		unset( $messages['@metadata'] );

		return array_keys( $messages );
	}
}
