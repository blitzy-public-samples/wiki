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

namespace MediaWiki\Skins\Notion\Tests\Unit\FeatureManagement\Requirements;

use MediaWiki\Config\HashConfig;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Skins\Notion\ConfigHelper;
use MediaWiki\Skins\Notion\Constants;
use MediaWiki\Skins\Notion\FeatureManagement\Requirement;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\DynamicConfigRequirement;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\LimitedWidthContentRequirement;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\LoggedInRequirement;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\OverridableConfigRequirement;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\OverrideableRequirementHelper;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\SimpleRequirement;
use MediaWiki\Skins\Notion\FeatureManagement\Requirements\UserPreferenceRequirement;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the seven requirement implementations the Notion skin's feature gates are built
 * from.
 *
 * A requirement answers one question - "is this condition met right now?" - and the feature manager
 * asks nothing else of it. That makes each class small, and it makes the differences *between* them
 * the thing worth testing: which of them re-reads its collaborators on every call, which honours a
 * query-string override, and which distinguishes "no answer" from "false". Those three properties
 * are what decide whether a reader's pinned sidebar, limited content width or font size survives a
 * page load, and none of them is visible in the emitted markup.
 *
 * The classes are covered together because they share one contract - `Requirement`, whose whole
 * surface is `getName()` and `isMet()` - and because several of the interesting assertions are
 * comparative. Every collaborator is either a mock or one of core's own in-memory
 * implementations (`HashConfig`, `FauxRequest`), so nothing here touches a service container, a
 * database or a global.
 *
 * @group Notion
 * @group FeatureManagement
 */
class RequirementsTest extends MediaWikiUnitTestCase {

	/**
	 * A user identity that reports itself as registered or anonymous.
	 *
	 * @param bool $isRegistered
	 * @return UserIdentity
	 */
	private function newUser( bool $isRegistered ): UserIdentity {
		$user = $this->createMock( UserIdentity::class );
		$user->method( 'isRegistered' )->willReturn( $isRegistered );

		return $user;
	}

	/**
	 * A user-options lookup that answers with one value for any option.
	 *
	 * @param mixed $value
	 * @return UserOptionsLookup
	 */
	private function newUserOptionsLookup( $value ): UserOptionsLookup {
		$lookup = $this->createMock( UserOptionsLookup::class );
		$lookup->method( 'getOption' )->willReturn( $value );

		return $lookup;
	}

	/**
	 * The stored boolean is returned unchanged, and the name is passed through.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\SimpleRequirement::getName
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\SimpleRequirement::isMet
	 */
	public function testSimpleRequirement() {
		$met = new SimpleRequirement( Constants::REQUIREMENT_IS_MAIN_PAGE, true );
		$unmet = new SimpleRequirement( Constants::REQUIREMENT_IS_MAIN_PAGE, false );

		$this->assertInstanceOf( Requirement::class, $met );
		$this->assertSame( Constants::REQUIREMENT_IS_MAIN_PAGE, $met->getName() );
		$this->assertTrue( $met->isMet() );
		$this->assertFalse( $unmet->isMet() );

		// The value is captured at construction, so repeated calls cannot disagree. This is the
		// property that distinguishes it from DynamicConfigRequirement below.
		$this->assertSame( $met->isMet(), $met->isMet() );
	}

	/**
	 * Registration state decides the answer, and it is read from the identity, not cached.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\LoggedInRequirement::getName
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\LoggedInRequirement::isMet
	 */
	public function testLoggedInRequirement() {
		$named = new LoggedInRequirement(
			$this->newUser( true ),
			Constants::REQUIREMENT_LOGGED_IN
		);
		$anon = new LoggedInRequirement(
			$this->newUser( false ),
			Constants::REQUIREMENT_LOGGED_IN
		);

		$this->assertSame( Constants::REQUIREMENT_LOGGED_IN, $named->getName() );
		$this->assertTrue( $named->isMet(), 'A registered user meets the requirement.' );
		$this->assertFalse( $anon->isMet(), 'An anonymous user does not.' );
	}

	/**
	 * The configuration is re-read on every call, so a mid-request change is honoured.
	 *
	 * This is the whole reason the class exists: `FullyInitialised` is false while MediaWiki is
	 * still booting and true afterwards, so a requirement that cached its answer at construction
	 * would report the boot-time value for the rest of the request.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\DynamicConfigRequirement::getName
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\DynamicConfigRequirement::isMet
	 */
	public function testDynamicConfigRequirementRereadsItsConfig() {
		$config = new HashConfig( [ Constants::CONFIG_KEY_FULLY_INITIALISED => false ] );
		$requirement = new DynamicConfigRequirement(
			$config,
			Constants::CONFIG_KEY_FULLY_INITIALISED,
			Constants::REQUIREMENT_FULLY_INITIALISED
		);

		$this->assertSame( Constants::REQUIREMENT_FULLY_INITIALISED, $requirement->getName() );
		$this->assertFalse( $requirement->isMet(), 'The configured value is false.' );

		$config->set( Constants::CONFIG_KEY_FULLY_INITIALISED, true );
		$this->assertTrue(
			$requirement->isMet(),
			'A value that changed after construction must be picked up on the next call.'
		);

		// Truthy non-booleans are cast rather than rejected, which is what lets a wiki set the
		// variable to 1 or to a non-empty string.
		$config->set( Constants::CONFIG_KEY_FULLY_INITIALISED, 1 );
		$this->assertTrue( $requirement->isMet() );
		$config->set( Constants::CONFIG_KEY_FULLY_INITIALISED, '' );
		$this->assertFalse( $requirement->isMet() );
	}

