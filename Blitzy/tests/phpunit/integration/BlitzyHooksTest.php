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

namespace MediaWiki\Skins\Blitzy\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skins\Blitzy\Hooks\BlitzyHooks;
use MediaWiki\Skins\Blitzy\SkinBlitzy;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * Integration tests for the Blitzy skin's two hook handlers.
 *
 * WHY THIS FILE EXISTS
 * ====================
 * Gate 13 pairs every registration with an invocation: a registered hook needs a test that
 * triggers it through a normal page render and asserts an observable effect on the document.
 * This class is that enforcement site for the hook half of the gate, and for that half only.
 * The client-side listeners and the announcement dismissal read site are the other half, and
 * they are asserted by tests/qunit/announcement.test.js, dropdown.test.js and
 * tableOfContents.test.js against a browser; nothing here duplicates them, because a PHP test
 * cannot dispatch a DOM event.
 *
 * It is also the named read-site evidence for Gate 12's configuration propagation audit. The
 * `blitzy-theme` user preference is written by `DefaultUserOptions` in skin.json and by the
 * verification instance's settings, and read in exactly one place reachable from a page
 * render: BlitzyHooks::onBeforePageDisplay(). The theme tests below are what let DELIVERY.md
 * claim that the read site is reachable rather than merely present. The eight `config` options
 * are a different surface with a different read site, BlitzyViewModel, and belong to
 * BlitzyConfigSecurityTest; the only claim this file makes about them is the negative one,
 * that these two handlers do not read any of them.
 *
 * Gate 1 is NOT satisfied by this file, or by any other PHP test. That gate requires a real
 * imported Wikipedia article rendered on a running instance and committed as a PNG, and it is
 * bound to captures 1 and 2 of the committed capture set. Passing tests here say nothing about
 * it.
 * Neither is this file one of Gate 8's four integration checks; its result is reported under
 * PHPUnit results in DELIVERY.md, separately from those.
 *
 * WHAT IT ASSERTS, AND WHERE ELSE IT COULD NOT BE ASSERTED
 * =======================================================
 * The theme class is the one effect in the whole skin that no template can produce. A
 * SkinMustache skin may only template the contents of the body tag, so the class on the root
 * element has to come from OutputPage::addHtmlClasses(), and that method's value is consumed
 * in exactly one place in all of MediaWiki: OutputPage::headElement(). A rendered page is
 * therefore the only observation point, which is why these tests render one rather than
 * inspecting template data. A template-level assertion here would be structurally incapable
 * of passing.
 *
 * Two details of that observation decide whether the assertions mean anything:
 *
 *   - addHtmlClasses() merges rather than replaces, so a handler that ran twice would put two
 *     theme classes on the root element and every "contains" assertion would still pass. The
 *     assertions below therefore count the theme classes and require exactly one.
 *   - MediaWiki puts `skin-thumbsize-clientpref-<size>` on the same element unconditionally,
 *     alongside the ResourceLoader document classes. A pattern anchored on `clientpref` would
 *     match that and pass without the skin doing anything at all, so the pattern used here is
 *     anchored on the full `skin-theme-clientpref-` prefix, and no assertion compares the
 *     whole class attribute for equality.
 *
 * HOW THE HANDLERS ARE INVOKED
 * ============================
 * Through the real HookContainer, always. The value of this file is that deleting an entry
 * from skin.json's `Hooks` block breaks it, so nothing here registers a Blitzy handler of its
 * own: MediaWikiIntegrationTestCase::clearHooks() would wipe the manifest registration under
 * test, and setTemporaryHook() would do the same for one hook because its `$replace`
 * parameter defaults to true. Neither is called, there is no setUp() to hide such a call in,
 * and the handler is reached either by rendering a page or by asking the container to run the
 * hook the same way HookRunner does.
 *
 * Neither hook interface is imported or named. The two interfaces sit in different namespaces
 * on the 1.43 runtime the harness pins and on the 1.47 tree this repository checks out for API
 * reference, and the compatibility aliases are deprecated, which under this suite's
 * `convertDeprecationsToExceptions` would turn a mere mention into a failure. The hook *names*
 * are stable across the whole supported range, so the names are what these tests use.
 * HookContainer::getHandlerCallbacks() is avoided for the same reason: it is deprecated and
 * emits a deprecation notice when called.
 *
 * DATABASE POSTURE
 * ================
 * This class declares `@group Database`, for the whole class and by design. Two things it has
 * to do require it: rendering a real page needs a real page, from
 * MediaWikiIntegrationTestCase::getExistingTestPage(), and the logged-in half of the theme
 * contract needs a registered user, from ::getTestUser(). Both of those throw a
 * LogicException rather than skipping when the group is absent, so the lighter database-free
 * posture would have meant asserting the theme through something other than a rendered page,
 * which is precisely what Gate 13 rules out. The anonymous half needs no database and is
 * driven through the declared default with ::overrideConfigValue() instead.
 *
 * COVERAGE ANNOTATIONS
 * ====================
 * Both forms are present, and both are needed. Every test method names the handler it targets,
 * which is what makes a failure legible and what documents which half of the hook footprint an
 * assertion belongs to. The class-level annotation is what attributes the rest of the handler
 * class: the constructor and the private helper the navigation handler delegates its class
 * merging to. Those statements run on every one of these tests, and the shape cases are the only
 * assertions in the package that reach them, so without the class-level annotation the coverage
 * report would describe a fully exercised file as half covered. PHPUnit merges the two forms
 * rather than choosing between them.
 *
 * @group Blitzy
 * @group Skins
 * @group Database
 * @covers \MediaWiki\Skins\Blitzy\Hooks\BlitzyHooks
 * @coversDefaultClass \MediaWiki\Skins\Blitzy\Hooks\BlitzyHooks
 */
class BlitzyHooksTest extends MediaWikiIntegrationTestCase {

