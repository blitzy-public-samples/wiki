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

use MediaWiki\Skins\Notion\ConfigHelper;
use MediaWikiIntegrationTestCase;

/**
 * Tests that every service `includes/ServiceWiring.php` declares can actually be constructed.
 *
 * The manifest names the wiring file and the skin's own registration asks the container for
 * services by name, so a wiring entry that throws — or a service the skin requests and the wiring
 * never registers — is discovered on a live request rather than at registration. That failure mode
 * is what makes this worth a test of its own: the container is lazy, so a broken closure costs
 * nothing until the first page that needs it.
 *
 * The provider reads the wiring file rather than listing service names, so a service added later is
 * covered without anyone remembering this file exists. `ConfigHelper` additionally gets a typed
 * assertion, because "did not throw" would also be satisfied by a closure that returned the wrong
 * object.
 *
 * @group Notion
 * @coversNothing PHPUnit cannot express coverage of a file that only returns an array
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideServiceNames
	 * @param string $name
	 */
	public function testEveryWiredServiceConstructs( string $name ) {
		$service = $this->getServiceContainer()->get( $name );

		$this->assertIsObject( $service, "`$name` must construct into an object." );
	}

	public static function provideServiceNames(): iterable {
		$wiring = require dirname( __DIR__, 3 ) . '/includes/ServiceWiring.php';

		foreach ( array_keys( $wiring ) as $name ) {
			yield $name => [ $name ];
		}
	}

	/**
	 * The name the skin's configuration layer resolves is wired to the class it expects.
	 */
	public function testConfigHelperIsWiredToItsOwnClass() {
		$helper = $this->getServiceContainer()->get( 'Notion.ConfigHelper' );

		$this->assertInstanceOf( ConfigHelper::class, $helper );
		$this->assertSame(
			$helper,
			$this->getServiceContainer()->get( 'Notion.ConfigHelper' ),
			'The container must hand back one shared instance: the helper is stateless by design '
				. 'and constructing it per caller would only multiply the work.'
		);
	}
}
