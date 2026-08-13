<?php

namespace MediaWiki\Skins\Notion;

use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;

/**
 * Evaluates the request- and title-sensitive halves of the skin's configuration.
 *
 * Several Notion features are configurable per page rather than per wiki: the limited content
 * width and the configurable font size are each switched off on the pages where they would fight
 * the content instead of helping it. This class is the single place that turns such an options
 * array, the current request and the current title into one boolean answer: should the feature be
 * *disabled* on the page being rendered right now?
 *
 * Each feature brings its own rules, declared declaratively in `skin.json` — the width feature as
 * `$wgNotionMaxWidthOptions`, the font-size feature as `$wgNotionFontSizeConfigurableOptions` —
 * and those rule sets are genuinely different, so nothing here should be read as one shared list
 * of excluded pages. As shipped, the width rules exclude the main page, the Special and Category
 * namespaces, and requests carrying `action=history|edit|submit` or any `diff`, while re-enabling
 * `Special:Preferences` by name; the font-size rules leave the main page alone, add the File
 * namespace and the IDs 100 and 710 (which are wiki-specific rather than core namespaces) to the
 * exclusions, exclude a wider set of `action` values, exclude no `diff`, and re-enable nothing.
 * Reading either set off the other gets the answer wrong. What the two share is the evaluation
 * *procedure* below, not the pages it is applied to.
 *
 * Callers reach it as the `Notion.ConfigHelper` service, which `includes/ServiceWiring.php`
 * constructs once per request and injects into the feature-management layer. It is therefore
 * deliberately stateless — the special-page factory is its only collaborator and the answer
 * depends exclusively on the arguments passed in. Nothing is cached or memoised: the same
 * instance is asked about different option sets, and a cache keyed on anything less than the
 * whole (options, request, title) triple would silently return the wrong answer.
 *
 * The order in which the checks are applied is behaviour, not presentation, and it is documented
 * step by step on {@see self::shouldDisable}. Two of those steps are deliberately
 * counter-intuitive; both are load-bearing and neither may be reordered.
 *
 * @package Notion
 * @internal This class is an implementation detail of the Notion skin. No other extension or
 *   skin may depend on it, and it carries no stability guarantee across releases.
 * @since 1.0.0
 */
class ConfigHelper {

	/**
	 * @param SpecialPageFactory $specialPageFactory Resolves special-page aliases so that a
	 *   configuration entry written with a canonical English name, for example
	 *   `Special:Preferences`, still matches a request that arrived under a localised alias or a
	 *   redirect of that special page.
	 */
	public function __construct(
		private readonly SpecialPageFactory $specialPageFactory,
	) {
	}

