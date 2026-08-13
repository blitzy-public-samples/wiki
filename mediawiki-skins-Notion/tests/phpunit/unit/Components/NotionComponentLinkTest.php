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
use MediaWiki\Linker\Linker;
use MediaWiki\Message\Message;
use MediaWiki\Skins\Notion\Components\NotionComponentLink;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's link primitive.
 *
 * `NotionComponentLink` emits three keys - `icon`, `text` and `array-attributes` - and that is
 * core's own portlet-link record shape, the skin's one canonical link shape. `Link.mustache` is
 * written against exactly that shape, expanding `array-attributes` one key/value record at a time,
 * so a link this class builds and a link core built are interchangeable at the template. Unifying
 * the two is what keeps `href` -- the attribute most easily lost -- from being dropped by whichever
 * producer the partial was not written against, and it is why these tests assert the record list
 * rather than a serialised attribute string.
 *
 * `NotionComponentMenuListItem` is the consumer, unioning those keys with the `item-class` and
 * `item-id` belonging to the surrounding `<li>`. There is no call site in today's rendering path,
 * though: the links a page renders are core's own records, delivered as
 * `data-portlets.*.array-items[].array-links[]` and passed through untouched by
 * `NotionComponentMenu`, and no template includes `>Link` while `MenuListItem.mustache` and
 * `MenuContents.mustache` are still to be written. The class is kept faithful to the reference
 * skin's component set, and emitting the canonical shape is what makes those partials able to
 * render it unchanged when they land.
 *
 * What the assertions have to be precise about is the record list itself, since nothing downstream
 * would reveal a mistake in it: `Linker::tooltipAndAccesskeyAttribs()` resolves five separate
 * messages to produce `title` and `accesskey`, any of which can be absent or disabled on a real
 * wiki, and a null-valued attribute has to be dropped rather than carried. Values are stored raw
 * and escaped by the template's `{{value}}` expression, so each arm below pins the whole list -
 * keys, order and unescaped values - rather than a substring of it.
 *
 * Two isolation rules govern every test here and neither may be relaxed:
 *
 *   - The localizer is always a test double. `MediaWikiUnitTestCase` calls
 *     `MediaWikiServices::disallowGlobalInstanceInUnitTests()`, so a real `MessageLocalizer`, or a
 *     real `Message` built through `wfMessage()`, would trip the "Premature access to service
 *     container" guard. The component stays testable in that isolation precisely because it hands
 *     its localizer to `Linker::tooltipAndAccesskeyAttribs()` as that method's fourth argument,
 *     which is what stops `Linker` from falling back to `RequestContext::getMain()`.
 *   - `Linker::accesskey()` memoises its answer in the public static `Linker::$accesskeycache`,
 *     keyed by access-key hint alone. That cache outlives a test method, so every test uses its
 *     own hint AND the cache is cleared symmetrically in ::setUp() and ::tearDown().
 *     ::testAccessKeyCacheLeaksBetweenLocalizers() is the test that demonstrates why: with a warm
 *     cache the second component silently reports the first component's access key.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentLink
 */
class NotionComponentLinkTest extends MediaWikiUnitTestCase {

	/**
	 * The complete set of keys `getTemplateData()` is allowed to emit.
	 *
	 * Asserted on every path so that a key added without a matching consumer change, or a key
	 * silently dropped, fails here rather than as an empty hole in a rendered menu row.
	 */
	private const EXPECTED_TEMPLATE_DATA_KEYS = [ 'icon', 'text', 'array-attributes' ];

	/**
	 * Every `msg()` call the current test's localizer received, in order, with all arguments.
	 *
	 * Recording the raw arguments - rather than checking only the first one - is what lets a test
	 * assert that a message was *not* requested, and that a message parameter was passed at all.
	 *
	 * @var array[]
	 */
	private array $messageCalls = [];

	protected function setUp(): void {
		parent::setUp();
		// Enter every test with a cold access-key cache, whatever ran before it.
		Linker::$accesskeycache = [];
		$this->messageCalls = [];
	}

	protected function tearDown(): void {
		// And leave one behind, so a test in this class can never colour a later test's result.
		Linker::$accesskeycache = [];
		parent::tearDown();
	}