	/**
	 * The two hook names skin.json declares, and the complete footprint the skin is allowed.
	 *
	 * Spelled as MediaWiki spells them rather than as the handler methods are named, because
	 * these are the strings the manifest registers and the container is asked about.
	 */
	private const HOOKS = [ 'BeforePageDisplay', 'SkinTemplateNavigation::Universal' ];

	/** Name of the user preference the BeforePageDisplay handler reads. */
	private const THEME_PREFERENCE = 'blitzy-theme';

	/** Prefix of the theme class the handler puts on the root element. */
	private const THEME_CLASS_PREFIX = 'skin-theme-clientpref-';

	/**
	 * Pattern matching one theme class and nothing else.
	 *
	 * Anchored on the full theme prefix so that `skin-thumbsize-clientpref-standard`, which
	 * MediaWiki adds to the same element on every page, cannot satisfy it.
	 */
	private const THEME_CLASS_PATTERN = '/\bskin-theme-clientpref-[a-z]+\b/';

	/**
	 * Every theme the skin implements: `day` forces light, `night` forces dark, and `os`
	 * follows the operating system. The forced pair is what makes the override explicit in
	 * both directions, so all three are asserted, each to the exclusion of the other two.
	 */
	private const THEMES = [ 'day', 'night', 'os' ];

	/** Class the navigation handler appends to every entry in a declared bucket. */
	private const MENU_ITEM_CLASS = 'blitzy-menu-item';

	/**
	 * The class MediaWiki itself puts on every rendered navigation entry.
	 *
	 * Used as an entry counter: one occurrence in a portlet's markup means one entry, whatever
	 * that entry is, which is how completeness can be asserted for a whole bucket without
	 * naming the entries MediaWiki happens to have produced.
	 */
	private const CORE_LIST_ITEM_CLASS = 'mw-list-item';

	/**
	 * Every entry of the navigation fixture, and the exact `class` value the handler must
	 * leave behind for it.
	 *
	 * Kept beside the fixture rather than computed from it, because a computed expectation
	 * would reimplement the behaviour under test and would agree with any bug it shared. Only
	 * the declared buckets appear here; the fixture's other two buckets are asserted to be
	 * untouched instead.
	 */
	private const EXPECTED_ENTRY_CLASSES = [
		'associated-pages' => [
			'nstab-main' => 'selected ' . self::MENU_ITEM_CLASS,
			'talk' => 'new ' . self::MENU_ITEM_CLASS,
		],
		'views' => [
			'view' => self::MENU_ITEM_CLASS,
			'edit' => self::MENU_ITEM_CLASS,
			'history' => self::MENU_ITEM_CLASS,
		],
		'actions' => [
			'move' => self::MENU_ITEM_CLASS,
			'watch' => 'mw-watchlink ' . self::MENU_ITEM_CLASS,
		],
		'variants' => [
			0 => [ 'ca-variants-en', self::MENU_ITEM_CLASS ],
		],
		'user-menu' => [
			'preferences' => self::MENU_ITEM_CLASS,
			'logout' => 'mw-logout ' . self::MENU_ITEM_CLASS,
		],
		'user-interface-preferences' => [
			'uls' => self::MENU_ITEM_CLASS,
		],
	];

	/**
	 * The navigation buckets skin.json declares, mapped to the template-data key each one is
	 * rendered under. Both halves are needed: the bucket name is what the handler looks for in
	 * the links array, and the portlet key is where the rendered items can be counted.
	 */
	private const BUCKET_PORTLETS = [
		'user-menu' => 'data-user-menu',
		'user-interface-preferences' => 'data-user-interface-preferences',
		'notifications' => 'data-notifications',
		'views' => 'data-views',
		'actions' => 'data-actions',
		'variants' => 'data-variants',
		'associated-pages' => 'data-associated-pages',
	];

	/**
	 * Element id prefixes MediaWiki gives sidebar and toolbox entries.
	 *
	 * Those entries come from the sidebar, not from the navigation buckets the handler
	 * declares, so they must come back from a render without the Blitzy class. Asserting that
	 * is how this file shows the handler stays inside its declared surface.
	 */
	private const SIDEBAR_ID_PREFIXES = [ 'n-', 't-' ];

	/**
	 * Rendered list item ids that must exist and must carry the Blitzy class.
	 *
	 * All five are core ids with a decade of stability behind them, and all five are derived
	 * from array keys in the declared buckets, so their presence is also evidence that the
	 * handler renamed no key: `ca-nstab-main` comes from associated-pages, `ca-view` and
	 * `ca-history` from views, and `pt-preferences` and `pt-logout` from the user menu of a
	 * logged-in user.
	 */
	private const CLASSED_ITEM_IDS = [
		'ca-nstab-main',
		'ca-view',
		'ca-history',
		'pt-preferences',
		'pt-logout',
	];

	/**
	 * All eight of the skin's configuration options, set to values a rendered page shows.
	 *
	 * Used to prove a negative: neither handler reads any of them. The announcement values
	 * change the rendered body, which is what makes the comparison meaningful rather than
	 * vacuous, and the two link targets are site-relative so that they survive the validator
	 * every configuration-sourced link target passes through.
	 */
	private const CONFIGURED_OPTIONS = [
		'BlitzyAnnounceEnable' => true,
		'BlitzyAnnounceText' => 'Blitzy hook test announcement',
		'BlitzyAnnounceLabel' => 'Read the notes',
		'BlitzyAnnounceLink' => '/wiki/Main_Page',
		'BlitzyPrimaryActionLabel' => 'Start building',
		'BlitzyPrimaryActionLink' => '/wiki/Special:Watchlist',
		'BlitzySecondaryActionLabel' => 'Talk to an expert',
		'BlitzySecondaryActionLink' => '/wiki/Special:Random',
	];

	/** Title of the page every render in this class is taken of. */
	private const TEST_PAGE = 'Blitzy hooks test page';

