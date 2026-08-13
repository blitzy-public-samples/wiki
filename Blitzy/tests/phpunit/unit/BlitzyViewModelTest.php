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
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Skins\Blitzy\Tests\Unit;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\HashConfig;
use MediaWiki\Skins\Blitzy\BlitzyUrlValidator;
use MediaWiki\Skins\Blitzy\BlitzyViewModel;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Blitzy skin's view model.
 *
 * This class is the Gate 12 evidence artifact for the skin. Gate 12 asks that every
 * configuration option have a named write site and a named read site reachable from page
 * render, recorded in the propagation audit table of DELIVERY.md. The write sites are the
 * `config` block of skin.json and harness/LocalSettings.template.php; BlitzyViewModel is the
 * single read site for all eight options, and the tests below are what make that table true
 * rather than aspirational. Two independent kinds of proof are given for each option, because
 * either one alone can be satisfied vacuously:
 *
 *   - Structural. Every view model here is driven by a real HashConfig rather than a Config
 *     test double. HashConfig::get() throws a ConfigException naming the option when the
 *     option is absent, so ::testEveryConfigurationOptionIsReadFromSiteConfiguration removing
 *     one option at a time and asserting that exact exception proves the read happened. A
 *     recorded expectation on a mock would prove only that a mock was configured.
 *   - Behavioural. Each option is additionally set to two different values and the resulting
 *     change in the returned array is asserted. This is what would still hold if the view
 *     model ever guarded its reads with Config::has(), where a missing option would answer a
 *     default instead of throwing and the structural argument would go quiet.
 *
 * The other half of this class's remit is the negative half of rule R13. BlitzyViewModel does
 * not HTML-escape anything, and that is deliberate: MediaWiki's TemplateParser escapes `{{ }}`
 * interpolation, so escaping here as well would double-escape and an injected payload would
 * render visibly as `&lt;script&gt;`. There is exactly one escaping site and it is the
 * template. Accordingly ::testConfigurationStringsAreNeverEscapedByTheViewModel asserts
 * byte-identical pass-through, and it is meant to fail loudly if a well-meaning change ever
 * adds escaping here. What this class does owe R13 is normalisation and omission, and those
 * are asserted too: control characters stripped, values trimmed, hostile link targets kept out
 * of every href. The positive assertion that rendered output is escaped, and the end-to-end
 * injection of a payload and a `javascript:` URL into a live page, belong to
 * tests/phpunit/integration/BlitzyConfigSecurityTest.php; duplicating them here would put the
 * boundary the wrong way round.
 *
 * Three mechanical constraints shape the file. It has to live in a directory named `unit`,
 * because MediaWikiUnitTestCase reflects its own subclass's filename and fails any test found
 * elsewhere. It needs the class-level `@covers` below, because phpunit.xml.dist sets
 * forceCoversAnnotation and MediaWikiCoversValidator re-checks every test method on every run
 * whether or not coverage is being collected. And it must not reach the service container,
 * which is why BlitzyViewModel::localUrlForSpecialPage() is a protected seam: resolving a
 * special page needs MediaWikiServices, and a unit test is denied the global instance. The
 * double in ::newViewModel() overrides that one method and nothing else, so every other line
 * under test is the production line.
 *
 * Nothing here touches the network, a database, the filesystem or the clock, so rule R5 holds
 * and the suite is a self-contained step of harness/verify.sh as Gate 10 requires. Every URL
 * below is an inert string.
 *
 * @group Blitzy
 * @covers \MediaWiki\Skins\Blitzy\BlitzyViewModel
 */
class BlitzyViewModelTest extends MediaWikiUnitTestCase {

	/**
	 * Site-relative prefix the test double answers instead of resolving a Title.
	 *
	 * Injected into the double rather than hardcoded inside it, so the expectation in a test
	 * and the value the double produces have one source of truth. An anonymous class does not
	 * inherit the enclosing class's private scope, so it cannot read this constant directly.
	 */
	private const SEAM_URL_PREFIX = '/wiki/Special:';

	/**
	 * Payloads that must survive ::build() byte for byte.
	 *
	 * Every one of them would be altered by an HTML-escaping implementation, which is the
	 * point: these cases are the tripwire for a second escaping site being introduced. The
	 * ampersand cases matter most, because `&` is escaped by every escaping function there is,
	 * including the ones that leave angle brackets alone.
	 */
	private const PASS_THROUGH_PAYLOADS = [
		'a script element' => '<script>alert(1)</script>',
		'a bare ampersand' => 'Tom & Jerry',
		'double quotes and an ampersand' => 'He said "Blitzy" & left',
		'a single quote' => "It's ready",
		'an existing entity reference' => '&amp; already encoded',
		'an angle-bracketed attribute' => '<a href="/wiki/Blitzy">read</a>',
	];

	/**
	 * Complete site configuration for the skin, with every option set to a usable value.
	 *
	 * Completeness is not cosmetic. HashConfig::get() throws on an option it does not hold, so
	 * a partial array would make every test fail for the wrong reason and would mask the one
	 * test that deliberately removes an option. Overrides are unioned on the left so that they
	 * win, which is PHP's `+` semantics for arrays.
	 *
	 * The two link targets are deliberately unlike anything ::SEAM_URL_PREFIX can produce, so
	 * a configured target can never be mistaken for a resolved fallback in a failure message.
	 *
	 * @param array $overrides Option values replacing the defaults below.
	 * @return array Settings array suitable for HashConfig.
	 */
	private static function completeSettings( array $overrides = [] ): array {
		return $overrides + [
			BlitzyViewModel::OPTION_ANNOUNCE_ENABLE => true,
			BlitzyViewModel::OPTION_ANNOUNCE_TEXT => 'State of Wiki Engineering',
			BlitzyViewModel::OPTION_ANNOUNCE_LABEL => 'Read Now',
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => '/wiki/Blitzy:Release_notes',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => 'Start building',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => '/wiki/Blitzy:Start_building',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => 'Talk to an expert',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => 'https://example.org/experts',
		];
	}

