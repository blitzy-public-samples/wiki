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

use MediaWiki\Skins\Notion\Components\NotionComponentPinnableContainer;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's pinnable container component.
 *
 * The component is a two-key data holder consumed by the paired
 * `PinnableContainer/Pinned/Open`, `PinnableContainer/Unpinned/Open` and
 * `PinnableContainer/Close` templates. It emits:
 *
 *   - `id`, the region id stem such as `notion-toc` or `notion-page-tools`, from which the Open
 *     partials derive the rendered `<id>-pinned-container` and `<id>-unpinned-container` ids. The
 *     component never appends those suffixes itself, so the value must survive untouched.
 *   - `is-pinned`, the flag every calling template branches on with `{{#is-pinned}}` /
 *     `{{^is-pinned}}` to decide which of the two placements receives the region's content.
 *
 * Two properties of that contract are load-bearing, and each is asserted below:
 *
 *   - Both placements must be describable, not just the one the default selects. The stylesheets
 *     lay out the pinned and the unpinned position, and the client-side script only relocates the
 *     region between markup that is already rendered and already styled. A component that could
 *     only report one state would break pinning outright wherever JavaScript is unavailable,
 *     which is why the pinned and the unpinned case are both exercised here rather than being
 *     treated as the same code path with a different argument.
 *   - `is-pinned` must be a genuine boolean. Mustache sections test truthiness, not identity, so a
 *     string `'0'` or `'false'`, or an integer, would still open the `{{#is-pinned}}` branch and
 *     silently render the region in the wrong container. The failure would never surface as an
 *     error — only as a sidebar region appearing in a dropdown, or the reverse.
 *
 * Every assertion therefore uses assertSame(), which compares arrays with `===`. That is
 * deliberately strict on three axes at once: it fails if a value changes type, if a key is
 * renamed or an extra key leaks into the template data, and if the key order changes.
 *
 * The component under test has no collaborators — it holds two readonly scalars — so these tests
 * need no services, no globals and no database, and extend MediaWikiUnitTestCase directly rather
 * than the skin's snapshot base class.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentPinnableContainer
 */
class NotionComponentPinnableContainerTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateData() {
		// Pinned placement: the id is reported exactly as supplied, alongside a boolean true.
		$pinnedId = 'pinned-container';
		$pinnedContainer = new NotionComponentPinnableContainer( $pinnedId, true );

		$this->assertSame(
			[
				'id' => $pinnedId,
				'is-pinned' => true,
			],
			$pinnedContainer->getTemplateData(),
			'Template data for a pinned container should correctly include the ID and is-pinned status.'
		);

		// Unpinned placement: a different id and the inverse flag, which proves the two
		// constructor arguments are carried through independently of one another and that no
		// state leaks between instances.
		$unpinnedId = 'unpinned-container';
		$unpinnedContainer = new NotionComponentPinnableContainer( $unpinnedId, false );

		$this->assertSame(
			[
				'id' => $unpinnedId,
				'is-pinned' => false,
			],
			$unpinnedContainer->getTemplateData(),
			'Template data for an unpinned container should accurately reflect a different ID '
				. 'and is-pinned status.'
		);

		// Default state: callers that already know the region is pinned, or that have no
		// preference to read, omit the second argument entirely and rely on the documented
		// default. Locking it here means a change to that default cannot pass unnoticed. A
		// realistic `notion-` prefixed region id is used to document the calling convention.
		$defaultId = 'notion-toc';
		$defaultContainer = new NotionComponentPinnableContainer( $defaultId );

		$this->assertSame(
			[
				'id' => $defaultId,
				'is-pinned' => true,
			],
			$defaultContainer->getTemplateData(),
			'Omitting the second constructor argument should leave the container pinned, which '
				. 'is the documented default that callers rely on.'
		);
	}
}