	/**
	 * The attribute records as a plain key => value map, for assertions that do not care about
	 * order.
	 *
	 * @param array $templateData Data emitted by the component.
	 * @return array<string,string>
	 */
	private static function attributeMap( array $templateData ): array {
		$map = [];
		foreach ( $templateData['array-attributes'] as $attribute ) {
			$map[ $attribute['key'] ] = $attribute['value'];
		}
		return $map;
	}

	/**
	 * The attribute names in the order the component emitted them.
	 *
	 * Order is asserted because the emitted data is snapshotted elsewhere in the suite and because
	 * `href` first is the documented reading order of the rendered markup.
	 *
	 * @param array $templateData Data emitted by the component.
	 * @return string[]
	 */
	private static function attributeKeys( array $templateData ): array {
		return array_column( $templateData['array-attributes'], 'key' );
	}

	/**
	 * A localizer that records every call and resolves each key to its own name.
	 *
	 * Keeping the key as the resolved text is what lets the assertions prove *which* message
	 * produced each attribute rather than merely that some attribute was produced.
	 *
	 * @param array $options Keyed overrides:
	 *   - `missing`: string[] keys whose `exists()` reports false.
	 *   - `disabled`: string[] keys whose `isDisabled()` reports true. `Linker` treats a disabled
	 *     tooltip or access key as "no attribute", which is how a wiki suppresses one.
	 *   - `plain`: string value every `plain()` returns; this is what becomes the access key.
	 *   - `text`: array mapping a key to the text it resolves to, for keys whose resolved value
	 *     needs to differ from the key itself.
	 * @return MessageLocalizer
	 */
	private function newRecordingLocalizer( array $options = [] ): MessageLocalizer {
		$missing = $options['missing'] ?? [];
		$disabled = $options['disabled'] ?? [];
		$plain = $options['plain'] ?? 'k';
		$texts = $options['text'] ?? [];

		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback(
			function ( ...$args ) use ( $missing, $disabled, $plain, $texts ) {
				$this->messageCalls[] = $args;
				$key = $args[0];

				// A configured mock rather than a real Message: constructing one would reach for
				// the service container this test class is forbidden from touching.
				return $this->createConfiguredMock( Message::class, [
					'exists' => !in_array( $key, $missing, true ),
					'isDisabled' => in_array( $key, $disabled, true ),
					'text' => $texts[$key] ?? $key,
					'plain' => $plain,
					'__toString' => $texts[$key] ?? $key,
				] );
			}
		);

		return $localizer;
	}

	/**
	 * The message keys the component and `Linker` resolve for a fully described link, in order.
	 *
	 * @param string $accessKeyHint Hint the component was constructed with.
	 * @param string $accessKey Access key the localizer resolves, which `Linker` passes on as the
	 *   parameter of the `brackets` message.
	 * @return array[] Expected `msg()` argument lists.
	 */
	private function expectedMessageCalls( string $accessKeyHint, string $accessKey ): array {
		return [
			// Linker::titleAttrib() runs first, and always passes its message parameters array.
			[ 'tooltip-' . $accessKeyHint, [] ],
			// Then Linker::accesskey(), and only if it answered does the bracketed suffix follow.
			[ 'accesskey-' . $accessKeyHint ],
			[ 'word-separator' ],
			[ 'brackets', $accessKey ],
			// The component's own aria-label lookup comes last, after Linker has been consulted.
			[ $accessKeyHint . '-label' ],
		];
	}