	/**
	 * The manifest registers the BeforePageDisplay handler, and registers it to this class.
	 *
	 * Half of Gate 13's pairing. On its own it proves nothing about behaviour, which the theme
	 * tests below supply; without it, those tests could keep passing on a wiki where some other
	 * component happened to apply a theme class, and deleting the manifest entry would go
	 * unnoticed. The handler description is asserted rather than the handler count, because a
	 * count says nothing about whose handler it is.
	 *
	 * @covers ::onBeforePageDisplay
	 */
	public function testBeforePageDisplayIsRegisteredToTheBlitzyHandler() {
		$hookContainer = $this->getServiceContainer()->getHookContainer();

		$this->assertTrue(
			$hookContainer->isRegistered( 'BeforePageDisplay' ),
			'skin.json must register a BeforePageDisplay handler. Without it the theme class '
				. 'never reaches the root element, and no template can put it there instead.'
		);
		$this->assertStringContainsString(
			BlitzyHooks::class . '::onBeforePageDisplay',
			implode( "\n", $hookContainer->getHandlerDescriptions( 'BeforePageDisplay' ) ),
			'The BeforePageDisplay handler registered for this wiki must be the Blitzy one.'
		);
	}

	/**
	 * The manifest registers the navigation handler, and registers it to this class.
	 *
	 * The hook is named with the double colon MediaWiki uses, while the handler method that
	 * serves it is named with two underscores. Both spellings are asserted here, in the one
	 * place where a mismatch between them would otherwise surface as a handler that is
	 * registered and never called.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testSkinTemplateNavigationUniversalIsRegisteredToTheBlitzyHandler() {
		$hookContainer = $this->getServiceContainer()->getHookContainer();

		$this->assertTrue(
			$hookContainer->isRegistered( 'SkinTemplateNavigation::Universal' ),
			'skin.json must register a SkinTemplateNavigation::Universal handler, or the '
				. 'navigation entries reach the page without the class the skin styles them by.'
		);
		$this->assertStringContainsString(
			BlitzyHooks::class . '::onSkinTemplateNavigation__Universal',
			implode(
				"\n",
				$hookContainer->getHandlerDescriptions( 'SkinTemplateNavigation::Universal' )
			),
			'The navigation handler registered for this wiki must be the Blitzy one, and its '
				. 'method must be spelled with the two underscores MediaWiki derives.'
		);
	}

	/**
	 * The skin's hook footprint is exactly the two hooks it declares, and nothing else.
	 *
	 * Rule R12's runtime evidence for the hook surface. The plan describes the footprint as
	 * deliberately minimal, and a claim of minimality that nothing measures is an invitation to
	 * grow: a third registration added later would be invisible to every other test in this
	 * package, because each of those only ever asks about a hook it already knows the name of.
	 *
	 * The search is by handler class, not by the string "Blitzy", so that a closure defined in
	 * a Blitzy test class cannot be mistaken for a shipped registration.
	 *
	 * @covers ::onBeforePageDisplay
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testHookFootprintIsExactlyTheTwoDeclaredHooks() {
		$hookContainer = $this->getServiceContainer()->getHookContainer();

		$hooksHandledByBlitzy = [];
		foreach ( $hookContainer->getHookNames() as $hookName ) {
			foreach ( $hookContainer->getHandlerDescriptions( $hookName ) as $description ) {
				if ( str_contains( $description, BlitzyHooks::class ) ) {
					$hooksHandledByBlitzy[] = $hookName;
				}
			}
		}

		$hooksHandledByBlitzy = array_values( array_unique( $hooksHandledByBlitzy ) );
		sort( $hooksHandledByBlitzy );
		$declared = self::HOOKS;
		sort( $declared );

		$this->assertSame(
			$declared,
			$hooksHandledByBlitzy,
			'The skin must handle exactly the two hooks skin.json declares. A third handler is '
				. 'user-visible behaviour that no entry of the in-scope list authorises.'
		);
	}

	/**
	 * A stored theme preference reaches the root element of a rendered page, once.
	 *
	 * The other half of Gate 13's pairing for BeforePageDisplay, and the evidence that Gate
	 * 12's `blitzy-theme` read site is reachable from a page render rather than merely
	 * present. The page is rendered through OutputPage::output(), the same call the web entry
	 * point makes, so the handler runs because the manifest registered it and not because this
	 * test arranged anything.
	 *
	 * The invalid values in the provider are not padding. A preference value is stored as an
	 * opaque string and is externally influenced, so `night day` would put two theme classes on
	 * the root element if the handler trusted it, and `Night` would put a class there that no
	 * stylesheet matches. Both must resolve to the operating-system theme instead.
	 *
	 * @dataProvider provideStoredThemePreferences
	 * @covers ::onBeforePageDisplay
	 */
	public function testStoredThemePreferenceReachesTheRootElement(
		string $storedValue,
		string $expectedTheme
	) {
		$context = $this->newBlitzyContext( true );
		$this->setThemePreference( $context->getUser(), $storedValue );

		$this->assertThemeApplied(
			$context,
			$expectedTheme,
			"a registered user whose stored preference is '$storedValue'"
		);
	}

	/**
	 * Stored preference values, and the theme each one must resolve to.
	 *
	 * @return array[] Case name to [ stored preference value, expected theme ]
	 */
	public static function provideStoredThemePreferences(): array {
		return [
			'day forces light even where the operating system asks for dark' => [ 'day', 'day' ],
			'night forces dark even where the operating system asks for light' => [
				'night',
				'night',
			],
			'os defers to the operating system' => [ 'os', 'os' ],
			'two themes in one value fall back to os' => [ 'night day', 'os' ],
			'a theme in the wrong case falls back to os' => [ 'Night', 'os' ],
			'an unimplemented theme falls back to os' => [ 'sepia', 'os' ],
			'an empty value falls back to os' => [ '', 'os' ],
		];
	}

