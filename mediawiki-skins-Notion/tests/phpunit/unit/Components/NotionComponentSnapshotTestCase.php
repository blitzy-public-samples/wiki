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
 * @since 1.47
 */
namespace MediaWiki\Skins\Notion\Tests\Unit\Components;

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
 *   - Fixtures are regenerated from the MediaWiki core checkout by running
 *     `PHPUNIT_UPDATE_SNAPSHOTS=1 composer phpunit:unit` and reviewing the diff. The skin
 *     ships no PHPUnit configuration of its own; core's `extensions:unit` test suite is
 *     what discovers these tests.
 *
 * @since 1.47
 */
class NotionComponentSnapshotTestCase extends MediaWikiUnitTestCase {

	/**
	 * Record $data as the new expectation for a snapshot, replacing any existing fixture.
	 *
	 * Called for every assertion when the `PHPUNIT_UPDATE_SNAPSHOTS` environment variable is
	 * set, and safe to call directly when a test needs to seed a fixture. The fixture is
	 * pretty-printed so that regenerated snapshots stay diffable.
	 *
	 * @param string $snapshotName Fixture file name including its `.json` extension, resolved
	 *   inside the `__snapshots__` directory beside this file.
	 * @param array $data Template data to persist as the expectation.
	 */
	public function updateSnapshot( $snapshotName, $data ) {
		$snapshotPath = __DIR__ . '/__snapshots__/' . $snapshotName;
		file_put_contents( $snapshotPath, json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Assert that $data still equals the expectation stored in a snapshot fixture.
	 *
	 * A fixture that does not exist is treated as an empty array, so a non-empty payload
	 * fails loudly rather than silently passing against a snapshot that was never committed.
	 * The failure message names the command that regenerates the fixture.
	 *
	 * @param string $snapshotName Fixture file name including its `.json` extension, resolved
	 *   inside the `__snapshots__` directory beside this file.
	 * @param array $data Template data produced by the component under test.
	 * @param string $msg Optional context prepended to the failure message.
	 */
	public function assertEqualsSnapshot( $snapshotName, $data, $msg = '' ) {
		$snapshotPath = __DIR__ . '/__snapshots__/' . $snapshotName;

		// Update snapshot if --update-snapshots flag is set via environment variable
		if ( getenv( 'PHPUNIT_UPDATE_SNAPSHOTS' ) ) {
			$this->updateSnapshot( $snapshotName, $data );
		}

		$actualData = file_exists( $snapshotPath ) ?
			json_decode( file_get_contents( $snapshotPath ), true ) : [];

		$this->assertEquals(
			$actualData,
			$data,
			$msg . ' If changes are expected, update snapshot by running: '
				. '`PHPUNIT_UPDATE_SNAPSHOTS=1 composer phpunit:unit`'
		);
	}
}
