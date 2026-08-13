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
namespace MediaWiki\Skins\Notion\Tests\Unit\Components;

use JsonException;
use MediaWikiUnitTestCase;

/**
 * Snapshot assertion support for the Notion skin's component unit tests.
 *
 * Component tests extend this class to lock the array a `NotionComponent*` class hands to
 * its Mustache template against a checked-in JSON fixture, so that every change to emitted
 * template data surfaces as a reviewable diff instead of passing unnoticed.
 *
 * Contract for subclasses:
 *
 *   - Snapshot names are kebab-case and carry the `.json` extension, for example
 *     `menu-1.json`, `page-tools-2.json`, `page-toolbar-move-ca-addsection.json` or
 *     `userlinks-loggedin.json`.
 *   - Fixtures are read from, and written to, the `__snapshots__` directory that sits
 *     beside this file, that is `tests/phpunit/unit/Components/__snapshots__/`.
 *   - Every fixture a test asserts against is committed. There is no implicit empty
 *     expectation: `::assertEqualsSnapshot()` fails when the file is absent, unreadable or
 *     not valid JSON, so a component that emits nothing cannot pass against a fixture that
 *     was never written.
 *   - Fixtures are regenerated from the MediaWiki core checkout by running
 *     `PHPUNIT_UPDATE_SNAPSHOTS=1 composer phpunit:unit` and reviewing the diff - the value
 *     must be exactly `1` - after which the assertion still runs against what was written.
 *     The skin ships no PHPUnit configuration of its own; core's `extensions:unit` test
 *     suite is what discovers these tests.
 *   - A snapshot locks the emitted array and nothing more. It is evidence of what changed,
 *     not proof that what it records is right, so each test also asserts the behaviour it
 *     is named for inline. Both belong in a component test: the inline assertions state the
 *     intent, the fixture catches everything nobody thought to assert.
 *
 * @since 1.47
 */
class NotionComponentSnapshotTestCase extends MediaWikiUnitTestCase {

	/**
	 * Value `PHPUNIT_UPDATE_SNAPSHOTS` must hold, exactly, before any fixture is rewritten.
	 *
	 * Deliberately an identity check against `'1'` rather than a truthiness test. Rewriting a
	 * checked-in expectation is destructive: it turns whatever the component currently emits
	 * into the thing the suite from then on demands, so a regression recorded this way passes
	 * for ever afterwards. An unrelated value such as `PHPUNIT_UPDATE_SNAPSHOTS=0`, `false` or
	 * `no` - each of which a shell or a CI job may well export meaning "do not" - must
	 * therefore leave every fixture alone.
	 */
	private const UPDATE_SNAPSHOTS_ENABLED = '1';

	/**
	 * Directory every fixture is read from and written to.
	 *
	 * Resolved once here so that both methods below cannot drift apart, and so the containment
	 * check in ::resolveSnapshotPath() has a single canonical prefix to compare against.
	 *
	 * @return string Absolute path, with no trailing separator.
	 */
	private static function getSnapshotDirectory(): string {
		return __DIR__ . '/__snapshots__';
	}

	/**
	 * Resolve a fixture name to an absolute path inside the snapshot directory.
	 *
	 * The name arrives from the calling test rather than from user input, but it still reaches
	 * the filesystem - `assertEqualsSnapshot()` reads through it and `updateSnapshot()` writes
	 * through it - and an unvalidated name is a traversal waiting to happen: `../../skin.json`
	 * would have this class overwrite the manifest with pretty-printed template data. Three
	 * separate conditions therefore have to hold, and each rejects a different mistake:
	 *
	 *   - the name is a bare basename, so no separator of either flavour and no `..` segment
	 *     can steer the write out of the directory;
	 *   - it ends in `.json`, because these fixtures are JSON and nothing else;
	 *   - the concatenated path still starts with the snapshot directory, which catches
	 *     anything the first two checks did not anticipate.
	 *
	 * `realpath()` is deliberately not used for that last check: it returns false for a path
	 * that does not exist yet, which is exactly the case when a new fixture is being written.
	 *
	 * @param string $snapshotName Fixture file name including its `.json` extension.
	 * @return string Absolute path to the fixture.
	 */
	private function resolveSnapshotPath( string $snapshotName ): string {
		$this->assertSame(
			$snapshotName,
			basename( $snapshotName ),
			'A snapshot name must be a bare file name, not a path: ' . $snapshotName
		);
		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/',
			$snapshotName,
			'A snapshot name must be a JSON file name built from letters, digits, dots, '
				. 'hyphens and underscores: ' . $snapshotName
		);
		$this->assertStringNotContainsString(
			'..',
			$snapshotName,
			'A snapshot name may not contain a parent-directory segment: ' . $snapshotName
		);

		$directory = self::getSnapshotDirectory();
		$snapshotPath = $directory . '/' . $snapshotName;
		$this->assertStringStartsWith(
			$directory . '/',
			$snapshotPath,
			'A snapshot must resolve inside ' . $directory
		);

