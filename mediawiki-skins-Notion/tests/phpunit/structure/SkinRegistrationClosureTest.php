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

use MediaWikiIntegrationTestCase;

/**
 * Asserts that the skin's registration is closed on three axes: one skin, resolvable paths and
 * resolvable messages.
 *
 * Each axis fails in a different way when it is left open, and none of the three fails at the point
 * where the mistake was made:
 *
 *   - **One skin.** This skin registers exactly one name, `notion`. Registering a second variant —
 *     the way Vector registers both `vector` and `vector-2022` — would ship a skin nobody selects,
 *     built from partials nobody renders, and every later inventory would count it as delivered.
 *     The same singularity has to hold in the places that are keyed by skin name rather than by
 *     class: the Less import path, the third-party style overrides and the `skinname-*` message.
 *   - **Paths.** `remoteSkinPath`, `SkinLessImportPaths`, `MessagesDirs`, `templateDirectory` and
 *     `AutoloadNamespaces` are strings in a JSON file that nothing type-checks. A wrong one costs a
 *     404 on every asset, a token layer silently resolved from another skin's directory, or an
 *     unresolvable Mustache partial — and the last of those is a fatal `LightnCandy` exception at
 *     render time, because the template parser runs with `FLAG_ERROR_EXCEPTION`.
 *   - **Messages.** Every key the manifest declares must resolve. Core's own
 *     `SkinsTest::testConstructor` covers this once the skin can be instantiated; this test covers
 *     it from the manifest alone, so the guarantee does not depend on the skin class being
 *     constructible in the environment the suite runs in.
 *
 * The test reads `skin.json` and the `i18n/` files from disk rather than through
 * `ExtensionRegistry`, so what is asserted is the manifest that ships rather than the registry's
 * merged view of it, and nothing here needs the skin to be enabled to be meaningful.
 *
 * @group Notion
 * @coversNothing
 */
class SkinRegistrationClosureTest extends MediaWikiIntegrationTestCase {

	/** The one skin name this skin may register. */
	private const SKIN_KEY = 'notion';

	/**
	 * Message keys in `i18n/en.json` that do not carry the skin's own prefix, with the reason each
	 * is allowed to.
	 *
	 * All seven are reproduced from Vector verbatim because their names are the contract, not a
	 * label: `empty-language-selector-body` is the language-button's empty state, and the six
	 * `skin-theme-*` keys are the night-mode client preference, whose option names are deliberately
	 * prefix-less so that the preference is named the same way in every skin that offers it. Any
	 * other unprefixed key would be a message this skin has no business defining, so the list is
	 * exhaustive rather than illustrative.
	 */
	private const UNPREFIXED_MESSAGE_KEYS = [
		'empty-language-selector-body',
		'skin-theme-day-label',
		'skin-theme-description',
		'skin-theme-exclusion-notice',
		'skin-theme-name',
		'skin-theme-night-label',
		'skin-theme-os-label',
	];

	/**
	 * Every Mustache partial the modern component set may contain.
	 *
	 * This is the parity target the skin is specified against — the 42 partials of Vector's modern
	 * template tree, named as the templates name each other. It is listed here so that a partial
	 * reference which resolves to nothing can be reported as one of two different faults: a typo in
	 * a name that was never planned, or a template authored ahead of one it includes.
	 */
	private const PLANNED_TEMPLATES = [
		'skin',
		'Header',
		'Logo',
		'SearchBox',
		'UserLinks',
		'UserLinksDropdown',
		'MainMenu',
		'MainMenuDropdown',
		'MainMenuPinned',
		'Menu',
		'MenuContents',
		'MenuListItem',
		'Tabs',
		'PageTitlebar',
		'PageToolbar',
		'PageToolbarActions',
		'PageTools',
		'Indicators',
		'BeforeContent',
		'ColumnStart',
		'ColumnEnd',
		'TableOfContents',
		'TableOfContents__list',
		'TableOfContents__line',
		'PinnableHeader',
		'PinnableElement/Open',
		'PinnableElement/Close',
		'PinnableContainer/Pinned/Open',
		'PinnableContainer/Unpinned/Open',
		'PinnableContainer/Close',
		'StickyHeader',
		'BottomDock',
		'Appearance',
		'LanguageDropdown',
		'Variants',
		'Footer',
		'Footer__row',
		'Button',
		'Icon',
		'Link',
		'Dropdown/Open',
		'Dropdown/Close',
	];

