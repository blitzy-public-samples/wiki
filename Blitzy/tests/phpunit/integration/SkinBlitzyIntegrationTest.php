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

use DifferenceEngine;
use MediaWiki\Context\RequestContext;
use MediaWiki\Skins\Blitzy\BlitzyViewModel;
use MediaWiki\Skins\Blitzy\SkinBlitzy;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use User;

/**
 * Integration tests for the Blitzy skin, asserted against pages this suite really renders.
 *
 * WHAT THIS SUITE IS FOR
 * =====================
 * It is the server-render half of Gate 9. Gate 9 has four obligations and this file owns one
 * and a half of them: every Mustache partial reachable from the root template must have its
 * rendered output asserted present, and every piece of chrome the skin owns must have a
 * rendered instance asserted by ELEMENT PRESENCE before any computed-style assertion runs
 * anywhere. The other two obligations, that every declared ResourceLoader module is delivered
 * and that every skinStyles override actually applies, are computed-style claims and belong to
 * tests/playwright/fidelity.spec.ts. Nothing here asserts a CSS value, a stylesheet, or the
 * visual effect of a class, because a PHP render cannot honestly observe any of the three.
 *
 * The ordering matters more than it looks. A downstream fidelity assertion must never be able
 * to pass vacuously against an element that was never rendered, so the presence assertions
 * below are what make the computed-style assertions elsewhere meaningful rather than merely
 * green.
 *
 * It is also the PHP partner of Gate 8's first check, the live smoke test over page view,
 * edit, save and diff. harness/verify.sh runs the shell half against a real HTTP server; this
 * file runs the half a PHP render can reach, which is: a real page created and then edited
 * through MediaWiki's own save path, a real core diff between the two revisions it produced,
 * and the skin's chrome contract asserted intact on each surface. Rendering the history and
 * diff PAGER internals is browser work and is deliberately left to Playwright and to
 * skinStyles; asserting core's diff or history markup here would test core, not the skin.
 *
 * Gate 8 forbids substituting test pass rates or coverage percentages for any of its four
 * checks, so DELIVERY.md must report this class's result as the Gate 8 check-1 PHP evidence
 * SEPARATELY from the PHPUnit pass rate and the rule R14 coverage figure. The three are
 * different claims and conflating them would let a green suite stand in for a check nobody ran.
 *
 * WHAT THIS SUITE IS NOT
 * ======================
 * It does not satisfy Gate 1. Gate 1 is the end-to-end boundary and is evidenced only by the
 * committed PNGs of a real imported Wikipedia article, captures 1 and 2 from
 * tests/playwright/capture.spec.ts; unit and integration tests do not satisfy it, and no PHP
 * test in this package should ever be cited as if they did.
 *
 * It is not rule R2's verification site either. R2 is verified by
 * tests/playwright/domContract.spec.ts, which asserts the preserved selectors on a page
 * genuinely fetched over HTTP. The selector assertions here are a COMPLEMENT to that: they
 * catch a dropped contract one layer earlier, in the template rather than in the response.
 * Two consequences of R2's own scope statement still bind this file. R2 explicitly permits new
 * Blitzy classes alongside core's, so no assertion here requires the ABSENCE of a Blitzy class
 * from a core-contract element. And every portlet class assertion is containment-based rather
 * than an equality, because MediaWiki\Skin\Components\SkinComponentMenu appends ' emptyPortlet'
 * to the class of a menu with nothing in it: an equality would be wrong rather than merely
 * brittle, and it is measurably wrong here, since three of the four portlets the personal menu
 * renders arrive empty on a default installation.
 *
 * THE DATABASE POSTURE, STATED ONCE
 * ================================
 * This class is DB-backed and declares `@group Database`. That is a whole-file decision, not a
 * per-test one: ::getTestUser(), ::getTestSysop(), ::getExistingTestPage(), ::editPage() and
 * ::deletePage() each throw a LogicException rather than skipping when the group is absent, so
 * a class that used any of them without it would fail on construction of the fixture.
 *
 * The group buys four things a DB-free arrangement cannot have, and each one is load bearing
 * for an obligation above rather than a convenience:
 *
 *   - Real parser output, so `data-toc` is populated by MediaWiki's own parser from real `==`
 *     headings. That is the only way includes/templates/TableOfContents.mustache renders at
 *     all, and building a Wikimedia\Parsoid\Core\TOCData by hand would assert the shape of a
 *     hand-built object instead.
 *   - A real registered user, so core fills the `actions` bucket and `#p-cactions` exists.
 *     Measured: core supplies an anonymous viewer NO actions at all, so that preserved
 *     identifier is unreachable without an account.
 *   - Two real revisions saved through MediaWiki's save path, so Gate 8's diff is a real
 *     DifferenceEngine diff between real revisions.
 *   - A real deletion, so core's undelete link is non-empty and `#contentSub2`, which
 *     ContentBody.mustache guards on it, is genuinely rendered rather than assumed.
 *
 * ::clearHooks() is deliberately never called. Vector's integration test calls it, and doing so
 * here would silently unregister the manifest-declared MediaWiki\Skins\Blitzy\Hooks\BlitzyHooks
 * handlers. Nothing in this file needs them cleared, and Gate 13 asserts them through a real
 * page render in BlitzyHooksTest, so clearing them would be a landmine rather than a tidy-up.
 *
 * WHAT IS NOT ASSERTED, AND WHY
 * =============================
 * Two template-data key names are avoided on purpose. `data-rendered-with` is not registered by
 * MediaWiki\Skin\Components\SkinComponentRegistry on either release in the supported range,
 * measured on the 1.47 tree this repository checks out as well as absent from the 1.43 line the
 * harness pins; and the `data-portlets.data-footer-*` shape does not exist either, the footer
 * arriving as `data-footer` with `data-info`, `data-places` and `data-icons`. The footer is
 * therefore asserted through its rendered DOM, which is what SiteFooter.mustache was authored
 * against.
 *
 * The manifest's `menus` argument is also never second-guessed here. skin.json declares
 * `user-menu` and not `personal`, and MediaWiki\Skin\SkinTemplate emits a deprecation for the
 * latter; with failOnWarning set, a test that asked for the deprecated menu would fail the
 * build. `#p-personal` survives regardless, because SkinComponentMenu renames `user-menu` to
 * `personal` when it builds the portlet, which is why the assertions below name the rendered
 * identifier rather than the manifest key.
 *
 * @group Blitzy
 * @group Skins
 * @group Database
 * @covers \MediaWiki\Skins\Blitzy\SkinBlitzy
 */
class SkinBlitzyIntegrationTest extends MediaWikiIntegrationTestCase {

	/**
	 * The name ValidSkinNames registers the skin under, and the value $wgDefaultSkin takes.
	 */
	private const SKIN_NAME = 'blitzy';

	/**
	 * Title of the article every render in this suite is built from.
	 *
	 * A fixed title rather than a generated one, so that a failure message names the same page
	 * on every run and so that two renders inside one test are unambiguously the same surface.
	 */
	private const ARTICLE_TITLE = 'Blitzy integration article';

	/**
	 * Wikitext for that article.
	 *
	 * Every element here exists to make one partial or one preserved contract reachable, and
	 * nothing here is decoration:
	 *
	 *   two `==` headings and one `===`  core's parser publishes TOCData, which is what makes
	 *                                   `data-toc` non-empty and TableOfContents.mustache
	 *                                   render, including its recursive nested branch
	 *   the category link                core's ::getCategories() emits `#catlinks`, which is
	 *                                   what makes CategoryFooter.mustache render
	 *   the internal link                exercises the link pipeline that populates LinkCache
	 *                                   during a render rather than stubbing around it
	 *   the wikitable                    the treatment the skin's Tables.less owns, present so
	 *                                   the rendered body is representative of a real article
	 */
	private const ARTICLE_WIKITEXT = "Prose introducing the [[Main Page|project]].\n\n"
		. "== First section ==\nProse in the first section.\n\n"
		. "=== Nested section ===\nProse one level deeper.\n\n"
		. "== Second section ==\n"
		. "{| class=\"wikitable\"\n! Column\n|-\n| Cell\n|}\n\n"
		. "[[Category:Blitzy integration fixtures]]\n";

