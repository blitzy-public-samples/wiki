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

use MediaWiki\Config\HashConfig;
use MediaWiki\Skins\Blitzy\BlitzyUrlValidator;
use MediaWiki\Skins\Blitzy\BlitzyViewModel;
use MediaWiki\Skins\Blitzy\SkinBlitzy;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use SkinException;
use SkinMustache;

/**
 * Unit tests for the Blitzy skin class.
 *
 * What this class can and cannot assert is decided by one fact about the class under test,
 * so it is worth stating before the tests rather than after: ::getTemplateData() is not
 * callable here, and nothing in this file calls it.
 *
 * SkinMustache::getTemplateData() ends by resolving the main page through the static
 * Title::newMainPage(). That helper is given no MessageLocalizer by SkinMustache, so it
 * falls through to the global wfMessage() function, which needs the real service container.
 * MediaWikiUnitTestCase has already called
 * MediaWikiServices::disallowGlobalInstanceInUnitTests(), disabled ExtensionRegistry,
 * revoked SettingsBuilder access and stripped $GLOBALS down to an eight-entry allowlist. A
 * static call reaching a global function cannot be intercepted, so no arrangement of mocks
 * makes the merged array observable in a unit test. Working around it would be worse than
 * leaving it alone: PHPUnit cannot stub a parent:: call, a partial mock that leaves the
 * method real still runs the real parent body, and an anonymous subclass that skips the
 * parent would assert the behaviour of the stub rather than of the skin.
 *
 * The merge itself is therefore asserted where it can be asserted honestly, against a
 * rendered page, by
 * MediaWiki\Skins\Blitzy\Tests\Integration\SkinBlitzyIntegrationTest. That is a scoping
 * decision and not a gap: this file never skips a test to sidestep the sandbox, because a
 * skipped assertion reads as a pass.
 *
 * What is left is more than a reflection exercise, because the constructor is genuinely
 * service-free. Skin::__construct() reads $options['name'], stores the options, evaluates
 * the final and purely arithmetic Skin::getOptions(), and builds a SkinComponentRegistry
 * whose components stay null until something asks for one. Nothing on that path touches a
 * service, a global or the database, so every test below instantiates the real SkinBlitzy
 * with real collaborators and no test double at all. That is also where this file's
 * contribution to rule R14's coverage floor comes from; the body of ::getTemplateData() is
 * covered by the integration suite, and the two clover reports merge under coverage/php/.
 *
 * Four contracts are pinned here, each because breaking it fails silently somewhere else:
 *
 *  - The visibility and ownership of ::getTemplateData(). Core declares it public, so a
 *    protected override is a fatal error when the class is loaded, not a test failure.
 *  - The manifest-to-constructor plumbing, including that a declared argument beats the
 *    core default rather than the other way round.
 *  - Key-disjointness between the skin's two merge operands, which the left-biased +
 *    operator would otherwise resolve by discarding Blitzy data without any error.
 *  - The thinness of the class, which rule R12 requires and which nothing else measures.
 *
 * @group Blitzy
 * @covers \MediaWiki\Skins\Blitzy\SkinBlitzy
 */
class SkinBlitzyTest extends MediaWikiUnitTestCase {

	/**
	 * Every template-data key SkinMustache::getTemplateData() sets by name, spelled exactly
	 * as core spells it.
	 *
	 * This is the left-hand operand of the union performed by SkinBlitzy::getTemplateData(),
	 * reproduced here because it is the set the skin's own keys have to stay clear of. Core
	 * also derives one key per entry of the manifest's `messages` argument, which is why the
	 * prefix below is tested separately: those keys are built at run time and cannot be
	 * listed.
	 */
	private const CORE_TEMPLATE_DATA_KEYS = [
		'array-indicators',
		'html-site-notice',
		'html-user-message',
		'html-subtitle',
		'html-body-content',
		'html-categories',
		'html-after-content',
		'html-undelete-link',
		'html-user-language-attributes',
		'link-mainpage',
	];

	/**
	 * Prefix core gives the message keys it folds into the template data.
	 */
	private const CORE_MESSAGE_KEY_PREFIX = 'msg-';