	/**
	 * An unregistered visitor gets the theme the site declares as the default.
	 *
	 * The handler makes one preference lookup and makes it for every visitor, so this is the
	 * same code path the previous test exercises, reached with nothing stored. That is worth
	 * asserting separately because it is the path that matters for the anonymous theme switch:
	 * MediaWiki's client preferences script rewrites an existing `-clientpref-` class from a
	 * cookie and never adds one that is absent, so a handler that skipped unregistered
	 * visitors would break the switch quietly.
	 *
	 * The `null` case asserts the default skin.json itself declares, which makes this test the
	 * pairing for Gate 12's write site as well as its read site.
	 *
	 * @dataProvider provideDeclaredThemeDefaults
	 * @covers ::onBeforePageDisplay
	 */
	public function testDeclaredDefaultThemeReachesUnregisteredVisitors(
		?string $declaredDefault,
		string $expectedTheme
	) {
		if ( $declaredDefault !== null ) {
			// The user options service reads the declared defaults when it is constructed, so
			// the override has to happen before anything asks for that service. Merging over
			// the existing map rather than replacing it keeps every core default in place,
			// including the thumbnail size the root element also carries.
			$this->overrideConfigValue(
				MainConfigNames::DefaultUserOptions,
				[ self::THEME_PREFERENCE => $declaredDefault ] + $this->getServiceContainer()
					->getMainConfig()
					->get( MainConfigNames::DefaultUserOptions )
			);
		}

		$context = $this->newBlitzyContext( false );
		$this->assertFalse(
			$context->getUser()->isRegistered(),
			'This case must exercise the unregistered path, not a logged-in one.'
		);

		$this->assertThemeApplied(
			$context,
			$expectedTheme,
			'an unregistered visitor of a site whose declared default is '
				. ( $declaredDefault ?? 'the one skin.json ships' )
		);
	}

	/**
	 * Declared defaults, and the theme an unregistered visitor must therefore get.
	 *
	 * @return array[] Case name to [ declared default or null for the shipped one, theme ]
	 */
	public static function provideDeclaredThemeDefaults(): array {
		return [
			'the default declared in skin.json' => [ null, 'os' ],
			'a site that defaults to forced light' => [ 'day', 'day' ],
			'a site that defaults to forced dark' => [ 'night', 'night' ],
		];
	}