	/**
	 * Announcement values, and both call-to-action pairs, exactly as the verification instance
	 * configures them.
	 *
	 * harness/LocalSettings.template.php applies the same announcement strings from the
	 * BLITZY_ANNOUNCE_* environment variables so that the bar appears in every one of the
	 * twenty-one captures, which is why these are the values used here rather than invented
	 * ones: the surface this suite asserts is the surface the captures photograph.
	 *
	 * Both call-to-action link targets are configured, and that is load bearing for the
	 * anonymous-versus-registered test at the end of this file. BlitzyViewModel resolves a
	 * fallback special page ONLY when configuration supplies no target of its own, and that
	 * fallback is the skin's single identity-dependent value: Special:CreateAccount for a
	 * visitor and Special:Watchlist for an account. Configuring both targets removes the
	 * fallback from the picture entirely, which is what allows the two renders to be compared
	 * region by region instead of around a documented exception.
	 */
	private const ANNOUNCEMENT_TEXT = 'State of Wiki Engineering — Read the Notes';
	private const ANNOUNCEMENT_LABEL = 'Read Now';
	private const ANNOUNCEMENT_LINK = '/wiki/Special:RecentChanges';
	private const PRIMARY_ACTION_LABEL = 'Start building';
	private const PRIMARY_ACTION_LINK = '/wiki/Special:NewPages';
	private const SECONDARY_ACTION_LABEL = 'Talk to an expert';
	private const SECONDARY_ACTION_LINK = '/wiki/Special:Random';

	/**
	 * Every template-data key MediaWiki\Skin\SkinMustache::getTemplateData() sets by name,
	 * plus the two MediaWiki\Skin\SkinTemplate contributes.
	 *
	 * This is the LEFT operand of the union SkinBlitzy::getTemplateData() performs. PHP's `+`
	 * keeps its left operand on a key collision, so core is meant to win every one of these,
	 * and the list is here so that the merge can be asserted against core's whole contract
	 * rather than against a sample of it.
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
		'data-portlets',
		'data-portlets-sidebar',
	];

	/**
	 * Prefix core gives every message key it folds in from the manifest's `messages` argument.
	 */
	private const CORE_MESSAGE_KEY_PREFIX = 'msg-';

	/**
	 * Wikitext for the two revisions the diff is taken between, and the one word that changes.
	 *
	 * A single distinctive word changes between them, and it changes as a whole token. That is
	 * deliberate: MediaWiki's diff marks changes at word level and wraps the differing words in
	 * their own elements, so a change spanning part of a word would leave neither spelling
	 * contiguous in the HTML and neither would be findable. With whole tokens, the presence of
	 * both words in the rendered page is proof that a real diff between two real revisions
	 * reached it, established without naming one core diff class.
	 */
	private const DIFF_OLD_WIKITEXT = "Prose recording the word riverbank for the diff.\n";
	private const DIFF_NEW_WIKITEXT = "Prose recording the word coastline for the diff.\n";
	private const DIFF_OLD_TOKEN = 'riverbank';
	private const DIFF_NEW_TOKEN = 'coastline';

	/**
	 * The chrome the skin puts on every page, whatever the page is and whatever action produced
	 * it.
	 *
	 * This is the contract asserted across actions and across authentication states, so it holds
	 * only markers that depend on neither. Nothing content-derived belongs here: the table of
	 * contents needs headings, the category footer needs categories and the tab groups need
	 * navigation entries core will only supply in some situations, so each of those is asserted
	 * where its precondition is arranged instead.
	 */
	private const CHROME_MARKERS = [
		'mw-jump-link',
		'blitzy-nav-card',
		'id="blitzy-nav-target"',
		'id="p-personal"',
		'blitzy-cta--outline',
		'blitzy-cta--gradient',
		'blitzy-content-header',
		'<main id="content" class="mw-body blitzy-content-body">',
		'id="bodyContent"',
		'id="contentSub"',
		'id="footer"',
		'blitzy-footer__row',
	];

	/**
	 * Regions of the page that must render identically for a visitor and for an account, with the
	 * pattern that selects each.
	 *
	 * Every one is a region the skin composes, and between them they cover each of its structural
	 * areas: the navigation card's own affordances, the navigation MediaWiki supplies inside it,
	 * the content header, the table of contents beside the content, and the footer. Each pattern
	 * closes on the first matching end tag, which is safe here because none of these regions
	 * nests an element of the same kind.
	 *
	 * @var array<string,string>
	 */
	private const IDENTITY_INDEPENDENT_REGIONS = [
		'call-to-action cluster' => '/<div class="blitzy-nav-card__cta">.*?<\/div>/s',
		'navigation portlet' => '/<div\b[^>]*\bid="p-navigation".*?<\/div>/s',
		'content header title' => '/<div class="blitzy-content-header__title">.*?<\/div>/s',
		'table of contents card' => '/<nav class="blitzy-toc".*?<\/nav>/s',
		'footer places row' => '/<ul\b[^>]*\bid="footer-places".*?<\/ul>/s',
	];

	/**
	 * Pattern selecting the personal menu's portlet container, the one region that is expected to
	 * differ with identity.
	 */
	private const PERSONAL_PORTLET_REGION = '/<div\b[^>]*\bid="p-personal".*?<\/div>/s';

	/**
	 * The prefixes the template-data naming contract reserves, and what each one promises.
	 *
	 * `array-` is a list, `is-` is a boolean and `data-` is an object. `html-` is raw HTML and
	 * is deliberately absent: BlitzyViewModel must never publish a configuration-derived value
	 * under it, because that prefix is what tells a template to interpolate a value unescaped.
	 */
	private const NAMING_CONTRACT_PREFIXES = [
		'array-' => 'array',
		'is-' => 'boolean',
		'data-' => 'array',
	];

	/**
	 * Configure the skin's eight options before every test.
	 *
	 * ::overrideConfigValue() and ::overrideConfigValues() are the only configuration mechanism
	 * used anywhere in this file. Writing a settings file would put a change outside the skin's
	 * own directory, which rule R1 forbids, and would not be undone between tests; these
	 * helpers are reverted by the base class in teardown.
	 *
	 * The names come from BlitzyViewModel's public constants rather than from string literals,
	 * so this fixture cannot drift away from skin.json's `config` block without a fatal error
	 * pointing straight at the drift. This is scaffolding for a render and nothing more: rule
	 * R13's per-option enumeration, with an injection payload in every string and a javascript:
	 * URL in every link target, belongs to BlitzyConfigSecurityTest and is not repeated here.
	 *
	 * The announcement is ENABLED, because the bar is part of the chrome the captures
	 * photograph and because a disabled bar renders nothing at all, which would leave one of the
	 * nine partials unreachable. The one test that needs it off turns it off for itself.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			BlitzyViewModel::OPTION_ANNOUNCE_ENABLE => true,
			BlitzyViewModel::OPTION_ANNOUNCE_TEXT => self::ANNOUNCEMENT_TEXT,
			BlitzyViewModel::OPTION_ANNOUNCE_LABEL => self::ANNOUNCEMENT_LABEL,
			BlitzyViewModel::OPTION_ANNOUNCE_LINK => self::ANNOUNCEMENT_LINK,
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LABEL => self::PRIMARY_ACTION_LABEL,
			BlitzyViewModel::OPTION_PRIMARY_ACTION_LINK => self::PRIMARY_ACTION_LINK,
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LABEL => self::SECONDARY_ACTION_LABEL,
			BlitzyViewModel::OPTION_SECONDARY_ACTION_LINK => self::SECONDARY_ACTION_LINK,
		] );
	}

	/**
	 * Build the skin the way a real page view builds it.
	 *
	 * Going through SkinFactory rather than calling the constructor is the point. MediaWiki\Skin
	 * \SkinFactory::makeSkin() throws a SkinException for a name it has no builder for, and
	 * assembles the constructor arguments from the manifest through ObjectFactory, so a call
	 * that returns at all has already proved three things: ValidSkinNames.blitzy resolved, the
	 * MainConfig and UrlUtils services named by the manifest were injectable, and their order
	 * agrees with the constructor signature. A disagreement there passes the options array where
	 * a service is expected and fails with a TypeError instead of a quiet misconfiguration.
	 *
	 * @return SkinBlitzy
	 */
	private function newSkin(): SkinBlitzy {
		$skin = $this->getServiceContainer()->getSkinFactory()->makeSkin( self::SKIN_NAME );

		$this->assertInstanceOf(
			SkinBlitzy::class,
			$skin,
			'SkinFactory must build the Blitzy skin class for the registered name.'
		);

		return $skin;
	}