	/**
	 * Build a view model over the supplied settings, with the special-page seam stubbed.
	 *
	 * The validator is the real BlitzyUrlValidator over a real UrlUtils, never a double: a
	 * stubbed validator would let this file assert its own idea of what is hostile instead of
	 * what the allowlist actually rejects. UrlUtils is safe to construct here because its
	 * constructor takes no required options and ::parse() reads only the protocol list held in
	 * MainConfigSchema, touching no global and no service.
	 *
	 * @param array $settings Complete settings array, normally from ::completeSettings().
	 * @return BlitzyViewModel Production class with only ::localUrlForSpecialPage() replaced.
	 */
	private function newViewModel( array $settings ): BlitzyViewModel {
		return new class(
			new HashConfig( $settings ),
			new BlitzyUrlValidator( new UrlUtils() ),
			self::SEAM_URL_PREFIX
		) extends BlitzyViewModel {
			/**
			 * @param Config $config Site configuration under test.
			 * @param BlitzyUrlValidator $urlValidator Real allowlist, not a double.
			 * @param string $seamPrefix Prefix answered in place of a resolved Title.
			 */
			public function __construct(
				Config $config,
				BlitzyUrlValidator $urlValidator,
				private readonly string $seamPrefix,
			) {
				parent::__construct( $config, $urlValidator );
			}

			/**
			 * @inheritDoc
			 */
			protected function localUrlForSpecialPage( string $name ): string {
				return $this->seamPrefix . $name;
			}
		};
	}

	/**
	 * Parent template data shaped the way SkinComponentTableOfContents shapes it.
	 *
	 * That component answers an empty array for a page with no table of contents, for
	 * `__NOTOC__` and for a parse that produced no sections, and publishes an integer
	 * `number-section-count` only when there is something to list. `data-toc` is core's key and
	 * is spelled literally here on purpose: it is the contract between core and this skin, and
	 * a test that read it from a private constant would agree with the implementation even if
	 * both had drifted away from core.
	 *
	 * @param int $count Number of sections core reports.
	 * @return array Parent template data for ::build().
	 */
	private static function parentDataWithSections( int $count ): array {
		return [
			'data-toc' => [
				'number-section-count' => $count,
				'array-sections' => array_fill( 0, $count, [ 'line' => 'Section' ] ),
			],
		];
	}

	/**
	 * The option names, spelled out as literals rather than through the constants.
	 *
	 * This is the one place in the test suite where the eight strings are written by hand, and
	 * it exists so that a rename of a constant cannot silently drift away from the `config`
	 * block of skin.json. MediaWiki reads a skin manifest's configuration options by their
	 * un-prefixed name, so these are exactly the names Config::get() is called with and exactly
	 * the names the manifest declares.
	 */
	public function testOptionConstantsSpellTheManifestOptionNames(): void {
		$this->assertSame( 'BlitzyAnnounceEnable', BlitzyViewModel::OPTION_ANNOUNCE_ENABLE );
		$this->assertSame( 'BlitzyAnnounceText', BlitzyViewModel::OPTION_ANNOUNCE_TEXT );
		$this->assertSame( 'BlitzyAnnounceLabel', BlitzyViewModel::OPTION_ANNOUNCE_LABEL );
		$this->assertSame( 'BlitzyAnnounceLink', BlitzyViewModel::OPTION_ANNOUNCE_LINK );
		$this->assertSame(
			'BlitzyPrimaryActionLabel',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL
		);
		$this->assertSame(
			'BlitzyPrimaryActionLink',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK
		);
		$this->assertSame(
			'BlitzySecondaryActionLabel',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL
		);
		$this->assertSame(
			'BlitzySecondaryActionLink',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK
		);
	}

	/**
	 * The published template-data keys and fallback page names, as literals.
	 *
	 * The key spellings carry two contracts at once. MediaWiki's template-data naming
	 * convention reserves `data-` for objects and `is-` for booleans, and the `blitzy` segment
	 * is what keeps each key clear of the core namespace, since SkinBlitzy merges this array
	 * with `+` and the left operand wins every collision.
	 *
	 * The fallback names are canonical core special-page names rather than aliases. `Randompage`
	 * in particular is not interchangeable with `Random`: the latter is only an alias, and
	 * resolving a name that is missing from the alias map emits a warning on every page view,
	 * which the zero-warning build gate would fail on.
	 */
	public function testTemplateDataKeyConstantsFollowTheNamingContract(): void {
		$this->assertSame( 'data-blitzy-announcement', BlitzyViewModel::KEY_ANNOUNCEMENT );
		$this->assertSame( 'data-blitzy-cta-primary', BlitzyViewModel::KEY_ACTION_PRIMARY );
		$this->assertSame( 'data-blitzy-cta-secondary', BlitzyViewModel::KEY_ACTION_SECONDARY );
		$this->assertSame( 'is-blitzy-toc-available', BlitzyViewModel::KEY_TOC_AVAILABLE );
		$this->assertSame( 'CreateAccount', BlitzyViewModel::FALLBACK_PAGE_ANONYMOUS );
		$this->assertSame( 'Watchlist', BlitzyViewModel::FALLBACK_PAGE_REGISTERED );
		$this->assertSame( 'Randompage', BlitzyViewModel::FALLBACK_PAGE_SECONDARY );
	}