	/**
	 * The theme class lands on the root element only, beside the classes core put there.
	 *
	 * Three failures this rules out, none of which the theme tests above would notice. A
	 * handler that used addBodyClasses() would satisfy every "contains" assertion against a
	 * rendered page while leaving the `html.skin-theme-clientpref-*` selectors in this skin's
	 * stylesheets and in MediaWiki's own dark mode mixin unmatched. A handler that assigned the
	 * class list instead of merging into it would discard the ResourceLoader document classes,
	 * taking client-side JavaScript detection with them. And a class attribute compared for
	 * equality would encode core's classes into this test, which is why nothing here does.
	 *
	 * @covers ::onBeforePageDisplay
	 */
	public function testThemeClassLandsOnTheRootElementAndKeepsCoreClasses() {
		$html = $this->renderPage( $this->newBlitzyContext( false ) );

		$rootClasses = preg_split(
			'/\s+/',
			$this->classAttribute( $html, 'html' ),
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		$this->assertContains(
			self::THEME_CLASS_PREFIX . 'os',
			$rootClasses,
			'The theme class must be on the root element, where the theme selectors expect it.'
		);
		$this->assertGreaterThan(
			1,
			count( $rootClasses ),
			'The handler must merge its class into the root element class list rather than '
				. 'replace it, so the classes MediaWiki put there must survive alongside it.'
		);
		$this->assertDoesNotMatchRegularExpression(
			self::THEME_CLASS_PATTERN,
			$this->classAttribute( $html, 'body' ),
			'The theme class must not appear on the body element. A class there cannot satisfy '
				. 'a selector anchored to the root element, so it would be silently inert.'
		);
	}

	/**
	 * A rendered page carries the Blitzy class on navigation entries, and nowhere else.
	 *
	 * Gate 13's pairing for the navigation handler, taken from the markup MediaWiki actually
	 * produced. The ids asserted here are core's own, derived by MediaWiki from the array keys
	 * of the buckets after the hook returns, so finding them intact is also evidence that the
	 * handler renamed no key: a renamed key silently renames a preserved element id.
	 *
	 * The sidebar half of the assertion is the more interesting one. Sidebar and toolbox
	 * entries are rendered from the sidebar rather than from the navigation buckets the skin
	 * declares, and the handler must leave them alone; a handler that walked every bucket it
	 * found would style them too and this is the only test that would notice.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testRenderedNavigationEntriesCarryTheBlitzyClass() {
		$itemClasses = $this->renderedListItemClasses(
			$this->renderPage( $this->newBlitzyContext( true ) )
		);

		foreach ( self::CLASSED_ITEM_IDS as $id ) {
			$this->assertArrayHasKey(
				$id,
				$itemClasses,
				"The rendered page must contain the navigation entry $id, or this test is "
					. 'asserting the class on entries that were never rendered.'
			);
			$this->assertStringContainsString(
				self::MENU_ITEM_CLASS,
				$itemClasses[$id],
				"The rendered navigation entry $id must carry the Blitzy class, because the "
					. "skin's stylesheets hook on it rather than on the element id."
			);
		}

		$sidebarEntries = 0;
		foreach ( $itemClasses as $id => $classes ) {
			foreach ( self::SIDEBAR_ID_PREFIXES as $prefix ) {
				if ( !str_starts_with( (string)$id, $prefix ) ) {
					continue;
				}

				$sidebarEntries++;
				$this->assertStringNotContainsString(
					self::MENU_ITEM_CLASS,
					$classes,
					"The sidebar entry $id must not carry the Blitzy class. The handler is "
						. 'confined to the buckets skin.json declares, and the sidebar is not '
						. 'one of them.'
				);
			}
		}

		$this->assertGreaterThan(
			0,
			$sidebarEntries,
			'The rendered page must contain sidebar entries, or the assertion that they stay '
				. 'unclassed passed without examining anything.'
		);
	}

	/**
	 * Every entry of every declared bucket is classed, not merely the first one of each.
	 *
	 * The previous test names individual entries, which cannot show that none was missed. This
	 * one counts: each rendered entry carries core's own per-entry class, so comparing that
	 * count with the count of Blitzy classes in the same portlet markup asserts completeness
	 * for the whole bucket without naming a single entry. An empty declared bucket satisfies it
	 * with zero on both sides, which is the correct outcome for a bucket MediaWiki left empty.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testEveryEntryOfEveryDeclaredBucketCarriesTheBlitzyClass() {
		$portlets = $this->navigationPortlets( $this->newBlitzyContext( true ) );

		$entriesSeen = 0;
		foreach ( self::BUCKET_PORTLETS as $bucket => $portletKey ) {
			$portlet = $portlets[$portletKey] ?? null;
			$this->assertIsArray(
				$portlet,
				"The skin must render a portlet for the declared $bucket bucket, under the key "
					. "$portletKey that MediaWiki derives from it."
			);

			$items = (string)( $portlet['html-items'] ?? '' );
			$entries = substr_count( $items, self::CORE_LIST_ITEM_CLASS );

			$this->assertSame(
				$entries,
				substr_count( $items, self::MENU_ITEM_CLASS ),
				"Every one of the $entries entries rendered from the $bucket bucket must carry "
					. 'the Blitzy class, not just the ones this package happens to name.'
			);

			$entriesSeen += $entries;
		}

		$this->assertGreaterThan(
			0,
			$entriesSeen,
			'The declared buckets must have rendered at least one entry between them, or the '
				. 'count comparison above compared nothing with nothing.'
		);
	}

	/**
	 * The handler adds no bucket, adds no entry, removes none and renames none.
	 *
	 * This is the handler's behavioural contract and rule R12's runtime evidence at once, and
	 * it is worth more than the class assertions: appending a class is what the skin needs
	 * today, while the invariant is what stops a later author from making the navigation "more
	 * useful" by adding an entry to it. MediaWiki derives element ids from these keys after the
	 * hook returns, so a renamed key would take a preserved id with it.
	 *
	 * Keys are compared as sets rather than as ordered lists, because reordering a bucket is
	 * permitted and only membership is not.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testNavigationBucketAndEntryKeysSurviveTheHook() {
		$links = self::newNavigationFixture();
		$before = $links;

		$this->runNavigationHook( $links );

		$this->assertSame(
			self::keySets( $before ),
			self::keySets( $links ),
			'The bucket names and the entry keys inside them must come back unchanged: nothing '
				. 'added, nothing removed, nothing renamed.'
		);
		$this->assertSame(
			$before['user-page'],
			$links['user-page'],
			'A bucket outside the declared list must come back untouched, down to its values. '
				. 'user-page is rendered by this skin but is not one of the buckets it declares.'
		);
		$this->assertSame(
			$before['footer-places'],
			$links['footer-places'],
			'Footer buckets must come back untouched. MediaWiki rejects new footer entries from '
				. 'a skin that has not opted into footer menus, and this skin has not.'
		);
	}

	/**
	 * The class is appended to the existing value, in the shape that value arrived in.
	 *
	 * Every case in the expected map is a shape MediaWiki really produces, and each one fails
	 * differently if handled carelessly. A string value must stay a string, because MediaWiki
	 * concatenates onto the string form itself when it labels language variant entries
	 * immediately after this hook returns. An array value must stay an array. The missing,
	 * false and null cases all mean "no classes yet". Existing names must survive in their
	 * original order, because `selected` and `new` carry meaning to core's own stylesheets.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testNavigationClassIsAppendedWithoutDisturbingExistingClasses() {
		$links = self::newNavigationFixture();

		$this->runNavigationHook( $links );

		foreach ( self::EXPECTED_ENTRY_CLASSES as $bucket => $entries ) {
			foreach ( $entries as $key => $expected ) {
				$this->assertSame(
					$expected,
					$links[$bucket][$key]['class'],
					"The $bucket entry $key must come back with the Blitzy class appended to "
						. 'its existing classes, in the same shape and the same order.'
				);
			}
		}
	}

	/**
	 * Running the handler twice adds the class once.
	 *
	 * Not a hypothetical: MediaWiki builds the navigation array more than once per request for
	 * some actions, and a handler that appended blindly would produce markup that differs
	 * between two identical requests. Byte-identical captures depend on it not doing that.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testNavigationClassIsAppendedOnlyOnce() {
		$links = self::newNavigationFixture();

		$this->runNavigationHook( $links );
		$afterFirstRun = $links;
		$this->runNavigationHook( $links );

		$this->assertSame(
			$afterFirstRun,
			$links,
			'A second run over the same navigation array must change nothing at all.'
		);

		foreach ( self::EXPECTED_ENTRY_CLASSES as $bucket => $entries ) {
			foreach ( array_keys( $entries ) as $key ) {
				$classes = $links[$bucket][$key]['class'];
				$names = is_array( $classes )
					? $classes
					: preg_split( '/\s+/', (string)$classes, -1, PREG_SPLIT_NO_EMPTY );

				$this->assertCount(
					1,
					array_keys( $names, self::MENU_ITEM_CLASS, true ),
					"The $bucket entry $key must carry the Blitzy class exactly once."
				);
			}
		}
	}

	/**
	 * Navigation data the handler cannot interpret is left exactly as it was found.
	 *
	 * Three cases in one, all of them real. A declared bucket the running MediaWiki version
	 * does not provide must be skipped rather than created, or the skin would invent an empty
	 * portlet on a version whose navigation shape differs. A bucket that is not an array, and
	 * an entry that is not an array, must pass through untouched: MediaWiki validates entry
	 * shape immediately after this hook returns and its report names the offending entry, which
	 * is more useful than an error raised here.
	 *
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testMalformedNavigationDataPassesThroughUnchanged() {
		$links = [
			'views' => 'not a bucket',
			'actions' => [
				'delete' => 'not an entry',
				'protect' => [ 'text' => 'Protect', 'href' => '/wiki/Special:Protect' ],
			],
		];
		$before = $links;

		$this->runNavigationHook( $links );

		$this->assertSame(
			[ 'views', 'actions' ],
			array_keys( $links ),
			'The handler must not create a declared bucket that was absent from the array.'
		);
		$this->assertSame(
			$before['views'],
			$links['views'],
			'A bucket that is not an array must come back exactly as it was found.'
		);
		$this->assertSame(
			$before['actions']['delete'],
			$links['actions']['delete'],
			'An entry that is not an array must come back exactly as it was found.'
		);
		$this->assertArrayHasKey(
			'class',
			$links['actions']['protect'],
			'The well-formed entry must have been given a class value.'
		);
		$this->assertSame(
			self::MENU_ITEM_CLASS,
			$links['actions']['protect']['class'],
			'A well-formed entry beside a malformed one must still be classed, so that one bad '
				. 'entry cannot quietly cost a whole bucket its styling.'
		);
	}

	/**
	 * The BeforePageDisplay handler contributes nothing but the one root element class.
	 *
	 * The plan says these handlers touch the root element and the navigation buckets and
	 * nothing else, and this is where that claim is measured. A head item or a body class added
	 * here would be user-visible behaviour that no in-scope entry authorises, and it would be
	 * invisible to every other assertion in this package. The class list is read directly as
	 * well, so that "exactly one class" is asserted at the source and not only in the markup.
	 *
	 * @covers ::onBeforePageDisplay
	 */
	public function testBeforePageDisplayAddsNoHeadItemAndNoBodyClass() {
		$context = $this->newBlitzyContext( false );
		$output = $context->getOutput();
		$internals = TestingAccessWrapper::newFromObject( $output );

		$headItemsBefore = $output->getHeadItemsArray();
		$bodyClassesBefore = $internals->mAdditionalBodyClasses;

		$this->runBeforePageDisplayHandlers( $context );

		$this->assertSame(
			$headItemsBefore,
			$output->getHeadItemsArray(),
			'The handler must add no head item. Styles and scripts are declared in skin.json '
				. 'and delivered by ResourceLoader, which is a different mechanism entirely.'
		);
		$this->assertSame(
			$bodyClassesBefore,
			$internals->mAdditionalBodyClasses,
			'The handler must add no body class. The body classes this skin wants are the ones '
				. "skin.json's bodyClasses argument declares."
		);
		$this->assertSame(
			[ self::THEME_CLASS_PREFIX . 'os' ],
			$internals->mAdditionalHtmlClasses,
			'The handler must contribute exactly one root element class and no more.'
		);
	}

	/**
	 * Neither handler reads any of the skin's eight configuration options.
	 *
	 * Gate 12 traces every option from a write site to a read site, and both handlers are
	 * absent from that table on purpose: the options are read by the view model, and their
	 * escaping and link validation are asserted by BlitzyConfigSecurityTest. Asserting the
	 * negative here is what keeps that division honest, because an option read from a hook
	 * would still reach the page and would bypass the escaping the view model performs.
	 *
	 * Two renders are compared with the configuration switched on between them. The comparison
	 * would be vacuous if the configuration made no difference at all, so the difference is
	 * asserted first.
	 *
	 * @covers ::onBeforePageDisplay
	 * @covers ::onSkinTemplateNavigation__Universal
	 */
	public function testHandlersIgnoreTheSkinConfiguration() {
		$shipped = $this->renderPage( $this->newBlitzyContext( true ) );

		$this->overrideConfigValues( self::CONFIGURED_OPTIONS );
		$configured = $this->renderPage( $this->newBlitzyContext( true ) );

		$this->assertNotSame(
			$shipped,
			$configured,
			'Setting all eight options must change the rendered page, or the comparisons below '
				. 'prove nothing about the handlers ignoring them.'
		);
		$this->assertSame(
			$this->classAttribute( $shipped, 'html' ),
			$this->classAttribute( $configured, 'html' ),
			'The root element classes must not vary with the skin configuration. The theme is '
				. 'resolved from a user preference and from nothing else.'
		);
		$this->assertSame(
			substr_count( $shipped, self::MENU_ITEM_CLASS ),
			substr_count( $configured, self::MENU_ITEM_CLASS ),
			'The navigation entries must not vary with the skin configuration either.'
		);
	}

	/**
	 * Builds a request context that renders the Blitzy skin over an existing page.
	 *
	 * The skin is set explicitly rather than left to the site's default skin setting, so that
	 * every assertion in this class is about Blitzy on any wiki the suite runs on, including one
	 * where another skin is the default.
	 *
	 * @param bool $registered Whether the visitor is a registered user or an unregistered one
	 * @return RequestContext
	 */
	private function newBlitzyContext( bool $registered ): RequestContext {
		$user = $registered
			? $this->getTestUser()->getUser()
			: $this->getServiceContainer()->getUserFactory()->newAnonymous();

		$context = new RequestContext();
		$context->setRequest( new FauxRequest() );
		$context->setTitle( $this->getExistingTestPage( self::TEST_PAGE )->getTitle() );
		$context->setUser( $user );
		$context->setLanguage( 'en' );
		$context->setActionName( 'view' );

		$skin = $this->getServiceContainer()->getSkinFactory()->makeSkin( 'blitzy' );
		$this->assertInstanceOf(
			SkinBlitzy::class,
			$skin,
			'ValidSkinNames.blitzy in skin.json must resolve to the Blitzy skin class. The skin '
				. 'factory throws for a name it does not know, so reaching this assertion at '
				. 'all already proves the name is registered.'
		);
		$context->setSkin( $skin );

		return $context;
	}

	/**
	 * Stores a theme preference for a user, without writing to the database.
	 *
	 * The options manager keeps the value in its own cache, and that manager is the same object
	 * the handler is given as its UserOptionsLookup, so a render observes the value. Saving it
	 * would add a write that proves nothing extra about the handler.
	 *
	 * @param UserIdentity $user
	 * @param string $theme Value to store, valid or not
	 */
	private function setThemePreference( UserIdentity $user, string $theme ): void {
		$this->getServiceContainer()->getUserOptionsManager()->setOption(
			$user,
			self::THEME_PREFERENCE,
			$theme
		);
	}

	/**
	 * Renders a full page the way the web entry point does, and returns its HTML.
	 *
	 * OutputPage::output() is where MediaWiki runs BeforePageDisplay, so this is what makes the
	 * handler run because the manifest registered it. Asking for the HTML as a return value
	 * keeps the page out of the test runner's own output stream, which this suite treats as a
	 * failure in its own right.
	 *
	 * @param RequestContext $context
	 * @return string
	 */
	private function renderPage( RequestContext $context ): string {
		$html = (string)$context->getOutput()->output( true );

		$this->assertStringContainsString(
			'<html',
			$html,
			'The skin must render a complete document rather than a fragment, or there is no '
				. 'root element for the theme class to be asserted on.'
		);

		return $html;
	}

	/**
	 * Runs the registered BeforePageDisplay handlers against a context's output.
	 *
	 * Used where the effect being asserted is a property of the output object rather than of the
	 * markup, so that a full render is not needed. The hook is run through the container with
	 * the same options MediaWiki's own hook runner uses, which means the manifest registration
	 * stays part of what is being asserted.
	 *
	 * @param RequestContext $context
	 */
	private function runBeforePageDisplayHandlers( RequestContext $context ): void {
		$this->getServiceContainer()->getHookContainer()->run(
			'BeforePageDisplay',
			[ $context->getOutput(), $context->getSkin() ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * Runs the registered SkinTemplateNavigation::Universal handlers over a links array.
	 *
	 * The array is passed by reference through the arguments list, which is how MediaWiki's own
	 * hook runner passes it. Nothing is registered here and nothing is cleared: with the
	 * skin.json entry deleted, no handler runs and every assertion about the class fails, which
	 * is exactly the coupling Gate 13 asks for.
	 *
	 * The handler documents that it never reads its first argument, so the skin the factory
	 * builds is enough for it; the rendered-page tests are what exercise the handler with the
	 * skin MediaWiki itself passes.
	 *
	 * @param array &$links Navigation buckets, modified in place
	 */
	private function runNavigationHook( array &$links ): void {
		$arguments = [
			$this->getServiceContainer()->getSkinFactory()->makeSkin( 'blitzy' ),
			&$links,
		];

		$this->getServiceContainer()->getHookContainer()->run(
			'SkinTemplateNavigation::Universal',
			$arguments,
			[ 'abortable' => false ]
		);
	}

	/**
	 * Returns the skin's rendered portlet data for a context.
	 *
	 * Building the template data runs the navigation pipeline, and therefore the navigation
	 * hook, exactly as a page view does.
	 *
	 * @param RequestContext $context
	 * @return array Portlet data keyed as MediaWiki keys it, one entry per menu
	 */
	private function navigationPortlets( RequestContext $context ): array {
		// The context skin is a clone of the SkinBlitzy the factory built, which
		// ::newBlitzyContext() asserts; the annotation states the same fact for readers and for
		// static analysis.
		/** @var SkinBlitzy $skin */
		$skin = $context->getSkin();

		$templateData = $skin->getTemplateData();
		$this->assertArrayHasKey(
			'data-portlets',
			$templateData,
			'MediaWiki must supply the portlet data the skin renders its menus from.'
		);

		return $templateData['data-portlets'];
	}

	/**
	 * Extracts the class attribute of the first element of a given name in some HTML.
	 *
	 * Deliberately returns the attribute rather than asserting against it, because the root
	 * element also carries the ResourceLoader document classes, the skin's own element
	 * attributes and a thumbnail size class. Every caller therefore asserts about the classes it
	 * owns and never compares the attribute as a whole.
	 *
	 * @param string $html
	 * @param string $element Element name, without angle brackets
	 * @return string The value of the element's class attribute
	 */
	private function classAttribute( string $html, string $element ): string {
		$this->assertMatchesRegularExpression(
			'/<' . $element . '\b[^>]*>/',
			$html,
			"The rendered page must contain a $element element."
		);

		preg_match( '/<' . $element . '\b[^>]*\sclass="([^"]*)"/', $html, $matches );
		$this->assertCount(
			2,
			$matches,
			"The rendered $element element must carry a class attribute."
		);

		return $matches[1];
	}

	/**
	 * Maps every rendered list item that has an id to that item's class attribute.
	 *
	 * Both attributes are read from the whole tag rather than in a fixed order, so that the
	 * order MediaWiki happens to serialise them in is not baked into the assertions.
	 *
	 * @param string $html
	 * @return array<string,string> Element id to class attribute value
	 */
	private function renderedListItemClasses( string $html ): array {
		preg_match_all( '/<li\b[^>]*>/', $html, $tags );

		$itemClasses = [];
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bid="([^"]+)"/', $tag, $id )
				&& preg_match( '/\bclass="([^"]*)"/', $tag, $class )
			) {
				$itemClasses[$id[1]] = $class[1];
			}
		}

		$this->assertNotSame(
			[],
			$itemClasses,
			'The rendered page must contain identified list items, or there is no navigation '
				. 'markup here to assert anything about.'
		);

		return $itemClasses;
	}

