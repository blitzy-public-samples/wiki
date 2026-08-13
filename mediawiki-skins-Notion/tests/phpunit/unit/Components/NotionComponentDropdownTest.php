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

use MediaWiki\Skins\Notion\Components\NotionComponentDropdown;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's dropdown component.
 *
 * `NotionComponentDropdown` is a pure value object: it accepts five scalars and returns the
 * array that `Dropdown/Open.mustache` renders. It reads no service, no global and no database,
 * so this test extends `MediaWikiUnitTestCase` directly instead of the skin's snapshot base
 * class — there is no state to snapshot, only a small and exactly specified contract to lock.
 *
 * Two properties are asserted:
 *
 *  1. Every value the caller supplies reaches the template unchanged, under the key the
 *     template reads it from, with its type intact (notably, an absent icon stays `null` and
 *     never degrades to an empty string).
 *  2. `label-class` composes the Codex quiet fake-button class list and gains
 *     `cdx-button--icon-only` if and only if an icon was supplied. That class list is how the
 *     template's `<label>` element — which is deliberately not a real `<button>`, so the
 *     checkbox hack keeps the dropdown usable with JavaScript disabled — still picks up Codex
 *     button styling.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentDropdown
 */
class NotionComponentDropdownTest extends MediaWikiUnitTestCase {

	/**
	 * Dropdowns without and with an icon, which is the component's only branch.
	 *
	 * @return array[]
	 */
	public static function provideDropdownData(): array {
		return [
			'Dropdown' => [
				'id' => 'mock-dropdown',
				'label' => 'Mock Dropdown',
				'class' => 'some-class',
				'icon' => null,
				'tooltip' => 'A tooltip for the dropdown',
				'expectedClasses' => 'some-class',
				'expectedIconButtonClasses' => '',
			],
			'Dropdown with icon' => [
				'id' => 'mock-icon-dropdown',
				'label' => 'Mock Icon Dropdown',
				'class' => 'some-icon-class',
				'icon' => 'icon-some',
				'tooltip' => 'A tooltip for the icon dropdown',
				'expectedClasses' => 'some-icon-class',
				'expectedIconButtonClasses' => 'cdx-button--icon-only',
			],
		];
	}

	/**
	 * @covers ::getTemplateData
	 * @dataProvider provideDropdownData
	 * @param string $id Dropdown id; the template derives the checkbox and label ids from it.
	 * @param string $label Handle text, which also becomes the checkbox `aria-label`.
	 * @param string $class Additional classes for the outermost element.
	 * @param string|null $icon Icon name, or null for a dropdown rendered without an icon.
	 * @param string $tooltip Pre-rendered tooltip attribute string for the outermost element.
	 * @param string $expectedClasses Value expected under the `class` key.
	 * @param string $expectedIconButtonClasses Icon-only class expected within `label-class`.
	 */
	public function testGetTemplateData(
		string $id,
		string $label,
		string $class,
		?string $icon,
		string $tooltip,
		string $expectedClasses,
		string $expectedIconButtonClasses
	): void {
		// Create a new NotionComponentDropdown object
		$dropdown = new NotionComponentDropdown( $id, $label, $class, $icon, $tooltip );
		// Call the getTemplateData method
		$templateData = $dropdown->getTemplateData();

		// Verifying that the template data is constructed as expected. assertSame rather than
		// assertEquals because the types matter as much as the values here: `icon` must stay
		// null for an icon-less dropdown, since the template branches on `{{#icon}}`.
		$this->assertSame( $id, $templateData['id'],
			'The id must reach the template verbatim; the checkbox and label ids derive from it.' );
		$this->assertSame( $label, $templateData['label'],
			'The label must reach the template verbatim; it is also the checkbox aria-label.' );
		$this->assertSame( $expectedClasses, $templateData['class'],
			'Caller-supplied classes must be forwarded unchanged to the outermost element.' );
		$this->assertSame( $icon, $templateData['icon'],
			'The icon name must be forwarded unchanged, and stay null when none was supplied.' );
		$this->assertSame( $tooltip, $templateData['html-tooltip'],
			'The tooltip attribute string must be forwarded unchanged.' );

		// Verifying that the label-class is constructed as expected. The Notion dropdown handle
		// is a Mustache <label>, not a <button>, so Codex styling arrives entirely through this
		// class list: the quiet fake-button composition, plus the icon-only modifier when an
		// icon is present.
		$this->assertStringContainsString( 'cdx-button', $templateData['label-class'],
			'The dropdown handle must carry the base Codex button class.' );
		$this->assertStringContainsString( 'cdx-button--fake-button', $templateData['label-class'],
			'The handle is a label element, so it must be styled as a Codex fake button.' );
		$this->assertStringContainsString( 'cdx-button--weight-quiet', $templateData['label-class'],
			'Notion chrome is quiet, so the handle must use the quiet Codex button weight.' );
		// Vacuously true for the icon-less case, where the provider supplies an empty needle;
		// the assertion below is what actually constrains that case.
		$this->assertStringContainsString( $expectedIconButtonClasses, $templateData['label-class'],
			'An icon dropdown must be styled as an icon-only Codex button.' );
		if ( $icon === null ) {
			$this->assertStringNotContainsString( 'cdx-button--icon-only', $templateData['label-class'],
				'A dropdown without an icon must not be styled as an icon-only Codex button.' );
		}

		// These key names are the shared contract with the skin's Mustache partials:
		// `Dropdown/Open.mustache` renders `html-notion-menu-checkbox-attributes` onto the
		// checkbox input, `html-notion-menu-label-attributes` onto the label, and appends
		// `checkbox-class` to the checkbox class list, while `Menu.mustache` consumes the `id`,
		// `class`, `label`, `label-class` and `html-tooltip` keys asserted above. The `notion`
		// infix in the two attribute keys is skin-specific, so both are pinned here: because
		// each is an empty string rather than a rendered value, a rename or omission would not
		// raise a Mustache error, it would silently drop whatever a consumer later substitutes
		// (Extension:ULS binds to `checkbox-class`, for instance). Presence and emptiness are
		// asserted; the number of keys deliberately is not, since a consumer adding its own key
		// after construction — which several do — must stay allowed.
		$this->assertArrayHasKey( 'html-notion-menu-label-attributes', $templateData,
			'Dropdown/Open.mustache renders this key onto the label element.' );
		$this->assertSame( '', $templateData['html-notion-menu-label-attributes'],
			'No label attributes are contributed by default; consumers substitute their own.' );
		$this->assertArrayHasKey( 'html-notion-menu-checkbox-attributes', $templateData,
			'Dropdown/Open.mustache renders this key onto the checkbox input.' );
		$this->assertSame( '', $templateData['html-notion-menu-checkbox-attributes'],
			'No checkbox attributes are contributed by default; consumers substitute their own.' );
		$this->assertArrayHasKey( 'checkbox-class', $templateData,
			'Dropdown/Open.mustache appends this key to the checkbox class list.' );
	}
}