	/**
	 * Build a request context for one render.
	 *
	 * The call order below is not stylistic. MediaWiki\Context\RequestContext::setTitle() clears
	 * the cached action name, and clearing it after anything has already read it raises a PHP
	 * notice; phpunit.xml.dist sets convertNoticesToExceptions, so that notice would fail the
	 * test rather than appear in a log. Title first, then identity, then language, then action
	 * name is the order that never trips it, and OutputPage::setTitle() is never called at all
	 * for the same reason, since it delegates straight back to this object.
	 *
	 * @param Title $title Page being rendered.
	 * @param User|null $user Viewing user, or null to render as an anonymous visitor.
	 * @param string $action Action name, as MediaWiki would have resolved it from the request.
	 * @return RequestContext
	 */
	private function newContext( Title $title, ?User $user, string $action ): RequestContext {
		$context = new RequestContext();
		$context->setTitle( $title );
		if ( $user !== null ) {
			$context->setUser( $user );
		}
		$context->setLanguage( 'en' );
		$context->setActionName( $action );

		return $context;
	}

	/**
	 * Build a skin with its context and output populated, ready to render.
	 *
	 * MediaWiki\Skin\SkinMustache::generateHTML() is the entry point a real request uses: it
	 * sets up the template context, resolves the root template from the manifest's `template`
	 * argument and processes it against ::getTemplateData(). Going through it, from a skin
	 * prepared here, is what makes every assertion in this file an assertion about the real
	 * render path rather than about a template rendered in isolation with hand-built data. The
	 * skin is returned rather than the HTML so that the same preparation serves both the
	 * template-data tests and the rendered-output tests, and neither has to arrange its own.
	 *
	 * Body content is injected with OutputPage::addWikiTextAsContent(), which parses the
	 * wikitext through MediaWiki's own parser and carries the whole of its metadata into the
	 * output: the section list that becomes `data-toc`, and the category links that become
	 * `html-categories`. OutputPage::addParserOutput() would do the same job but is not
	 * portable across the supported range, having gained a required ParserOptions parameter in
	 * 1.44 while skin.json declares support from 1.43.
	 *
	 * @param Title $title Page being rendered.
	 * @param User|null $user Viewing user, or null for an anonymous visitor.
	 * @param string $action Action name for the render.
	 * @param string $wikitext Body wikitext to parse into the output, or the empty string to
	 *   render the chrome around an empty body.
	 * @return SkinBlitzy Skin with its context and output populated, ready to render or to be
	 *   asked for its template data.
	 */
	private function prepareSkin(
		Title $title,
		?User $user,
		string $action,
		string $wikitext = ''
	): SkinBlitzy {
		$context = $this->newContext( $title, $user, $action );
		$skin = $this->newSkin();
		$skin->setContext( $context );

		$out = $context->getOutput();
		$out->setPageTitle( $title->getPrefixedText() );
		if ( $wikitext !== '' ) {
			$out->addWikiTextAsContent( $wikitext );
		}

		return $skin;
	}

	/**
	 * Render one page through the skin and return the HTML it emitted.
	 *
	 * @param Title $title Page being rendered.
	 * @param User|null $user Viewing user, or null for an anonymous visitor.
	 * @param string $action Action name for the render.
	 * @param string $wikitext Body wikitext to parse into the output.
	 * @return string HTML the skin emitted for the body of the document.
	 */
	private function renderPage(
		Title $title,
		?User $user,
		string $action,
		string $wikitext = ''
	): string {
		$html = $this->prepareSkin( $title, $user, $action, $wikitext )->generateHTML();

		$this->assertNotSame( '', $html, 'The skin must emit HTML for a page it renders.' );

		return $html;
	}

	/**
	 * Create the fixture article and return its title.
	 *
	 * ::getExistingTestPage() creates the page, and the edit that follows replaces its content
	 * with the wikitext this suite needs, so the page arrives with real content and a real
	 * history. The status is asserted rather than assumed: a failed save would otherwise surface
	 * much later as a missing table of contents or a missing category footer, in a test whose
	 * name points at the template instead of at the fixture.
	 *
	 * @return Title
	 */
	private function newArticle(): Title {
		$page = $this->getExistingTestPage( self::ARTICLE_TITLE );

		$this->assertStatusGood(
			$this->editPage( $page, self::ARTICLE_WIKITEXT, 'Blitzy integration fixture' ),
			'The fixture article must save before any render can be asserted against it.'
		);

		return $page->getTitle();
	}

	/**
	 * Render the fixture article as a registered user.
	 *
	 * The render every structural assertion in this file is made against, because it is the one
	 * that reaches all nine partials at once. Registered rather than anonymous for one measured
	 * reason: core fills the `actions` navigation bucket only for an account, so `#p-cactions`,
	 * a preserved identifier gadgets rely on, does not exist on an anonymous render of the same
	 * page at all.
	 *
	 * @return string
	 */
	private function renderArticleAsRegisteredUser(): string {
		return $this->renderPage(
			$this->newArticle(),
			$this->getTestUser()->getUser(),
			'view',
			self::ARTICLE_WIKITEXT
		);
	}

	/**
	 * Extract one region of the rendered page, failing when it is not there.
	 *
	 * A region is matched rather than a bare substring so that two renders can be compared
	 * element for element. Every pattern used below closes on the first matching end tag, which
	 * is safe for exactly the regions it is applied to: none of them nests an element of the
	 * same kind.
	 *
	 * The absent case FAILS instead of returning an empty string, which is the discipline rule
	 * R11 states for computed-style assertions applied here to presence: a comparison against a
	 * region that was never rendered would otherwise report equality between two nothings.
	 *
	 * @param string $pattern Regular expression selecting the region.
	 * @param string $html Rendered page.
	 * @param string $label Human-readable region name, used in the failure message.
	 * @return string The matched region.
	 */
	private function extractRegion( string $pattern, string $html, string $label ): string {
		$matches = [];

		$this->assertSame(
			1,
			preg_match( $pattern, $html, $matches ),
			"The rendered page must contain the $label region."
		);

		return $matches[0];
	}

	/**
	 * Template data for the fixture article, as a registered user sees it.
	 *
	 * @return array
	 */
	private function templateDataForArticle(): array {
		return $this->prepareSkin(
			$this->newArticle(),
			$this->getTestUser()->getUser(),
			'view',
			self::ARTICLE_WIKITEXT
		)->getTemplateData();
	}