	/**
	 * A navigation array in the shape MediaWiki supplies one.
	 *
	 * It holds all seven buckets skin.json declares, including an empty one, plus two buckets
	 * outside that list so that the handler's confinement to its own is observable. Every
	 * `class` shape MediaWiki produces is represented: a string, an array, `false`, `null` and
	 * the key being absent altogether. One entry carries `html` instead of `text`, and one
	 * bucket is keyed by integer rather than by name, both of which MediaWiki really does.
	 *
	 * @return array
	 */
	private static function newNavigationFixture(): array {
		return [
			'associated-pages' => [
				'nstab-main' => [
					'text' => 'Page',
					'href' => '/wiki/Page',
					'class' => 'selected',
				],
				'talk' => [
					'text' => 'Discussion',
					'href' => '/wiki/Talk:Page',
					'class' => 'new',
				],
			],
			'views' => [
				'view' => [ 'text' => 'Read', 'href' => '/wiki/Page' ],
				'edit' => [
					'text' => 'Edit',
					'href' => '/w/index.php?title=Page&action=edit',
					'class' => false,
				],
				'history' => [
					'text' => 'View history',
					'href' => '/w/index.php?title=Page&action=history',
					'class' => null,
				],
			],
			'actions' => [
				'move' => [ 'text' => 'Move', 'href' => '/wiki/Special:MovePage/Page' ],
				'watch' => [
					'text' => 'Watch',
					'href' => '/w/index.php?title=Page&action=watch',
					'class' => 'mw-watchlink',
				],
			],
			'variants' => [
				[
					'text' => 'English',
					'href' => '/wiki/Page',
					'class' => [ 'ca-variants-en' ],
				],
			],
			'user-menu' => [
				'preferences' => [
					'text' => 'Preferences',
					'href' => '/wiki/Special:Preferences',
				],
				'logout' => [
					'text' => 'Log out',
					'href' => '/wiki/Special:UserLogout',
					'class' => 'mw-logout',
				],
			],
			'user-interface-preferences' => [
				'uls' => [ 'html' => '<span>en</span>' ],
			],
			'notifications' => [],
			'user-page' => [
				'userpage' => [ 'text' => 'Example', 'href' => '/wiki/User:Example' ],
			],
			'footer-places' => [
				'privacy' => [
					'text' => 'Privacy policy',
					'href' => '/wiki/Project:Privacy_policy',
				],
			],
		];
	}

