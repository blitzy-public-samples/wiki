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

namespace MediaWiki\Skins\Notion\Tests\Integration\Components;

use MediaWiki\Context\RequestContext;
use MediaWiki\Linker\Linker;
use MediaWiki\Skins\Notion\Components\NotionComponentUserLinks;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * Tests for the one branch of `NotionComponentUserLinks::getDropdown()` that needs real services.
 *
 * When core supplies no personal-page icon the component substitutes `userAvatar` and replaces the
 * dropdown's tooltip with `Linker::tooltip( 'notion-anon-user-menu-title' )`. That call resolves a
 * message against the main request context, so it cannot run inside `MediaWikiUnitTestCase`; every
 * other property of the component is covered by the unit test of the same name, which keeps the
 * icon non-empty precisely to stay inside that boundary. Rather than leave the branch untested or
 * stub a global, it is exercised here against the loaded skin.
 *
 * Two things are asserted that only an integration test can reach:
 *
 *  1. The substitution itself, in both directions — the fallback applies when there is a menu to
 *     describe, and does not apply when the menu is empty, since a tooltip on a control that is
 *     hidden outright would describe nothing.
 *  2. That the two messages involved really exist in the skin's `i18n/en.json`. This is the reason
 *     the message name passed to `Linker::tooltip()` is unprefixed while the declared key carries
 *     the `tooltip-` prefix (T287494): the two are easy to get out of step, and a missing message
 *     would otherwise surface as a tooltip silently missing from the rendered page.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentUserLinks
 */
class NotionComponentUserLinksTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		// The assertions below name the English strings from the skin's own i18n/en.json, so the
		// interface language is pinned rather than inherited from the test wiki's configuration.
		$this->setUserLang( 'en' );
	}

	/**
	 * @param string $userIcon
	 * @param int $itemCount
	 * @return array the dropdown's template data
	 */
	private function buildDropdownData( string $userIcon, int $itemCount ): array {
		$items = [];
		for ( $i = 0; $i < $itemCount; $i++ ) {
			$items[] = [
				'name' => 'login',
				'id' => 'pt-login-' . $i,
				'class' => 'mw-list-item',
				'array-links' => [ [
					'icon' => 'logIn',
					'array-attributes' => [ [ 'key' => 'href', 'value' => '/login' ] ],
					'text' => 'Log in',
				] ],
			];
		}

		$component = new NotionComponentUserLinks(
			RequestContext::getMain(),
			UserIdentityValue::newAnonymous( '127.0.0.1' ),
			$this->getServiceContainer()->getUserNameUtils(),
			[
				'data-user-menu' => [
					'id' => 'p-personal',
					'class' => '',
					'html-tooltip' => '',
					'array-items' => $items,
				],
			],
			$userIcon
		);

		return $component->getTemplateData()['data-user-links-dropdown'];
	}

	/**
	 * @covers ::getDropdown
	 */
	public function testMissingUserIconFallsBackToTheAvatarAndTheAnonTooltip() {
		$dropdown = $this->buildDropdownData( '', 2 );

		$this->assertSame( 'userAvatar', $dropdown['icon'],
			'With no icon from core the dropdown still needs an indicator, so the avatar stands in.' );
		$this->assertSame(
			Linker::tooltip( 'notion-anon-user-menu-title' ),
			$dropdown['html-tooltip'],
			'The tooltip must come from Linker for the unprefixed name, which is what resolves the '
				. 'skin\'s declared tooltip-notion-anon-user-menu-title message (T287494).'
		);
		$this->assertSame( ' title="More options"', $dropdown['html-tooltip'],
			'An anonymous viewer\'s menu also holds a donate link, so core\'s "User menu" wording '
				. 'is deliberately overridden by this skin\'s own message.' );
	}

	/**
	 * @covers ::getDropdown
	 */
	public function testAnEmptyMenuKeepsThePersonalToolsTooltipAndNoIcon() {
		$dropdown = $this->buildDropdownData( '', 0 );

		$this->assertSame( '', $dropdown['icon'],
			'With nothing in the menu there is nothing to indicate, so no icon is substituted.' );
		$this->assertSame( ' title="Personal settings"', $dropdown['html-tooltip'],
			'The fallback is conditional on the menu having content; otherwise the component\'s own '
				. 'personal-tools tooltip stands.' );
	}

	/**
	 * @covers ::getDropdown
	 */
	public function testAnIconSuppliedByCoreIsNeverOverridden() {
		$dropdown = $this->buildDropdownData( 'userTemporary', 2 );

		$this->assertSame( 'userTemporary', $dropdown['icon'] );
		$this->assertSame( ' title="Personal settings"', $dropdown['html-tooltip'],
			'The anon-specific tooltip belongs to the fallback path only.' );
	}

	/**
	 * Both messages the dropdown depends on are declared by this skin.
	 *
	 * @covers ::getDropdown
	 */
	public function testTheTwoDropdownMessagesExist() {
		$this->assertTrue( wfMessage( 'notion-personal-tools-tooltip' )->exists(),
			'i18n/en.json must declare the personal-tools tooltip.' );
		$this->assertTrue( wfMessage( 'tooltip-notion-anon-user-menu-title' )->exists(),
			'Linker::tooltip() is given the name unprefixed, so the declared key is the prefixed '
				. 'one; a mismatch between the two loses the tooltip silently.' );
	}
}
