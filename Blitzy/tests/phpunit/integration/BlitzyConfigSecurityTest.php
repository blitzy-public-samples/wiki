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
use MediaWiki\Page\LinkCache;
use MediaWiki\Skins\Blitzy\BlitzyViewModel;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\TalkPageNotificationManager;
use MediaWikiIntegrationTestCase;

/**
 * Treats every one of the skin's configuration options as hostile input and proves, against a
 * really rendered page, that none of them can inject markup or a dangerous link target.
 *
 * WHAT THIS SUITE IS FOR
 * ======================
 * This is the enforcement site for rule R13, config input is untrusted. R13 asks for a test
 * that sets each string option to an injection payload and each link-target option to a
 * javascript: URL, then asserts that the payload appears escaped and that the malicious link
 * is suppressed. That is exactly the shape of the matrix below.
 *
 * The rule's own prose scopes itself to "the five string and two link-target config options",
 * but expanding the eight options skin.json actually registers yields one boolean, four
 * strings and THREE link targets: the announcement target and both call-to-action targets.
 * The stated count cannot be reconciled with the registered surface, so the resolution
 * recorded in the plan is implemented here instead: every configuration-sourced string is
 * asserted escaped and every configuration-sourced link target is asserted scheme-validated.
 * That is a strict superset of the rule's stated scope, so the rule holds under either
 * reading, and no option is left exempt on the strength of a guess about which one the count
 * meant to exclude. For an injection control a superset is always the safe direction.
 *
 * ::testEveryManifestConfigurationOptionIsCoveredBySecurityMatrix is what keeps that promise
 * true over time. It reads the `config` block out of skin.json and asserts that the set of
 * options this class's data providers exercise is exactly the set the manifest declares, so a
 * ninth option cannot be registered without the security matrix growing to cover it. Every
 * other test here could be individually correct while the class as a whole quietly stopped
 * being exhaustive; that one assertion is the reason it cannot.
 *
 * WHY EVERY ASSERTION IS MADE AT THE RENDER BOUNDARY
 * =================================================
 * MediaWiki\Skins\Blitzy\BlitzyViewModel deliberately does NOT HTML-escape. It normalises the
 * configured strings, strips ASCII control characters, trims, and hands them on raw, because
 * TemplateParser already escapes `{{ }}` interpolation and escaping twice would render an
 * injected payload visibly as `&amp;lt;script&amp;gt;` while a naive "the payload is escaped"
 * assertion still passed. includes/templates/AnnouncementBar.mustache and
 * includes/templates/NavCard.mustache are therefore the single escaping site, and
 * harness/LocalSettings.template.php writes the values raw for the same reason.
 *
 * So this suite never inspects ::build()'s return value. It renders a page through
 * MediaWiki\Skins\Blitzy\SkinBlitzy and asserts on the HTML, which is what R13's own wording,
 * "HTML-escaped at render", asks for. That assertion is also true under the escape-in-PHP
 * design the plan's prose describes, which is precisely why it is the one worth writing: it
 * observes the property the rule cares about rather than the place the property happens to be
 * implemented. Each string is checked at its OWN render site, not merely somewhere in the
 * document, so an option that stopped being read at all would fail rather than pass vacuously.
 *
 * A REJECTED LINK TARGET HAS TWO LAWFUL SHAPES, AND THEY ARE NOT THE SAME
 * ======================================================================
 * MediaWiki\Skins\Blitzy\BlitzyUrlValidator answers null for every target outside its
 * allowlist of site-relative paths, http and https. What the view model does with that null
 * differs by option, and both behaviours are asserted as authored rather than as assumed:
 *
 *   - The announcement target is DROPPED. The `href` key is omitted, the enclosing Mustache
 *     section is falsy, and the action affordance renders as a span with no href attribute at
 *     all. This suite asserts the attribute is absent, never that it is empty or a fragment:
 *     an empty href still renders a link, and it resolves to the page it sits on.
 *   - A call-to-action target FALLS BACK to a core special page, so the anchor keeps an href.
 *     The security property there is that the hostile target never reaches the attribute and
 *     that what does reach it is the same site-relative path an unconfigured option produces.
 *
 * Both shapes are also asserted from the other side. ::provideAcceptedLinkTargets feeds each
 * of the three options an allowlisted https, http and site-relative target and asserts it
 * arrives in the rendered href, so a validator that simply rejected everything could not pass
 * this suite. The unit-level enumeration of the allowlist itself belongs to
 * tests/phpunit/unit/BlitzyUrlValidatorTest.php; this file only walks the end-to-end path.
 *
 * GATE 12: THIS FILE IS THE PRIMARY READ-PATH EVIDENCE
 * ===================================================
 * Gate 12 requires every configuration option to have a named write site and a named read
 * site reachable from a page render. The write sites are the `config` block of skin.json,
 * which declares the shipped defaults, and harness/LocalSettings.template.php, which sets
 * them on the verification instance; the read sites are BlitzyViewModel and the templates;
 * the propagation table itself lives in DELIVERY.md. This suite is what proves those read
 * sites are genuinely reachable from a render, for all eight options rather than a sample:
 * every payload assertion locates the value at the element that is supposed to carry it.
 * The Less-token half of Gate 12 is measured by tools/token-audit.js and is out of scope here.
 *
 * WHAT THIS FILE DOES NOT CLAIM
 * =============================
 * Gate 1, the end-to-end boundary, is NOT satisfied by this or by any other PHP test. It is
 * bound to captures 1 and 2, committed PNGs of a really imported Wikipedia article, and a
 * green run here must never be reported as evidence for it. Neither is this file one of Gate
 * 8's four integration checks; its pass rate belongs in the PHPUnit section of DELIVERY.md,
 * reported separately from them.
 *
 * The neighbouring boundaries are just as deliberate. The preserved DOM contract of rule R2 is
 * asserted by tests/playwright/domContract.spec.ts, so where this suite touches the
 * announcement or navigation markup it is scaffolding for a security assertion and not an R2
 * claim. Rule R5, zero external origins, is verified for subresources by
 * tests/playwright/externalOrigins.spec.ts; what is asserted here is narrower and still worth
 * asserting, namely that a rejected foreign or protocol-relative target never reaches a
 * rendered href. The nine-partial server-render sweep of Gate 9 belongs to
 * SkinBlitzyIntegrationTest.php. Nothing here measures a computed style, reads a stylesheet or
 * touches screenshots/: how the announcement bar looks is the business of
 * tests/playwright/fidelity.spec.ts, and only whether it escapes is the business of this file.
 *
 * DATABASE POSTURE
 * ================
 * There is deliberately no `@group Database`. A class without it gets storage disabled, which
 * turns any stray database read into a thrown RuntimeException rather than a silent
 * dependency, and this suite needs no real page, user or revision: a Title built by
 * ::makeTitle with its article id reset, an anonymous authority and body content injected
 * through OutputPage are enough for the announcement bar and the navigation card to render.
 * One mock is load bearing rather than decorative. Without a mocked LinkCache,
 * SkinComponentFooter resolves the footer's own links through Title::exists() and the render
 * dies on the disabled backend; TalkPageNotificationManager is mocked alongside it, following
 * the reference skin's precedent, so the render cannot depend on new-message state either.
 *
 * ::clearHooks() is never called. It would unregister the manifest's BlitzyHooks handlers,
 * whose liveness the sibling BlitzyHooksTest.php exists to prove, and a page rendered without
 * them is not the page this wiki serves.
 *
 * @group Blitzy
 * @group Skins
 * @covers \MediaWiki\Skins\Blitzy\BlitzyViewModel
 * @covers \MediaWiki\Skins\Blitzy\BlitzyUrlValidator
 * @covers \MediaWiki\Skins\Blitzy\SkinBlitzy
 */
class BlitzyConfigSecurityTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	/**
	 * The one boolean option, and the four string and three link-target options.
	 *
	 * The names come from BlitzyViewModel's own public constants rather than from string
	 * literals repeated here, so the manifest, the production read site and this matrix have a
	 * single source of truth. ::testEveryManifestConfigurationOptionIsCoveredBySecurityMatrix
	 * proves that source agrees with skin.json, which is what makes the reuse safe.
	 */
	private const FLAG_OPTION = BlitzyViewModel::OPTION_ANNOUNCE_ENABLE;

	private const STRING_OPTIONS = [
		BlitzyViewModel::OPTION_ANNOUNCE_TEXT,
		BlitzyViewModel::OPTION_ANNOUNCE_LABEL,
		BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL,
		BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL,
	];

	private const LINK_OPTIONS = [
		BlitzyViewModel::OPTION_ANNOUNCE_LINK,
		BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK,
		BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK,
	];

	/**
	 * Every data provider in this class, named so the completeness test can walk them.
	 *
	 * One invariant makes that walk possible and every provider below honours it: the FIRST
	 * element of every dataset is the configuration option that dataset exercises. It is
	 * redundant in the providers that only ever touch one option, and it is kept anyway,
	 * because it is what lets exhaustiveness be asserted mechanically instead of by review.
	 */
	private const PROVIDER_METHODS = [
		'provideStringInjectionPayloads',
		'provideRejectedAnnouncementTargets',
		'provideRejectedCallToActionTargets',
		'provideAcceptedLinkTargets',
		'provideAnnouncementPresenceStates',
	];

	/**
	 * Class hooks of the four elements that carry configuration-sourced values.
	 *
	 * Defined by includes/templates/AnnouncementBar.mustache and
	 * includes/templates/NavCard.mustache. They are used to locate an element precisely, so
	 * that "the payload is escaped" means "escaped where it landed" and not "escaped somewhere
	 * in eight kilobytes of HTML".
	 */
	private const CLASS_ANNOUNCEMENT_TEXT = 'blitzy-announcement__text';
	private const CLASS_ANNOUNCEMENT_ACTION = 'blitzy-announcement__action';
	private const CLASS_ACTION_PRIMARY = 'blitzy-cta--outline';
	private const CLASS_ACTION_SECONDARY = 'blitzy-cta--gradient';

	/**
	 * Element each option is rendered by, which is the Gate 12 read path expressed as code.
	 *
	 * A label and its target share an element: the label becomes its text and the target
	 * becomes its href. The boolean option has no element of its own — it decides whether the
	 * announcement is emitted at all — so it is absent from this map by design and is asserted
	 * by presence instead.
	 */
	private const RENDER_SITE = [
		BlitzyViewModel::OPTION_ANNOUNCE_TEXT => self::CLASS_ANNOUNCEMENT_TEXT,
		BlitzyViewModel::OPTION_ANNOUNCE_LABEL => self::CLASS_ANNOUNCEMENT_ACTION,
		BlitzyViewModel::OPTION_ANNOUNCE_LINK => self::CLASS_ANNOUNCEMENT_ACTION,
		BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => self::CLASS_ACTION_PRIMARY,
		BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => self::CLASS_ACTION_PRIMARY,
		BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => self::CLASS_ACTION_SECONDARY,
		BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => self::CLASS_ACTION_SECONDARY,
	];

	/**
	 * Harmless values for all eight options, applied under every override.
	 *
	 * Two properties matter. The announcement is ENABLED here, because a payload placed in the
	 * announcement text or label can only be observed on a bar that renders; and every value
	 * is distinctive, so an assertion that finds a payload at the wrong site fails on the
	 * mismatch rather than on absence. Each test overrides exactly the option it is exercising
	 * and inherits the rest, which is what makes a failure name one option instead of eight.
	 */
	private const BENIGN_SETTINGS = [
		self::FLAG_OPTION => true,
		BlitzyViewModel::OPTION_ANNOUNCE_TEXT => 'Benign announcement text',
		BlitzyViewModel::OPTION_ANNOUNCE_LABEL => 'Benign announcement label',
		BlitzyViewModel::OPTION_ANNOUNCE_LINK => '/wiki/Benign_announcement_target',
		BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => 'Benign primary label',
		BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => '/wiki/Benign_primary_target',
		BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => 'Benign secondary label',
		BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => '/wiki/Benign_secondary_target',
	];

	/**
	 * Title of the page every render in this suite is taken of, and the body it is given.
	 *
	 * The body is injected rather than parsed, because no assertion here concerns wiki content:
	 * R13 scopes itself to configuration and core sanitises everything else.
	 */
	private const PAGE_TITLE = 'BlitzyConfigSecurity';
	private const BODY_CONTENT = '<p id="blitzy-test-body">Body content for the configuration '
		. 'security suite.</p>';

	/**
	 * Keep the render off infrastructure this suite has nothing to say about.
	 *
	 * Both services are replaced for the reason given in the class comment: the footer resolves
	 * its links through LinkCache, and storage is disabled for a class outside the Database
	 * group. Nothing else is mocked, so the page these tests inspect is assembled by the real
	 * skin, the real components and the real templates.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->setService( 'LinkCache', $this->createMock( LinkCache::class ) );
		$this->setService(
			'TalkPageNotificationManager',
			$this->createMock( TalkPageNotificationManager::class )
		);
	}

	/**
	 * Render a page with the given configuration overrides layered over the benign baseline.
	 *
	 * PHP's `+` keeps the left operand when a key collides, so the caller's overrides win and
	 * every option it does not mention keeps its harmless value.
	 *
	 * @param array<string,mixed> $overrides Configuration options to set, by their un-prefixed
	 *   manifest names.
	 * @return string The page's rendered HTML.
	 */
	private function renderWithConfig( array $overrides ): string {
		$this->overrideConfigValues( $overrides + self::BENIGN_SETTINGS );

		return $this->render();
	}

	/**
	 * Render a page with whatever configuration is currently in force.
	 *
	 * Called directly by the shipped-defaults test, which must see the manifest's own values
	 * rather than the baseline this suite otherwise imposes.
	 *
	 * SkinMustache::generateHTML() is the entry point: it sets up the template context, builds
	 * the template data through SkinBlitzy and processes the root template, so everything this
	 * suite asserts on has travelled the same path a web request travels.
	 *
	 * @return string The page's rendered HTML.
	 */
	private function render(): string {
		$title = Title::makeTitle( NS_MAIN, self::PAGE_TITLE );

		// A page that does not exist, stated rather than looked up: resolving an article id
		// would be a storage read, and this class runs with storage disabled.
		$title->resetArticleID( 0 );

		$context = new RequestContext();
		$context->setTitle( $title );
		$context->setAuthority( $this->mockAnonNullAuthority() );
		$context->setLanguage( 'en' );
		$context->setActionName( 'view' );
		$context->getOutput()->addHTML( self::BODY_CONTENT );

		$skin = $this->getServiceContainer()->getSkinFactory()->makeSkin( 'blitzy' );
		$skin->setContext( $context );

		return $skin->generateHTML();
	}

	/**
	 * Read the `config` block out of the skin manifest.
	 *
	 * The manifest is the declaration site for all eight options and for their shipped
	 * defaults, and its schema sets additionalProperties to false, so there is no other shape
	 * the block could take and no other place an option could hide. Reading it here rather than
	 * hardcoding a list is the whole point: this is how the completeness test notices an option
	 * that was registered without being covered.
	 *
	 * @return array<string,array> The manifest's configuration options, keyed by option name.
	 */
	private function manifestConfigurationBlock(): array {
		$path = __DIR__ . '/../../../skin.json';
		$this->assertFileExists(
			$path,
			'The skin manifest must be readable from the test directory, because it is the '
				. 'declaration site this suite measures its own completeness against.'
		);

		$manifest = json_decode( (string)file_get_contents( $path ), true );
		$this->assertIsArray( $manifest, 'skin.json must be valid JSON.' );
		$this->assertArrayHasKey(
			'config',
			$manifest,
			'skin.json must declare a config block: it is the write site Gate 12 names, and '
				. 'the manifest schema admits configuration options nowhere else.'
		);
		$this->assertIsArray( $manifest['config'], 'The config block must be an object.' );

		return $manifest['config'];
	}

	/**
	 * Return the one start tag whose class attribute carries the given hook.
	 *
	 * Exactly one is required, and an absent element FAILS rather than skips: a security
	 * assertion against markup that was never rendered would pass for the wrong reason. A
	 * duplicate is a failure too, because each of these elements is emitted once per page and a
	 * second copy would mean the partial had been referenced twice.
	 *
	 * A regular expression is used rather than an HTML parser because rule R15 forbids adding a
	 * dependency, and because the markup being matched is emitted by this skin's own templates
	 * and its shape is fixed by them.
	 *
	 * @param string $html Rendered page.
	 * @param string $class Class hook to look for.
	 * @return string The element's complete start tag.
	 */
	private function startTagWithClass( string $html, string $class ): string {
		$pattern = '/<[a-z][a-z0-9]*\b[^>]*\bclass="[^"]*\b'
			. preg_quote( $class, '/' )
			. '\b[^"]*"[^>]*>/i';

		$this->assertSame(
			1,
			preg_match_all( $pattern, $html, $matches ),
			"Exactly one element carrying the $class hook must be rendered, so that this "
				. 'assertion names one element rather than passing vacuously or matching a '
				. 'duplicate.'
		);

		return $matches[0][0];
	}

	/**
	 * Return the value of one attribute of a start tag, or null when it carries none.
	 *
	 * Null is a meaningful answer here rather than an error: the absence of an href is the
	 * documented outcome of a rejected announcement target, and it is asserted as such.
	 *
	 * @param string $startTag A complete start tag, as returned by ::startTagWithClass().
	 * @param string $attribute Attribute name.
	 * @return string|null The attribute's value exactly as rendered, still HTML-escaped.
	 */
	private function attributeValue( string $startTag, string $attribute ): ?string {
		$pattern = '/\s' . preg_quote( $attribute, '/' ) . '="([^"]*)"/i';
		if ( preg_match( $pattern, $startTag, $matches ) !== 1 ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Return the inner HTML of the one element whose class attribute carries the given hook.
	 *
	 * The back-reference to the captured tag name is what keeps this honest for the two
	 * affordances that render as an anchor when they have a target and as a span when they do
	 * not. None of these four elements ever contains a nested element of its own, which is why
	 * a lazy match up to the closing tag is exact rather than approximate.
	 *
	 * @param string $html Rendered page.
	 * @param string $class Class hook to look for.
	 * @return string The element's inner HTML, exactly as rendered.
	 */
	private function innerHtmlOfElementWithClass( string $html, string $class ): string {
		$pattern = '/<([a-z][a-z0-9]*)\b[^>]*\bclass="[^"]*\b'
			. preg_quote( $class, '/' )
			. '\b[^"]*"[^>]*>(.*?)<\/\1>/is';

		$this->assertSame(
			1,
			preg_match_all( $pattern, $html, $matches ),
			"Exactly one element carrying the $class hook must be rendered, and it must be "
				. 'closed, before its contents can be asserted on.'
		);

		return $matches[2][0];
	}

	/**
	 * Assert that no start tag anywhere in the page carries an event-handler attribute.
	 *
	 * This is the assertion that distinguishes a neutralised payload from an executed one. A
	 * payload that survives escaping ends up as text, where `onerror=` is inert; a payload that
	 * breaks out of an attribute ends up as an attribute, where it is not.
	 *
	 * Quoted attribute values are blanked before the search, which is what makes the check
	 * precise in both directions. An escaped quote inside an href leaves `onmouseover=` sitting
	 * harmlessly inside that value and must not be reported; a real breakout re-pairs the
	 * quotes, so the handler ends up outside every quoted run and is reported.
	 *
	 * @param string $html Rendered page.
	 * @param string $context What was being rendered, for the failure message.
	 */
	private function assertNoEventHandlerAttribute( string $html, string $context ): void {
		$offenders = [];

		preg_match_all( '/<[a-z][a-z0-9]*\b[^>]*>/i', $html, $tags );
		foreach ( $tags[0] as $tag ) {
			$withoutAttributeValues = (string)preg_replace( '/"[^"]*"|\'[^\']*\'/', '""', $tag );
			if ( preg_match( '/\son[a-z]+\s*=/i', $withoutAttributeValues ) === 1 ) {
				$offenders[] = $tag;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"No rendered element may carry an event-handler attribute ($context). A handler in "
				. 'the markup means a configuration value escaped its text node or broke out of '
				. 'its attribute.'
		);
	}

	/**
	 * Assert that every href in the page uses a scheme the skin is allowed to emit.
	 *
	 * Safe means a document fragment, a site-relative path with a single leading slash, or an
	 * absolute http or https URL. A protocol-relative target fails this, which is deliberate:
	 * it is a foreign origin wearing a relative disguise. Rule R5 is verified for subresources
	 * by tests/playwright/externalOrigins.spec.ts and this is not a claim to have done its job;
	 * it is the narrower guarantee that no rejected target reached an attribute a browser acts
	 * on.
	 *
	 * @param string $html Rendered page.
	 * @param string $context What was being rendered, for the failure message.
	 */
	private function assertEveryHrefUsesASafeScheme( string $html, string $context ): void {
		preg_match_all( '/\shref="([^"]*)"/i', $html, $matches );

		$unsafe = array_values( array_filter(
			$matches[1],
			static fn ( string $href ): bool =>
				preg_match( '{^(?:\#|/(?!/)|https?://)}i', $href ) !== 1
		) );

		$this->assertSame(
			[],
			$unsafe,
			"Every rendered href must be a fragment, a site-relative path or an http or https "
				. "URL ($context). Anything else is either a dangerous scheme or a foreign "
				. 'origin that the allowlist was supposed to have stopped.'
		);
	}

	/**
	 * Assert that a rejected link target left no trace anywhere in the rendered page.
	 *
	 * Both the target as configured and the fragments it could be recognised by are checked,
	 * because normalisation happens before validation: a tab-interrupted scheme is stripped to a
	 * working one, so the string that must not appear is not always the string that was set.
	 *
	 * @param string $html Rendered page.
	 * @param string $target Target exactly as configured.
	 * @param string[] $forbiddenFragments Fragments that must not appear anywhere.
	 */
	private function assertHostileTargetLeftNoTrace(
		string $html,
		string $target,
		array $forbiddenFragments
	): void {
		$this->assertStringNotContainsString(
			$target,
			$html,
			'A link target outside the allowlist must not appear anywhere in the rendered page.'
		);

		foreach ( $forbiddenFragments as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$html,
				"The rendered page must not contain \"$fragment\": a rejected target must be "
					. 'suppressed rather than normalised into something a browser would still act '
					. 'on.'
			);
		}
	}

	/**
	 * Every option skin.json registers is exercised by this class's payload matrix.
	 *
	 * The single highest-value test in the file, and the reason the rest of it can be trusted.
	 * It asserts two things: that the boolean, string and link-target lists this class works
	 * from partition the manifest's options exactly, and that the providers between them touch
	 * every one of those options. Registering a ninth option therefore fails this test until
	 * the security matrix grows to cover it, which is how "every option, never a subset" stays
	 * true after this file is written.
	 */
	public function testEveryManifestConfigurationOptionIsCoveredBySecurityMatrix(): void {
		$declared = array_keys( $this->manifestConfigurationBlock() );
		sort( $declared );

		$classified = array_merge( [ self::FLAG_OPTION ], self::STRING_OPTIONS, self::LINK_OPTIONS );
		sort( $classified );
		$this->assertSame(
			$declared,
			$classified,
			'The one boolean, four string and three link-target options this suite classifies '
				. 'must be exactly the options skin.json registers. A mismatch means an option '
				. 'is either uncategorised or misspelled, and either way it is untested.'
		);

		$exercised = [];
		foreach ( self::PROVIDER_METHODS as $provider ) {
			$datasets = self::{$provider}();
			$this->assertNotSame(
				[],
				$datasets,
				"Provider $provider must yield at least one dataset; an empty provider silently "
					. 'removes an option from the matrix.'
			);

			foreach ( $datasets as $name => $dataset ) {
				$this->assertArrayHasKey(
					0,
					$dataset,
					"Dataset \"$name\" of $provider must name the option it exercises as its "
						. 'first argument, which is the invariant this completeness check reads.'
				);
				$exercised[$dataset[0]] = true;
			}
		}

		$covered = array_keys( $exercised );
		sort( $covered );
		$this->assertSame(
			$declared,
			$covered,
			'Every configuration option must be exercised by this security matrix, because rule '
				. 'R13 is satisfied only when no option is exempt. Registered but unexercised: '
				. '[' . implode( ', ', array_diff( $declared, $covered ) ) . ']. Exercised but '
				. 'not registered: [' . implode( ', ', array_diff( $covered, $declared ) ) . '].'
		);
	}

	/**
	 * The shipped announcement configuration keeps the bar off every page.
	 *
	 * The three fixed announcement values belong to the verification instance alone and must
	 * not become the skin's defaults: a wiki that merely installs this skin gets no
	 * announcement band. Nothing else in the package checks that, so it is checked here, at the
	 * declaration site and again at the render boundary, since a default is only really disabled
	 * if a page rendered under it is empty of the bar.
	 */
	public function testShippedAnnouncementConfigurationKeepsTheBarOffThePage(): void {
		$config = $this->manifestConfigurationBlock();

		$this->assertArrayHasKey( self::FLAG_OPTION, $config, 'The enable flag must be declared.' );
		$this->assertFalse(
			$config[self::FLAG_OPTION]['value'],
			'The shipped default of ' . self::FLAG_OPTION . ' must be false. The announcement '
				. 'values the verification instance fixes apply to that instance only.'
		);

		foreach (
			[
				BlitzyViewModel::OPTION_ANNOUNCE_TEXT,
				BlitzyViewModel::OPTION_ANNOUNCE_LABEL,
				BlitzyViewModel::OPTION_ANNOUNCE_LINK,
			] as $option
		) {
			$this->assertArrayHasKey( $option, $config, "$option must be declared." );
			$this->assertSame(
				'',
				$config[$option]['value'],
				"The shipped default of $option must be empty, so that no announcement content "
					. 'ships with the skin even if the flag were switched on by mistake.'
			);
		}

		$html = $this->render();
		$this->assertStringNotContainsString(
			'blitzy-announcement',
			$html,
			'A page rendered under the shipped defaults must contain no announcement markup at '
				. 'all: neither the band, nor its class hook, nor its dismiss control.'
		);
	}

	/**
	 * Four classes of injection payload, applied to each of the four string options.
	 *
	 * Each dataset carries the option, the payload as an administrator would set it, the exact
	 * markup that option's element must render, and the executable fragment that must be absent
	 * from it. The four classes are chosen because they fail differently:
	 *
	 *   - A script element is the canonical payload and the one rule R13 is written around.
	 *   - An attribute breakout carries a double quote, a single quote and a greater-than sign.
	 *     These are the characters an escaping function can be configured not to touch, and a
	 *     gap that only appears in attribute context is the realistic failure mode.
	 *   - A pre-encoded entity proves escaping happens exactly ONCE. The correct rendering of a
	 *     literal `&lt;` is `&amp;lt;`, so the reader sees the entity they typed; what must never
	 *     appear is the doubly-escaped form of the whole expectation, which every dataset also
	 *     asserts against.
	 *   - An event handler inside a tag is what turns a missed escape into code execution
	 *     without needing a script element at all.
	 *
	 * @return array<string,array>
	 */
	public static function provideStringInjectionPayloads(): array {
		$payloads = [
			'script element' => [
				'<script>alert("blitzy")</script>',
				'&lt;script&gt;alert(&quot;blitzy&quot;)&lt;/script&gt;',
				'<script',
			],
			'attribute breakout' => [
				'blitzy" onmouseover=\'alert(1)\' >',
				'blitzy&quot; onmouseover=&#039;alert(1)&#039; &gt;',
				'" onmouseover=',
			],
			'pre-encoded entity' => [
				'&lt;b&gt;bold&lt;/b&gt;',
				'&amp;lt;b&amp;gt;bold&amp;lt;/b&amp;gt;',
				'<b>',
			],
			'event handler in a tag' => [
				'<img src=x onerror=alert(1)>',
				'&lt;img src=x onerror=alert(1)&gt;',
				'<img',
			],
		];

		$datasets = [];
		foreach ( self::STRING_OPTIONS as $option ) {
			foreach ( $payloads as $payloadName => [ $payload, $escaped, $executable ] ) {
				$datasets["$option, $payloadName"] = [ $option, $payload, $escaped, $executable ];
			}
		}

		return $datasets;
	}

	/**
	 * A hostile string reaches its own element escaped, and reaches nothing else at all.
	 *
	 * The rendered value is compared exactly rather than searched for. These templates strip
	 * their own whitespace deliberately, so the element's contents are the configured value and
	 * nothing besides, and an exact comparison is what turns "the payload is escaped somewhere"
	 * into "the payload is escaped where it landed". It is also the assertion that makes this
	 * test impossible to pass vacuously: an option that stopped being read would render an empty
	 * element and fail here.
	 *
	 * @dataProvider provideStringInjectionPayloads
	 */
	public function testConfigurationStringIsEscapedAtItsRenderSite(
		string $option,
		string $payload,
		string $escaped,
		string $executableFragment
	): void {
		$html = $this->renderWithConfig( [ $option => $payload ] );
		$rendered = $this->innerHtmlOfElementWithClass( $html, self::RENDER_SITE[$option] );

		$this->assertSame(
			$escaped,
			$rendered,
			"$option must reach its element HTML-escaped exactly once. Escaping is the "
				. 'template\'s job, in its double-brace interpolation; the view model hands the '
				. 'value on raw on purpose, so a switch to the raw triple-brace form would show '
				. 'up right here.'
		);

		$this->assertStringNotContainsString(
			$payload,
			$html,
			"The payload set in $option must not appear unescaped anywhere in the page."
		);

		$this->assertStringNotContainsString(
			$executableFragment,
			$rendered,
			"The element rendering $option must not contain \"$executableFragment\": escaped, the "
				. 'payload is inert text, and if that fragment survives then it is markup again.'
		);

		$this->assertStringNotContainsString(
			htmlspecialchars( $escaped, ENT_QUOTES ),
			$html,
			"$option must not be escaped twice. Doubly-escaped output looks safe while being "
				. 'wrong: the reader sees entity soup, and a naive "it is escaped" assertion '
				. 'still passes.'
		);

		$this->assertNoEventHandlerAttribute( $html, "$option set to a hostile string" );
	}

	/**
	 * Seven classes of link target that the allowlist must refuse.
	 *
	 * Shared by the announcement and the call-to-action providers so that all three link options
	 * face an identical battery. Each dataset carries the target and the fragments that must not
	 * survive anywhere in the page, which are not always the target itself: normalisation strips
	 * control characters before validation, so an interrupted scheme has to be checked in the
	 * form it would reach a browser in as well as in the form it was configured in.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	private static function rejectedLinkTargets(): array {
		return [
			// The scheme rule R13 names explicitly.
			'javascript scheme' => [ 'javascript:alert(1)', [ 'javascript:' ] ],
			// Case variation, because a case-sensitive comparison would let this through.
			'javascript scheme in mixed case' => [
				'JaVaScRiPt:alert(1)',
				[ 'JaVaScRiPt:', 'javascript:' ],
			],
			// A second dangerous scheme, carrying a whole document.
			'data scheme carrying a document' => [
				'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
				[ 'data:text/html' ],
			],
			'vbscript scheme' => [ 'vbscript:msgbox(1)', [ 'vbscript:' ] ],
			// A foreign origin with no scheme of its own, which a scheme-only check would miss.
			'protocol-relative foreign origin' => [
				'//evil.example/landing',
				[ 'evil.example' ],
			],
			// Browsers discard control characters from a URL before acting on it, so both of
			// these reach a browser as a working javascript: scheme if they are not stripped
			// before the allowlist is consulted.
			'tab-interrupted scheme' => [ "java\tscript:alert(1)", [ 'javascript:' ] ],
			'newline-prefixed scheme' => [ "\njavascript:alert(1)", [ 'javascript:' ] ],
		];
	}

	/**
	 * The announcement bar's target, against every rejected class.
	 *
	 * @return array<string,array>
	 */
	public static function provideRejectedAnnouncementTargets(): array {
		$datasets = [];
		foreach ( self::rejectedLinkTargets() as $name => [ $target, $forbidden ] ) {
			$option = BlitzyViewModel::OPTION_ANNOUNCE_LINK;
			$datasets["$option, $name"] = [ $option, $target, $forbidden ];
		}

		return $datasets;
	}

	/**
	 * A rejected announcement target leaves the affordance with no href attribute at all.
	 *
	 * This is the strict shape of the contract, and it is asserted strictly. The view model
	 * omits the key, the enclosing Mustache section goes falsy, and the affordance renders as a
	 * span. An empty href would be a link to the page it sits on and a placeholder fragment
	 * would be a link to nowhere; both are worse than the absence, and both would pass a laxer
	 * assertion than this one.
	 *
	 * The bar itself is asserted to survive: rejecting a target must cost the announcement its
	 * link, not its message. That the option is read at all is proved by
	 * ::testAcceptedLinkTargetReachesItsRenderedHref, without which an unread option would
	 * satisfy an absence test for the wrong reason.
	 *
	 * @dataProvider provideRejectedAnnouncementTargets
	 */
	public function testRejectedAnnouncementTargetEmitsNoHrefAtAll(
		string $option,
		string $target,
		array $forbiddenFragments
	): void {
		$html = $this->renderWithConfig( [ $option => $target ] );
		$this->assertHostileTargetLeftNoTrace( $html, $target, $forbiddenFragments );

		$startTag = $this->startTagWithClass( $html, self::CLASS_ANNOUNCEMENT_ACTION );
		$this->assertNull(
			$this->attributeValue( $startTag, 'href' ),
			'A rejected announcement target must leave no href attribute behind. Rendered start '
				. "tag: $startTag"
		);

		$this->assertStringContainsString(
			'id="blitzy-announcement"',
			$html,
			'The announcement band must still render: a rejected target costs it its link, not '
				. 'its message.'
		);
		$this->assertSame(
			self::BENIGN_SETTINGS[BlitzyViewModel::OPTION_ANNOUNCE_LABEL],
			$this->innerHtmlOfElementWithClass( $html, self::CLASS_ANNOUNCEMENT_ACTION ),
			'The affordance must keep its label, so the bar stays legible without being '
				. 'clickable.'
		);

		$this->assertNoEventHandlerAttribute( $html, "$option set to a rejected target" );
		$this->assertEveryHrefUsesASafeScheme( $html, "$option set to a rejected target" );
	}

	/**
	 * Both calls to action, against every rejected class.
	 *
	 * @return array<string,array>
	 */
	public static function provideRejectedCallToActionTargets(): array {
		$options = [
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK,
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK,
		];

		$datasets = [];
		foreach ( $options as $option ) {
			foreach ( self::rejectedLinkTargets() as $name => [ $target, $forbidden ] ) {
				$datasets["$option, $name"] = [ $option, $target, $forbidden ];
			}
		}

		return $datasets;
	}

	/**
	 * A rejected call-to-action target never reaches the rendered href.
	 *
	 * The shape here differs from the announcement's, and deliberately so: the view model
	 * resolves a call to action's target to a core special page when configuration offers none
	 * it can use, so the anchor keeps an href and what has to be proven is that the href is the
	 * safe fallback rather than the hostile target. Asserting the attribute is absent would be
	 * asserting a different implementation than the one this skin has.
	 *
	 * A site-relative path is required rather than merely a non-hostile one, because the
	 * fallback is a core special page and because a foreign origin in the chrome would be a
	 * second failure on top of the first.
	 *
	 * @dataProvider provideRejectedCallToActionTargets
	 */
	public function testRejectedCallToActionTargetNeverReachesTheRenderedHref(
		string $option,
		string $target,
		array $forbiddenFragments
	): void {
		$html = $this->renderWithConfig( [ $option => $target ] );
		$this->assertHostileTargetLeftNoTrace( $html, $target, $forbiddenFragments );

		$hook = self::RENDER_SITE[$option];
		$startTag = $this->startTagWithClass( $html, $hook );
		$href = $this->attributeValue( $startTag, 'href' );

		$this->assertNotNull(
			$href,
			"The call to action must still be a usable link when $option is rejected: its target "
				. "falls back to a core special page. Rendered start tag: $startTag"
		);
		$this->assertMatchesRegularExpression(
			'{^/(?!/)}',
			$href,
			"The href rendered for a rejected $option must be a site-relative path with a single "
				. "leading slash, which is what the fallback produces. Rendered: $href"
		);
		foreach ( $forbiddenFragments as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$href,
				"The href rendered for a rejected $option must not contain \"$fragment\"."
			);
		}

		$this->assertNoEventHandlerAttribute( $html, "$option set to a rejected target" );
		$this->assertEveryHrefUsesASafeScheme( $html, "$option set to a rejected target" );
	}

	/**
	 * Allowlisted targets for all three link options, including one that tests attribute context.
	 *
	 * These are the positive controls, and they are what keeps the rejection tests honest: a
	 * validator that answered null for everything would satisfy every absence assertion in this
	 * file and fail here instead. The fourth target is accepted by the allowlist and still
	 * carries a double quote, so it is the case that proves escaping applies inside an attribute
	 * value and not only inside a text node.
	 *
	 * @return array<string,array>
	 */
	public static function provideAcceptedLinkTargets(): array {
		$targets = [
			'https url' => [ 'https://example.org/page', 'https://example.org/page' ],
			'http url' => [ 'http://example.org/page', 'http://example.org/page' ],
			'site-relative path' => [ '/wiki/Ada_Lovelace', '/wiki/Ada_Lovelace' ],
			'https url carrying a double quote' => [
				'https://example.org/page?q="onmouseover="alert(1)',
				'https://example.org/page?q=&quot;onmouseover=&quot;alert(1)',
			],
		];

		$datasets = [];
		foreach ( self::LINK_OPTIONS as $option ) {
			foreach ( $targets as $name => [ $target, $expected ] ) {
				$datasets["$option, $name"] = [ $option, $target, $expected ];
			}
		}

		return $datasets;
	}

	/**
	 * An allowlisted target reaches the rendered href, escaped for attribute context.
	 *
	 * @dataProvider provideAcceptedLinkTargets
	 */
	public function testAcceptedLinkTargetReachesItsRenderedHref(
		string $option,
		string $target,
		string $expectedAttributeValue
	): void {
		$html = $this->renderWithConfig( [ $option => $target ] );
		$startTag = $this->startTagWithClass( $html, self::RENDER_SITE[$option] );

		$this->assertSame(
			$expectedAttributeValue,
			$this->attributeValue( $startTag, 'href' ),
			"An allowlisted $option must reach the rendered href, escaped for attribute context "
				. 'and otherwise unaltered. This is the positive control the rejection tests '
				. 'depend on: without it, an option that was never read would satisfy them.'
		);

		$this->assertNoEventHandlerAttribute( $html, "$option set to an allowlisted target" );
		$this->assertEveryHrefUsesASafeScheme( $html, "$option set to an allowlisted target" );
	}

	/**
	 * The four states that decide whether the announcement band exists.
	 *
	 * Two of them are edge cases rather than padding. An enabled bar with no text would be an
	 * empty coloured band, and an enabled bar whose text is nothing but whitespace and control
	 * characters is the same thing wearing a disguise, because normalisation strips those
	 * characters before the emptiness is tested.
	 *
	 * @return array<string,array>
	 */
	public static function provideAnnouncementPresenceStates(): array {
		$states = [
			'enabled with text renders the band' => [ true, 'Announcement text', true ],
			'disabled with text renders nothing' => [ false, 'Announcement text', false ],
			'enabled with empty text renders nothing' => [ true, '', false ],
			'enabled with only whitespace and control characters renders nothing' => [
				true, " \t\r\n ", false,
			],
		];

		// Named the way the other four providers name their datasets, option first, so that a
		// failure identifies the option and the state without the report having to be read twice.
		$datasets = [];
		foreach ( $states as $name => [ $enabled, $text, $expectRendered ] ) {
			$option = self::FLAG_OPTION;
			$datasets["$option, $name"] = [ $option, $enabled, $text, $expectRendered ];
		}

		return $datasets;
	}

	/**
	 * A disabled announcement is absent from the document, not hidden inside it.
	 *
	 * The absence is asserted on the class hook as well as the id, and the page is asserted to
	 * carry no display suppression either, because "absent from the DOM flow" is an explicit
	 * compliance point rather than a stylistic preference: a band left in the document with
	 * `display: none` is still in the accessibility tree, still in the byte-compared captures and
	 * still readable by anything that ignores CSS.
	 *
	 * Both branches also assert that the navigation card is unaffected, so a bug that removed
	 * the whole chrome could not read as a passing absence.
	 *
	 * @dataProvider provideAnnouncementPresenceStates
	 */
	public function testAnnouncementEnableOptionGovernsPresenceInTheDocument(
		string $option,
		bool $enabled,
		string $text,
		bool $expectRendered
	): void {
		$html = $this->renderWithConfig( [
			$option => $enabled,
			BlitzyViewModel::OPTION_ANNOUNCE_TEXT => $text,
		] );

		if ( $expectRendered ) {
			$this->assertStringContainsString(
				'id="blitzy-announcement"',
				$html,
				'An enabled announcement with text must render its band.'
			);
			$this->assertSame(
				$text,
				$this->innerHtmlOfElementWithClass( $html, self::CLASS_ANNOUNCEMENT_TEXT ),
				'The band must carry the configured text, so this test cannot pass on a band '
					. 'that renders empty.'
			);
		} else {
			$this->assertStringNotContainsString(
				'blitzy-announcement',
				$html,
				'A suppressed announcement must be absent from the document entirely: no id, no '
					. 'class hook and no dismiss control.'
			);
			foreach ( [ 'display:none', 'display: none' ] as $suppression ) {
				$this->assertStringNotContainsString(
					$suppression,
					$html,
					'A suppressed announcement must not be hidden with a display rule either. '
						. 'Absence from the document is the requirement; hiding is not compliant.'
				);
			}
		}

		$this->assertStringContainsString(
			'blitzy-nav-card',
			$html,
			'The navigation card must render in both states, so that an absent announcement is '
				. 'read as an absent announcement and not as an absent page.'
		);
	}

	/**
	 * Every option hostile at once: the page still renders and nothing dangerous survives.
	 *
	 * Per-option tests cannot catch an interaction, and this configuration is the interaction:
	 * two payload classes in the chrome's text, two more in its labels, and all three link
	 * targets rejected for different reasons at the same time. The page is asserted to keep the
	 * contract core requires of it and its injected body content, so a render that "passed" by
	 * collapsing would fail here.
	 */
	public function testEveryConfigurationOptionHostileAtOnce(): void {
		$html = $this->renderWithConfig( [
			self::FLAG_OPTION => true,
			BlitzyViewModel::OPTION_ANNOUNCE_TEXT => '<script>alert("text")</script>',
			BlitzyViewModel::OPTION_ANNOUNCE_LABEL => 'label" onmouseover=\'alert(1)\' >',
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => 'javascript:alert(1)',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => '<img src=x onerror=alert(1)>',
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK =>
				'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => '&lt;b&gt;bold&lt;/b&gt;',
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => '//evil.example/landing',
		] );

		foreach ( [ 'id="bodyContent"', '<footer id="footer"', 'id="blitzy-test-body"' ] as $marker ) {
			$this->assertStringContainsString(
				$marker,
				$html,
				"A page whose every configuration option is hostile must still render: $marker "
					. 'is missing, so the payloads cost the page its structure rather than being '
					. 'neutralised.'
			);
		}

		$forbidden = [ '<script', 'javascript:', 'data:text/html', 'vbscript:', 'evil.example', '<b>' ];
		foreach ( $forbidden as $fragment ) {
			$this->assertStringNotContainsString(
				$fragment,
				$html,
				"With every option hostile, the page must not contain \"$fragment\" anywhere."
			);
		}

		$this->assertNoEventHandlerAttribute( $html, 'every configuration option hostile at once' );
		$this->assertEveryHrefUsesASafeScheme( $html, 'every configuration option hostile at once' );

		$escapedAtSite = [
			self::CLASS_ANNOUNCEMENT_TEXT => '&lt;script&gt;alert(&quot;text&quot;)&lt;/script&gt;',
			self::CLASS_ANNOUNCEMENT_ACTION => 'label&quot; onmouseover=&#039;alert(1)&#039; &gt;',
			self::CLASS_ACTION_PRIMARY => '&lt;img src=x onerror=alert(1)&gt;',
			self::CLASS_ACTION_SECONDARY => '&amp;lt;b&amp;gt;bold&amp;lt;/b&amp;gt;',
		];
		foreach ( $escapedAtSite as $hook => $expected ) {
			$this->assertSame(
				$expected,
				$this->innerHtmlOfElementWithClass( $html, $hook ),
				"Each hostile string must still reach its own element, escaped once ($hook). "
					. 'Four payloads set at the same time must not shift, merge or lose their '
					. 'escaping.'
			);
		}

		$this->assertNull(
			$this->attributeValue(
				$this->startTagWithClass( $html, self::CLASS_ANNOUNCEMENT_ACTION ),
				'href'
			),
			'The rejected announcement target must be dropped even when every other option is '
				. 'hostile too.'
		);

		foreach ( [ self::CLASS_ACTION_PRIMARY, self::CLASS_ACTION_SECONDARY ] as $hook ) {
			$href = $this->attributeValue( $this->startTagWithClass( $html, $hook ), 'href' );
			$this->assertNotNull( $href, "The $hook call to action must keep a usable target." );
			$this->assertMatchesRegularExpression(
				'{^/(?!/)}',
				$href,
				"The $hook call to action must fall back to a site-relative core target rather "
					. "than emitting the rejected one. Rendered: $href"
			);
		}
	}
}