	/**
	 * Reduces a navigation array to its bucket names and entry keys, as sorted sets.
	 *
	 * Sorting is what makes reordering permissible while membership is not: the handler is
	 * allowed to change the order entries appear in, and is not allowed to change which entries
	 * exist. Buckets that are not arrays reduce to an empty set rather than raising, so that a
	 * malformed bucket can be compared like any other.
	 *
	 * @param array $links
	 * @return array<string,array> Bucket name to its sorted entry keys
	 */
	private static function keySets( array $links ): array {
		$keySets = [];
		foreach ( $links as $bucket => $entries ) {
			$keys = is_array( $entries ) ? array_keys( $entries ) : [];
			sort( $keys );
			$keySets[$bucket] = $keys;
		}
		ksort( $keySets );

		return $keySets;
	}

	/**
	 * Renders a page and asserts that the expected theme, and only it, reached the root element.
	 *
	 * The class is examined twice over, once in the markup and once in the list the handler
	 * contributed, because neither observation catches everything on its own.
	 *
	 * The markup is what a browser sees, and it is where a wrong theme, or a second theme class
	 * beside the right one, shows up. What it cannot show is the same class applied twice:
	 * MediaWiki collapses duplicates when it serialises a class list, so a handler that ran
	 * twice per request produces markup indistinguishable from one that ran once. Reading the
	 * contributed list is what catches that, and it matters beyond tidiness, because output
	 * that depends on how many times a handler ran is output that cannot be reproduced.
	 *
	 * @param RequestContext $context Context to render, with any preference already set
	 * @param string $expectedTheme One of self::THEMES
	 * @param string $case Human-readable description of the case, used in failure messages
	 */
	private function assertThemeApplied(
		RequestContext $context,
		string $expectedTheme,
		string $case
	): void {
		$html = $this->renderPage( $context );

		$this->assertSoleThemeClass(
			$this->classAttribute( $html, 'html' ),
			$expectedTheme,
			$case
		);

		$this->assertSame(
			[ self::THEME_CLASS_PREFIX . $expectedTheme ],
			TestingAccessWrapper::newFromObject( $context->getOutput() )->mAdditionalHtmlClasses,
			"The handler must contribute exactly one root element class for $case, and it must "
				. 'be that theme. A duplicate would be invisible in the markup.'
		);
	}

