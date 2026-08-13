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

namespace MediaWiki\Skins\Notion\Tests\Structure;

use MediaWiki\Skins\Notion\Constants;
use MediaWikiIntegrationTestCase;
use ReflectionClass;

/**
 * Asserts that the skin's configuration surface is closed on both sides.
 *
 * `Constants` names configuration variables and user preferences as PHP constants; `skin.json`
 * declares them to MediaWiki. Nothing in the language ties the two together, and a mismatch is
 * silent in the worst possible way:
 *
 *   - A `CONFIG_KEY_*` constant naming a variable the manifest does not declare produces a
 *     `ConfigException` from `Config::get()` at request time - not a default - because MediaWiki
 *     has no registration for it. Whichever page first reaches that requirement is the one that
 *     breaks, which may be long after the constant was added.
 *   - A `PREF_KEY_*` constant with no `DefaultUserOptions` entry reads back as null for every
 *     user who has never set it, so a pinned region silently renders unpinned on a fresh account
 *     while working perfectly for the developer who already has the row in their user_properties.
 *   - A declared option with no constant behind it is dead configuration: documented to wiki
 *     administrators, settable, and read by nothing.
 *
 * The test therefore reflects `Constants` rather than listing its members, so a constant added
 * tomorrow is covered without anyone remembering to extend this file, and it reads `skin.json`
 * from disk so that the assertion is about the manifest that ships rather than about the
 * registry's merged view of it.
 *
 * Two kinds of key are deliberately excluded, and each exclusion is narrow and named:
 *
 *   - Core-owned keys the skin consults but does not own: `FullyInitialised` (a core global that
 *     `DynamicConfigRequirement` reads) and the `skin` user preference (core's own). A skin may
 *     not declare either, so requiring a manifest entry for them would be wrong rather than
 *     strict. Both are matched by name, not by pattern, so a skin-owned key can never fall
 *     through this hole.
 *   - Manifest keys consumed only as ResourceLoader module configuration, which reach the client
 *     through a `config` list in `skin.json` and are never read through a PHP constant. Those are
 *     listed explicitly below with the module that reads each one.
 *
 * @group Notion
 * @coversNothing
 */
class ConfigurationClosureTest extends MediaWikiIntegrationTestCase {

	/**
	 * Configuration variables the skin reads but core owns and declares.
	 *
	 * `FullyInitialised` is set by core's `Setup.php`, and
	 * `FeatureManagement\Requirements\DynamicConfigRequirement` is what consults it.
	 */
	private const CORE_OWNED_CONFIG_KEYS = [
		'FullyInitialised',
	];

	/**
	 * User preferences the skin reads but core owns and declares.
	 *
	 * `skin` is core's own preference; the skin reads it to know which skin a user has chosen.
	 */
	private const CORE_OWNED_PREFERENCE_KEYS = [
		'skin',
	];

	/**
	 * Declared variables that reach their consumer as ResourceLoader module configuration.
	 *
	 * `NotionTableOfContentsCollapseAtCount` is exported to the client through the `config` list
	 * of the `skins.notion.js` module in `skin.json`, where the variable is named as a string
	 * rather than through a PHP constant, so no `CONFIG_KEY_*` constant is expected for it.
	 */
	private const CLIENT_ONLY_CONFIG_KEYS = [
		'NotionTableOfContentsCollapseAtCount',
	];

	/**
	 * The skin's own manifest, decoded.
	 *
	 * @return array
	 */
	private function getManifest(): array {
		$path = dirname( __DIR__, 3 ) . '/skin.json';
		$this->assertFileExists( $path, 'The skin manifest must exist.' );

		$manifest = json_decode( (string)file_get_contents( $path ), true );
		$this->assertIsArray( $manifest, 'The skin manifest must be valid JSON.' );

		return $manifest;
	}

