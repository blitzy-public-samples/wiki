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

use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skins\Notion\ConfigHelper;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * Tests for the Notion skin's per-page configuration evaluator.
 *
 * `ConfigHelper::shouldDisable()` turns an options array from `skin.json`, the current request and
 * the current title into one boolean: must this feature be switched off on the page being rendered?
 * It is what decides, per page, whether the limited content width and the configurable font size
 * apply — so a wrong answer here is a visibly wrong layout rather than an internal detail.
 *
 * This is an integration test because the class resolves real titles: it calls
 * `Title::newFromText()` on every configured page name, `Title::makeTitle()` on the canonical form
 * of a special page, and `$title->isMainPage()`, which reads the wiki's own `mainpage` message.
 * Substituting doubles for those would test a different function from the one that ships. Vector
 * keeps the equivalent test in the same place for the same reason.
 *
 * The order of the checks inside `::shouldDisable()` is behaviour rather than presentation, and the
 * class documents two steps as deliberately counter-intuitive. Both are asserted here in the form
 * that fails if the steps are reordered:
 *
 *  - A query-string parameter that is *present* answers the question outright, whether or not its
 *    value matches the configured pattern. Every later check is skipped, so a page that a namespace
 *    exclusion would have disabled stays enabled when a listed parameter is present with a
 *    non-matching value.
 *  - The main page is answered before special-page resolution and before either title list, so an
 *    explicit `mainpage` setting cannot be contradicted by a page-title exclusion.
 *  - Inclusions are checked before exclusions, which is what lets one page opt back in while its
 *    whole namespace is excluded.
 *
 * @group Notion
 * @coversDefaultClass \MediaWiki\Skins\Notion\ConfigHelper
 */
class ConfigHelperTest extends MediaWikiIntegrationTestCase {

	private function newInstance(): ConfigHelper {
		return new ConfigHelper( $this->getServiceContainer()->getSpecialPageFactory() );
	}

	private function request(): FauxRequest {
		return new FauxRequest();
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testNoOptionsMeansNothingIsDisabled() {
		$this->assertFalse(
			$this->newInstance()->shouldDisable( [], $this->request() ),
			'An empty options array configures no exclusion, so the feature stays on.'
		);
		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				[], $this->request(), Title::makeTitle( NS_MAIN, 'Some article' )
			)
		);
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testMissingTitleNeverThrowsHoweverMuchIsConfigured() {
		$options = [
			'exclude' => [
				'mainpage' => true,
				'namespaces' => [ NS_SPECIAL, NS_FILE ],
				'pagetitles' => [ 'Special:Userlogin' ],
			],
			'include' => [ 'Special:Preferences' ],
		];

		$this->assertFalse(
			$this->newInstance()->shouldDisable( $options, $this->request(), null ),
			'A request that carried no title answers false rather than failing, which is what the '
				. 'leading conjunct of the namespace check is for.'
		);
	}

	/**
	 * The query-string check answers outright for any parameter that is present.
	 *
	 * @dataProvider provideQueryString
	 * @covers ::shouldDisable
	 * @param array $querystring configured patterns
	 * @param array $requestParams
	 * @param bool $expected
	 * @param string $because
	 */
	public function testQueryStringExclusion(
		array $querystring, array $requestParams, bool $expected, string $because
	) {
		$options = [ 'exclude' => [ 'querystring' => $querystring ] ];

		$this->assertSame(
			$expected,
			$this->newInstance()->shouldDisable(
				$options,
				new FauxRequest( $requestParams ),
				Title::makeTitle( NS_MAIN, 'Some article' )
			),
			$because
		);
	}

	public static function provideQueryString(): array {
		return [
			'a star pattern matches any value the parameter has' => [
				[ 'action' => '*' ], [ 'action' => 'history' ], true,
				'`*` is rewritten to `.+`, so any non-empty value disables the feature.',
			],
			'a star pattern does not match an empty value' => [
				[ 'action' => '*' ], [ 'action' => '' ], false,
				'`.+` needs at least one character, so an empty value is not a match.',
			],
			'an explicit pattern matches' => [
				[ 'action' => 'edit|submit' ], [ 'action' => 'submit' ], true,
				'The configured value is used as a regular expression.',
			],
			'an explicit pattern that does not match still answers here' => [
				[ 'action' => 'edit|submit' ], [ 'action' => 'view' ], false,
				'The parameter is present, so this check answers and no later check runs.',
			],
			'an absent parameter is not an answer' => [
				[ 'action' => '*' ], [ 'oldid' => '5' ], false,
				'Only a parameter that is actually present short-circuits.',
			],
			'the first present parameter wins' => [
				[ 'action' => 'never-matches', 'diff' => '*' ],
				[ 'action' => 'view', 'diff' => '17' ],
				false,
				'Iteration stops at the first present parameter, so `diff` is never consulted.',
			],
		];
	}