	/**
	 * Site configuration carrying all eight of the skin's options.
	 *
	 * All eight are present because HashConfig::get() throws on an option it does not hold,
	 * and BlitzyViewModel reads every one of them. The option names come from the view
	 * model's own public constants rather than from string literals, so this fixture cannot
	 * drift from the manifest without a fatal error pointing straight at the drift.
	 *
	 * Two of the values are load bearing rather than illustrative. The announcement is
	 * enabled and given text so that the view model emits its announcement key at all, which
	 * is what stops the disjointness test below from passing against a smaller array than the
	 * skin really merges. And both call-to-action targets are configured, as site-relative
	 * paths that the validator accepts: an unset target makes the view model resolve a
	 * fallback special page through Title::getLocalURL(), which needs the service container
	 * this sandbox withholds. Configuring them keeps the whole of ::build() reachable without
	 * a wiki. The announcement values are the ones the verification instance applies.
	 *
	 * @return HashConfig
	 */
	private static function newConfig(): HashConfig {
		return new HashConfig( [
			BlitzyViewModel::OPTION_ANNOUNCE_ENABLE => true,
			BlitzyViewModel::OPTION_ANNOUNCE_TEXT => 'State of Wiki Engineering — Read the Notes',
			BlitzyViewModel::OPTION_ANNOUNCE_LABEL => 'Read Now',
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => '/wiki/Special:RecentChanges',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => 'Start building',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => '/wiki/Special:CreateAccount',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => 'Talk to an expert',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => '/wiki/Special:Random',
		] );
	}

	/**
	 * The core URL parser, configured with an inert host.
	 *
	 * A real instance rather than a double, because UrlUtils reads no global, resolves no
	 * service and opens no connection: the host below is a string it parses and never
	 * contacts, which is what keeps this suite compliant with rule R5.
	 *
	 * @return UrlUtils
	 */
	private static function newUrlUtils(): UrlUtils {
		return new UrlUtils( [ UrlUtils::SERVER => 'https://wiki.example' ] );
	}

	/**
	 * The skin options exactly as skin.json's ValidSkinNames.blitzy `args` entry declares
	 * them.
	 *
	 * ObjectFactory hands this array to the constructor as its last argument on every real
	 * page view, so passing the manifest's own values is what makes these tests exercise the
	 * configuration the wiki actually runs rather than a convenient subset.
	 *
	 * @return array
	 */
	private static function newManifestOptions(): array {
		return [
			'name' => 'blitzy',
			'templateDirectory' => 'includes/templates',
			'template' => 'skin',
			'responsive' => true,
			'clientPrefEnabled' => true,
			'toc' => false,
			'bodyClasses' => [ 'skin-blitzy' ],
			'styles' => [ 'skins.blitzy.tokens', 'skins.blitzy.styles' ],
			'scripts' => [ 'skins.blitzy.js' ],
		];
	}

	/**
	 * The real skin, built the way ObjectFactory builds it.
	 *
	 * The argument order is the manifest's: every service named by `services`, in the order
	 * it names them, and then the `args` array last. A signature that disagreed with the
	 * manifest would pass the options array where a service is expected, so constructing the
	 * skin this way is itself a check on that agreement.
	 *
	 * @return SkinBlitzy
	 */
	private static function newSkin(): SkinBlitzy {
		return new SkinBlitzy(
			self::newConfig(),
			self::newUrlUtils(),
			self::newManifestOptions()
		);
	}

	/**
	 * The view model the skin constructs internally, assembled here from the same
	 * collaborators.
	 *
	 * The skin keeps its instance private, so the right-hand operand of the merge is rebuilt
	 * rather than extracted. Reaching into the private property would assert the same values
	 * while coupling this test to a field name that is deliberately not part of any contract.
	 *
	 * @return BlitzyViewModel
	 */
	private static function newViewModel(): BlitzyViewModel {
		return new BlitzyViewModel(
			self::newConfig(),
			new BlitzyUrlValidator( self::newUrlUtils() )
		);
	}

	/**
	 * The skin answers to the name the manifest registers it under.
	 *
	 * SkinFactory looks a skin up by this string and $wgDefaultSkin is set to it, so the
	 * two-line installation the skin promises depends on the `name` argument arriving intact.
	 */
	public function testGetSkinName(): void {
		$this->assertSame(
			'blitzy',
			self::newSkin()->getSkinName(),
			'The skin must report the name ValidSkinNames registers it under.'
		);
	}

	/**
	 * The skin is a Mustache skin.
	 *
	 * Everything the manifest declares about templates follows from this: the parser rooted
	 * at `templateDirectory`, recursive partials, and the root template named by `template`
	 * are all inherited and none of them is reimplemented. The base class is named without a
	 * namespace on purpose. MediaWiki 1.44 moved it to MediaWiki\Skin\SkinMustache and left
	 * the original name behind as an alias, so on 1.43, the earliest release the manifest
	 * supports, the unqualified name is the real class and the namespaced one does not exist
	 * yet. It is the one spelling valid across the whole supported range, and it is the
	 * spelling the skin class itself extends.
	 */
	public function testExtendsSkinMustache(): void {
		$this->assertInstanceOf(
			SkinMustache::class,
			self::newSkin(),
			'The skin must extend SkinMustache for the manifest template pipeline to apply.'
		);
	}