	/**
	 * All eight configuration options, one per case.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function provideConfigurationOptionNames(): array {
		return [
			'announcement enable flag' => [ BlitzyViewModel::OPTION_ANNOUNCE_ENABLE ],
			'announcement text' => [ BlitzyViewModel::OPTION_ANNOUNCE_TEXT ],
			'announcement button label' => [ BlitzyViewModel::OPTION_ANNOUNCE_LABEL ],
			'announcement link target' => [ BlitzyViewModel::OPTION_ANNOUNCE_LINK ],
			'primary action label' => [ BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL ],
			'primary action link target' => [ BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK ],
			'secondary action label' => [ BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL ],
			'secondary action link target' => [ BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK ],
		];
	}

	/**
	 * Gate 12, structurally: every option is genuinely read out of site configuration.
	 *
	 * A single ::build() over an announcement that is both enabled and populated reads all
	 * eight options, so removing exactly one of them and asserting the ConfigException that
	 * HashConfig raises proves that option has a read site. Asserting the message as well is
	 * what makes the proof specific: it names the option that was missing, so the test cannot
	 * pass because some *other* option happened to be read first.
	 *
	 * The exception is asserted rather than allowed to escape. phpunit.xml.dist sets
	 * failOnWarning and failOnRisky, and an uncaught exception from a deliberately incomplete
	 * fixture would be an error rather than evidence.
	 *
	 * @dataProvider provideConfigurationOptionNames
	 */
	public function testEveryConfigurationOptionIsReadFromSiteConfiguration(
		string $option
	): void {
		$settings = self::completeSettings();
		unset( $settings[$option] );

		$viewModel = $this->newViewModel( $settings );

		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( "undefined option: '$option'" );
		$viewModel->build( false, self::parentDataWithSections( 3 ) );
	}

	/**
	 * A complete configuration reads without raising, which is the control for the case above.
	 *
	 * Without this, a mistake that made ::build() throw unconditionally would satisfy all eight
	 * cases of the exception test and look like proof of eight read sites.
	 */
	public function testCompleteConfigurationBuildsWithoutRaising(): void {
		$data = $this->newViewModel( self::completeSettings() )
			->build( false, self::parentDataWithSections( 3 ) );

		$this->assertCount( 4, $data );
	}

	/**
	 * The four string-valued options, each with the key its value must reach.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function provideStringOptionPropagation(): array {
		return [
			'announcement text' => [
				BlitzyViewModel::OPTION_ANNOUNCE_TEXT,
				BlitzyViewModel::KEY_ANNOUNCEMENT,
				'text',
			],
			'announcement button label' => [
				BlitzyViewModel::OPTION_ANNOUNCE_LABEL,
				BlitzyViewModel::KEY_ANNOUNCEMENT,
				'label',
			],
			'primary action label' => [
				BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL,
				BlitzyViewModel::KEY_ACTION_PRIMARY,
				'label',
			],
			'secondary action label' => [
				BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL,
				BlitzyViewModel::KEY_ACTION_SECONDARY,
				'label',
			],
		];
	}

	/**
	 * Gate 12, behaviourally: each string option changes the value of exactly its own key.
	 *
	 * Two distinct values are pushed through in turn, because asserting a single value proves
	 * only that the returned array agrees with the fixture, which a hardcoded default would do
	 * as well. Two values that both arrive can only have come from configuration.
	 *
	 * @dataProvider provideStringOptionPropagation
	 */
	public function testStringOptionsReachTheirTemplateDataKeys(
		string $option,
		string $containerKey,
		string $subKey
	): void {
		foreach ( [ 'Alpha wording', 'Bravo wording' ] as $value ) {
			$data = $this->newViewModel( self::completeSettings( [ $option => $value ] ) )
				->build( false );

			$this->assertArrayHasKey( $containerKey, $data );
			$this->assertArrayHasKey( $subKey, $data[$containerKey] );
			$this->assertSame( $value, $data[$containerKey][$subKey] );
		}
	}

	/**
	 * The three link-target options, each with the object whose href it must reach.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provideLinkTargetOptionPropagation(): array {
		return [
			'announcement link target' => [
				BlitzyViewModel::OPTION_ANNOUNCE_LINK,
				BlitzyViewModel::KEY_ANNOUNCEMENT,
			],
			'primary action link target' => [
				BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK,
				BlitzyViewModel::KEY_ACTION_PRIMARY,
			],
			'secondary action link target' => [
				BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK,
				BlitzyViewModel::KEY_ACTION_SECONDARY,
			],
		];
	}

	/**
	 * Gate 12, behaviourally: each link-target option changes exactly its own href.
	 *
	 * One site-relative and one absolute https target per option, so both accepted branches of
	 * the allowlist are exercised for every one of the three.
	 *
	 * @dataProvider provideLinkTargetOptionPropagation
	 */
	public function testLinkTargetOptionsReachTheirHrefKeys(
		string $option,
		string $containerKey
	): void {
		$targets = [ '/wiki/Blitzy:Alpha_target', 'https://example.org/bravo-target' ];

		foreach ( $targets as $target ) {
			$data = $this->newViewModel( self::completeSettings( [ $option => $target ] ) )
				->build( false );

			$this->assertArrayHasKey( 'href', $data[$containerKey] );
			$this->assertSame( $target, $data[$containerKey]['href'] );
		}
	}

