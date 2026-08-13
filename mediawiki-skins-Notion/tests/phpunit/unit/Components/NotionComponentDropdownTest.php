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

use MediaWiki\Skins\Notion\Components\NotionComponentDropdown;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's dropdown component.
 *
 * `NotionComponentDropdown` is a pure value object: it accepts eight scalars and returns the
 * array that `Dropdown/Open.mustache` renders. It reads no service, no global and no database,
 * so this test extends `MediaWikiUnitTestCase` directly instead of the skin's snapshot base
 * class — there is no state to snapshot, only a small and exactly specified contract to lock.
 *
 * Three properties are asserted:
 *
 *  1. Every value the caller supplies reaches the template unchanged, under the key the
 *     template reads it from, with its type intact (notably, an absent icon stays `null` and
 *     never degrades to an empty string).
 *  2. `label-class` composes the Codex quiet fake-button class list and gains
 *     `cdx-button--icon-only` exactly when the caller asked for an icon-only handle, and, when
 *     the caller said nothing, exactly when the icon is truthy: the component's fallback tests the
 *     value rather than the key, so an empty-string icon takes the same branch as `null`. That
 *     class list is how the template's `<label>` element — which is deliberately not a real
 *     `<button>`, so the checkbox hack keeps the dropdown usable with JavaScript disabled —
 *     still picks up Codex button styling.
 *  3. `$iconOnly` is what decides icon-only, not the presence of `$icon`. The distinction is
 *     the whole point of the parameter: a control that pairs an icon with a visible label, as
 *     the language dropdown and the page toolbar do, must be able to say so at construction
 *     instead of unpicking a class afterwards. `testIconOnlyIsDeclaredNotInferred()` covers all
 *     four combinations of icon presence and declaration, and
 *     `testIconOnlyDefaultsToIconPresence()` pins the null default that keeps callers which
 *     predate the parameter byte-for-byte unchanged.
 *  4. The emitted key set is exactly ten keys in a fixed order, and the three keys the component
 *     contributes as seams for its consumers start as empty strings rather than as null or as a
 *     value of their own, while the disclosure state starts as a literal false.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentDropdown
 */
class NotionComponentDropdownTest extends MediaWikiUnitTestCase {

	/**
	 * The exact keys `getTemplateData()` emits, in order.
	 *
	 * `Dropdown/Open.mustache` reads `id`, `label`, `label-class`, `icon`, `class`,
	 * `html-tooltip`, `checkbox-class` and the two `html-notion-menu-*-attributes` seams, so this
	 * list is the component's whole rendered surface. Locking the order as well as the membership
	 * costs nothing and makes the JSON snapshots of every component that embeds this one stable.
	 */
	private const EXPECTED_TEMPLATE_KEYS = [
		'id',
		'label',
		'label-class',
		'icon',
		'html-notion-menu-label-attributes',
		'html-notion-menu-checkbox-attributes',
		'class',
		'html-tooltip',
		'checkbox-class',
		'is-expanded',
	];

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

		// The ten keys are the whole contract this component is responsible for, and they are
		// asserted as an exact ordered list rather than by presence alone. Two failure modes make
		// that worth doing: an eleventh key added here would reach every consumer of the component --
		// the user menu, the page tools, the language control, the variants menu -- unnoticed,
		// because a Mustache template silently ignores data it does not read; and a key initialised
		// to something other than the documented empty string would be interpolated verbatim into
		// the markup. Consumers legitimately add keys of their own to the returned array *after*
		// construction, which is why this is asserted on the component's own output only.
		$this->assertSame(
			self::EXPECTED_TEMPLATE_KEYS,
			array_keys( $templateData ),
			'The dropdown emits exactly these ten keys, in this order, and nothing else.'
		);
		$this->assertSame(
			'',
			$templateData['checkbox-class'],
			'The checkbox class starts empty: Dropdown/Open.mustache appends it to the input\'s '
				. 'class list, and only a consumer such as the language control -- where '
				. 'ext.uls.interface binds to the value -- substitutes anything for it.'
		);

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
		$this->assertSame( '', $templateData['checkbox-class'],
			'No checkbox classes are contributed unless the caller supplies them.' );