	/**
	 * A present query-string parameter outranks a namespace exclusion that would have matched.
	 *
	 * @covers ::shouldDisable
	 */
	public function testAPresentQueryStringParameterPreemptsTheNamespaceExclusion() {
		$options = [
			'exclude' => [
				'querystring' => [ 'action' => 'edit' ],
				'namespaces' => [ NS_TALK ],
			],
		];
		$title = Title::makeTitle( NS_TALK, 'Some article' );

		$this->assertTrue(
			$this->newInstance()->shouldDisable(
				$options, new FauxRequest( [ 'action' => 'edit' ] ), $title
			),
			'A matching parameter disables the feature.'
		);
		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				$options, new FauxRequest( [ 'action' => 'view' ] ), $title
			),
			'The parameter is present but does not match, and that answer is final — the excluded '
				. 'namespace below is never reached. Reordering the two checks would change this '
				. 'page from enabled to disabled.'
		);
		$this->assertTrue(
			$this->newInstance()->shouldDisable( $options, new FauxRequest( [] ), $title ),
			'With the parameter absent the namespace exclusion does apply.'
		);
	}

	/**
	 * @dataProvider provideMainPage
	 * @covers ::shouldDisable
	 * @param mixed $configured
	 * @param bool $expected
	 */
	public function testMainPageIsAnsweredByItsOwnSetting( $configured, bool $expected ) {
		$options = [ 'exclude' => [ 'mainpage' => $configured ] ];

		$this->assertSame(
			$expected,
			$this->newInstance()->shouldDisable(
				$options, $this->request(), Title::newMainPage()
			),
			'The declared return type is a strict boolean, so a truthy configuration value must be '
				. 'cast rather than returned as it stands.'
		);
	}

	public static function provideMainPage(): array {
		return [
			'disabled' => [ true, true ],
			'enabled' => [ false, false ],
			'truthy integer' => [ 1, true ],
			'falsy integer' => [ 0, false ],
			'truthy string' => [ 'yes', true ],
		];
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testMainPageSettingCannotBeContradictedByATitleExclusion() {
		$mainPage = Title::newMainPage();
		$options = [
			'exclude' => [
				'mainpage' => false,
				'pagetitles' => [ $mainPage->getPrefixedText() ],
				'namespaces' => [ $mainPage->getNamespace() ],
			],
		];

		$this->assertFalse(
			$this->newInstance()->shouldDisable( $options, $this->request(), $mainPage ),
			'The main page is answered before either title list and before the namespace check, so '
				. 'an explicit `mainpage => false` keeps the feature on however the page is listed '
				. 'elsewhere.'
		);
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testMainPageWithoutAnExplicitSettingIsNotDisabled() {
		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				[ 'exclude' => [ 'pagetitles' => [ Title::newMainPage()->getPrefixedText() ] ] ],
				$this->request(),
				Title::newMainPage()
			),
			'An absent `mainpage` key defaults to false, and that default still short-circuits.'
		);
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testPageTitleExclusionMatchesTheRootOfASubpage() {
		$options = [ 'exclude' => [ 'pagetitles' => [ 'Project:Guidelines' ] ] ];

		$this->assertTrue(
			$this->newInstance()->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_PROJECT, 'Guidelines/Style' )
			),
			'Titles are compared against the root, so one entry covers a page and its subpages.'
		);
		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_PROJECT, 'Guidelines other' )
			),
			'A different page whose name merely starts alike must not match.'
		);
	}

	/**
	 * Configuration names special pages canonically; the request may arrive under an alias.
	 *
	 * `Special:Login` is an alias of `Special:Userlogin` in core's English alias list, so this is
	 * the real thing rather than a contrived one: a wiki configuring the canonical name must still
	 * match a reader who followed the alias.
	 *
	 * @covers ::shouldDisable
	 */
	public function testSpecialPageAliasesAreCanonicalisedBeforeComparison() {
		$options = [ 'exclude' => [ 'pagetitles' => [ 'Special:Userlogin' ] ] ];
		$helper = $this->newInstance();

		$this->assertTrue(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_SPECIAL, 'Userlogin' )
			),
			'The canonical name matches itself.'
		);
		$this->assertTrue(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_SPECIAL, 'Login' )
			),
			'An alias must be resolved to its canonical name, or configuration written the way the '
				. 'documentation requires would silently miss the page.'
		);
		$this->assertTrue(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_SPECIAL, 'Login/subpage' )
			),
			'The subpage parameter plays no part in the comparison, which matches on the special '
				. 'page itself.'
		);
		$this->assertFalse(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_SPECIAL, 'Preferences' )
			),
			'An unrelated special page must not match.'
		);
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testInclusionsTrumpExclusions() {
		$options = [
			'exclude' => [
				'namespaces' => [ NS_SPECIAL ],
				'pagetitles' => [ 'Special:Preferences' ],
			],
			'include' => [ 'Special:Preferences' ],
		];
		$helper = $this->newInstance();

		$this->assertFalse(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_SPECIAL, 'Preferences' )
			),
			'An inclusion is found before either exclusion is consulted, which is the only way one '
				. 'page can opt back in while its whole namespace is excluded.'
		);
		$this->assertTrue(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_SPECIAL, 'Version' )
			),
			'A special page that is not included still falls to the namespace exclusion.'
		);
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testInclusionsAreAlsoAliasResolved() {
		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				[
					'exclude' => [ 'namespaces' => [ NS_SPECIAL ] ],
					'include' => [ 'Special:Userlogin' ],
				],
				$this->request(),
				Title::makeTitle( NS_SPECIAL, 'Login' )
			),
			'The canonical rewrite happens before both lists, so inclusions benefit from it too.'
		);
	}

	/**
	 * @covers ::shouldDisable
	 */
	public function testNamespaceExclusionIsTheLastWord() {
		$options = [ 'exclude' => [ 'namespaces' => [ NS_FILE, NS_CATEGORY ] ] ];
		$helper = $this->newInstance();

		$this->assertTrue(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_FILE, 'Example.png' )
			)
		);
		$this->assertTrue(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_CATEGORY, 'Examples' )
			)
		);
		$this->assertFalse(
			$helper->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_MAIN, 'Example' )
			),
			'A namespace that is not listed leaves the feature enabled.'
		);
		$this->assertFalse(
			$helper->shouldDisable( [ 'exclude' => [ 'namespaces' => [] ] ], $this->request(),
				Title::makeTitle( NS_FILE, 'Example.png' ) ),
			'An empty namespace list excludes nothing.'
		);
	}

	/**
	 * A configured page name the title parser cannot read must not disable anything, and must not
	 * raise anything either.
	 *
	 * @covers ::shouldDisable
	 */
	public function testUnparseableConfiguredTitlesAreIgnored() {
		$options = [
			'exclude' => [ 'pagetitles' => [ '<invalid title>', '' ] ],
			'include' => [ '<invalid title>' ],
		];

		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				$options, $this->request(), Title::makeTitle( NS_MAIN, 'Some article' )
			),
			'The null guards around both comparisons exist for exactly this configuration.'
		);
	}

	/**
	 * The helper is stateless, so one instance may be asked about different option sets.
	 *
	 * `includes/ServiceWiring.php` constructs it once per request and the feature-management layer
	 * asks it about several features, so a cache keyed on anything less than the whole
	 * (options, request, title) triple would hand back another feature's answer.
	 *
	 * @covers ::shouldDisable
	 */
	public function testOneInstanceAnswersIndependentlyForEachOptionSet() {
		$helper = $this->newInstance();
		$title = Title::makeTitle( NS_FILE, 'Example.png' );

		$this->assertTrue(
			$helper->shouldDisable( [ 'exclude' => [ 'namespaces' => [ NS_FILE ] ] ],
				$this->request(), $title )
		);
		$this->assertFalse(
			$helper->shouldDisable( [ 'exclude' => [ 'namespaces' => [ NS_CATEGORY ] ] ],
				$this->request(), $title ),
			'The second question must be answered on its own options, not on the first answer.'
		);
		$this->assertTrue(
			$helper->shouldDisable( [ 'exclude' => [ 'namespaces' => [ NS_FILE ] ] ],
				$this->request(), $title ),
			'And the first question must still answer the same way afterwards.'
		);
	}

	/**
	 * The main request's own object is accepted, not merely a FauxRequest.
	 *
	 * @covers ::shouldDisable
	 */
	public function testTheMainRequestIsAcceptedAsWell() {
		$this->assertFalse(
			$this->newInstance()->shouldDisable(
				[ 'exclude' => [ 'querystring' => [ 'action' => '*' ] ] ],
				RequestContext::getMain()->getRequest(),
				Title::makeTitle( NS_MAIN, 'Some article' )
			),
			'A real request with no action parameter reaches the later checks unchanged.'
		);
	}
}