	/**
	 * A link described by an icon, a localizer and an access-key hint.
	 *
	 * This is the only combination that contributes attributes beyond `href`, and the whole record
	 * list is asserted rather than sampled: `title`, `accesskey` and `aria-label` are built from
	 * five different messages, and a spot check would pass while any of the others was missing or
	 * misordered.
	 *
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateData() {
		$href = '/mock-link';
		$text = 'Mock Text';
		$icon = 'mock-icon';
		// Unique to this test: Linker::$accesskeycache is keyed by hint alone.
		$accessKeyHint = 'notion-test-fully-described';

		$localizer = $this->newRecordingLocalizer( [
			'plain' => 'x',
			'text' => [ $accessKeyHint . '-label' => 'Mock aria label' ],
		] );

		$actual = ( new NotionComponentLink( $href, $text, $icon, $localizer, $accessKeyHint ) )
			->getTemplateData();

		$this->assertSame(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'A fully described link emits exactly these three keys, in this order, and nothing more'
		);
		$this->assertSame( $icon, $actual['icon'], 'The icon name is passed through untouched' );
		$this->assertSame( $text, $actual['text'], 'The label is passed through untouched' );
		$this->assertSame(
			[ 'href', 'title', 'accesskey', 'aria-label' ],
			self::attributeKeys( $actual ),
			'href is emitted first, then the attributes Linker derives, then the accessible name'
		);

		// `Linker::titleAttrib()` assembles the title from three messages when the 'withaccess'
		// option is in play, which `tooltipAndAccesskeyAttribs()` always adds: the tooltip, a
		// word separator, and the bracketed access key. The mocked localizer renders each as its
		// own key, so the expected title is those three keys concatenated. The access key itself
		// comes from `plain()`, not `text()`, which is why it reads `x` rather than a key name.
		$this->assertSame(
			[
				'href' => $href,
				'title' => 'tooltip-' . $accessKeyHint . 'word-separatorbrackets',
				'accesskey' => 'x',
				'aria-label' => 'Mock aria label',
			],
			self::attributeMap( $actual ),
			'Every attribute record, with its value stored raw for the template to escape'
		);

		$this->assertSame(
			$this->expectedMessageCalls( $accessKeyHint, 'x' ),
			$this->messageCalls,
			'The aria-label is read from `<hint>-label`, and the tooltip and access key from '
				. 'their own `tooltip-` and `accesskey-` keys'
		);
	}

	/**
	 * An absent `<hint>-label` message costs the link its aria-label and nothing else.
	 *
	 * Most access-key hints have no `-label` companion on a real wiki - it is the exception, not
	 * the rule - so this is the common case rather than an edge case, and the `exists()` guard is
	 * what stops the attribute from being emitted as the literal message key. The tooltip and
	 * access key must still be produced.
	 *
	 * @covers ::getTemplateData
	 */
	public function testAriaLabelIsOmittedWhenItsMessageDoesNotExist() {
		$accessKeyHint = 'notion-test-missing-label';
		$localizer = $this->newRecordingLocalizer( [
			'missing' => [ $accessKeyHint . '-label' ],
			'plain' => 'y',
		] );

		$actual = ( new NotionComponentLink( '/mock-link', 'Mock Text', null, $localizer, $accessKeyHint ) )
			->getTemplateData();

		$this->assertSame(
			[
				'href' => '/mock-link',
				'title' => 'tooltip-' . $accessKeyHint . 'word-separatorbrackets',
				'accesskey' => 'y',
			],
			self::attributeMap( $actual ),
			'Without its message there is no aria-label, and no empty attribute in its place'
		);
		$this->assertNotContains(
			'aria-label',
			self::attributeKeys( $actual ),
			'A missing message must not surface as an aria-label naming the message key'
		);
		$this->assertSame(
			$this->expectedMessageCalls( $accessKeyHint, 'y' ),
			$this->messageCalls,
			'The component still asks for the label message; it just cannot use the answer'
		);
	}

	/**
	 * A disabled `accesskey-` message costs the link its access key and its bracketed suffix.
	 *
	 * `Linker::accesskey()` reports false for a message a wiki has disabled with `-`, and
	 * `titleAttrib()` then leaves the tooltip bare instead of appending an empty bracket pair.
	 * Both halves are asserted, because emitting `accesskey=""` would make the control
	 * unreachable by keyboard shortcut while looking correct in the markup.
	 *
	 * @covers ::getTemplateData
	 */
	public function testAccessKeyIsOmittedWhenItsMessageIsDisabled() {
		$accessKeyHint = 'notion-test-disabled-accesskey';
		$localizer = $this->newRecordingLocalizer( [
			'disabled' => [ 'accesskey-' . $accessKeyHint ],
		] );

		$actual = ( new NotionComponentLink( '/mock-link', 'Mock Text', null, $localizer, $accessKeyHint ) )
			->getTemplateData();

		$this->assertSame(
			[
				'href' => '/mock-link',
				'title' => 'tooltip-' . $accessKeyHint,
				'aria-label' => $accessKeyHint . '-label',
			],
			self::attributeMap( $actual ),
			'A disabled access key leaves a bare tooltip, with no accesskey attribute at all'
		);
		$this->assertSame(
			[
				[ 'tooltip-' . $accessKeyHint, [] ],
				[ 'accesskey-' . $accessKeyHint ],
				[ $accessKeyHint . '-label' ],
			],
			$this->messageCalls,
			'Neither the word separator nor the brackets are resolved once the access key is gone'
		);
	}