	/**
	 * The registered name resolves to the skin class, and the skin answers to that name.
	 *
	 * One assertion covering three separate pieces of wiring, which is why it is worth a test of
	 * its own: skin.json's ValidSkinNames entry was read, ObjectFactory could supply MainConfig
	 * and UrlUtils in the order the constructor declares them, and the `name` argument survived
	 * into the instance. The two-line installation the skin promises,
	 * `wfLoadSkin( 'Blitzy' ); $wgDefaultSkin = 'blitzy';`, is exactly this lookup, so a failure
	 * here means the skin cannot be selected at all.
	 */
	public function testSkinFactoryBuildsTheRegisteredSkin(): void {
		$this->assertSame(
			self::SKIN_NAME,
			$this->newSkin()->getSkinName(),
			'The skin built by SkinFactory must report the name skin.json registers it under.'
		);
	}

	/**
	 * Every key core puts in the template data survives the skin's merge.
	 *
	 * SkinBlitzy::getTemplateData() returns `parent::getTemplateData() + $viewModel->build( ... )`
	 * and this is the left operand arriving intact. It matters beyond tidiness: `data-portlets`
	 * carries the preserved portlet identifiers, `html-body-content` carries core's
	 * `#mw-content-text` wrapper and print footer, and `html-categories` carries `#catlinks`, so
	 * a key lost here is a rule R2 contract lost with it.
	 */
	public function testTemplateDataKeepsEveryCoreKey(): void {
		$data = $this->templateDataForArticle();

		foreach ( self::CORE_TEMPLATE_DATA_KEYS as $key ) {
			$this->assertArrayHasKey(
				$key,
				$data,
				"Core's `$key` template-data key must survive the skin's merge."
			);
		}
	}

	/**
	 * The skin publishes its own four keys, and the announcement key only when there is a bar.
	 *
	 * These four are the whole of the right operand of the merge and the whole of what the
	 * Blitzy partials consume beyond core's data. The announcement key is the conditional one:
	 * BlitzyViewModel omits it entirely rather than publishing an empty object, which is what
	 * lets the enclosing Mustache section keep a disabled bar out of the document instead of
	 * hiding it. Configuration enables it for this suite, so it is present here, and the
	 * disabled case is asserted separately further down.
	 */
	public function testTemplateDataPublishesTheSkinsOwnKeys(): void {
		$data = $this->templateDataForArticle();

		$this->assertArrayHasKey(
			BlitzyViewModel::KEY_ACTION_PRIMARY,
			$data,
			'The primary call to action must reach the template data.'
		);
		$this->assertArrayHasKey(
			BlitzyViewModel::KEY_ACTION_SECONDARY,
			$data,
			'The secondary call to action must reach the template data.'
		);
		$this->assertArrayHasKey(
			BlitzyViewModel::KEY_TOC_AVAILABLE,
			$data,
			'The table-of-contents availability flag must reach the template data.'
		);
		$this->assertArrayHasKey(
			BlitzyViewModel::KEY_ANNOUNCEMENT,
			$data,
			'An enabled announcement with text must reach the template data.'
		);

		$this->assertSame(
			self::PRIMARY_ACTION_LABEL,
			$data[BlitzyViewModel::KEY_ACTION_PRIMARY]['label'] ?? null,
			'The primary call to action must carry the configured label.'
		);
		$this->assertSame(
			self::SECONDARY_ACTION_LABEL,
			$data[BlitzyViewModel::KEY_ACTION_SECONDARY]['label'] ?? null,
			'The secondary call to action must carry the configured label.'
		);
		$this->assertSame(
			self::ANNOUNCEMENT_TEXT,
			$data[BlitzyViewModel::KEY_ANNOUNCEMENT]['text'] ?? null,
			'The announcement must carry the configured text.'
		);
		$this->assertSame(
			self::ANNOUNCEMENT_LABEL,
			$data[BlitzyViewModel::KEY_ANNOUNCEMENT]['label'] ?? null,
			'The announcement must carry the configured button label.'
		);

		// The href assertions do double duty. They confirm each configured target survived the
		// allowlist in MediaWiki\Skins\Blitzy\BlitzyUrlValidator, and they confirm the fallback
		// branch was NOT taken: BlitzyViewModel resolves a core special page only when
		// configuration supplies no target, and that fallback is the skin's single
		// identity-dependent value. Pinning all three targets to configuration is what makes the
		// anonymous-versus-registered comparison at the end of this file a comparison of the
		// chrome rather than of a documented exception inside it.
		$this->assertSame(
			self::ANNOUNCEMENT_LINK,
			$data[BlitzyViewModel::KEY_ANNOUNCEMENT]['href'] ?? null,
			'The announcement button must point at the configured target.'
		);
		$this->assertSame(
			self::PRIMARY_ACTION_LINK,
			$data[BlitzyViewModel::KEY_ACTION_PRIMARY]['href'] ?? null,
			'The primary call to action must point at the configured target, not at a fallback.'
		);
		$this->assertSame(
			self::SECONDARY_ACTION_LINK,
			$data[BlitzyViewModel::KEY_ACTION_SECONDARY]['href'] ?? null,
			'The secondary call to action must point at the configured target, not at a fallback.'
		);
	}

	/**
	 * Core folds one `msg-` key into the template data for every entry of the manifest's
	 * `messages` argument, and the skin's own messages are among them.
	 *
	 * The two keys asserted here are chosen rather than sampled. `blitzy-jumptocontent` is a new
	 * key this skin defines, so it proves i18n/en.json is registered through MessagesDirs and
	 * reaches a render; `tagline` is a core key the skin reuses, which is the behaviour the
	 * preservation mandate asks for wherever core already provides an equivalent. A key missing
	 * from the manifest's list does not raise anything at all, it silently interpolates as the
	 * empty string, so the rendered text is asserted alongside the key's presence.
	 */
	public function testTemplateDataCarriesTheManifestMessages(): void {
		$data = $this->templateDataForArticle();
		$skinMessageKey = self::CORE_MESSAGE_KEY_PREFIX . 'blitzy-jumptocontent';
		$coreMessageKey = self::CORE_MESSAGE_KEY_PREFIX . 'tagline';

		$this->assertArrayHasKey(
			$skinMessageKey,
			$data,
			"The skin's own `blitzy-jumptocontent` message must reach the template data."
		);
		$this->assertSame(
			'Jump to content',
			$data[$skinMessageKey],
			'The skip-link message must arrive as the plain text i18n/en.json defines.'
		);
		$this->assertArrayHasKey(
			$coreMessageKey,
			$data,
			'The reused core `tagline` message must reach the template data.'
		);
		$this->assertNotSame(
			'',
			$data[$coreMessageKey],
			'A message listed in the manifest must resolve rather than interpolate as empty.'
		);
	}

	/**
	 * The union that builds the template data discards nothing from either operand.
	 *
	 * This is the one behaviour of SkinBlitzy::getTemplateData() that no unit test can observe,
	 * because the parent call needs a fully initialised request context, and it is the behaviour
	 * a future author is most likely to break. PHP's `+` keeps its LEFT operand on a key
	 * collision, so with the parent data on the left every core key wins and any colliding
	 * Blitzy key would be dropped in silence. Reaching for array_merge() would invert exactly
	 * that protection.
	 *
	 * Both directions are asserted at once here: all four of the skin's keys are still present,
	 * so none of them was swallowed by the union, and core's own `data-toc` is still the array
	 * core computed, so nothing overwrote it either. The table of contents is the sharpest case
	 * available, because `is-blitzy-toc-available` is DERIVED from core's `data-toc`: the two
	 * keys have to coexist, with core's value intact, for the flag to be true at all.
	 */
	public function testMergeDiscardsNoKeyFromEitherOperand(): void {
		$data = $this->templateDataForArticle();
		$skinKeys = [
			BlitzyViewModel::KEY_ANNOUNCEMENT,
			BlitzyViewModel::KEY_ACTION_PRIMARY,
			BlitzyViewModel::KEY_ACTION_SECONDARY,
			BlitzyViewModel::KEY_TOC_AVAILABLE,
		];

		foreach ( $skinKeys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$data,
				"`$key` must survive the union rather than be discarded by a key collision."
			);
			$this->assertNotContains(
				$key,
				self::CORE_TEMPLATE_DATA_KEYS,
				"`$key` must stay clear of the core keys the union resolves in core's favour."
			);
		}