	/**
	 * Absolute path of the skin's root directory.
	 *
	 * @return string
	 */
	private function getSkinDirectory(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * The skin's own manifest, decoded.
	 *
	 * @return array
	 */
	private function getManifest(): array {
		$path = $this->getSkinDirectory() . '/skin.json';
		$this->assertFileExists( $path, 'The skin manifest must exist.' );

		$manifest = json_decode( (string)file_get_contents( $path ), true );
		$this->assertIsArray( $manifest, 'The skin manifest must be valid JSON.' );

		return $manifest;
	}

	/**
	 * The arguments the manifest passes to the skin constructor.
	 *
	 * @param array $manifest
	 * @return array
	 */
	private function getSkinArgs( array $manifest ): array {
		$entry = $manifest['ValidSkinNames'][self::SKIN_KEY] ?? null;
		$this->assertIsArray( $entry, 'The skin must be registered under the key `notion`.' );

		$args = $entry['args'][0] ?? $entry['args'] ?? null;
		$this->assertIsArray( $args, 'The registration must pass a constructor argument array.' );

		return $args;
	}

	/**
	 * A decoded i18n file.
	 *
	 * @param string $language
	 * @return array
	 */
	private function getMessages( string $language ): array {
		$path = $this->getSkinDirectory() . "/i18n/$language.json";
		$this->assertFileExists( $path, "i18n/$language.json must exist." );

		$messages = json_decode( (string)file_get_contents( $path ), true );
		$this->assertIsArray( $messages, "i18n/$language.json must be valid JSON." );
		unset( $messages['@metadata'] );

		return $messages;
	}

	/**
	 * Exactly one skin, named in lower case, and its name agreed on in both places.
	 */
	public function testExactlyOneSkinIsRegistered() {
		$manifest = $this->getManifest();
		$names = $manifest['ValidSkinNames'] ?? [];

		$this->assertSame( [ self::SKIN_KEY ], array_keys( $names ),
			'The skin registers one name. A second variant would be a skin with no user, built '
				. 'from partials with no renderer.' );
		$registeredKey = (string)array_key_first( $names );
		$this->assertSame( $registeredKey, strtolower( $registeredKey ),
			'`$wgValidSkinNames` keys are the skin id in all lower case, and skin resolution is '
				. 'case-sensitive, so a capital here would make `?useskin=notion` find nothing.' );

		$this->assertSame( self::SKIN_KEY, $this->getSkinArgs( $manifest )['name'],
			'Core\'s SkinsTest asserts that the `name` option equals the registered key; a '
				. 'mismatch breaks skin resolution rather than merely reading oddly.' );
		$this->assertSame( 'Notion', $manifest['name'],
			'The manifest name is the directory name, which is what the skins/ symlink is called.' );
		$this->assertSame( 'skin', $manifest['type'] );
		$this->assertSame( 2, $manifest['manifest_version'],
			'Skin registration is only manifest-driven at schema version 2.' );
	}

	/**
	 * The places keyed by skin name carry that one name and no other.
	 */
	public function testEverySkinKeyedRegistryNamesOnlyThisSkin() {
		$manifest = $this->getManifest();

		$this->assertSame( [ self::SKIN_KEY ], array_keys( $manifest['SkinLessImportPaths'] ?? [] ),
			'A stray entry here would compile another skin\'s token layer into this skin\'s CSS.' );
		$this->assertSame( [ self::SKIN_KEY ], array_keys( $manifest['ResourceModuleSkinStyles'] ?? [] ),
			'Third-party module overrides are keyed by skin name, so a second key would restyle a '
				. 'skin this repository does not own.' );

		$bodyClasses = $this->getSkinArgs( $manifest )['bodyClasses'] ?? [];
		$this->assertContains( 'skin-' . self::SKIN_KEY, $bodyClasses,
			'Stylesheets and gadgets key off the body class, so it must name this skin.' );
		foreach ( $bodyClasses as $class ) {
			$this->assertStringNotContainsString( 'vector', $class,
				'No body class may name the skin this one was specified against.' );
		}
	}

	/**
	 * Every path the manifest declares resolves inside the skin.
	 */
	public function testDeclaredPathsResolve() {
		$manifest = $this->getManifest();
		$root = $this->getSkinDirectory();
		$args = $this->getSkinArgs( $manifest );

		$lessPath = $manifest['SkinLessImportPaths'][self::SKIN_KEY];
		$this->assertDirectoryExists( "$root/$lessPath",
			'The Less import path is what makes `@import \'mediawiki.skin.variables.less\'` resolve '
				. 'to this skin\'s token layer instead of the default one.' );
		$this->assertFileExists( "$root/$lessPath/mediawiki.skin.variables.less",
			'The import path must actually contain the token file it exists to provide.' );

		foreach ( $manifest['MessagesDirs']['Notion'] ?? [] as $dir ) {
			$this->assertDirectoryExists( "$root/$dir" );
		}

		$this->assertDirectoryExists( "$root/" . $args['templateDirectory'],
			'`SkinMustache` builds its TemplateParser over this directory.' );
		$this->assertStringNotContainsString( '..', $args['templateDirectory'],
			'The template directory must be inside the skin.' );
		$this->assertSame( 'skin', $args['template'],
			'The master template is the one `SkinMustache::generateHTML()` renders by default.' );

		$paths = $manifest['ResourceFileModulePaths'] ?? [];
		$this->assertSame( '', $paths['localBasePath'] ?? null,
			'An empty local base path roots module files at the skin directory.' );
		$this->assertSame( $manifest['name'], $paths['remoteSkinPath'] ?? null,
			'Asset URLs resolve under $IP/skins/<remoteSkinPath>, which is the name of the symlink '
				. 'the developer environment creates; the two must agree or every asset 404s.' );

		foreach ( $manifest['AutoloadNamespaces'] ?? [] as $prefix => $dir ) {
			$this->assertDirectoryExists( "$root/$dir",
				"Namespace $prefix is autoloaded from a directory that must exist." );
		}
		$class = $manifest['ValidSkinNames'][self::SKIN_KEY]['class'];
		$this->assertStringStartsWith( 'MediaWiki\\Skins\\Notion\\', $class,
			'The skin class must live in the namespace the manifest autoloads.' );
	}

	/**
	 * Every Mustache partial referenced by a template resolves to a file in the same tree.
	 *
	 * An unresolvable partial is not a cosmetic problem: `TemplateParser` compiles with
	 * `FLAG_ERROR_EXCEPTION`, so the first page that renders the referencing template raises rather
	 * than degrading. The two ways it can happen are reported separately, because they call for
	 * different fixes — a name that was never planned is a typo, while a planned name that is
	 * absent means a template was authored before the one it includes.
	 */
	public function testEveryTemplatePartialReferenceResolves() {
		$manifest = $this->getManifest();
		$dir = $this->getSkinDirectory() . '/' . $this->getSkinArgs( $manifest )['templateDirectory'];

		$templates = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'mustache' ) {
				$templates[] = $file->getPathname();
			}
		}
		$this->assertNotSame( [], $templates, 'The template directory must hold templates.' );