	/**
	 * Determine whether the configuration should be disabled on the page.
	 *
	 * @param array $options read from MediaWiki configuration.
	 *   $options = [
	 *      'exclude' => [
	 *            'mainpage' => (bool) should it be disabled on the main page?
	 *            'namespaces' => int[] namespaces it should be excluded on.
	 *            'querystring' => array of strings mapping to regex for patterns
	 *                     the query strings it should be excluded on
	 *                     e.g. [ 'action' => '*' ] disable on all actions
	 *            'pagetitles' => string[] of pages it should be excluded on.
	 *                     For special pages, use canonical English name.
	 *      ],
	 *      'include' => string[] of pages it should be enabled on, even when one of the
	 *               exclusions above would otherwise switch it off.
	 *               For special pages, use canonical English name.
	 *   ]
	 * @param WebRequest $request
	 * @param Title|null $title
	 *
	 * @return bool True when the feature the options describe must be disabled on this page.
	 */
	public function shouldDisable( array $options, WebRequest $request, ?Title $title = null ): bool {
		// Every title comparison below is made against the *root* title, so a subpage such as
		// `User:Example/sandbox` is evaluated as `User:Example`. Derived up front, before any
		// branch, because more than one of the checks that follow needs it.
		$canonicalTitle = $title ? $title->getRootTitle() : null;

		$exclusions = $options['exclude'] ?? [];
		$inclusions = $options['include'] ?? [];

		// Query string exclusions are evaluated first, and they short-circuit everything else.
		//
		// The first configured parameter that is actually *present* on the request decides the
		// whole answer: its pattern is tested against the parameter's value and the result is
		// returned immediately, including when the pattern does not match. A non-match therefore
		// answers false rather than falling through to the checks below, and no later configured
		// parameter is consulted. Parameters absent from the request are skipped entirely, so
		// iteration order only matters among the parameters the request actually carries.
		$excludeQueryString = $exclusions['querystring'] ?? [];
		foreach ( $excludeQueryString as $param => $excludedParamPattern ) {
			$paramValue = $request->getRawVal( $param );
			if ( $paramValue !== null ) {
				if ( $excludedParamPattern === '*' ) {
					// Backwards compatibility for the '*' wildcard.
					$excludedParamPattern = '.+';
				}
				return (bool)preg_match( "/$excludedParamPattern/", $paramValue );
			}
		}

		if ( $title && $title->isMainPage() ) {
			// only one check to make
			// The main page is a page like no other: it is answered here, before the special-page
			// resolution and before either title list, so neither an inclusion nor a page-title
			// or namespace exclusion can contradict the explicit `mainpage` setting. The cast
			// keeps the declared return type honest for configuration that supplies a truthy
			// value rather than a strict boolean.
			return (bool)( $exclusions['mainpage'] ?? false );
		}
		if ( $canonicalTitle && $canonicalTitle->isSpecialPage() ) {
			// Configuration names special pages canonically, in English, while the request may
			// have arrived under a localised alias. Rewriting the title to its canonical form
			// here is what lets both title lists below compare like with like. The second
			// element of the resolved pair is the subpage parameter, which plays no part in that
			// comparison because the lists match on the special page itself.
			[ $canonicalName, $par ] = $this->specialPageFactory->resolveAlias( $canonicalTitle->getDBkey() );
			if ( $canonicalName ) {
				$canonicalTitle = Title::makeTitle( NS_SPECIAL, $canonicalName );
			}
		}

		//
		// Check the inclusions based on the canonical title
		// The inclusions are checked first as these trump any exclusions.
		//
		// This is what lets `'include' => [ 'Special:Preferences' ]` keep a feature switched on
		// for that one page even though NS_SPECIAL appears in the excluded namespaces: the
		// inclusion is found here and returns before the page-title and namespace exclusions
		// below are ever reached.
		foreach ( $inclusions as $titleText ) {
			$includedTitle = Title::newFromText( $titleText );

			// Both operands are guarded purely for null safety, and neither guard can change the
			// outcome: a title the configuration could not parse and a request that carried no
			// title at all can never be equal to a real page.
			if ( $canonicalTitle && $includedTitle && $canonicalTitle->equals( $includedTitle ) ) {
				return false;
			}
		}

		//
		// Check the excluded page titles based on the canonical title
		//
		// Special pages listed in the configuration match here rather than above because the
		// canonical title was normalised for exactly that purpose.
		$pageTitles = $exclusions['pagetitles'] ?? [];
		foreach ( $pageTitles as $titleText ) {
			$excludedTitle = Title::newFromText( $titleText );

			if ( $canonicalTitle && $excludedTitle && $canonicalTitle->equals( $excludedTitle ) ) {
				return true;
			}
		}

		//
		// Check the exclusions
		// If nothing matches the exclusions to determine what should happen
		//
		// The namespace check is the last word. It is evaluated against the title as requested
		// rather than its root, and the leading conjunct means a request without a title answers
		// false here instead of failing, however many namespaces are configured.
		$excludeNamespaces = $exclusions['namespaces'] ?? [];
		return $title && $title->inNamespaces( $excludeNamespaces );
	}
}