		// The initial disclosure state is server-rendered rather than left to scripting, and
		// `Dropdown/Open.mustache` derives both the checkbox's `checked` attribute and the
		// `aria-expanded` it announces from this single key. It is asserted to be exactly false,
		// not merely present: a dropdown that arrived checked would open on load, and one that
		// arrived without the key would announce no expanded state to assistive technology until
		// its first interaction, which is the defect this key exists to prevent.
		$this->assertArrayHasKey( 'is-expanded', $templateData,
			'Dropdown/Open.mustache derives checked and aria-expanded from this key.' );
		$this->assertFalse( $templateData['is-expanded'],
			'Every dropdown this skin renders is served closed.' );
	}

	/**
	 * All four combinations of icon presence and icon-only declaration.
	 *
	 * @return array[]
	 */
	public static function provideIconOnlyMatrix(): array {
		return [
			'icon, declared icon-only' => [ 'search', true, true ],
			'icon, declared NOT icon-only' => [ 'language-progressive', false, false ],
			'no icon, declared icon-only' => [ null, true, true ],
			'no icon, declared NOT icon-only' => [ null, false, false ],
		];
	}

	/**
	 * @covers ::getTemplateData
	 * @dataProvider provideIconOnlyMatrix
	 * @param string|null $icon Icon name, or null for a dropdown rendered without an icon.
	 * @param bool $iconOnly What the caller declares about the handle.
	 * @param bool $expectIconOnlyClass Whether `cdx-button--icon-only` should be emitted.
	 */
	public function testIconOnlyIsDeclaredNotInferred(
		?string $icon,
		bool $iconOnly,
		bool $expectIconOnlyClass
	): void {
		$templateData = ( new NotionComponentDropdown(
			'mock-dropdown', 'Mock Dropdown', '', $icon, '', $iconOnly
		) )->getTemplateData();

		if ( $expectIconOnlyClass ) {
			$this->assertStringContainsString( 'cdx-button--icon-only', $templateData['label-class'],
				'A handle declared icon-only must carry the Codex icon-only modifier.' );
		} else {
			$this->assertStringNotContainsString( 'cdx-button--icon-only', $templateData['label-class'],
				'A handle that pairs an icon with a visible label must NOT be styled icon-only; '
					. 'that is the whole reason $iconOnly exists rather than inferring from $icon.' );
		}
		// The declaration governs styling only. The icon itself must still reach the template
		// untouched, so a non-icon-only control keeps its glyph.
		$this->assertSame( $icon, $templateData['icon'],
			'The icon name must be forwarded regardless of the icon-only declaration.' );
	}

	/**
	 * @covers ::getTemplateData
	 */
	public function testIconOnlyDefaultsToIconPresence(): void {
		// Omitting $iconOnly must reproduce the historical icon-presence inference exactly, so
		// that adopting the parameter is opt-in and no existing call site is restyled by surprise.
		$withIcon = ( new NotionComponentDropdown( 'a', 'A', '', 'search' ) )->getTemplateData();
		$withoutIcon = ( new NotionComponentDropdown( 'b', 'B' ) )->getTemplateData();

		$this->assertStringContainsString( 'cdx-button--icon-only', $withIcon['label-class'],
			'With no declaration and an icon present, the legacy inference must still apply.' );
		$this->assertStringNotContainsString( 'cdx-button--icon-only', $withoutIcon['label-class'],
			'With no declaration and no icon, no icon-only modifier may appear.' );

		// The exact strings the pre-parameter implementation emitted, including the trailing space
		// that consumers used to concatenate onto. Asserted verbatim because `label-class` is a
		// string the skin's snapshots record, so a stray space would be a real diff.
		$handle = 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled '
			. 'cdx-button--weight-quiet';
		$this->assertSame( $handle . ' cdx-button--icon-only ', $withIcon['label-class'],
			'The icon-only default output must be byte-for-byte what it always was.' );
		$this->assertSame( $handle, $withoutIcon['label-class'],
			'The plain default output must be byte-for-byte what it always was.' );
	}

	/**
	 * @covers ::getTemplateData
	 */
	public function testCallerSuppliedClassesAreAppendedNotSubstituted(): void {
		// These two parameters exist so that a consumer never has to rebuild the Codex class
		// string itself — the language dropdown used to, and a duplicated composition drifts.
		$handle = 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled '
			. 'cdx-button--weight-quiet';

		$notIconOnly = ( new NotionComponentDropdown(
			'p-lang-btn', '3 languages', '', 'language-progressive', '', false,
			'cdx-button--action-progressive mw-portlet-lang-heading-3', 'mw-interlanguage-selector'
		) )->getTemplateData();
		$this->assertSame(
			$handle . ' cdx-button--action-progressive mw-portlet-lang-heading-3',
			$notIconOnly['label-class'],
			'Caller classes must be appended after the Codex composition, separated by exactly '
				. 'one space, with the Codex classes intact.'
		);
		$this->assertSame( 'mw-interlanguage-selector', $notIconOnly['checkbox-class'],
			'Checkbox classes are core/extension-owned hook names and must pass through verbatim.' );

		$iconOnly = ( new NotionComponentDropdown(
			'p-lang-btn', 'Languages', '', 'language', '', true,
			'mw-portlet-lang-heading-empty', 'mw-interlanguage-selector-empty'
		) )->getTemplateData();
		$this->assertSame(
			$handle . ' cdx-button--icon-only mw-portlet-lang-heading-empty',
			$iconOnly['label-class'],
			'The icon-only modifier already ends in a space, so appending caller classes must not '
				. 'introduce a second one.'
		);
		$this->assertSame( 'mw-interlanguage-selector-empty', $iconOnly['checkbox-class'],
			'The empty-selector hook name must pass through verbatim too.' );
	}
}