	/**
	 * The override helper distinguishes "no override" from an override of false.
	 *
	 * Returning null rather than false for an absent parameter is what lets its two callers fall
	 * back to their own source of truth. If it answered false, every feature would appear to have
	 * been explicitly switched off by a request that said nothing about it at all.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\OverrideableRequirementHelper::isMet
	 */
	public function testOverrideableRequirementHelper() {
		$requirementName = 'LimitedWidth';

		$this->assertNull(
			( new OverrideableRequirementHelper( new FauxRequest(), $requirementName ) )->isMet(),
			'A request carrying no override must answer null, not false.'
		);

		// The lower-case spelling is the documented reader-facing one.
		$this->assertTrue(
			( new OverrideableRequirementHelper(
				new FauxRequest( [ 'notionlimitedwidth' => '1' ] ),
				$requirementName
			) )->isMet(),
			'A lower-case override of 1 enables the requirement.'
		);
		$this->assertFalse(
			( new OverrideableRequirementHelper(
				new FauxRequest( [ 'notionlimitedwidth' => '0' ] ),
				$requirementName
			) )->isMet(),
			'A lower-case override of 0 disables it, and is not mistaken for an absent override.'
		);

		// The camel-case spelling is accepted as well, and is checked second.
		$this->assertTrue(
			( new OverrideableRequirementHelper(
				new FauxRequest( [ 'NotionLimitedWidth' => '1' ] ),
				$requirementName
			) )->isMet(),
			'The camel-case spelling of the same override is honoured.'
		);
		$this->assertFalse(
			( new OverrideableRequirementHelper(
				new FauxRequest( [ 'notionlimitedwidth' => '0', 'NotionLimitedWidth' => '1' ] ),
				$requirementName
			) )->isMet(),
			'The lower-case spelling is consulted first, so it wins when both are present.'
		);
	}

	/**
	 * A stored preference decides the answer unless the request overrides it.
	 *
	 * The three falsy spellings are not interchangeable trivia: user options arrive from the
	 * database as strings, so `'0'` is what a disabled boolean preference actually looks like, and
	 * `'disabled'` is the spelling the client-preference scripts write for an enumerated one.
	 * Treating either as enabled would leave a reader's unpinned sidebar pinned again on the next
	 * page load.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\UserPreferenceRequirement::getName
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\UserPreferenceRequirement::isPreferenceEnabled
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\UserPreferenceRequirement::isMet
	 */
	public function testUserPreferenceRequirement() {
		$title = $this->createMock( Title::class );
		$newRequirement = fn ( $optionValue, $request, $titleOrNull = null ) =>
			new UserPreferenceRequirement(
				$this->newUser( true ),
				$this->newUserOptionsLookup( $optionValue ),
				Constants::PREF_KEY_TOC_PINNED,
				Constants::REQUIREMENT_TOC_PINNED,
				$request,
				$titleOrNull
			);

		$enabled = $newRequirement( 1, new FauxRequest(), $title );
		$this->assertSame( Constants::REQUIREMENT_TOC_PINNED, $enabled->getName() );
		$this->assertTrue( $enabled->isMet(), 'A stored 1 enables the feature.' );
		$this->assertTrue( $enabled->isPreferenceEnabled() );

		foreach ( [ 0, '0', 'disabled', '', null ] as $falsy ) {
			$this->assertFalse(
				$newRequirement( $falsy, new FauxRequest(), $title )->isMet(),
				'A stored ' . var_export( $falsy, true ) . ' must leave the feature disabled.'
			);
		}

		// Any other value counts as enabled, which is how enumerated preferences such as a theme
		// name are read.
		$this->assertTrue( $newRequirement( 'day', new FauxRequest(), $title )->isMet() );

		// No title means no page to render the feature on, whatever the preference says.
		$this->assertFalse(
			$newRequirement( 1, new FauxRequest() )->isMet(),
			'Without a title the requirement is unmet even for an enabled preference.'
		);

		// The request override wins in both directions, including over a title-less request.
		$this->assertFalse(
			$newRequirement( 1, new FauxRequest( [ 'notiontocpinned' => '0' ] ), $title )->isMet(),
			'An override of 0 disables a feature the preference had enabled.'
		);
		$this->assertTrue(
			$newRequirement( 0, new FauxRequest( [ 'notiontocpinned' => '1' ] ), $title )->isMet(),
			'An override of 1 enables a feature the preference had disabled.'
		);
	}