	/**
	 * Message text is carried raw, for the template to escape.
	 *
	 * Message text is wiki content: an administrator can put a quotation mark, an angle bracket or
	 * an ampersand in any of these messages. In the canonical record shape the escaping belongs to
	 * the template, which expands each record as `{{key}}="{{value}}"` -- a double-brace expression,
	 * so every value is HTML-escaped exactly once on its way into the attribute. This test pins the
	 * component's half of that contract: values reach the record list unescaped and unconcatenated,
	 * so nothing is double-escaped and nothing is escaped twice differently. Escaping here instead
	 * would surface as `&amp;quot;` in the rendered markup.
	 *
	 * @covers ::getTemplateData
	 */
	public function testAttributeValuesAreCarriedRawForTheTemplateToEscape() {
		$accessKeyHint = 'notion-test-escaping';
		$localizer = $this->newRecordingLocalizer( [
			'plain' => '"',
			'text' => [
				$accessKeyHint . '-label' => 'Alt & "quoted" <label>',
				'tooltip-' . $accessKeyHint => 'Tip "with" <markup> & more',
				'word-separator' => ' ',
				'brackets' => '[?]',
			],
		] );

		$actual = ( new NotionComponentLink( '/mock-link', 'Mock Text', null, $localizer, $accessKeyHint ) )
			->getTemplateData();

		$this->assertSame(
			[
				'href' => '/mock-link',
				'title' => 'Tip "with" <markup> & more [?]',
				'accesskey' => '"',
				'aria-label' => 'Alt & "quoted" <label>',
			],
			self::attributeMap( $actual ),
			'Quotation marks, angle brackets and ampersands are carried verbatim, because '
				. 'Link.mustache escapes each value with a double-brace expression'
		);
	}

