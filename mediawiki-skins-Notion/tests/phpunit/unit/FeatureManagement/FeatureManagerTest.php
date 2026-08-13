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

namespace MediaWiki\Skins\Notion\Tests\Unit\FeatureManagement;

use InvalidArgumentException;
use LogicException;
use MediaWiki\Config\HashConfig;
use MediaWiki\Context\IContextSource;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skins\Notion\ConfigHelper;
use MediaWiki\Skins\Notion\Constants;
use MediaWiki\Skins\Notion\FeatureManagement\FeatureManager;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use RuntimeException;
use Wikimedia\Assert\ParameterElementTypeException;
use Wikimedia\Assert\ParameterTypeException;

/**
 * Unit tests for the Notion skin's feature manager.
 *
 * The manager does two jobs, and the second is the one with visible consequences. It answers
 * `isFeatureEnabled()` by AND-ing a feature's requirements, and it renders
 * `getFeatureBodyClass()`, whose output lands on the `<html>` element and is what every layout
 * stylesheet, `features.js` and the client-preference scripts select on. A wrong class there is
 * not an error: the page renders, and the sidebar is simply in the wrong place, or a reader's
 * chosen theme silently reverts to day.
 *
 * The class-name vocabulary is therefore asserted precisely, because three parts of it are
 * load-bearing and none is self-evident:
 *
 *   - the camel-case feature name becomes hyphenated lower case, so `PageToolsPinned` must render
 *     as `page-tools-pinned`;
 *   - a `clientpref-` suffix marks a class the browser is allowed to rewrite, and the features
 *     that only persist server-side deliberately use `enabled`/`disabled` instead, so that
 *     `features.js` is never pointed at a class no amount of clicking can change;
 *   - night mode drops the skin's own prefix entirely and renders `skin-theme-*`, which is what
 *     lets editors target one class across skins.
 *
 * Every collaborator is a mock or one of core's in-memory implementations, so no service
 * container, database or global is touched.
 *
 * @group Notion
 * @group FeatureManagement
 * @coversDefaultClass \MediaWiki\Skins\Notion\FeatureManagement\FeatureManager
 */
class FeatureManagerTest extends MediaWikiUnitTestCase {

	/**
	 * Build a manager over mocked collaborators.
	 *
	 * @param array $options Keyed overrides:
	 *   - `preferences`: array mapping a preference key to its stored value.
	 *   - `queryString`: array of request parameters.
	 *   - `config`: array of configuration values.
	 *   - `shouldDisable`: bool the config helper answers with.
	 *   - `title`: Title|null the context reports.
	 * @return FeatureManager
	 */
	private function newFeatureManager( array $options = [] ): FeatureManager {
		$preferences = $options['preferences'] ?? [];
		$user = $this->createMock( User::class );

		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$userOptionsLookup->method( 'getOption' )->willReturnCallback(
			static fn ( $_user, $key ) => $preferences[$key] ?? null
		);

		$configHelper = $this->createMock( ConfigHelper::class );
		$configHelper->method( 'shouldDisable' )
			->willReturn( $options['shouldDisable'] ?? false );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'getUser' )->willReturn( $user );
		$context->method( 'getRequest' )
			->willReturn( new FauxRequest( $options['queryString'] ?? [] ) );
		$context->method( 'getConfig' )->willReturn( new HashConfig( ( $options['config'] ?? [] ) + [
			Constants::CONFIG_KEY_FONT_SIZE_CONFIGURABLE_OPTIONS => [],
		] ) );
		$context->method( 'getTitle' )
			->willReturn( $options['title'] ?? $this->createMock( Title::class ) );