	/**
	 * Configuration is accepted in three shapes, and a request override beats all of them.
	 *
	 * All three shapes exist in production configuration, which is why the class normalises rather
	 * than validates. The audience selection is the part with teeth: a wiki that enables a feature
	 * for `logged_in` only must not have it leak to anonymous readers, and vice versa.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\OverridableConfigRequirement::getName
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\OverridableConfigRequirement::isMet
	 */
	public function testOverridableConfigRequirement() {
		$configName = Constants::CONFIG_KEY_LANGUAGE_IN_HEADER;
		$requirementName = Constants::REQUIREMENT_LANGUAGE_IN_HEADER;
		$newRequirement = fn ( $configValue, bool $isRegistered, $request ) =>
			new OverridableConfigRequirement(
				new HashConfig( [ $configName => $configValue ] ),
				$this->newUser( $isRegistered ),
				$request,
				$configName,
				$requirementName
			);

		$this->assertSame(
			$requirementName,
			$newRequirement( true, true, new FauxRequest() )->getName()
		);

		// Shape 1: a plain boolean applies to everybody.
		$this->assertTrue( $newRequirement( true, true, new FauxRequest() )->isMet() );
		$this->assertTrue( $newRequirement( true, false, new FauxRequest() )->isMet() );
		$this->assertFalse( $newRequirement( false, true, new FauxRequest() )->isMet() );

		// Shape 2: an explicit default applies to everybody and beats any sibling key.
		$default = [ 'default' => true, 'logged_in' => false, 'logged_out' => false ];
		$this->assertTrue( $newRequirement( $default, true, new FauxRequest() )->isMet() );
		$this->assertTrue( $newRequirement( $default, false, new FauxRequest() )->isMet() );

		// Shape 3: per-audience values, with an absent key read as false.
		$perAudience = [ 'logged_in' => true, 'logged_out' => false ];
		$this->assertTrue( $newRequirement( $perAudience, true, new FauxRequest() )->isMet() );
		$this->assertFalse( $newRequirement( $perAudience, false, new FauxRequest() )->isMet() );
		$this->assertFalse(
			$newRequirement( [ 'logged_in' => true ], false, new FauxRequest() )->isMet(),
			'An audience the configuration does not mention is read as false, not as a warning.'
		);

		// A request override wins over every shape, in both directions.
		$override = new FauxRequest( [ 'notionlanguageinheader' => '0' ] );
		$this->assertFalse( $newRequirement( true, true, $override )->isMet() );
		$override = new FauxRequest( [ 'notionlanguageinheader' => '1' ] );
		$this->assertTrue( $newRequirement( false, false, $override )->isMet() );
	}

	/**
	 * The limited-width requirement inverts the config helper's answer and needs a title.
	 *
	 * The inversion is the easy half to get backwards, and getting it backwards would apply the
	 * reading-column width to exactly the pages configured to be exempt from it. The title guard
	 * is a real runtime case rather than a testing convenience: the factory builds this
	 * requirement from a nullable `IContextSource::getTitle()`.
	 *
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\LimitedWidthContentRequirement::getName
	 * @covers \MediaWiki\Skins\Notion\FeatureManagement\Requirements\LimitedWidthContentRequirement::isMet
	 */
	public function testLimitedWidthContentRequirement() {
		$options = [ 'exclude' => [ 'mainpage' => true ] ];
		$config = new HashConfig( [ Constants::CONFIG_KEY_MAX_WIDTH_OPTIONS => $options ] );
		$request = new FauxRequest();
		$title = $this->createMock( Title::class );

		$newRequirement = function ( bool $shouldDisable, $titleOrNull ) use (
			$config, $request, $options, $title
		) {
			$configHelper = $this->createMock( ConfigHelper::class );
			$configHelper->method( 'shouldDisable' )
				// The options the helper is asked about must be the ones the manifest declares,
				// read through the constant rather than re-spelled here, and the title and request
				// must be forwarded untouched. A call with anything else fails the `with()`
				// constraint rather than quietly returning null.
				->with( $options, $request, $title )
				->willReturn( $shouldDisable );

			return new LimitedWidthContentRequirement(
				$config,
				$configHelper,
				$request,
				$titleOrNull
			);
		};

		$this->assertSame(
			Constants::REQUIREMENT_LIMITED_WIDTH_CONTENT,
			$newRequirement( false, $title )->getName()
		);
		$this->assertTrue(
			$newRequirement( false, $title )->isMet(),
			'A page the configuration does not exclude meets the requirement.'
		);
		$this->assertFalse(
			$newRequirement( true, $title )->isMet(),
			'A page the configuration excludes does not, so the helper answer is inverted.'
		);
		// A request without a title is unmet, and the helper must not be consulted at all: the
		// short circuit is what lets shouldDisableMaxWidth() declare a non-nullable Title.
		$untouchedHelper = $this->createMock( ConfigHelper::class );
		$untouchedHelper->expects( $this->never() )->method( 'shouldDisable' );
		$this->assertFalse(
			( new LimitedWidthContentRequirement( $config, $untouchedHelper, $request, null ) )
				->isMet(),
			'A request without a title is unmet and must not reach the helper at all.'
		);
	}
}