		$this->assertIsArray(
			$data['data-toc'],
			"Core's table-of-contents data must reach the templates as core built it."
		);
		$this->assertGreaterThan(
			0,
			$data['data-toc']['number-section-count'] ?? 0,
			'The fixture article has headings, so core must report sections for it.'
		);
		$this->assertTrue(
			$data[BlitzyViewModel::KEY_TOC_AVAILABLE],
			"The skin's flag must be derived from core's section count in the merged array."
		);
	}

	/**
	 * Every key the skin contributes honours the documented template-data naming contract.
	 *
	 * MediaWiki's contract is that `array-` marks a list, `is-` marks a boolean and `data-`
	 * marks an object, and it is a real contract rather than a convention: a Mustache section on
	 * a key of the wrong shape changes the context it pushes, and the template renders the wrong
	 * thing without any error.
	 *
	 * The keys are discovered from the merged data rather than listed, so a key added to
	 * BlitzyViewModel later is audited by this test without anyone remembering to add it. Core's
	 * own `msg-` keys are excluded because core builds them from the manifest's `messages`
	 * argument as plain strings, and two of them are spelled with the skin's prefix.
	 *
	 * The `html-` prefix is asserted absent, and that is a rule R13 assertion rather than a
	 * naming one. That prefix is what tells a template to interpolate a value unescaped, and
	 * every value the skin contributes originates in site configuration, so a configuration
	 * string reaching an `html-` key would be a stored-injection vector on every page of the
	 * wiki.
	 *
	 * Every `data-` value is required to be an array outright rather than an array or null,
	 * because BlitzyViewModel OMITS a key it has nothing for instead of publishing a null under
	 * it. That is not a detail: omission is what empties the enclosing Mustache section and takes
	 * a disabled announcement out of the document, where a null would leave the key present. The
	 * count asserted at the end is what keeps this loop from passing over an empty selection.
	 */
	public function testSkinTemplateDataKeysHonourTheNamingContract(): void {
		$data = $this->templateDataForArticle();
		$audited = 0;

		foreach ( $data as $key => $value ) {
			if ( !str_contains( $key, 'blitzy' )
				|| str_starts_with( $key, self::CORE_MESSAGE_KEY_PREFIX )
			) {
				continue;
			}

			$audited++;
			$prefix = null;
			foreach ( array_keys( self::NAMING_CONTRACT_PREFIXES ) as $candidate ) {
				if ( str_starts_with( $key, $candidate ) ) {
					$prefix = $candidate;
					break;
				}
			}

			$this->assertStringStartsNotWith(
				'html-',
				$key,
				"`$key` must not use the html- prefix: it originates in site configuration, and "
					. 'that prefix is what tells a template to interpolate a value unescaped.'
			);
			$this->assertNotNull(
				$prefix,
				"`$key` must be prefixed array-, is- or data-, and never html-, because the "
					. 'skin publishes no raw HTML of its own.'
			);
			$this->assertSame(
				self::NAMING_CONTRACT_PREFIXES[$prefix],
				gettype( $value ),
				"`$key` is prefixed `$prefix`, so its value must have the type that prefix "
					. 'promises.'
			);
		}

		$this->assertSame(
			4,
			$audited,
			'All four of the keys the skin contributes must be present to be audited.'
		);
	}

	/**
	 * One case per Mustache partial, carrying markers that partial and no other emits.
	 *
	 * This provider is the inventory Gate 9's first obligation is measured against: nine files in
	 * includes/templates/, nine cases here, and every case reached from the single root template
	 * on one render. An orphaned partial is a wiring failure even when the file is perfect in
	 * isolation, and a case that stops matching is exactly how that shows up.
	 *
	 * Every marker below was taken from the template as authored and confirmed against a real
	 * render, not assumed from a specification. Identifiers are matched with their attribute so
	 * the assertion pins the element; class hooks are matched as bare tokens, because a class
	 * attribute legitimately gains neighbours and pinning the whole attribute would turn a
	 * harmless addition into a failure.
	 *
	 * Three cases carry a marker that proves more than presence:
	 *
	 *   NavCard        `id="p-navigation"` is core's portlet identifier re-emitted verbatim,
	 *                  which is the mechanism by which a preserved selector survives a complete
	 *                  visual redesign.
	 *   TableOfContents `blitzy-toc__item--level-2` can only appear if the partial recursed into
	 *                  itself for the nested heading, which is what SkinMustache's
	 *                  ::enableRecursivePartials( true ) exists to permit.
	 *   CategoryFooter `id="catlinks"` is core's own container, arriving inside the skin's
	 *                  wrapper rather than rebuilt by it.
	 *
	 * @return array<string,array{0:string[]}>
	 */
	public static function providePartialMarkers(): array {
		return [
			'skin.mustache' => [ [
				'<a class="mw-jump-link" href="#bodyContent">',
				'blitzy-page',
				'blitzy-page__inner',
				'blitzy-content-layout',
			] ],
			'AnnouncementBar.mustache' => [ [
				'id="blitzy-announcement"',
				'blitzy-announcement__inner',
				'blitzy-announcement__text',
				'blitzy-announcement__action',
				'id="blitzy-announcement-dismiss"',
			] ],
			'NavCard.mustache' => [ [
				'blitzy-nav-card',
				'blitzy-nav-card__inner',
				'blitzy-nav-card__logo',
				'id="blitzy-nav-checkbox"',
				'id="blitzy-nav-button"',
				'id="blitzy-nav-target"',
				'id="p-navigation"',
				'id="p-search"',
				'id="searchform"',
				'blitzy-nav-card__cta',
				'blitzy-cta--outline',
				'blitzy-cta--gradient',
			] ],
			'PersonalMenu.mustache' => [ [
				'blitzy-personal-menu',
				'id="blitzy-personal-checkbox"',
				'id="blitzy-personal-button"',
				'blitzy-personal-menu__content',
				'id="p-personal"',
			] ],
			'ContentHeader.mustache' => [ [
				'blitzy-content-header',
				'blitzy-content-header__title',
				'blitzy-content-header__tagline',
				'blitzy-content-header__indicators',
				'blitzy-tabs',
				'id="firstHeading"',
				'mw-first-heading',
			] ],
			'ContentBody.mustache' => [ [
				'<main id="content" class="mw-body blitzy-content-body">',
				'id="bodyContent"',
				'blitzy-content-body__inner',
				'aria-labelledby="firstHeading"',
				'data-mw-ve-target-container',
				'id="contentSub"',
			] ],
			'TableOfContents.mustache' => [ [
				'blitzy-toc',
				'blitzy-toc__title',
				'id="blitzy-toc-checkbox"',
				'id="blitzy-toc-button"',
				'blitzy-toc__list',
				'blitzy-toc__item--level-2',
			] ],
			'CategoryFooter.mustache' => [ [
				'blitzy-category-footer',
				'id="catlinks"',
			] ],
			'SiteFooter.mustache' => [ [
				'id="footer"',
				'mw-footer',
				'blitzy-footer__inner',
				'blitzy-footer__row',
			] ],
		];
	}

	/**
	 * Every partial in includes/templates/ is reached from the root template and puts its own
	 * markup on a rendered page.
	 *
	 * The first of Gate 9's four obligations, and the reason the fixture article is shaped the way
	 * it is: headings so the table of contents exists, a category link so the category footer
	 * exists, an enabled announcement so the announcement bar exists, and a registered viewer so
	 * core supplies the navigation entries the tab groups need. Without any one of those, a
	 * partial would be absent for a reason that has nothing to do with whether it is wired up.
	 *
	 * @dataProvider providePartialMarkers
	 * @param string[] $markers Substrings the partial must have contributed to the page.
	 */
	public function testEveryPartialRendersOnTheArticlePage( array $markers ): void {
		$html = $this->renderArticleAsRegisteredUser();

		foreach ( $markers as $marker ) {
			$this->assertStringContainsString(
				$marker,
				$html,
				"The rendered page must contain `$marker`, which only this partial emits. A "
					. 'missing marker means the partial is either orphaned from skin.mustache or '
					. 'no longer emitting what the skin contracts for.'
			);
		}
	}

	/**
	 * The skip link is the first thing the skin emits, and it points at the body content.
	 *
	 * The affordance only works if it comes first, so this is a positional contract rather than a
	 * presence one. It is asserted on what the SKIN emits, which is what this test can see:
	 * OutputPage::headElement() puts an empty ARIA live region immediately after the body start
	 * tag, before any skin markup and out of reach of any Mustache skin, so the same claim
	 * written as `body > *:first-child` would fail here and in every other skin alike. Core's
	 * container is empty and not focusable, so the skip link is still the first thing a keyboard
	 * user reaches.
	 *
	 * The target is `#bodyContent` rather than `#content` because that is the element
	 * ContentBody.mustache gives the labelling relationship to, and a skip link that lands on a
	 * different element than the one announced would be worse than none.
	 */
	public function testJumpLinkIsTheFirstElementTheSkinEmits(): void {
		$html = $this->renderArticleAsRegisteredUser();

		$this->assertStringStartsWith(
			'<a class="mw-jump-link" href="#bodyContent">',
			$html,
			'The skip link must be the first element the skin emits, and must target the body '
				. 'content region.'
		);
	}

	/**
	 * The content body keeps every part of core's body contract on the element core expects it.
	 *
	 * Asserted on the extracted start tags rather than on the page, because the claim is that
	 * these attributes sit on the SAME elements. Both attributes present anywhere in the document
	 * would satisfy a pair of substring assertions while the labelling relationship and the
	 * editor's target container pointed at different elements, which is the failure this test
	 * exists to catch.
	 *
	 * `data-mw-ve-target-container` is here because the specification puts VisualEditor container
	 * chrome in scope while excluding VisualEditor internals: the attribute is the container
	 * contract, and it is the whole of what this skin owes that editor.
	 */
	public function testContentBodyPreservesTheCoreBodyContract(): void {
		$html = $this->renderArticleAsRegisteredUser();

		$main = $this->extractRegion( '/<main\b[^>]*\bid="content"[^>]*>/', $html, 'main content' );
		$this->assertStringContainsString(
			'mw-body',
			$main,
			"The main content element must keep core's mw-body class alongside the skin's own."
		);

		$bodyContent = $this->extractRegion(
			'/<div\b[^>]*\bid="bodyContent"[^>]*>/',
			$html,
			'body content'
		);
		$this->assertStringContainsString(
			'aria-labelledby="firstHeading"',
			$bodyContent,
			'The body content element must be labelled by the first heading.'
		);
		$this->assertStringContainsString(
			'data-mw-ve-target-container',
			$bodyContent,
			'The body content element must carry the VisualEditor target-container attribute.'
		);
	}

	/**
	 * The subtitle region is emitted exactly once.
	 *
	 * Core annotates `#contentSub` as in use by editors and not to be hidden or removed, and it
	 * is the easiest contract in this skin to break: both ContentHeader.mustache and
	 * ContentBody.mustache render regions around the page title, and emitting it from each would
	 * duplicate the identifier page-wide. A duplicate identifier is a blocking accessibility
	 * failure across every capture rather than a local defect, and every presence assertion in
	 * this file would still pass with two of them on the page, which is why the count is asserted
	 * rather than the presence.
	 */
	public function testContentSubIsEmittedExactlyOnce(): void {
		$html = $this->renderArticleAsRegisteredUser();

		$this->assertSame(
			1,
			substr_count( $html, 'id="contentSub"' ),
			'The subtitle region must appear exactly once in the rendered page.'
		);
	}

	/**
	 * Core's undelete link populates the second subtitle region, and only then.
	 *
	 * ContentBody.mustache guards `#contentSub2` on core's `html-undelete-link`, so the region is
	 * conditional by design and cannot be asserted on an ordinary page view. Rather than assume
	 * the guard, this test produces the condition: a page is created, deleted, and then rendered
	 * for an administrator, at which point MediaWiki\Skin\Skin::getUndeleteLink() finds a
	 * non-existent title, an authority that may see deleted history, and one deleted revision.
	 *
	 * That is also the only way to know the guard is a guard rather than a suppression. The
	 * subtitle count is re-asserted here because this is the one render in the suite where a
	 * second `contentSub`-prefixed identifier legitimately appears, and `contentSub2` must not
	 * have been produced by duplicating `contentSub`.
	 */
	public function testUndeleteLinkPopulatesTheSecondSubtitleRegion(): void {
		$page = $this->getExistingTestPage( 'Blitzy integration deleted article' );
		$prefixedText = $page->getTitle()->getPrefixedText();
		$this->deletePage( $page, 'Blitzy integration fixture deletion' );

		// Re-resolved rather than reused: the existing instance has already cached the article
		// identifier it had before the deletion, and getUndeleteLink() asks whether the title
		// exists.
		$title = Title::newFromText( $prefixedText );
		$this->assertInstanceOf(
			Title::class,
			$title,
			'The deleted fixture title must still resolve after the page is gone.'
		);

		$html = $this->renderPage( $title, $this->getTestSysop()->getUser(), 'view' );

		$this->assertStringContainsString(
			'id="contentSub2"',
			$html,
			"Core's undelete link must reach the page through the second subtitle region."
		);
		$this->assertSame(
			1,
			substr_count( $html, 'id="contentSub"' ),
			'The first subtitle region must still be emitted exactly once.'
		);
	}

	/**
	 * A disabled announcement bar is absent from the document, not hidden in it.
	 *
	 * The requirement is absence from the document's flow, so `display: none` would be
	 * non-compliant however it looked in a browser. That makes this an assertion about the HTML
	 * source, and it is the reason skin.mustache invokes the partial inside a section on the
	 * announcement OBJECT rather than behind a boolean: BlitzyViewModel omits the key entirely
	 * when the bar is disabled, a falsy section renders nothing, and nothing is what reaches the
	 * page.
	 *
	 * Both the identifier and the class hook are asserted absent, because a partial that emitted
	 * an empty wrapper would still satisfy an assertion against only one of them. No `style`
	 * attribute is asserted on anything: rule R11 keeps every visual claim in
	 * tests/playwright/fidelity.spec.ts, and a source-level style assertion would be that claim
	 * made in the wrong place.
	 */
	public function testAnnouncementBarIsAbsentFromTheDocumentWhenDisabled(): void {
		$this->overrideConfigValue( BlitzyViewModel::OPTION_ANNOUNCE_ENABLE, false );

		$html = $this->renderArticleAsRegisteredUser();

		$this->assertStringNotContainsString(
			'id="blitzy-announcement"',
			$html,
			'A disabled announcement bar must leave no element behind in the document.'
		);
		$this->assertStringNotContainsString(
			'blitzy-announcement',
			$html,
			'A disabled announcement bar must leave no class hook behind either, because the '
				. 'requirement is absence from the flow rather than a hidden element.'
		);

		// The rest of the chrome is untouched by the announcement being off, so a disabled bar
		// cannot be mistaken for a page that failed to render.
		$this->assertStringContainsString(
			'blitzy-nav-card',
			$html,
			'The navigation card must still render when the announcement bar is disabled.'
		);
	}

	/**
	 * Category links arrive inside the skin's wrapper, and inside the body content.
	 *
	 * Two claims, and the second is the one worth the test. The wrapper has to contain core's
	 * `#catlinks` rather than replace it, so the adjacency of the two elements is asserted
	 * directly. And the whole thing has to sit inside `#bodyContent`, which is asserted
	 * positionally: core places categories outside the parser output but inside the body, and a
	 * skin that moved them below the closing main element would change what a screen reader
	 * reaches while looking identical in a screenshot.
	 */
	public function testCategoryFooterWrapsCoreCategoryLinksInsideTheBody(): void {
		$html = $this->renderArticleAsRegisteredUser();

		$this->assertStringContainsString(
			'<div class="blitzy-category-footer"><div id="catlinks"',
			$html,
			"The category footer must wrap core's own category container rather than rebuild it."
		);

		$bodyContentAt = strpos( $html, 'id="bodyContent"' );
		$catlinksAt = strpos( $html, 'id="catlinks"' );
		$mainClosesAt = strpos( $html, '</main>' );

		$this->assertIsInt( $bodyContentAt, 'The body content region must be present.' );
		$this->assertIsInt( $catlinksAt, 'The category container must be present.' );
		$this->assertIsInt( $mainClosesAt, 'The main content element must be closed.' );
		$this->assertGreaterThan(
			$bodyContentAt,
			$catlinksAt,
			'Category links must be rendered inside the body content region.'
		);
		$this->assertLessThan(
			$mainClosesAt,
			$catlinksAt,
			'Category links must be rendered before the main content element closes.'
		);
	}

	/**
	 * The portlets whose identifiers other code depends on, and the class name core gives each.
	 *
	 * Two of the three identifiers do not match the name the portlet is built from, and that is
	 * the point of listing them here rather than deriving them. MediaWiki\Skin\Components
	 * \SkinComponentMenu renames `actions` to `cactions` and `user-menu` to `personal` before it
	 * builds the container, because a great deal of user code and a great many gadgets look for
	 * the older spellings. So `#p-personal` survives a complete redesign of the user menu without
	 * the manifest mentioning `personal` at all, and asserting the rendered identifier rather than
	 * the manifest key is what keeps this test correct however skin.json declares its menus.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function providePreservedPortlets(): array {
		return [
			'navigation, from the sidebar' => [ 'p-navigation', 'mw-portlet-navigation' ],
			'user menu, renamed to personal by core' => [ 'p-personal', 'mw-portlet-personal' ],
			'actions, renamed to cactions by core' => [ 'p-cactions', 'mw-portlet-cactions' ],
		];
	}

	/**
	 * Each preserved portlet reaches the page with core's identifier and core's classes.
	 *
	 * This complements tests/playwright/domContract.spec.ts, which is where rule R2 is verified
	 * against a page fetched over HTTP; the value of asserting it here too is that a dropped
	 * contract is caught in the template rather than in the response.
	 *
	 * The class assertion is containment and never equality, and that is a correctness
	 * requirement rather than a robustness preference. SkinComponentMenu appends ' emptyPortlet'
	 * to the class of a menu with no items, and the skin also adds its own class alongside
	 * core's, which R2 explicitly permits. An equality assertion would therefore be wrong in two
	 * independent ways at once. Vector's own integration test does compare whole class strings;
	 * that pattern is deliberately not reproduced.
	 *
	 * @dataProvider providePreservedPortlets
	 * @param string $id Identifier core gives the rendered portlet container.
	 * @param string $portletClass Class core gives it, beside the generic mw-portlet class.
	 */
	public function testPreservedPortletsKeepCoreIdentifiersAndClasses(
		string $id,
		string $portletClass
	): void {
		$html = $this->renderArticleAsRegisteredUser();

		$container = $this->extractRegion(
			'/<div\b[^>]*\bid="' . preg_quote( $id, '/' ) . '"[^>]*>/',
			$html,
			"`#$id` portlet"
		);

		$this->assertStringContainsString(
			'mw-portlet',
			$container,
			"`#$id` must keep core's generic portlet class."
		);
		$this->assertStringContainsString(
			$portletClass,
			$container,
			"`#$id` must keep core's `$portletClass` class, which is what gadgets select on."
		);
		$this->assertSame(
			1,
			substr_count( $html, 'id="' . $id . '"' ),
			"`#$id` must appear exactly once, because a duplicated identifier breaks both the "
				. 'selector contract and the accessibility gate.'
		);
	}

	/**
	 * Core's body content reaches the page exactly as core built it, once.
	 *
	 * `html-body-content` is not the parser output on its own: MediaWiki\Skin\SkinMustache builds
	 * it by wrapping the output in `#mw-content-text` and concatenating the print footer, so
	 * anything that post-processed that key would break two preserved contracts in one move.
	 * ContentBody.mustache interpolates it raw and unmodified, and this test is what proves that,
	 * by asserting the two markers core put in it are present and unduplicated.
	 *
	 * `data-nosnippet` is asserted on the print footer's own element rather than anywhere on the
	 * page. It is the attribute that keeps the retrieval notice out of search-engine snippets, so
	 * an assertion that did not pin it to that element would pass while the attribute drifted
	 * onto something else.
	 */
	public function testCoreBodyContentReachesThePageExactlyOnce(): void {
		$html = $this->renderArticleAsRegisteredUser();

		$this->assertSame(
			1,
			substr_count( $html, 'id="mw-content-text"' ),
			"Core's content wrapper must reach the page exactly once, as core built it."
		);
		$this->assertSame(
			1,
			substr_count( $html, 'class="printfooter"' ),
			'The print footer must reach the page exactly once.'
		);

		$printFooter = $this->extractRegion(
			'/<div\b[^>]*\bclass="printfooter"[^>]*>/',
			$html,
			'print footer'
		);
		$this->assertStringContainsString(
			'data-nosnippet',
			$printFooter,
			'The print footer must keep the attribute that excludes it from search snippets.'
		);
	}

	/**
	 * Assert the chrome the skin puts on every page is present on this one.
	 *
	 * @param string $html Rendered page.
	 * @param string $surface Human-readable description of the surface, for failure messages.
	 */
	private function assertChromeContract( string $html, string $surface ): void {
		foreach ( self::CHROME_MARKERS as $marker ) {
			$this->assertStringContainsString(
				$marker,
				$html,
				"The $surface must carry `$marker`: the skin's chrome does not vary with the "
					. 'surface it wraps.'
			);
		}
	}

	/**
	 * The actions whose chrome is asserted here.
	 *
	 * Every core action MediaWiki resolves from a request reaches the same skin, so the chrome
	 * must not branch on which one it was. These three are the ones the capture matrix
	 * photographs beyond a plain view.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function provideActionNames(): array {
		return [
			'reading a page' => [ 'view' ],
			'editing a page' => [ 'edit' ],
			'reading a page history' => [ 'history' ],
		];
	}

	/**
	 * The chrome is the same whichever action produced the page.
	 *
	 * Part of Gate 8's first check, and the part a PHP render can make honestly: the requirement
	 * is that every core action behaves as it does under the reference skin, and the skin's side
	 * of that is not to reshape itself around the action. The body of an edit page is core's form
	 * and the body of a history page is core's pager; both arrive through the same
	 * `html-body-content` key, and the navigation card, content header, body contract and footer
	 * around them are identical.
	 *
	 * The same wikitext is parsed into every one of the three renders on purpose, so the action
	 * name is the only thing that differs between them. What is deliberately NOT asserted is any
	 * part of core's edit form, history pager or diff table: those are core's markup, restyled
	 * through skinStyles and verified as computed styles by tests/playwright/fidelity.spec.ts.
	 * Asserting them here would be testing core rather than the skin.
	 *
	 * @dataProvider provideActionNames
	 * @param string $action Action name MediaWiki would have resolved for the request.
	 */
	public function testChromeSurvivesEveryRequestedAction( string $action ): void {
		$html = $this->renderPage(
			$this->newArticle(),
			$this->getTestUser()->getUser(),
			$action,
			self::ARTICLE_WIKITEXT
		);

		$this->assertChromeContract( $html, "page rendered for the `$action` action" );
	}

	/**
	 * Two saved revisions render as a real diff inside the skin's chrome.
	 *
	 * The furthest a PHP render reaches into Gate 8's first check, and every step of it is real:
	 * MediaWiki's own save path produces two revisions, MediaWiki's own DifferenceEngine produces
	 * the diff between them, and the skin renders whatever that put into the output. Nothing here
	 * is a fixture standing in for a core behaviour.
	 *
	 * The diff's presence is asserted through the two revisions' CONTENT rather than through any
	 * core diff class. That keeps the assertion on the right side of a boundary that matters: the
	 * appearance of the diff is a computed-style claim owned by tests/playwright/fidelity.spec.ts
	 * and skinStyles/mediawiki.diff.styles.less, while whether a diff arrived at all is a
	 * presence claim, and presence is what this suite is for. It also has to be asserted, or the
	 * chrome assertions below would pass just as happily around an empty page.
	 *
	 * What remains outside a PHP render is left to harness/verify.sh, which drives the same four
	 * operations over HTTP against the provisioned wiki: submitting the edit form so the round
	 * trip through the edit token is exercised, and loading the diff and history URLs so core's
	 * pager and the skinStyles layered over it are delivered by ResourceLoader. No test in this
	 * file is skipped in their place; a surface a PHP render cannot reach is simply not claimed
	 * here.
	 */
	public function testSavedRevisionsRenderAsADiffInsideTheChrome(): void {
		$page = $this->getExistingTestPage( 'Blitzy integration diff article' );

		$old = $this->editPage( $page, self::DIFF_OLD_WIKITEXT, 'Blitzy integration diff, before' );
		$this->assertStatusGood( $old, 'The first revision of the diff fixture must save.' );
		$new = $this->editPage( $page, self::DIFF_NEW_WIKITEXT, 'Blitzy integration diff, after' );
		$this->assertStatusGood( $new, 'The second revision of the diff fixture must save.' );

		$title = $page->getTitle();
		$context = $this->newContext( $title, $this->getTestUser()->getUser(), 'view' );
		$skin = $this->newSkin();
		$skin->setContext( $context );
		$context->getOutput()->setPageTitle( $title->getPrefixedText() );

		$differenceEngine = new DifferenceEngine(
			$context,
			$old->getNewRevision()->getId(),
			$new->getNewRevision()->getId(),
			0,
			false,
			false
		);
		$differenceEngine->showDiffPage( true );

		$html = $skin->generateHTML();

		$this->assertStringContainsString(
			self::DIFF_OLD_TOKEN,
			$html,
			'The diff must show the word the older revision carried.'
		);
		$this->assertStringContainsString(
			self::DIFF_NEW_TOKEN,
			$html,
			'The diff must show the word the newer revision carries.'
		);

		$this->assertChromeContract( $html, 'diff page' );

		// The diff arrives through the same single content wrapper an article does, so none of
		// core's preserved body contract is duplicated or displaced by it.
		$this->assertSame(
			1,
			substr_count( $html, 'id="mw-content-text"' ),
			"A diff must reach the page inside core's one content wrapper."
		);
		$this->assertSame(
			1,
			substr_count( $html, 'id="contentSub"' ),
			'A diff page must emit the subtitle region exactly once, as every other page does.'
		);
		$this->assertSame(
			1,
			substr_count( $html, 'class="printfooter"' ),
			'A diff page must emit the print footer exactly once, as every other page does.'
		);
	}

	/**
	 * A visitor and an account are shown the same chrome, differing only inside the personal menu.
	 *
	 * This is the testable form of the requirement that anonymous and logged-in chrome differ only
	 * in the contents of the personal menu, and it is what makes captures 1 and 20 of the capture
	 * matrix comparable: the same surface, the same width, the same mode, photographed in the two
	 * authentication states.
	 *
	 * It is asserted region by region rather than as an equality between the two whole documents,
	 * and the reason is worth recording rather than working around. Core, not the skin, supplies
	 * an anonymous viewer no page actions at all, so `#p-cactions` and the tab group around it are
	 * genuinely absent from an anonymous render of the very same page. That is core's behaviour,
	 * and passing it through unchanged is what the preservation mandate asks of the skin; claiming
	 * whole-document equality would therefore be claiming something false. What the skin owes, and
	 * what is asserted, is that not one region it composes itself varies with who is reading.
	 *
	 * Both call-to-action targets are configured for this suite, and that is a precondition of
	 * this test rather than a detail of the fixture. BlitzyViewModel resolves a fallback special
	 * page only when configuration supplies no target, and that fallback is the skin's single
	 * identity-dependent value: account creation for a visitor, the watchlist for an account. With
	 * both targets configured the fallback is never consulted, so the call-to-action cluster is
	 * expected to match byte for byte, and a difference in it would be a real defect rather than
	 * the documented exception.
	 */
	public function testAnonymousAndRegisteredChromeDifferOnlyInThePersonalMenu(): void {
		$title = $this->newArticle();
		$anonymous = $this->renderPage( $title, null, 'view', self::ARTICLE_WIKITEXT );
		$registered = $this->renderPage(
			$title,
			$this->getTestUser()->getUser(),
			'view',
			self::ARTICLE_WIKITEXT
		);

		$this->assertChromeContract( $anonymous, 'anonymous page view' );
		$this->assertChromeContract( $registered, 'logged-in page view' );

		foreach ( self::IDENTITY_INDEPENDENT_REGIONS as $label => $pattern ) {
			$this->assertSame(
				$this->extractRegion( $pattern, $anonymous, "$label, anonymous" ),
				$this->extractRegion( $pattern, $registered, "$label, logged in" ),
				"The $label must render identically for a visitor and for an account: the only "
					. 'chrome permitted to vary with identity is the personal menu.'
			);
		}

		$anonymousMenu = $this->extractRegion(
			self::PERSONAL_PORTLET_REGION,
			$anonymous,
			'personal menu, anonymous'
		);
		$registeredMenu = $this->extractRegion(
			self::PERSONAL_PORTLET_REGION,
			$registered,
			'personal menu, logged in'
		);

		$this->assertNotSame(
			$anonymousMenu,
			$registeredMenu,
			'The personal menu is the one region that must differ, because it is where core puts '
				. 'the entries that depend on who is reading.'
		);
		foreach ( [ 'anonymous' => $anonymousMenu, 'logged in' => $registeredMenu ] as $state => $menu ) {
			$this->assertStringContainsString(
				'mw-portlet-personal',
				$menu,
				"The $state personal menu must keep core's portlet class."
			);
			$this->assertStringContainsString(
				'mw-list-item',
				$menu,
				"The $state personal menu must carry the entries core supplies, so the difference "
					. 'between the two states is a difference between two populated menus rather '
					. 'than between a menu and an empty one.'
			);
		}
	}
}