	/**
	 * A bare link: no icon, no localizer and no access-key hint.
	 *
	 * This is the default construction used across the skin's menus, so it is the case that proves
	 * a link with no messages behind it still renders as a working anchor: `href` is always present,
	 * and here it is the only record. `array-attributes` is always a list, never null, which is what
	 * lets the template loop over it unconditionally -- and null-valued attributes are dropped from
	 * it rather than carried, so no attribute can ever render as the literal word "null".
	 *
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataWithoutLocalizer() {
		$href = '/mock-link';
		$text = 'Mock Text';

		$actual = ( new NotionComponentLink( $href, $text ) )->getTemplateData();

		$this->assertSame(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'A bare link emits the same key set, in the same order, as a fully described one'
		);
		$this->assertSame( $text, $actual['text'], 'The label is passed through untouched' );
		$this->assertNull(
			$actual['icon'],
			'An omitted icon stays null so the template skips the Icon partial entirely'
		);
		$this->assertSame(
			[ [ 'key' => 'href', 'value' => $href ] ],
			$actual['array-attributes'],
			'Without a localizer there is nothing to localise, so href is the only attribute'
		);
		$this->assertSame(
			[],
			Linker::$accesskeycache ?? [],
			'A link with no localizer must not reach Linker at all, warm cache or not'
		);
	}

	/**
	 * An access-key hint without a localizer emits nothing and resolves nothing.
	 *
	 * Both collaborators are required before any attribute is built, and this is the arm where
	 * the hint is present. It is the dangerous one: `Linker::accesskey()` falls back to
	 * `RequestContext::getMain()` when it is handed no localizer, which in a unit test is a
	 * "Premature access to service container" failure and on a real page is the wrong user
	 * language. Reaching Linker at all here would therefore be a defect, not merely waste.
	 *
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testAccessKeyHintWithoutLocalizerEmitsNothing() {
		$actual = ( new NotionComponentLink(
			'/mock-link',
			'Mock Text',
			'mock-icon',
			null,
			'notion-test-hint-without-localizer'
		) )->getTemplateData();

		$this->assertSame(
			[ [ 'key' => 'href', 'value' => '/mock-link' ] ],
			$actual['array-attributes'],
			'A hint alone is not enough: without a localizer no message can be resolved'
		);
		$this->assertSame(
			[],
			Linker::$accesskeycache ?? [],
			'Linker::accesskey() must not be called, or it would fall back to the main context'
		);
	}

	/**
	 * A localizer without an access-key hint emits nothing and asks for nothing but the label.
	 *
	 * The tooltip and access key are derived from the hint, so there is nothing to derive them
	 * from here. Pinning this arm stops a future change from building a meaningless
	 * `title="tooltip-"` out of an absent hint, and the recorded calls state exactly how far the
	 * component gets before the guard stops it.
	 *
	 * @covers ::__construct
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataWithoutAccessKeyHint() {
		$icon = 'mock-icon';
		// This localizer reports every message as existing, which is the worst case for this
		// path: even so, no attribute may be emitted while the access-key hint is missing.
		$localizer = $this->newRecordingLocalizer();

		$actual = ( new NotionComponentLink( '/mock-link', 'Mock Text', $icon, $localizer ) )
			->getTemplateData();

		$this->assertSame(
			self::EXPECTED_TEMPLATE_DATA_KEYS,
			array_keys( $actual ),
			'Omitting the access-key hint does not change the key set'
		);
		$this->assertSame( $icon, $actual['icon'], 'The icon name is still passed through' );
		$this->assertSame(
			[ [ 'key' => 'href', 'value' => '/mock-link' ] ],
			$actual['array-attributes'],
			'A localizer alone is not enough: without a hint there is no tooltip to build'
		);
		$this->assertSame(
			[],
			$this->messageCalls,
			'Both collaborators are required before any message is resolved, so not even the '
				. 'aria-label lookup happens and Linker is never reached'
		);
		$this->assertSame(
			[],
			Linker::$accesskeycache ?? [],
			'No hint means no access key, so nothing is memoised'
		);
	}

	/**
	 * The access-key cache is keyed by hint alone, so a warm cache outlives its localizer.
	 *
	 * This is not a claim about the component but about the static it depends on, and it is here
	 * because it is the reason every test in this class owns a unique hint and clears
	 * `Linker::$accesskeycache` in ::setUp() and ::tearDown(). Two components built with the same
	 * hint and different localizers must be shown to disagree while the cache is warm, and to
	 * agree with their own localizer once it is cleared. Without that discipline a test could
	 * pass on a value another test's localizer supplied.
	 *
	 * @covers ::getTemplateData
	 */
	public function testAccessKeyCacheLeaksBetweenLocalizers() {
		$accessKeyHint = 'notion-test-cache-isolation';

		$first = ( new NotionComponentLink(
			'/mock-link',
			'Mock Text',
			null,
			$this->newRecordingLocalizer( [ 'plain' => 'a' ] ),
			$accessKeyHint
		) )->getTemplateData();
		$this->assertSame( 'a', self::attributeMap( $first )['accesskey'] ?? null );
		$this->assertSame(
			[ $accessKeyHint => 'a' ],
			Linker::$accesskeycache,
			'The access key is memoised under the bare hint, with nothing to scope it to a wiki, '
				. 'a user language or a test'
		);

		// Same hint, a localizer answering differently, cache left warm on purpose.
		$second = ( new NotionComponentLink(
			'/mock-link',
			'Mock Text',
			null,
			$this->newRecordingLocalizer( [ 'plain' => 'b' ] ),
			$accessKeyHint
		) )->getTemplateData();
		$this->assertSame(
			'a',
			self::attributeMap( $second )['accesskey'] ?? null,
			'With a warm cache the second link reports the first localizer\'s access key'
		);

		// Clearing the cache is what makes the second localizer's answer observable.
		Linker::$accesskeycache = [];
		$third = ( new NotionComponentLink(
			'/mock-link',
			'Mock Text',
			null,
			$this->newRecordingLocalizer( [ 'plain' => 'b' ] ),
			$accessKeyHint
		) )->getTemplateData();
		$this->assertSame(
			'b',
			self::attributeMap( $third )['accesskey'] ?? null,
			'Once the cache is cleared the component reports its own localizer\'s access key'
		);
	}
}
