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

use MediaWiki\Skins\Notion\Components\NotionComponentPinnableElement;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's pinnable element component.
 *
 * NotionComponentPinnableElement is the smallest component in the skin: it is constructed with a
 * single id and hands that id straight to PinnableElement/Open.mustache, which renders it twice,
 * once as the HTML `id` attribute and once as a class. Both halves of that pair are load-bearing.
 * The class is what keeps a pinnable region correctly styled in its pinned and in its unpinned
 * position before any script runs, and the id is how the skin's `pinnableElement.js` locates the
 * region it has to move afterwards. An id that arrived transformed, or that went missing, would
 * therefore break pinning silently rather than fail loudly, which is why the pass-through is
 * asserted here rather than left implicit.
 *
 * Four components construct it with their own `self::ID`: Appearance with `notion-appearance`,
 * MainMenu with `notion-main-menu`, PageTools with `notion-page-tools` and TableOfContents with
 * `notion-toc`. Each of those composites unions this component's data with
 * NotionComponentPinnableContainer's using PHP's left-biased `+` operator, placing this component
 * first, so the emitted array has to stay at exactly one `id` entry. Any extra key added to the
 * component would win that union in all four composites and shadow whatever the container
 * supplies, so the single-key shape is a contract and not an implementation detail.
 *
 * The assertions below use assertSame rather than assertEquals precisely so that an added key, a
 * reordered array or a coerced value fails: strictness is the entire point of locking a one-key
 * contract. The test extends MediaWikiUnitTestCase directly rather than
 * NotionComponentSnapshotTestCase because a single-key payload is clearer and cheaper to assert
 * inline than to keep in a checked-in JSON fixture.
 *
 * The component takes no services, reads no globals and touches no storage backend, so this test
 * is a true unit test and lives under `tests/phpunit/unit/` accordingly.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentPinnableElement
 */
class NotionComponentPinnableElementTest extends MediaWikiUnitTestCase {

	/**
	 * The component must pass its constructor id through to the template unchanged, and expose
	 * nothing besides that id.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateData() {
		// Test with a mock ID
		$mockId = 'mock-element';
		$pinnableElement = new NotionComponentPinnableElement( $mockId );

		// Expected template data: the id, and nothing alongside it
		$expectedTemplateData = [
			'id' => $mockId,
		];

		// Fetching template data for the element
		$actualTemplateData = $pinnableElement->getTemplateData();

		// Assert that the actual template data matches the expected array
		$this->assertSame( $expectedTemplateData, $actualTemplateData,
			'Template data should correctly include the ID.' );

		// Additional test case to verify behavior with different ID, which proves the id is read
		// back from the constructor argument rather than derived, defaulted or shared between
		// instances
		$anotherMockId = 'another-mock-element';
		$anotherPinnableElement = new NotionComponentPinnableElement( $anotherMockId );

		// Fetching template data for the new element
		$anotherActualTemplateData = $anotherPinnableElement->getTemplateData();

		// Ensuring the new element's template data is as expected
		$this->assertSame( [ 'id' => $anotherMockId ], $anotherActualTemplateData,
			'Template data should accurately reflect a different ID.' );
	}
}