	/**
	 * The enable flag, in the shapes site configuration actually produces.
	 *
	 * MediaWiki's own boolean settings are cast rather than compared, so `1` and `'1'` enable a
	 * feature just as `true` does. `'0'` is included because it is the one string PHP considers
	 * falsy besides the empty one, and getting that wrong would turn an explicit opt-out into an
	 * opt-in.
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function provideAnnouncementEnableValues(): array {
		return [
			'boolean true renders the bar' => [ true, true ],
			'integer one renders the bar' => [ 1, true ],
			'string one renders the bar' => [ '1', true ],
			'arbitrary non-empty string renders the bar' => [ 'yes', true ],
			'boolean false suppresses the bar' => [ false, false ],
			'integer zero suppresses the bar' => [ 0, false ],
			'string zero suppresses the bar' => [ '0', false ],
			'empty string suppresses the bar' => [ '', false ],
			'null suppresses the bar' => [ null, false ],
		];
	}

	/**
	 * Gate 12, behaviourally, for the one option whose effect is presence rather than value.
	 *
	 * @dataProvider provideAnnouncementEnableValues
	 */
	public function testAnnouncementEnableOptionGovernsPresence(
		mixed $enable,
		bool $expectPresent
	): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_ENABLE => $enable,
		] ) )->build( false );

		if ( $expectPresent ) {
			$this->assertArrayHasKey( BlitzyViewModel::KEY_ANNOUNCEMENT, $data );
		} else {
			$this->assertArrayNotHasKey( BlitzyViewModel::KEY_ANNOUNCEMENT, $data );
		}
	}

	/**
	 * Every string option crossed with every payload that escaping would alter.
	 *
	 * Building the cross product here rather than writing it out keeps the option list in one
	 * place: adding a string option to ::provideStringOptionPropagation() extends the R13
	 * pass-through coverage automatically instead of silently leaving the new option untested.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function providePassThroughStrings(): array {
		$cases = [];

		foreach ( self::provideStringOptionPropagation() as $optionLabel => $spec ) {
			foreach ( self::PASS_THROUGH_PAYLOADS as $payloadLabel => $payload ) {
				$cases["$optionLabel carrying $payloadLabel"] = [
					$spec[0],
					$spec[1],
					$spec[2],
					$payload,
				];
			}
		}

		return $cases;
	}

	/**
	 * Rule R13, negative half: the view model escapes nothing, and must not start.
	 *
	 * The expectation is the raw payload, compared with assertSame, so this fails the moment an
	 * implementation introduces htmlspecialchars() or any equivalent. That failure is the
	 * intended behaviour of this test, not a gap in it: TemplateParser escapes `{{ }}`
	 * interpolation, so a second pass here would double-escape and every announcement would
	 * render visible entity noise. The single escaping site is the template, and the assertion
	 * that rendered output *is* escaped belongs to the integration suite.
	 *
	 * @dataProvider providePassThroughStrings
	 */
	public function testConfigurationStringsAreNeverEscapedByTheViewModel(
		string $option,
		string $containerKey,
		string $subKey,
		string $payload
	): void {
		$data = $this->newViewModel( self::completeSettings( [ $option => $payload ] ) )
			->build( false );

		$this->assertSame( $payload, $data[$containerKey][$subKey] );
	}

	/**
	 * A link target keeps its ampersands too, for the same reason.
	 *
	 * Query-string separators are the practical case: a target escaped on its way into the
	 * template data would arrive at the browser as `&amp;` and address the wrong page.
	 */
	public function testLinkTargetsAreNeverEscapedByTheViewModel(): void {
		$target = '/w/index.php?title=Blitzy&action=history';

		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => $target,
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => $target,
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => $target,
		] ) )->build( false );

		$this->assertSame( $target, $data[BlitzyViewModel::KEY_ANNOUNCEMENT]['href'] );
		$this->assertSame( $target, $data[BlitzyViewModel::KEY_ACTION_PRIMARY]['href'] );
		$this->assertSame( $target, $data[BlitzyViewModel::KEY_ACTION_SECONDARY]['href'] );
	}

	/**
	 * A disabled announcement is absent from the array, not present and falsy.
	 *
	 * Absence is the requirement, and it is asserted as absence deliberately. The bar has to be
	 * gone from the document's flow rather than hidden, `display: none` is explicitly
	 * non-compliant, and AnnouncementBar.mustache is invoked inside a Mustache section keyed on
	 * this entry. A present-but-empty value would keep that section falsy today and would still
	 * be a compliance risk, because it invites a template author to reach inside it.
	 *
	 * The rest of the chrome is asserted present in the same breath, so that a change which
	 * emptied the whole array could not pass this test.
	 */
	public function testDisabledAnnouncementIsAbsentFromTemplateData(): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_ENABLE => false,
		] ) )->build( false );

		$this->assertArrayNotHasKey( BlitzyViewModel::KEY_ANNOUNCEMENT, $data );
		$this->assertArrayHasKey( BlitzyViewModel::KEY_ACTION_PRIMARY, $data );
		$this->assertArrayHasKey( BlitzyViewModel::KEY_ACTION_SECONDARY, $data );
		$this->assertArrayHasKey( BlitzyViewModel::KEY_TOC_AVAILABLE, $data );
	}

	/**
	 * Text values that leave the announcement with nothing to say.
	 *
	 * Whitespace-only and control-character-only values matter as much as the empty string: both
	 * normalise to nothing, and an enabled bar with nothing in it would render as an empty
	 * coloured band across every page. The non-scalar and boolean cases are here because a
	 * mistyped setting must suppress the bar rather than raise an "Array to string conversion"
	 * warning that the zero-warning build gate would fail on.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function provideBlankAnnouncementText(): array {
		return [
			'empty string' => [ '' ],
			'a single space' => [ ' ' ],
			'tab and newline only' => [ "\t\n" ],
			'a NUL byte only' => [ "\x00" ],
			'null' => [ null ],
			'an array' => [ [ 'text' ] ],
			'boolean true' => [ true ],
			'boolean false' => [ false ],
		];
	}

	/**
	 * An enabled announcement with no usable text is absent as well.
	 *
	 * Both conditions gate the bar, so this is not a duplicate of the disabled case: it is the
	 * second of the two independent suppressions the contract requires.
	 *
	 * @dataProvider provideBlankAnnouncementText
	 */
	public function testEnabledAnnouncementWithBlankTextIsAbsent( mixed $text ): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_ENABLE => true,
			BlitzyViewModel::OPTION_ANNOUNCE_TEXT => $text,
		] ) )->build( false );

		$this->assertArrayNotHasKey( BlitzyViewModel::KEY_ANNOUNCEMENT, $data );
	}

	/**
	 * A fully configured announcement publishes exactly text, label and href.
	 *
	 * The whole object is compared rather than each key in turn, which pins the shape as well as
	 * the values: an extra entry appearing here would be an unreviewed addition to the surface
	 * AnnouncementBar.mustache consumes, and no key carries an `html-` prefix, so nothing in it
	 * can be interpolated as raw HTML without that being visible in review.
	 */
	public function testConfiguredAnnouncementPublishesTextLabelAndHref(): void {
		$data = $this->newViewModel( self::completeSettings() )->build( false );

		$this->assertSame(
			[
				'text' => 'State of Wiki Engineering',
				'label' => 'Read Now',
				'href' => '/wiki/Blitzy:Release_notes',
			],
			$data[BlitzyViewModel::KEY_ANNOUNCEMENT]
		);
	}

	/**
	 * An unlabelled announcement still renders; only missing text suppresses it.
	 *
	 * The `label` entry is always published, empty or not, because deciding whether an
	 * unlabelled call to action deserves an anchor is the template's job. Keeping that decision
	 * out of here is what stops an anchor with no accessible name reaching the page while
	 * leaving the notice itself intact.
	 */
	public function testUnlabelledAnnouncementStillPublishesItsTextAndHref(): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_LABEL => '',
		] ) )->build( false );

		$announcement = $data[BlitzyViewModel::KEY_ANNOUNCEMENT];

		$this->assertSame( '', $announcement['label'] );
		$this->assertSame( 'State of Wiki Engineering', $announcement['text'] );
		$this->assertSame( '/wiki/Blitzy:Release_notes', $announcement['href'] );
	}

	/**
	 * Link targets the allowlist must reject.
	 *
	 * Each case is a distinct evasion rather than a variation on one. The interrupted schemes
	 * matter because browsers discard control characters from a URL before acting on it, so a
	 * tab-interrupted `javascript:` arrives as a working scheme unless it is stripped first. The
	 * protocol-relative spellings matter because a browser normalises a backslash to a forward
	 * slash in the authority position, so three different spellings all resolve to the same
	 * foreign origin. And `ftp://` and `mailto:` are well-formed URLs that MediaWiki's own
	 * protocol list accepts, so rejecting them proves the scheme allowlist is doing work of its
	 * own rather than leaning on the parser.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function provideRejectedLinkTargets(): array {
		return [
			'a javascript scheme' => [ 'javascript:alert(1)' ],
			'an upper-case javascript scheme' => [ 'JavaScript:alert(1)' ],
			'a tab-interrupted javascript scheme' => [ "java\tscript:alert(1)" ],
			'a newline-interrupted javascript scheme' => [ "javascript\n:alert(1)" ],
			'a NUL-interrupted javascript scheme' => [ "javascript\x00:alert(1)" ],
			'a data scheme' => [ 'data:text/html;base64,PHNjcmlwdD4=' ],
			'a vbscript scheme' => [ 'vbscript:msgbox(1)' ],
			'a file scheme' => [ 'file:///etc/passwd' ],
			'an ftp scheme outside the allowlist' => [ 'ftp://ftp.example.org/pub' ],
			'a mailto scheme outside the allowlist' => [ 'mailto:nobody@example.org' ],
			'a protocol-relative target' => [ '//blitzy.example/promo' ],
			'a backslash protocol-relative target' => [ '\\\\blitzy.example/promo' ],
			'a mixed-separator protocol-relative target' => [ '/\\blitzy.example/promo' ],
			'an unparseable target' => [ 'not a url at all' ],
			'an empty target' => [ '' ],
			'a whitespace-only target' => [ '   ' ],
		];
	}

	/**
	 * A rejected announcement target removes the href entirely.
	 *
	 * Omission, not emptying. An empty href still renders an anchor and resolves to the page it
	 * sits on, so dropping the key is what makes it impossible for the template to emit a
	 * rejected target at all: the enclosing Mustache section simply goes falsy.
	 *
	 * The text and the label survive, which is the deliberate part: a wiki whose announcement
	 * link is hostile still shows its announcement, just with nothing to follow.
	 *
	 * @dataProvider provideRejectedLinkTargets
	 */
	public function testRejectedAnnouncementTargetOmitsTheHrefEntirely( string $target ): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => $target,
		] ) )->build( false );

		$announcement = $data[BlitzyViewModel::KEY_ANNOUNCEMENT];

		$this->assertArrayNotHasKey( 'href', $announcement );
		$this->assertSame( 'State of Wiki Engineering', $announcement['text'] );
		$this->assertSame( 'Read Now', $announcement['label'] );
	}

	/**
	 * Both calls to action crossed with every rejected target.
	 *
	 * The fallback page differs between the two, and the primary one differs again by
	 * registration state, so each case carries the canonical special-page name it must resolve
	 * to for an unregistered visitor.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function provideRejectedCallToActionTargets(): array {
		$actions = [
			'the primary call to action' => [
				BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK,
				BlitzyViewModel::KEY_ACTION_PRIMARY,
				BlitzyViewModel::FALLBACK_PAGE_ANONYMOUS,
			],
			'the secondary call to action' => [
				BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK,
				BlitzyViewModel::KEY_ACTION_SECONDARY,
				BlitzyViewModel::FALLBACK_PAGE_SECONDARY,
			],
		];

		$cases = [];

		foreach ( $actions as $actionLabel => $action ) {
			foreach ( self::provideRejectedLinkTargets() as $targetLabel => $target ) {
				$cases["$actionLabel given $targetLabel"] = [
					$action[0],
					$action[1],
					$action[2],
					$target[0],
				];
			}
		}

		return $cases;
	}

	/**
	 * A rejected call-to-action target is replaced by a core special page, never emitted.
	 *
	 * This is where the two link-bearing surfaces part company, and the difference is asserted
	 * rather than assumed. A rejected announcement target drops its href, because an
	 * announcement without a link is still a useful announcement. A rejected call-to-action
	 * target instead falls back to a canonical core special page, because the navigation card
	 * always offers somewhere to go. Either way the rejected value never reaches an href, which
	 * is the property rule R13 actually asks for; asserting the exact fallback URL proves it
	 * without needing a separate negative assertion, and the site-relative check records that
	 * the substituted target cannot leave this origin.
	 *
	 * @dataProvider provideRejectedCallToActionTargets
	 */
	public function testRejectedCallToActionTargetFallsBackToACoreSpecialPage(
		string $option,
		string $containerKey,
		string $fallbackPage,
		string $target
	): void {
		$data = $this->newViewModel( self::completeSettings( [ $option => $target ] ) )
			->build( false );

		$action = $data[$containerKey];

		$this->assertArrayHasKey( 'href', $action );
		$this->assertSame( self::SEAM_URL_PREFIX . $fallbackPage, $action['href'] );
		$this->assertStringStartsWith( '/', $action['href'] );
	}

	/**
	 * Targets the allowlist accepts, with the value that must reach the href.
	 *
	 * The padded and interrupted cases document that normalisation happens before validation, so
	 * the value the allowlist inspected is the value that gets emitted. The upper-case scheme
	 * case records that an accepted target is returned as normalised input rather than rewritten:
	 * the parser lowercases the scheme it reports internally, but the skin does not rewrite an
	 * administrator's URL.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provideAcceptedLinkTargets(): array {
		return [
			'a site-relative path' => [
				'/wiki/Blitzy:Release_notes',
				'/wiki/Blitzy:Release_notes',
			],
			'the site root' => [ '/', '/' ],
			'a script path with a query string' => [
				'/w/index.php?title=Blitzy&action=history',
				'/w/index.php?title=Blitzy&action=history',
			],
			'an absolute http target' => [
				'http://example.org/notes',
				'http://example.org/notes',
			],
			'an absolute https target' => [
				'https://example.org/notes',
				'https://example.org/notes',
			],
			'an upper-case https scheme' => [
				'HTTPS://example.org/notes',
				'HTTPS://example.org/notes',
			],
			'a padded target' => [
				'   https://example.org/notes   ',
				'https://example.org/notes',
			],
			'a target interrupted by a tab' => [
				"https://example.org/no\ttes",
				'https://example.org/notes',
			],
		];
	}

	/**
	 * An accepted target reaches all three hrefs as its normalised self.
	 *
	 * Driving all three options from one value keeps the three read sites honest about agreeing
	 * on the same allowlist, which is the point of routing them through a single validator.
	 *
	 * @dataProvider provideAcceptedLinkTargets
	 */
	public function testAcceptedLinkTargetsReachEveryHrefNormalised(
		string $target,
		string $expected
	): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => $target,
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => $target,
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => $target,
		] ) )->build( false );

		$this->assertSame( $expected, $data[BlitzyViewModel::KEY_ANNOUNCEMENT]['href'] );
		$this->assertSame( $expected, $data[BlitzyViewModel::KEY_ACTION_PRIMARY]['href'] );
		$this->assertSame( $expected, $data[BlitzyViewModel::KEY_ACTION_SECONDARY]['href'] );
	}

	/**
	 * The fallback page name is a pure function of registration state.
	 *
	 * Exposed as a public method precisely so both branches are reachable without a wiki, and
	 * asserted here against the canonical names rather than against URLs, because turning a
	 * special-page name into a URL is what needs the service container.
	 */
	public function testPrimaryActionFallbackPageDependsOnRegistrationState(): void {
		$viewModel = $this->newViewModel( self::completeSettings() );

		$this->assertSame( 'CreateAccount', $viewModel->getPrimaryActionFallbackPage( false ) );
		$this->assertSame( 'Watchlist', $viewModel->getPrimaryActionFallbackPage( true ) );
	}

	/**
	 * The primary fallback href is the only value in the whole array that varies with identity.
	 *
	 * The MODES requirement is that anonymous and logged-in chrome differ only in the contents of
	 * the personal menu, and captures 1 and 20 of the capture matrix photograph the same surface
	 * in both states. This asserts the invariant exactly rather than approximately: the two
	 * arrays are compared whole with that one href removed from each, so any second
	 * identity-dependent value appearing anywhere would fail here. Both labels are asserted
	 * identical as well, since a call to action whose wording changed with login state would be a
	 * visible difference in chrome even though its href is allowed to differ.
	 */
	public function testOnlyThePrimaryFallbackHrefVariesWithIdentity(): void {
		$settings = self::completeSettings( [
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => '',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => '',
		] );
		$parentData = self::parentDataWithSections( 2 );

		$anonymous = $this->newViewModel( $settings )->build( false, $parentData );
		$registered = $this->newViewModel( $settings )->build( true, $parentData );

		$this->assertSame(
			self::SEAM_URL_PREFIX . 'CreateAccount',
			$anonymous[BlitzyViewModel::KEY_ACTION_PRIMARY]['href']
		);
		$this->assertSame(
			self::SEAM_URL_PREFIX . 'Watchlist',
			$registered[BlitzyViewModel::KEY_ACTION_PRIMARY]['href']
		);
		$this->assertSame(
			$anonymous[BlitzyViewModel::KEY_ACTION_PRIMARY]['label'],
			$registered[BlitzyViewModel::KEY_ACTION_PRIMARY]['label']
		);

		unset(
			$anonymous[BlitzyViewModel::KEY_ACTION_PRIMARY]['href'],
			$registered[BlitzyViewModel::KEY_ACTION_PRIMARY]['href']
		);
		$this->assertSame( $anonymous, $registered );
	}

	/**
	 * The secondary call to action never branches on identity, even on its fallback.
	 *
	 * Special:Random is useful to everyone, so there is no second branch to keep in step.
	 */
	public function testSecondaryActionFallbackIsIdentityIndependent(): void {
		$settings = self::completeSettings( [
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => '',
		] );

		$anonymous = $this->newViewModel( $settings )->build( false );
		$registered = $this->newViewModel( $settings )->build( true );

		$this->assertSame(
			self::SEAM_URL_PREFIX . 'Randompage',
			$anonymous[BlitzyViewModel::KEY_ACTION_SECONDARY]['href']
		);
		$this->assertSame(
			$anonymous[BlitzyViewModel::KEY_ACTION_SECONDARY],
			$registered[BlitzyViewModel::KEY_ACTION_SECONDARY]
		);
	}

	/**
	 * A call to action may be unlabelled while keeping its target.
	 *
	 * The label and the target are independent, so an administrator who configures one without
	 * the other gets the half they configured rather than nothing.
	 */
	public function testUnlabelledCallToActionKeepsItsConfiguredTarget(): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => '',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => '',
		] ) )->build( false );

		$this->assertSame(
			[ 'label' => '', 'href' => '/wiki/Blitzy:Start_building' ],
			$data[BlitzyViewModel::KEY_ACTION_PRIMARY]
		);
		$this->assertSame(
			[ 'label' => '', 'href' => 'https://example.org/experts' ],
			$data[BlitzyViewModel::KEY_ACTION_SECONDARY]
		);
	}

	/**
	 * An href that resolves to nothing at all is omitted rather than emitted empty.
	 *
	 * The guard this exercises is unreachable through the ordinary seam, because a resolved
	 * special page always has a URL, so the seam is replaced here by one that answers the empty
	 * string. That is not a contrived scenario for its own sake: it is the branch that keeps an
	 * anchor whose href resolves to the current page off the navigation card, and asserting it is
	 * how the guard stays a guard instead of becoming dead code nobody notices.
	 */
	public function testCallToActionHrefIsOmittedWhenNothingCanBeResolved(): void {
		$viewModel = new class(
			new HashConfig( self::completeSettings( [
				BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => '',
				BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => '',
			] ) ),
			new BlitzyUrlValidator( new UrlUtils() )
		) extends BlitzyViewModel {
			/**
			 * @inheritDoc
			 */
			protected function localUrlForSpecialPage( string $name ): string {
				return '';
			}
		};

		$data = $viewModel->build( false );

		$this->assertSame(
			[ 'label' => 'Start building' ],
			$data[BlitzyViewModel::KEY_ACTION_PRIMARY]
		);
		$this->assertSame(
			[ 'label' => 'Talk to an expert' ],
			$data[BlitzyViewModel::KEY_ACTION_SECONDARY]
		);
	}

	/**
	 * Parent template data in every shape core can produce, plus the shapes it cannot.
	 *
	 * The first four cases are core's real behaviour: SkinComponentTableOfContents publishes an
	 * empty array for a page with no table of contents, for `__NOTOC__` and for a parse with no
	 * sections, and the key is absent altogether if the component never ran. That is why the flag
	 * is derived from the section count rather than from the presence of `data-toc`, which exists
	 * in all of the empty cases.
	 *
	 * The remaining cases are defensive. Core returns count(), an integer, so a string or a null
	 * would mean something upstream changed; answering false is the safe reading, and a bare cast
	 * would instead have turned 'many' into 0 quietly and '3' into a working answer by accident.
	 *
	 * @return array<string,array{0:array,1:bool}>
	 */
	public static function provideTableOfContentsParentData(): array {
		return [
			'no parent data at all' => [ [], false ],
			'parent data without a table of contents entry' => [
				[ 'html-body-content' => '<p>Ada Lovelace</p>' ],
				false,
			],
			'an empty table of contents entry' => [ [ 'data-toc' => [] ], false ],
			'a table of contents reporting no sections' => [
				[ 'data-toc' => [ 'number-section-count' => 0 ] ],
				false,
			],
			'a table of contents reporting one section' => [
				[ 'data-toc' => [ 'number-section-count' => 1 ] ],
				true,
			],
			'a table of contents reporting many sections' => [
				[ 'data-toc' => [ 'number-section-count' => 12 ] ],
				true,
			],
			'a numeric string section count' => [
				[ 'data-toc' => [ 'number-section-count' => '3' ] ],
				true,
			],
			'a non-numeric section count' => [
				[ 'data-toc' => [ 'number-section-count' => 'many' ] ],
				false,
			],
			'a null section count' => [
				[ 'data-toc' => [ 'number-section-count' => null ] ],
				false,
			],
			'a negative section count' => [
				[ 'data-toc' => [ 'number-section-count' => -1 ] ],
				false,
			],
		];
	}

	/**
	 * The table-of-contents flag is derived from core's data and is a genuine boolean.
	 *
	 * The type assertion is part of the naming contract rather than pedantry: the `is-` prefix
	 * promises a boolean, and a truthy integer arriving here would still make the Mustache
	 * section render while breaking the promise the prefix makes to every reader.
	 *
	 * Both the flag inside ::build()'s result and the public predicate are asserted, because
	 * TableOfContents.mustache consumes the former while the latter is what makes the derivation
	 * testable in isolation; they must not be allowed to disagree.
	 *
	 * @dataProvider provideTableOfContentsParentData
	 */
	public function testTableOfContentsFlagIsDerivedFromCoreParentData(
		array $parentData,
		bool $expected
	): void {
		$viewModel = $this->newViewModel( self::completeSettings() );

		$data = $viewModel->build( false, $parentData );

		$this->assertArrayHasKey( BlitzyViewModel::KEY_TOC_AVAILABLE, $data );
		$this->assertIsBool( $data[BlitzyViewModel::KEY_TOC_AVAILABLE] );
		$this->assertSame( $expected, $data[BlitzyViewModel::KEY_TOC_AVAILABLE] );
		$this->assertSame( $expected, $viewModel->isTableOfContentsAvailable( $parentData ) );
	}

	/**
	 * Omitting the parent data answers false, which is correct for a page with no sections.
	 *
	 * This exercises the default argument rather than passing an empty array explicitly, so the
	 * one-argument call shape stays supported for any caller that has no parent data to offer.
	 */
	public function testBuildWithoutParentDataReportsNoTableOfContents(): void {
		$data = $this->newViewModel( self::completeSettings() )->build( true );

		$this->assertFalse( $data[BlitzyViewModel::KEY_TOC_AVAILABLE] );
	}

	/**
	 * Every published key is Blitzy-namespaced, correctly typed, and never raw HTML.
	 *
	 * This is the invariant that makes the merge in SkinBlitzy::getTemplateData() safe. That
	 * method composes `parent::getTemplateData() + $viewModel->build( ... )`, and PHP's `+`
	 * operator keeps the left operand on a collision, so a Blitzy key that happened to match a
	 * core key would be discarded in silence: no error, no warning, and nothing visible except a
	 * component that stopped rendering. Asserting the namespace on every key is what turns that
	 * failure into a test failure instead.
	 *
	 * The exact count is asserted too, so the published surface cannot grow without a reviewer
	 * seeing this test change, and no key may begin with `html-`, the prefix the naming contract
	 * reserves for values a template is entitled to interpolate unescaped. Since every
	 * configuration-sourced string reaches the array through these keys, that last assertion is
	 * what keeps rule R13's trust boundary where it belongs.
	 */
	public function testPublishedKeysHonourTheTemplateDataNamingContract(): void {
		$data = $this->newViewModel( self::completeSettings() )
			->build( true, self::parentDataWithSections( 4 ) );

		$this->assertCount( 4, $data );

		foreach ( array_keys( $data ) as $key ) {
			$this->assertMatchesRegularExpression(
				'/^(?:data|is)-blitzy-[a-z][a-z-]*$/',
				$key,
				"Template data key '$key' is not Blitzy-namespaced"
			);
			$this->assertStringStartsNotWith(
				'html-',
				$key,
				"Template data key '$key' claims to carry raw HTML"
			);
		}

		$this->assertIsArray( $data[BlitzyViewModel::KEY_ANNOUNCEMENT] );
		$this->assertIsArray( $data[BlitzyViewModel::KEY_ACTION_PRIMARY] );
		$this->assertIsArray( $data[BlitzyViewModel::KEY_ACTION_SECONDARY] );
		$this->assertIsBool( $data[BlitzyViewModel::KEY_TOC_AVAILABLE] );
	}

	/**
	 * Raw configuration values and the normalised strings they must become.
	 *
	 * Control characters are removed rather than replaced, which is what the interrupted cases
	 * record: replacing them with a space would leave a token that still reads as a scheme to a
	 * browser, and that is precisely how a payload survives a downstream check.
	 *
	 * Numbers normalise to their decimal form because a wiki may legitimately configure one.
	 * Anything that is not a string or a number cannot be meaningful text for these options, so it
	 * normalises to nothing; that includes booleans, which PHP would otherwise cast to '1' and ''
	 * and which would put a stray digit on the page.
	 *
	 * The multibyte case guards the implementation detail that makes the rest safe: the control
	 * character pattern is applied bytewise, with no unicode modifier, so that malformed input is
	 * stripped instead of making the match fail outright. Every byte of a UTF-8 sequence outside
	 * ASCII is at or above 0x80, so nothing in this case is in range to be stripped.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function provideNormalisableConfigurationValues(): array {
		return [
			'an ordinary string is untouched' => [ 'Read Now', 'Read Now' ],
			'surrounding whitespace is trimmed' => [ '   Read Now   ', 'Read Now' ],
			'a whitespace-only value becomes empty' => [ '   ', '' ],
			'an empty string stays empty' => [ '', '' ],
			'an embedded NUL byte is removed' => [ "Read\x00Now", 'ReadNow' ],
			'an embedded tab is removed' => [ "Read\tNow", 'ReadNow' ],
			'an embedded newline is removed' => [ "Read\nNow", 'ReadNow' ],
			'an embedded carriage return is removed' => [ "Read\rNow", 'ReadNow' ],
			'an embedded DEL byte is removed' => [ "Read\x7FNow", 'ReadNow' ],
			'leading and trailing control bytes are removed' => [
				"\x01\x02Read Now\x1F",
				'Read Now',
			],
			'an integer becomes its decimal form' => [ 2026, '2026' ],
			'a float becomes its decimal form' => [ 1.5, '1.5' ],
			'null becomes empty' => [ null, '' ],
			'an array becomes empty' => [ [ 'Read Now' ], '' ],
			'an object becomes empty' => [ (object)[ 'label' => 'Read Now' ], '' ],
			'boolean true becomes empty' => [ true, '' ],
			'boolean false becomes empty' => [ false, '' ],
			'multibyte text survives intact' => [ 'Blitzy — Wiki', 'Blitzy — Wiki' ],
		];
	}

	/**
	 * Configuration strings are normalised on the way out, and never escaped.
	 *
	 * The primary call-to-action label is the surface under test because it is published
	 * unconditionally, so a value that normalises to nothing is still observable as an empty
	 * label rather than disappearing along with its container the way announcement text does.
	 *
	 * @dataProvider provideNormalisableConfigurationValues
	 */
	public function testConfigurationValuesAreNormalisedIntoPlainStrings(
		mixed $value,
		string $expected
	): void {
		$data = $this->newViewModel( self::completeSettings( [
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => $value,
		] ) )->build( false );

		$label = $data[BlitzyViewModel::KEY_ACTION_PRIMARY]['label'];

		$this->assertIsString( $label );
		$this->assertSame( $expected, $label );
	}

	/**
	 * The production class needs no test seam once every link target is configured.
	 *
	 * Every other test here drives a subclass, so this one drives BlitzyViewModel itself. It is
	 * what proves the class is constructible from the two collaborators SkinBlitzy actually hands
	 * it, and that a wiki which configures its own targets never resolves a special page at all:
	 * the null-coalescing operator short-circuits, so the one line that would need the service
	 * container is never reached. Were that not true, this test would fail here rather than
	 * failing later inside a real page render, which is the harder place to diagnose it.
	 */
	public function testProductionClassBuildsWithoutTheTestSeam(): void {
		$viewModel = new BlitzyViewModel(
			new HashConfig( self::completeSettings() ),
			new BlitzyUrlValidator( new UrlUtils() )
		);

		$data = $viewModel->build( true, self::parentDataWithSections( 2 ) );

		$this->assertSame(
			[
				'text' => 'State of Wiki Engineering',
				'label' => 'Read Now',
				'href' => '/wiki/Blitzy:Release_notes',
			],
			$data[BlitzyViewModel::KEY_ANNOUNCEMENT]
		);
		$this->assertSame(
			[ 'label' => 'Start building', 'href' => '/wiki/Blitzy:Start_building' ],
			$data[BlitzyViewModel::KEY_ACTION_PRIMARY]
		);
		$this->assertSame(
			[ 'label' => 'Talk to an expert', 'href' => 'https://example.org/experts' ],
			$data[BlitzyViewModel::KEY_ACTION_SECONDARY]
		);
		$this->assertTrue( $data[BlitzyViewModel::KEY_TOC_AVAILABLE] );
	}
}