	/**
	 * ::getTemplateData() is public, and the skin declares it rather than merely inheriting
	 * it.
	 *
	 * The most valuable assertion in this file, and the cheapest. Core declares the method
	 * public, so PHP rejects a narrower override while it is loading the class: the result is
	 * a fatal error reading "Access level to ... must be public", which takes down every page
	 * view and every other test at once rather than failing one assertion. An illustrative
	 * snippet in the specification writes the override as protected, so this is a mistake with
	 * a documented route back into the codebase, and one reflection lookup closes that route
	 * permanently.
	 *
	 * Checking the declaring class is the other half. Without it the test would still pass if
	 * the override were deleted, because the inherited method is public too, and the skin
	 * would then silently stop contributing its own data to every template.
	 *
	 * Reflection reads the signature without running the method, so this stays clear of the
	 * sandbox limitation described in the class comment.
	 */
	public function testGetTemplateDataIsPublicAndDeclaredHere(): void {
		$method = new ReflectionMethod( SkinBlitzy::class, 'getTemplateData' );

		$this->assertTrue(
			$method->isPublic(),
			'::getTemplateData() must stay public; core declares it public and a narrower '
				. 'override is a fatal error when the class is loaded.'
		);
		$this->assertSame(
			SkinBlitzy::class,
			$method->getDeclaringClass()->getName(),
			'::getTemplateData() must be declared by the skin itself, not inherited, or the '
				. 'skin contributes no data of its own.'
		);
	}

	/**
	 * Every argument the manifest declares reaches Skin::getOptions().
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function provideManifestOptions(): array {
		return [
			'template directory, which roots the Mustache parser' => [
				'templateDirectory',
				'includes/templates',
			],
			'root template name' => [ 'template', 'skin' ],
			'registered skin name' => [ 'name', 'blitzy' ],
			'responsive viewport handling' => [ 'responsive', true ],
			'client preference support' => [ 'clientPrefEnabled', true ],
			'core table of contents, suppressed in favour of the skin partial' => [
				'toc',
				false,
			],
			'body classes' => [ 'bodyClasses', [ 'skin-blitzy' ] ],
			'style modules' => [
				'styles',
				[ 'skins.blitzy.tokens', 'skins.blitzy.styles' ],
			],
			'script modules' => [ 'scripts', [ 'skins.blitzy.js' ] ],
		];
	}

	/**
	 * The manifest's arguments survive the constructor and are visible through
	 * Skin::getOptions().
	 *
	 * This is the plumbing every other declared behaviour rides on: SkinMustache builds its
	 * parser from `templateDirectory` and picks its root template from `template`, and
	 * ResourceLoader takes the module lists from `styles` and `scripts`. Skin::getOptions() is
	 * final and merges the declared options over a fixed set of defaults without consulting
	 * anything, which is what makes reading it safe here.
	 *
	 * The `toc` case earns its place by pointing the other way from the rest. Core defaults it
	 * to true, the manifest declares it false, and the skin renders its own table of contents
	 * partial instead. Asserting false therefore proves the merge resolves in favour of the
	 * manifest; every other case in the provider would still pass if the precedence were
	 * inverted.
	 *
	 * @dataProvider provideManifestOptions
	 * @param string $option Option name as declared in skin.json.
	 * @param mixed $expected Value the manifest declares for it.
	 */
	public function testGetOptionsSurfacesManifestArguments( string $option, $expected ): void {
		$options = self::newSkin()->getOptions();

		$this->assertArrayHasKey(
			$option,
			$options,
			"The `$option` argument declared in skin.json must reach Skin::getOptions()."
		);
		$this->assertSame(
			$expected,
			$options[$option],
			"The `$option` argument must arrive with the value skin.json declares."
		);
	}

	/**
	 * Options that carry no name are rejected.
	 *
	 * Skin::__construct() treats the name as mandatory whenever it is given a non-empty
	 * options array, because the name is what SkinFactory resolves and what the rest of the
	 * options are attributed to. Deriving this case by removing one key from the real manifest
	 * options, instead of hand-rolling a minimal array, is what makes the failure attributable
	 * to that key alone.
	 */
	public function testConstructorRejectsOptionsWithoutName(): void {
		$options = self::newManifestOptions();
		unset( $options['name'] );

		$this->expectException( SkinException::class );
		$this->expectExceptionMessage( 'Skin name must be specified' );

		new SkinBlitzy( self::newConfig(), self::newUrlUtils(), $options );
	}