		$checked = 0;
		foreach ( $templates as $path ) {
			$contents = (string)file_get_contents( $path );
			preg_match_all( '/\{\{>\s*([A-Za-z0-9_\/]+)\s*\}\}/', $contents, $matches );

			foreach ( array_unique( $matches[1] ) as $partial ) {
				$checked++;
				$this->assertContains( $partial, self::PLANNED_TEMPLATES,
					basename( $path ) . " references `$partial`, which is not one of the partials "
						. 'this skin is specified to have. Most likely a misspelling.' );
				$this->assertFileExists( "$dir/$partial.mustache",
					basename( $path ) . " includes `$partial`, so that partial must exist: "
						. 'LightnCandy compiles with FLAG_ERROR_EXCEPTION and an unresolved partial '
						. 'is a fatal error on the first page that renders this template.' );
			}
		}
		$this->assertGreaterThan( 0, $checked,
			'The templates present must reference at least one partial between them, or this test '
				. 'is asserting nothing.' );
	}

	/**
	 * Every ResourceLoader module the skin loads by name is defined by the same manifest.
	 */
	public function testEveryModuleTheSkinLoadsIsDefined() {
		$manifest = $this->getManifest();
		$args = $this->getSkinArgs( $manifest );
		$defined = array_keys( $manifest['ResourceModules'] ?? [] );

		$referenced = array_merge( $args['styles'] ?? [], $args['scripts'] ?? [] );
		$this->assertNotSame( [], $referenced );
		foreach ( $referenced as $module ) {
			$this->assertContains( $module, $defined,
				"The skin loads `$module`, so the manifest must define it." );
		}

		// A module may also depend on another of this skin's modules; those names are resolved the
		// same way and are just as silent when wrong.
		foreach ( $manifest['ResourceModules'] as $name => $definition ) {
			foreach ( $definition['dependencies'] ?? [] as $dependency ) {
				if ( str_starts_with( $dependency, 'skins.notion' ) ) {
					$this->assertContains( $dependency, $defined,
						"`$name` depends on `$dependency`, which this manifest must define." );
				}
			}
		}
	}

	/**
	 * Every message the manifest declares exists, and every message the skin ships is documented.
	 */
	public function testMessageClosure() {
		$manifest = $this->getManifest();
		$en = $this->getMessages( 'en' );
		$qqq = $this->getMessages( 'qqq' );

		$declared = $this->getSkinArgs( $manifest )['messages'] ?? [];
		foreach ( $manifest['ResourceModules'] as $definition ) {
			$declared = array_merge( $declared, $definition['messages'] ?? [] );
		}
		$declared = array_unique( array_merge( $declared, [
			$manifest['descriptionmsg'],
			$manifest['namemsg'],
			// Core looks these three up from the skin name itself rather than from any declaration.
			'skinname-' . self::SKIN_KEY,
			self::SKIN_KEY . '.css',
			self::SKIN_KEY . '.js',
		] ) );
		$this->assertNotSame( [], $declared );

		foreach ( $declared as $key ) {
			$this->assertTrue(
				array_key_exists( $key, $en ) || wfMessage( $key )->exists(),
				"`$key` is declared but resolves to nothing: it is neither in this skin's "
					. 'i18n/en.json nor a message core or an installed extension defines. Core\'s '
					. 'SkinsTest fails the whole skin for this.'
			);
		}

		// Compared as sets rather than as ordered lists: `banana-checker` requires the two key sets
		// to agree and says nothing about ordering, and encoding a file-layout convention as a hard
		// gate here would fail a future message added in a different position for no behavioural
		// reason.
		$enKeys = array_keys( $en );
		$qqqKeys = array_keys( $qqq );
		sort( $enKeys );
		sort( $qqqKeys );
		$this->assertSame( $enKeys, $qqqKeys,
			'Every message must be documented in qqq.json, and qqq must document nothing that does '
				. 'not exist. `banana-checker` enforces this in CI; asserting it here reports it in '
				. 'the same run as everything else.' );
		foreach ( $qqq as $key => $documentation ) {
			$this->assertNotSame( '', trim( (string)$documentation ),
				"`$key` has an empty qqq entry, which documents nothing." );
		}
	}

	/**
	 * The skin's own messages carry its prefix, and only one skin is named among them.
	 */
	public function testMessageNamesNameThisSkinAndNoOther() {
		$en = $this->getMessages( 'en' );
		$keys = array_keys( $en );

		$skinNameKeys = array_values( array_filter( $keys,
			static fn ( $key ) => str_starts_with( $key, 'skinname-' ) ) );
		$this->assertSame( [ 'skinname-' . self::SKIN_KEY ], $skinNameKeys,
			'One registered skin means exactly one skinname message; a second would name a variant '
				. 'that does not exist.' );

		$unprefixed = array_values( array_filter( $keys, static function ( $key ) {
			return !str_starts_with( $key, self::SKIN_KEY . '-' )
				&& !str_starts_with( $key, self::SKIN_KEY . '.' )
				&& !str_starts_with( $key, 'tooltip-' . self::SKIN_KEY . '-' )
				&& $key !== 'skinname-' . self::SKIN_KEY;
		} ) );
		sort( $unprefixed );
		$expectedUnprefixed = self::UNPREFIXED_MESSAGE_KEYS;
		sort( $expectedUnprefixed );
		$this->assertSame( $expectedUnprefixed, $unprefixed,
			'Only the named shared keys may go unprefixed. Anything else here is a message this '
				. 'skin would be defining on another owner\'s behalf.' );

		foreach ( $keys as $key ) {
			$this->assertStringNotContainsString( 'vector', $key,
				'No message may name the skin this one was specified against.' );
		}
	}
}
