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

use InvalidArgumentException;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentPinnableHeader;
use MediaWikiUnitTestCase;
use TypeError;

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
	 * Every `msg()` call the mocked localizer received in the current test, with all arguments.
	 *
	 * @var array[]
	 */
	private array $messageCalls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->messageCalls = [];
	}

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

		// Both aria messages must additionally receive the resolved region label as their $1,
		// which the emitted data cannot show and only the recorded calls can.
		$this->assertAriaMessagesCarryTheRegionLabel( $id, $pinAriaLabel, $unpinAriaLabel );
	}

	/**
	 * The two element types production uses reach the template verbatim.
	 *
	 * `label-tag-name` is interpolated as an element name in both the opening and the closing
	 * tag, so it is the one value in this component that cannot be wrong in a merely cosmetic
	 * way: anything other than a real tag name produces markup no parser will accept.
	 *
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testLabelTagNameIsForwardedForBothProductionTags() {
		foreach ( [ 'div', 'h2' ] as $tagName ) {
			$pinnableHeader = new NotionComponentPinnableHeader(
				$this->createMessageLocalizerMock(),
				true,
				'notion-example',
				'example-pinned',
				self::BUTTON_ARIA_LABEL_KEYS[1],
				self::BUTTON_ARIA_LABEL_KEYS[0],
				$tagName
			);

			$this->assertSame(
				$tagName,
				$pinnableHeader->getTemplateData()['label-tag-name'],
				"A label tag of $tagName must reach the template unchanged."
			);
		}
	}

	/**
	 * A null label tag is rejected at construction rather than rendered as an empty element.
	 *
	 * The parameter is typed `string` rather than `?string` precisely so this happens: a null
	 * would reach `PinnableHeader.mustache` and emit the malformed pair `<></>` around the region
	 * label, breaking the sidebar heading and any assistive technology reading it, with no PHP
	 * error anywhere to point at the cause. Locking the rejection here is what stops the type
	 * from being widened back "for symmetry" with the component this one mirrors.
	 *
	 * @covers ::__construct
	 */
	public function testNullLabelTagNameIsRejected() {
		$this->expectException( TypeError::class );

		new NotionComponentPinnableHeader(
			$this->createMessageLocalizerMock(),
			true,
			'notion-example',
			'example-pinned',
			self::BUTTON_ARIA_LABEL_KEYS[1],
			self::BUTTON_ARIA_LABEL_KEYS[0],
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable Asserting the rejection.
			null
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
	 * Tag names the component must refuse.
	 *
	 * `PinnableHeader.mustache` interpolates the label tag name into both an opening and a
	 * closing tag position, where Mustache's escaping is no defence: the first three cases below
	 * would each break out of the tag context and inject markup, the empty string would emit
	 * `<></>`, and `span` is simply not a label element this component supports. Rejecting them
	 * in the constructor is what lets the template stay a plain interpolation.
	 *
	 * @return array[]
	 */
	public static function provideRejectedLabelTagNames(): array {
		return [
			'attribute injected after the tag name' => [ 'div onclick="x()"' ],
			'tag closed early' => [ 'div><script>alert(1)</script' ],
			'leading whitespace' => [ ' h2' ],
			'empty string' => [ '' ],
			'unsupported element' => [ 'span' ],
			'wrong case' => [ 'DIV' ],
		];
	}

	/**
	 * The label tag name is validated against an allowlist rather than trusted.
	 * @covers ::__construct
	 * @dataProvider provideRejectedLabelTagNames
	 * @param string $labelTagName A tag name outside the permitted set.
	 */
	public function testInvalidLabelTagNameIsRejected( string $labelTagName ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '$labelTagName must be one of div, h2' );

		new NotionComponentPinnableHeader(
			$this->createMessageLocalizerMock(),
			true,
			'notion-example',
			'example-pinned',
			self::BUTTON_ARIA_LABEL_KEYS[1],
			self::BUTTON_ARIA_LABEL_KEYS[0],
			$labelTagName
		);
	}

	/**
	 * Both permitted tag names are accepted and reach the template unchanged.
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testPermittedLabelTagNamesAreAccepted() {
		foreach ( [ 'div', 'h2' ] as $labelTagName ) {
			$pinnableHeader = new NotionComponentPinnableHeader(
				$this->createMessageLocalizerMock(),
				true,
				'notion-example',
				'example-pinned',
				self::BUTTON_ARIA_LABEL_KEYS[1],
				self::BUTTON_ARIA_LABEL_KEYS[0],
				$labelTagName
			);

			$this->assertSame(
				$labelTagName,
				$pinnableHeader->getTemplateData()['label-tag-name'],
				"A '$labelTagName' label must reach the template unchanged."
			);
		}
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

		// Each production region must also announce itself: the aria messages carry this
		// region's own resolved label as their parameter, not a generic phrase.
		$this->assertAriaMessagesCarryTheRegionLabel(
			$id,
			self::BUTTON_ARIA_LABEL_KEYS[0],
			self::BUTTON_ARIA_LABEL_KEYS[1]
		);

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
	 * A MessageLocalizer that echoes back whichever key it is handed and records every call.
	 *
	 * The component only ever calls `Message::text()` on what it receives - message
	 * parameters are passed to `MessageLocalizer::msg()` as further arguments rather than
	 * through `Message::params()` - so `text()` is the single method that needs a return
	 * value. `__toString()` is configured alongside it so that a stringified message can
	 * never leak a bare mock into an assertion.
	 *
	 * The callback is variadic and stores the whole argument list in ::$messageCalls, which is
	 * what lets the tests assert the message *parameters* rather than only the keys. A callback
	 * that accepted `$key` alone would ignore the region label passed as `$1` to the two aria
	 * messages, so dropping or corrupting that parameter - and with it the difference between
	 * announcing "Move Tools to sidebar" and announcing a bare "Move to sidebar" - would leave
	 * the suite green.
	 *
	 * @return MessageLocalizer
	 */
	private function createMessageLocalizerMock(): MessageLocalizer {
		// Mocking the MessageLocalizer to provide predictable responses for given message keys.
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback( function ( ...$args ) {
			$this->messageCalls[] = $args;
			$key = $args[0];

			return $this->createConfiguredMock( Message::class, [
				// Simulated localization output.
				'text' => $key . self::MOCK_SUFFIX,
				'__toString' => $key . self::MOCK_SUFFIX,
			] );
		} );

		return $localizer;
	}

	/**
	 * Assert that both aria-label messages were resolved with the region label as their `$1`.
	 *
	 * The component resolves `<id>-label` a second time and hands the resulting text to the pin
	 * and unpin aria messages, so that assistive technology announces which region the button
	 * moves. That parameter is the whole point of those two messages - their English text is
	 * "Move $1 to sidebar" and "Hide $1" - and it is invisible in the emitted template data,
	 * which carries only the mocked resolution of the outer message. Recording the raw calls is
	 * the only way to see it.
	 *
	 * @param string $id Pinnable element id the component was constructed with.
	 * @param string $pinAriaLabel Key the caller supplied for the pin button.
	 * @param string $unpinAriaLabel Key the caller supplied for the unpin button.
	 */
	private function assertAriaMessagesCarryTheRegionLabel(
		string $id,
		string $pinAriaLabel,
		string $unpinAriaLabel
	): void {
		$resolvedLabel = $id . '-label' . self::MOCK_SUFFIX;

		$this->assertContains(
			[ $pinAriaLabel, $resolvedLabel ],
			$this->messageCalls,
			"The pin aria-label must be resolved as $pinAriaLabel with the region label as \$1."
		);
		$this->assertContains(
			[ $unpinAriaLabel, $resolvedLabel ],
			$this->messageCalls,
			"The unpin aria-label must be resolved as $unpinAriaLabel with the region label as \$1."
		);

		// The label message is resolved three times in total - once for the visible label and
		// once for each aria parameter - and asserting the count is what stops a change from
		// satisfying the two assertions above by passing a literal instead of the resolution.
		$labelCalls = array_filter(
			$this->messageCalls,
			static fn ( $call ) => $call === [ $id . '-label' ]
		);
		$this->assertCount(
			3,
			$labelCalls,
			'The region label is resolved once for the heading and once per aria message.'
		);
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