	/**
	 * Constant name to value, for every constant whose name matches a prefix.
	 *
	 * @param string $prefix Constant-name prefix, for example `CONFIG_` or `PREF_KEY_`.
	 * @return array<string,mixed> Constant name to declared value.
	 */
	private function getConstantsByPrefix( string $prefix ): array {
		$constants = ( new ReflectionClass( Constants::class ) )->getConstants();

		return array_filter(
			$constants,
			static fn ( $name ) => str_starts_with( $name, $prefix ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Every skin-owned configuration variable a constant names is declared by the manifest.
	 */
	public function testEveryConfigConstantIsDeclared() {
		$declared = array_keys( $this->getManifest()['config'] ?? [] );
		$this->assertNotSame( [], $declared, 'The manifest must declare configuration.' );

		$checked = 0;
		foreach ( $this->getConstantsByPrefix( 'CONFIG_' ) as $name => $value ) {
			// CONFIG_DEFAULT_* constants hold default *values* rather than variable names, so
			// they are not part of this contract; only string-valued names are.
			if ( !is_string( $value ) || in_array( $value, self::CORE_OWNED_CONFIG_KEYS, true ) ) {
				continue;
			}

			$this->assertContains(
				$value,
				$declared,
				"Constants::$name names the configuration variable \$wg$value, which skin.json "
					. 'does not declare. Config::get() raises a ConfigException for an undeclared '
					. 'option, so either declare it or drop the constant.'
			);
			$this->assertStringStartsWith(
				'Notion',
				$value,
				"Constants::$name names a skin-owned variable, so its name must carry the "
					. "skin's own prefix."
			);
			$checked++;
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'The reflection must find skin-owned CONFIG_ constants; finding none would mean this '
				. 'test passes without asserting anything.'
		);
	}

	/**
	 * Every declared configuration variable has a constant, or is client-only by declaration.
	 */
	public function testEveryDeclaredConfigVariableHasAConstant() {
		$constantValues = array_values( $this->getConstantsByPrefix( 'CONFIG_' ) );

		foreach ( array_keys( $this->getManifest()['config'] ?? [] ) as $declared ) {
			if ( in_array( $declared, self::CLIENT_ONLY_CONFIG_KEYS, true ) ) {
				continue;
			}

			$this->assertContains(
				$declared,
				$constantValues,
				"skin.json declares \$wg$declared, but no Constants::CONFIG_* constant names it. "
					. 'Declared configuration nothing reads is dead configuration: either add the '
					. 'constant and its consumer, or remove the declaration.'
			);
		}
	}

	/**
	 * Every skin-owned user preference a constant names has a declared default.
	 */
	public function testEveryPreferenceConstantHasADefault() {
		$defaults = $this->getManifest()['DefaultUserOptions'] ?? [];
		$this->assertNotSame( [], $defaults, 'The manifest must declare preference defaults.' );

		$checked = 0;
		foreach ( $this->getConstantsByPrefix( 'PREF_KEY_' ) as $name => $value ) {
			if ( in_array( $value, self::CORE_OWNED_PREFERENCE_KEYS, true ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				$value,
				$defaults,
				"Constants::$name names the user preference $value, which skin.json does not give "
					. 'a default. Without one it reads back as null for every user who has never '
					. 'set it.'
			);
			$this->assertStringStartsWith(
				'notion-',
				$value,
				"Constants::$name names a skin-owned preference, so it must carry the skin's own "
					. 'lower-case prefix.'
			);
			$checked++;
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'The reflection must find skin-owned PREF_KEY_ constants.'
		);
	}

	/**
	 * Every declared preference default is named by a constant.
	 */
	public function testEveryDeclaredPreferenceHasAConstant() {
		$constantValues = array_values( $this->getConstantsByPrefix( 'PREF_KEY_' ) );

		foreach ( array_keys( $this->getManifest()['DefaultUserOptions'] ?? [] ) as $preference ) {
			$this->assertContains(
				$preference,
				$constantValues,
				"skin.json declares a default for $preference, but no Constants::PREF_KEY_* "
					. 'constant names it, so nothing in the skin can read it without repeating '
					. 'the literal.'
			);
		}
	}

	/**
	 * Every declared configuration variable is registered with MediaWiki at runtime.
	 *
	 * The three assertions above compare two files with each other; this one compares the manifest
	 * with the wiki that loaded it, which is what proves the declarations are actually in effect
	 * rather than merely well formed. A variable declared under the wrong manifest key - `configs`
	 * rather than `config`, say, or nested one level too deep - would satisfy every other test in
	 * this class and still be missing from `Config`.
	 */
	public function testEveryDeclaredConfigVariableIsRegistered() {
		$config = $this->getServiceContainer()->getMainConfig();

		foreach ( array_keys( $this->getManifest()['config'] ?? [] ) as $declared ) {
			$this->assertTrue(
				$config->has( $declared ),
				"skin.json declares \$wg$declared, but MediaWiki has no such configuration "
					. 'variable registered, so Config::get() would raise a ConfigException.'
			);
		}
	}
}