	/**
	 * The two states the skin passes to the view model.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function provideUserRegistrationStates(): array {
		return [
			'anonymous visitor' => [ false ],
			'registered user' => [ true ],
		];
	}

	/**
	 * The skin's own template-data keys cannot collide with core's.
	 *
	 * ::getTemplateData() returns `parent::getTemplateData() + $viewModel->build( ... )`, and
	 * PHP's + operator keeps its LEFT operand on a collision. That direction is deliberate,
	 * because it guarantees core's keys reach the templates exactly as core built them. The
	 * cost of it is that a Blitzy key which happened to share a name with a core key would be
	 * dropped during the merge in complete silence: no error, no warning, no failing render,
	 * just a partial missing its data on every page. There is nowhere downstream that such a
	 * loss becomes visible, which is why it is caught here, at the only point where both
	 * operands can be inspected without running the merge.
	 *
	 * This is not the naming-discipline check the view model's own test performs. That one
	 * asserts the view model keeps to its prefix; this one asserts the skin's merge is
	 * collision-free against the set core actually occupies, which is the skin's contract
	 * rather than the view model's.
	 *
	 * The exact key set is asserted before the disjointness is, and that order matters: an
	 * empty or truncated array is trivially disjoint from everything, so without the first
	 * assertion this test could report success precisely when the view model had stopped
	 * producing anything. Both registration states are exercised because the skin passes the
	 * viewing user's state into ::build(), and the merge has to be safe for both.
	 *
	 * @dataProvider provideUserRegistrationStates
	 * @param bool $isRegisteredUser Whether the viewing user is registered.
	 */
	public function testViewModelKeysDoNotCollideWithCoreTemplateData(
		bool $isRegisteredUser
	): void {
		$blitzyKeys = array_keys( self::newViewModel()->build( $isRegisteredUser ) );

		$this->assertEqualsCanonicalizing(
			[
				BlitzyViewModel::KEY_ANNOUNCEMENT,
				BlitzyViewModel::KEY_ACTION_PRIMARY,
				BlitzyViewModel::KEY_ACTION_SECONDARY,
				BlitzyViewModel::KEY_TOC_AVAILABLE,
			],
			$blitzyKeys,
			'The view model must contribute its four documented keys, so that the '
				. 'disjointness assertion below cannot succeed against an empty array.'
		);

		$this->assertSame(
			[],
			array_intersect( $blitzyKeys, self::CORE_TEMPLATE_DATA_KEYS ),
			'No key the view model contributes may collide with a core key: the union in '
				. '::getTemplateData() keeps its left operand and would discard it silently.'
		);

		foreach ( $blitzyKeys as $key ) {
			$this->assertStringStartsNotWith(
				self::CORE_MESSAGE_KEY_PREFIX,
				$key,
				'No key the view model contributes may use the prefix core reserves for the '
					. 'messages it folds in from the manifest.'
			);
		}
	}

	/**
	 * The skin adds one method to what it inherits, and nothing else.
	 *
	 * Rule R12 forbids widening the surface, and thinness here is also what keeps the class
	 * within reach of rule R14's coverage floor: presentation logic belongs in the view model,
	 * where it needs no wiki, and in the templates and stylesheets, where the rendered-output
	 * gates can see it. Enumerating the declared methods is the only measurement of that.
	 *
	 * The same assertion covers a second claim that would otherwise go untested: the skin
	 * registers no hooks. Both hook handlers live in Hooks\BlitzyHooks, so a handler method
	 * appearing on this class would be a misplacement, and it would show up here as an extra
	 * name. Inherited methods are filtered out by comparing each declaring class, so this
	 * measures what the skin adds rather than what a Skin has.
	 */
	public function testDeclaresOnlyTheTemplateDataOverride(): void {
		$declared = [];
		foreach ( ( new ReflectionClass( SkinBlitzy::class ) )->getMethods() as $method ) {
			if ( $method->getDeclaringClass()->getName() === SkinBlitzy::class ) {
				$declared[] = $method->getName();
			}
		}

		$this->assertEqualsCanonicalizing(
			[ '__construct', 'getTemplateData' ],
			$declared,
			'The skin must declare only its constructor and the ::getTemplateData() '
				. 'override; any other method belongs in the view model or in the hook handler.'
		);
	}
}