	/**
	 * Asserts that a class attribute carries one theme class, and that it is the expected one.
	 *
	 * Both halves matter. The count catches a handler that ran twice, which a "contains"
	 * assertion cannot see because class lists accumulate. The exclusions catch a handler that
	 * added the right class beside the wrong one, which is what would make the override
	 * additive rather than decisive.
	 *
	 * @param string $classAttribute Value of the root element's class attribute
	 * @param string $expectedTheme One of self::THEMES
	 * @param string $case Human-readable description of the case, used in failure messages
	 */
	private function assertSoleThemeClass(
		string $classAttribute,
		string $expectedTheme,
		string $case
	): void {
		preg_match_all( self::THEME_CLASS_PATTERN, $classAttribute, $matches );

		$this->assertSame(
			[ self::THEME_CLASS_PREFIX . $expectedTheme ],
			$matches[0],
			"The root element must carry exactly one theme class for $case, and it must be the "
				. "$expectedTheme one. Two classes here means the class was applied twice."
		);

		foreach ( self::THEMES as $theme ) {
			if ( $theme === $expectedTheme ) {
				continue;
			}

			$this->assertStringNotContainsString(
				self::THEME_CLASS_PREFIX . $theme,
				$classAttribute,
				"The $theme theme must not reach the root element for $case. The override has "
					. 'to be decisive in both directions, not additive.'
			);
		}
	}
}