		return new FeatureManager( $configHelper, $userOptionsLookup, $context );
	}

	/**
	 * A feature is enabled only when every one of its requirements is met.
	 *
	 * @covers ::registerSimpleRequirement
	 * @covers ::registerFeature
	 * @covers ::isFeatureEnabled
	 * @covers ::isRequirementMet
	 */
	public function testFeaturesAndRequirementsAreAnded() {
		$featureManager = $this->newFeatureManager();
		$featureManager->registerSimpleRequirement( 'metA', true );
		$featureManager->registerSimpleRequirement( 'metB', true );
		$featureManager->registerSimpleRequirement( 'unmet', false );

		// The single-requirement shorthand and the list form must agree.
		$featureManager->registerFeature( 'shorthand', 'metA' );
		$featureManager->registerFeature( 'allMet', [ 'metA', 'metB' ] );
		$featureManager->registerFeature( 'oneUnmet', [ 'metA', 'unmet' ] );
		$featureManager->registerFeature( 'unmetFirst', [ 'unmet', 'metA' ] );
		$featureManager->registerFeature( 'noRequirements', [] );

		$this->assertTrue( $featureManager->isFeatureEnabled( 'shorthand' ) );
		$this->assertTrue( $featureManager->isFeatureEnabled( 'allMet' ) );
		$this->assertFalse(
			$featureManager->isFeatureEnabled( 'oneUnmet' ),
			'One unmet requirement disables the feature, whatever its siblings say.'
		);
		$this->assertFalse( $featureManager->isFeatureEnabled( 'unmetFirst' ) );
		$this->assertTrue(
			$featureManager->isFeatureEnabled( 'noRequirements' ),
			'A feature with no requirements is vacuously enabled.'
		);

		$this->assertTrue( $featureManager->isRequirementMet( 'metA' ) );
		$this->assertFalse( $featureManager->isRequirementMet( 'unmet' ) );
	}

	/**
	 * Every way of misusing the registry raises, rather than being silently absorbed.
	 *
	 * These are boot-time programming errors, and each exception is what turns a mis-ordered or
	 * duplicated registration into an immediate failure instead of a feature that quietly never
	 * switches on.
	 *
	 * @covers ::registerFeature
	 * @covers ::registerRequirement
	 * @covers ::registerSimpleRequirement
	 * @covers ::isFeatureEnabled
	 * @covers ::isRequirementMet
	 */
	public function testRegistrationErrors() {
		$featureManager = $this->newFeatureManager();
		$featureManager->registerSimpleRequirement( 'requirementA', true );
		$featureManager->registerFeature( 'featureA', 'requirementA' );

		// A feature referencing a requirement that has not been registered yet.
		try {
			$featureManager->registerFeature( 'featureB', 'requirementB' );
			$this->fail( 'An unregistered requirement must be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'requirementB', $e->getMessage() );
		}

		// A duplicate feature.
		try {
			$featureManager->registerFeature( 'featureA', 'requirementA' );
			$this->fail( 'A duplicate feature must be rejected.' );
		} catch ( LogicException $e ) {
			$this->assertStringContainsString( 'featureA', $e->getMessage() );
		}

		// A duplicate requirement.
		try {
			$featureManager->registerSimpleRequirement( 'requirementA', false );
			$this->fail( 'A duplicate requirement must be rejected.' );
		} catch ( LogicException $e ) {
			$this->assertStringContainsString( 'requirementA', $e->getMessage() );
		}

		// Requirements of the wrong type, and a list holding the wrong type.
		try {
			// @phan-suppress-next-line PhanTypeMismatchArgument Asserting the rejection.
			$featureManager->registerFeature( 'featureC', 42 );
			$this->fail( 'A non-string, non-array requirement set must be rejected.' );
		} catch ( ParameterTypeException $e ) {
			$this->assertStringContainsString( 'requirements', $e->getMessage() );
		}
		try {
			$featureManager->registerFeature( 'featureD', [ 42 ] );
			$this->fail( 'A requirement list holding a non-string must be rejected.' );
		} catch ( ParameterElementTypeException $e ) {
			$this->assertStringContainsString( 'requirements', $e->getMessage() );
		}

		// Querying things that were never registered.
		$this->expectException( InvalidArgumentException::class );
		$featureManager->isFeatureEnabled( 'neverRegistered' );
	}

	/**
	 * The stored preference value is read through the context's user.
	 *
	 * @covers ::getUserPreferenceValue
	 */
	public function testGetUserPreferenceValue() {
		$featureManager = $this->newFeatureManager( [
			'preferences' => [ Constants::PREF_KEY_FONT_SIZE => '2' ],
		] );

		$this->assertSame( '2', $featureManager->getUserPreferenceValue( Constants::PREF_KEY_FONT_SIZE ) );
		$this->assertNull(
			$featureManager->getUserPreferenceValue( Constants::PREF_KEY_TOC_PINNED ),
			'An unset preference reads back as null, and the caller decides what that means.'
		);
	}

	/**
	 * The body classes for the features that persist for every reader.
	 *
	 * @covers ::getFeatureBodyClass
	 */
	public function testClientPreferenceBodyClasses() {
		$featureManager = $this->newFeatureManager();
		$featureManager->registerSimpleRequirement( 'met', true );
		$featureManager->registerSimpleRequirement( 'unmet', false );
		$featureManager->registerFeature( Constants::FEATURE_TOC_PINNED, 'met' );
		$featureManager->registerFeature( Constants::FEATURE_APPEARANCE_PINNED, 'unmet' );
		$featureManager->registerFeature( Constants::FEATURE_LIMITED_WIDTH, 'met' );

		$this->assertSame(
			[
				'notion-feature-toc-pinned-clientpref-1',
				'notion-feature-appearance-pinned-clientpref-0',
				'notion-feature-limited-width-clientpref-1',
			],
			$featureManager->getFeatureBodyClass(),
			'One class per feature, in registration order, camel case hyphenated, with the '
				. 'clientpref suffix that marks a class the browser may rewrite.'
		);
	}

	/**
	 * The body classes for the features that only the server can change.
	 *
	 * The absence of a `clientpref-` suffix here is the point: `features.js` refuses to rewrite
	 * these, so a suffix mix-up would hand the browser a class it cannot usefully toggle.
	 *
	 * @covers ::getFeatureBodyClass
	 */
	public function testServerOnlyBodyClasses() {
		$featureManager = $this->newFeatureManager();
		$featureManager->registerSimpleRequirement( 'met', true );
		$featureManager->registerSimpleRequirement( 'unmet', false );
		$featureManager->registerFeature( Constants::FEATURE_MAIN_MENU_PINNED, 'met' );
		$featureManager->registerFeature( Constants::FEATURE_PAGE_TOOLS_PINNED, 'unmet' );
		$featureManager->registerFeature( Constants::FEATURE_LANGUAGE_IN_HEADER, 'met' );
		$featureManager->registerFeature( Constants::FEATURE_LIMITED_WIDTH_CONTENT, 'unmet' );
		$featureManager->registerFeature( Constants::FEATURE_NAVIGATION_UPDATE, 'met' );

		$this->assertSame(
			[
				'notion-feature-main-menu-pinned-enabled',
				'notion-feature-page-tools-pinned-disabled',
				'notion-feature-language-in-header-enabled',
				'notion-feature-limited-width-content-disabled',
				'notion-feature-navigation-update-enabled',
			],
			$featureManager->getFeatureBodyClass()
		);
	}

	/**
	 * The font-size class carries the stored value, and an excluded page suppresses it.
	 *
	 * The three-state class is what the appearance panel's font-size control reads, and
	 * `clientpref--excluded` is how a page on which the control does not apply says so. Both are
	 * asserted, because an excluded page that still advertised a size would render a control the
	 * page cannot honour.
	 *
	 * @covers ::getFeatureBodyClass
	 */
	public function testFontSizeBodyClass() {
		$featureManager = $this->newFeatureManager( [
			'preferences' => [ Constants::PREF_KEY_FONT_SIZE => '2' ],
		] );
		$featureManager->registerSimpleRequirement( 'met', true );
		$featureManager->registerFeature( Constants::FEATURE_FONT_SIZE, 'met' );

		$this->assertSame(
			[ 'notion-feature-custom-font-size-clientpref-2' ],
			$featureManager->getFeatureBodyClass(),
			'The stored size becomes the suffix, so the panel can show which one is active.'
		);

		// A page the configuration excludes from the control.
		$excluded = $this->newFeatureManager( [
			'preferences' => [ Constants::PREF_KEY_FONT_SIZE => '2' ],
			'shouldDisable' => true,
		] );
		$excluded->registerSimpleRequirement( 'met', true );
		$excluded->registerFeature( Constants::FEATURE_FONT_SIZE, 'met' );

		$this->assertSame(
			[ 'notion-feature-custom-font-size-clientpref--excluded' ],
			$excluded->getFeatureBodyClass(),
			'An excluded page advertises the exclusion instead of a size.'
		);
	}

	/**
	 * Night mode renders a skin-agnostic class, and the query string overrides the preference.
	 *
	 * The class is deliberately `skin-theme-*` with no `notion-` prefix, so that editors can target
	 * the same class in Minerva and here. The query-string aliases are what make a theme linkable
	 * for testing without changing a stored preference.
	 *
	 * @covers ::getFeatureBodyClass
	 */
	public function testNightModeBodyClass() {
		$stored = $this->newFeatureManager( [
			'preferences' => [ Constants::PREF_KEY_NIGHT_MODE => 'night' ],
		] );
		$stored->registerSimpleRequirement( 'met', true );
		$stored->registerFeature( Constants::PREF_NIGHT_MODE, 'met' );

		$this->assertSame(
			[ 'skin-theme-clientpref-night' ],
			$stored->getFeatureBodyClass(),
			'The stored theme becomes the suffix, under the cross-skin class name.'
		);

		// Every accepted query-string spelling, including the numeric aliases and the fallback for
		// a value that means nothing.
		$expectedByQueryValue = [
			'day' => 'day',
			'night' => 'night',
			'os' => 'os',
			'1' => 'night',
			'2' => 'os',
			'not-a-theme' => 'day',
			'' => 'day',
		];
		foreach ( $expectedByQueryValue as $queryValue => $expectedTheme ) {
			$featureManager = $this->newFeatureManager( [
				'preferences' => [ Constants::PREF_KEY_NIGHT_MODE => 'night' ],
				'queryString' => [ 'notionnightmode' => (string)$queryValue ],
			] );
			$featureManager->registerSimpleRequirement( 'met', true );
			$featureManager->registerFeature( Constants::PREF_NIGHT_MODE, 'met' );

			$this->assertSame(
				[ 'skin-theme-clientpref-' . $expectedTheme ],
				$featureManager->getFeatureBodyClass(),
				"A notionnightmode of '$queryValue' must resolve to the $expectedTheme theme."
			);
		}

		// An unmet requirement falls back to the light theme rather than to the stored value.
		$disabled = $this->newFeatureManager( [
			'preferences' => [ Constants::PREF_KEY_NIGHT_MODE => 'night' ],
		] );
		$disabled->registerSimpleRequirement( 'unmet', false );
		$disabled->registerFeature( Constants::PREF_NIGHT_MODE, 'unmet' );
		$this->assertSame( [ 'skin-theme-clientpref-day' ], $disabled->getFeatureBodyClass() );
	}

	/**
	 * A feature with no class in the switch raises rather than rendering nothing.
	 *
	 * The switch and the registered feature set have to agree, and this is the assertion that
	 * makes drift between them loud: without it, a feature added to the factory and forgotten here
	 * would emit no class at all and the layout depending on it would silently never apply.
	 *
	 * @covers ::getFeatureBodyClass
	 */
	public function testUnknownFeatureHasNoBodyClass() {
		$featureManager = $this->newFeatureManager();
		$featureManager->registerSimpleRequirement( 'met', true );
		$featureManager->registerFeature( 'NotAFeatureWithAClass', 'met' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage(
			'Feature NotAFeatureWithAClass has no associated feature class.'
		);
		$featureManager->getFeatureBodyClass();
	}
}