		return $snapshotPath;
	}

	/**
	 * Record $data as the new expectation for a snapshot, replacing any existing fixture.
	 *
	 * Called for every assertion when `PHPUNIT_UPDATE_SNAPSHOTS=1` is set, and safe to call
	 * directly when a test needs to seed a fixture. The fixture is pretty-printed, with slashes
	 * and Unicode left unescaped, so that regenerated snapshots stay diffable.
	 *
	 * Both halves of the write are checked. `json_encode()` is asked to throw rather than to
	 * return false, so a payload it cannot represent - a component object left in the array, a
	 * resource, a recursive structure - fails the test at the point of the mistake instead of
	 * truncating the fixture to the word `false`. The write itself is compared against the
	 * length it was asked to persist, because `file_put_contents()` reports a short write by
	 * returning fewer bytes rather than by failing, and a half-written fixture would then be
	 * read back as invalid JSON by the very next run. `LOCK_EX` keeps two concurrently running
	 * test processes from interleaving their bytes into the same file.
	 *
	 * @param string $snapshotName Fixture file name including its `.json` extension, resolved
	 *   inside the `__snapshots__` directory beside this file.
	 * @param array $data Template data to persist as the expectation.
	 */
	public function updateSnapshot( $snapshotName, $data ) {
		$snapshotPath = $this->resolveSnapshotPath( (string)$snapshotName );

		try {
			$json = json_encode(
				$data,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
					| JSON_THROW_ON_ERROR
			);
		} catch ( JsonException $e ) {
			$this->fail(
				'The template data for ' . $snapshotName . ' cannot be encoded as JSON, so it '
					. 'is not the plain array a Mustache template can render: ' . $e->getMessage()
			);
		}

		$this->assertDirectoryIsWritable(
			self::getSnapshotDirectory(),
			'Snapshots can only be regenerated when their directory is writable.'
		);
		$this->assertSame(
			strlen( $json ),
			file_put_contents( $snapshotPath, $json, LOCK_EX ),
			'The whole snapshot must reach ' . $snapshotName . '; a partial write would leave '
				. 'the fixture unreadable for the next run.'
		);
	}

	/**
	 * Assert that $data still equals the expectation stored in a snapshot fixture.
	 *
	 * The fixture must exist and be readable and valid JSON. A missing one is a failure in its
	 * own right rather than an empty expectation to compare against: treating it as `[]` lets a
	 * component that emits nothing at all pass against a fixture nobody ever committed, which
	 * is the opposite of what a snapshot is for. The failure message names the command that
	 * regenerates the fixture, so the remedy is never a guess.
	 *
	 * The comparison is strict. `assertEquals()` would accept `'1'` where `1` is expected,
	 * `1` where `true` is expected and `''` where `null` is expected - all four of which change
	 * how Mustache renders the value, because a section tests truthiness and an interpolation
	 * writes the value out. Since the fixture round-trips through JSON, the expectation carries
	 * real integers, booleans and nulls, and `assertSame()` is what keeps them that way.
	 *
	 * @param string $snapshotName Fixture file name including its `.json` extension, resolved
	 *   inside the `__snapshots__` directory beside this file.
	 * @param array $data Template data produced by the component under test.
	 * @param string $msg Optional context prepended to the failure message.
	 */
	public function assertEqualsSnapshot( $snapshotName, $data, $msg = '' ) {
		$snapshotName = (string)$snapshotName;
		$snapshotPath = $this->resolveSnapshotPath( $snapshotName );
		$regenerate = ' If changes are expected, update snapshot by running: '
			. '`PHPUNIT_UPDATE_SNAPSHOTS=1 composer phpunit:unit`';

		// Rewrite the fixture only on an exact opt-in, then keep going: the assertion below
		// still runs, which is what proves the file that was just written can be read back.
		if ( getenv( 'PHPUNIT_UPDATE_SNAPSHOTS' ) === self::UPDATE_SNAPSHOTS_ENABLED ) {
			$this->updateSnapshot( $snapshotName, $data );
		}

		$this->assertFileExists(
			$snapshotPath,
			$msg . ' The snapshot ' . $snapshotName . ' must be committed alongside the test.'
				. $regenerate
		);
		$this->assertFileIsReadable(
			$snapshotPath,
			$msg . ' The snapshot ' . $snapshotName . ' exists but cannot be read.'
		);

		$contents = file_get_contents( $snapshotPath );
		$this->assertIsString(
			$contents,
			$msg . ' The snapshot ' . $snapshotName . ' could not be read from disk.'
		);

		try {
			$expected = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			$this->fail(
				$msg . ' The snapshot ' . $snapshotName . ' is not valid JSON: '
					. $e->getMessage() . $regenerate
			);
		}
		$this->assertIsArray(
			$expected,
			$msg . ' The snapshot ' . $snapshotName . ' must hold a JSON object or array.'
		);

		$this->assertSame(
			$expected,
			$data,
			$msg . $regenerate
		);
	}
}
