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

use MediaWiki\Skins\Notion\Components\NotionComponent;
use MediaWiki\Skins\Notion\Components\NotionComponentButton;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the Notion skin's button view model.
 *
 * Three properties of `NotionComponentButton` are worth locking down in this much detail:
 *
 *   - The `cdx-button` class family is the mechanism by which the skin renders a Codex button
 *     instead of a bare styled `<button>`, so asserting the exact class strings is asserting the
 *     skin's design-system compliance. A dropped or misspelled modifier leaves a visually broken
 *     control that no PHP error would ever reveal, which is why these tests compare literal
 *     Codex class names rather than merely checking that some string was produced.
 *   - The constructor is called positionally from several call sites, so the parameter order is a
 *     cross-file contract. Every construction below therefore passes its arguments positionally
 *     on purpose: a reordered parameter would be a silent behavioural change rather than a fatal
 *     error, and these tests are what turn it back into a loud failure.
 *   - The component touches no service, no global and no storage backend -- it declares no
 *     imports at all -- so it is exercisable in complete isolation and `MediaWikiUnitTestCase`
 *     is the correct base class. Nothing here may introduce such a dependency.
 *
 * @group Notion
 * @group Components
 * @coversDefaultClass \MediaWiki\Skins\Notion\Components\NotionComponentButton
 */
class NotionComponentButtonTest extends MediaWikiUnitTestCase {

	/**
	 * Every button variation and the Codex modifier it is required to emit.
	 *
	 * The matrix is deliberately one construction per modifier so that a failure names the single
	 * property that regressed, and each assertion carries a message explaining the rule it
	 * enforces rather than restating the assertion.
	 *
	 * @covers ::getClasses
	 */
	public function testGetClasses() {
		$basicButton = new NotionComponentButton( 'Label' );
		$templateData = $basicButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button', $templateData['class'],
			'Every button carries the Codex base class, which is what styles it as a button at all.' );
		$this->assertStringNotContainsString( 'cdx-button--fake-button', $templateData['class'],
			'A button with no href renders as a real <button>, so it must not claim the fake-button classes.' );

		$primaryButton = new NotionComponentButton( 'Label', null, null, null, [], 'primary' );
		$templateData = $primaryButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button--weight-primary', $templateData['class'],
			'Primary button should have primary weight class.' );

		$quietButton = new NotionComponentButton( 'Label', null, null, null, [], 'quiet' );
		$templateData = $quietButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button--weight-quiet', $templateData['class'],
			'Quiet button should have quiet weight class.' );

		$progressiveButton = new NotionComponentButton( 'Label', null, null, null, [],
			'normal', 'progressive' );
		$templateData = $progressiveButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button--action-progressive', $templateData['class'],
			'Progressive button should have progressive action class.' );

		$destructiveButton = new NotionComponentButton( 'Label', null, null, null, [],
			'normal', 'destructive' );
		$templateData = $destructiveButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button--action-destructive', $templateData['class'],
			'Destructive button should have destructive action class.' );

		$iconOnlyButton = new NotionComponentButton(
			'Label', null, null, null, [], 'normal', 'default', true );
		$templateData = $iconOnlyButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button--icon-only', $templateData['class'],
			'Icon-only button should have icon-only class.' );

		// An href turns the element into an anchor styled as a button, so Codex needs both
		// fake-button classes: the base one and the enabled-state one.
		$linkButton = new NotionComponentButton(
			'Label', null, null, null, [], 'normal', 'default', false, '/wiki/Main_Page' );
		$templateData = $linkButton->getTemplateData();
		$this->assertStringContainsString( 'cdx-button--fake-button', $templateData['class'],
			'Button with an href should be styled as a fake button.' );
		$this->assertStringContainsString( 'cdx-button--fake-button--enabled', $templateData['class'],
			'Button with an href should also carry the enabled fake-button state class.' );

		// The class parameter is the only channel through which caller-owned notion-* classes
		// reach the button; the component itself owns no presentational class.
		$callerClassButton = new NotionComponentButton( 'Label', null, null, 'notion-caller-class' );
		$templateData = $callerClassButton->getTemplateData();
		$this->assertStringContainsString( 'notion-caller-class', $templateData['class'],
			'Caller supplied classes should be forwarded verbatim onto the button.' );
	}

	/**
	 * An unrecognised weight or action is normalised away rather than emitted.
	 *
	 * Codex defines no modifier for the `normal` weight or the `default` action -- they are the
	 * styling `cdx-button` already provides -- so normalisation has to produce no modifier at
	 * all. Emitting an invented class such as `cdx-button--weight-humongous` would silently
	 * produce an unstyled control, which is exactly the failure mode this test forbids.
	 *
	 * @covers ::__construct
	 * @covers ::getClasses
	 */
	public function testGetClassesNormalisesUnrecognisedWeightAndAction() {
		$button = new NotionComponentButton( 'Label', null, null, null, [], 'humongous', 'sideways' );
		$class = $button->getTemplateData()['class'];

		$this->assertSame( 'cdx-button', $class,
			'An unrecognised weight and action must fall back to the unmodified base class.' );
		$this->assertStringNotContainsString( 'cdx-button--weight-', $class,
			'Normalising to the normal weight must not emit any weight modifier.' );
		$this->assertStringNotContainsString( 'cdx-button--action-', $class,
			'Normalising to the default action must not emit any action modifier.' );
	}

	/**
	 * The order in which the class string is composed is part of the rendered contract.
	 *
	 * A fully loaded button exercises every branch of the composition at once, so an exact
	 * comparison catches both a lost modifier and a reordered or doubled separator -- neither of
	 * which a substring assertion would notice.
	 *
	 * @covers ::getClasses
	 */
	public function testGetClassesCompositionOrder() {
		$button = new NotionComponentButton(
			'Everything',
			'icon-sample',
			'btn-id',
			'notion-caller-class',
			[],
			'primary',
			'progressive',
			true,
			'/wiki/Main_Page'
		);

		$this->assertSame(
			'cdx-button '
				. 'cdx-button--fake-button cdx-button--fake-button--enabled '
				. 'cdx-button--weight-primary '
				. 'cdx-button--action-progressive '
				. 'cdx-button--icon-only '
				. 'notion-caller-class',
			$button->getTemplateData()['class'],
			'Classes compose as base, fake-button pair, weight, action, icon-only, caller classes, '
				. 'separated by single spaces.'
		);
	}

	/**
	 * Provides various configurations of NotionComponentButton to test different scenarios.
	 * Each case includes different combinations of the button's properties.
	 *
	 * The provider is static because a data provider is resolved before any test instance exists.
	 *
	 * @return array[] An array of test cases with parameters and expected values.
	 */
	public static function provideButtonData(): array {
		return [
			'Basic Button' => [
				// The visible text on the button.
				'label' => 'Click Me',
				// CSS classes expected without additional properties.
				'expectedClasses' => 'cdx-button',
				// Default button weight.
				'weight' => 'normal',
				// Indicates that the button is not icon-only.
				'iconOnly' => false,
				// No link for a basic button.
				'href' => null,
			],
			'Button With Primary Weight' => [
				// The visible text indicating a primary action.
				'label' => 'Primary Action',
				// Additional classes are expected due to the primary weight.
				'expectedClasses' =>
					'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled cdx-button--weight-primary',
				// Indicates primary visual importance.
				'weight' => 'primary',
				// Still not an icon-only button.
				'iconOnly' => false,
				// Providing an href activates additional styles.
				'href' => '/mock-link',
			],
			'Icon Only Button' => [
				// No visible text for an icon-only button.
				'label' => '',
				// CSS classes specifically for icon-only.
				'expectedClasses' => 'cdx-button cdx-button--icon-only',
				// Default weight even for icon-only buttons.
				'weight' => 'normal',
				// This button is icon-only.
				'iconOnly' => true,
				// No link for this icon-only button.
				'href' => null,
			],
			'Quiet Icon Only Link Button' => [
				// The label survives as the accessible name of an icon-only control.
				'label' => 'Search',
				// The quiet weight, the fake-button pair and icon-only all apply at once, which is
				// the shape the search toggle and the sticky header actually construct.
				'expectedClasses' =>
					'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled '
						. 'cdx-button--weight-quiet cdx-button--icon-only',
				// The unobtrusive weight Notion's quiet chrome uses throughout.
				'weight' => 'quiet',
				// Only the icon is shown.
				'iconOnly' => true,
				// Rendered as an anchor styled as a button.
				'href' => '/wiki/Special:Search',
			],
			'Normal Weight Adds No Modifier' => [
				// A plain button with nothing but a caller class.
				'label' => 'Plain',
				// The caller's class follows the base class directly, which can only happen when
				// the normalised normal weight and default action contribute nothing.
				'expectedClasses' => 'cdx-button additional-class',
				// The default weight.
				'weight' => 'normal',
				// Label and icon are both shown.
				'iconOnly' => false,
				// Rendered as a real button element.
				'href' => null,
			],
		];
	}

	/**
	 * Tests the `getTemplateData` method of the NotionComponentButton component.
	 * Each data set provided by `provideButtonData` is passed here to verify the component's output.
	 *
	 * @covers ::__construct
	 * @dataProvider provideButtonData
	 * @param string $label Visible button text.
	 * @param string $expectedClasses Class substring the composed class attribute must contain.
	 * @param string $weight One of the supported button weights.
	 * @param bool $iconOnly Whether only the icon is shown.
	 * @param string|null $href Link target, or null for a real button element.
	 */
	public function testGetTemplateData(
		string $label,
		string $expectedClasses,
		string $weight,
		bool $iconOnly,
		?string $href
	) {
		// Instantiate the component with the provided configuration.
		$button = new NotionComponentButton(
			$label,
			'icon-sample',
			'btn-id',
			'additional-class',
			// Custom data attribute as an example.
			[ 'data-test' => 'true' ],
			$weight,
			// Default action type.
			'default',
			$iconOnly,
			$href
		);

		// Acquire the generated template data from the component.
		$templateData = $button->getTemplateData();

		// The template reads these keys by name, so both the set and its order are contractual.
		$this->assertSame(
			[ 'label', 'icon', 'id', 'class', 'href', 'array-attributes' ],
			array_keys( $templateData ),
			'Template data exposes exactly the keys Button.mustache consumes, in order.'
		);

		// Assert each aspect of the template data matches expectations.
		$this->assertSame( $label, $templateData['label'],
			'The label is forwarded verbatim, including an empty label on an icon-only button.' );
		$this->assertSame( 'icon-sample', $templateData['icon'],
			'The icon name is forwarded unprefixed for Icon.mustache to expand.' );
		$this->assertSame( 'btn-id', $templateData['id'],
			'The id is emitted on its own key rather than through the attribute list.' );
		// Ensures the class string contains all expected CSS classes.
		$this->assertStringContainsString( $expectedClasses, $templateData['class'],
			'The composed class attribute carries every Codex modifier this configuration implies.' );
		$this->assertSame( $href, $templateData['href'],
			'The href is emitted on its own key and left null for a real button element.' );
		// Verifies custom attributes are included appropriately.
		$this->assertContains( [ 'key' => 'data-test', 'value' => 'true' ], $templateData['array-attributes'],
			'Caller supplied attributes reach the template as key/value records.' );
	}

	/**
	 * Attributes that must never reach the markup are filtered out of `array-attributes`.
	 *
	 * Two filtering rules are in play, and both matter to the rendered element. A null value means
	 * "no attribute", so forwarding it would emit an empty attribute such as `aria-hidden=""`,
	 * which is not the same thing as omitting it. The `id`, `class` and `href` keys are emitted
	 * from their own dedicated keys, so forwarding them as well would duplicate the attribute on
	 * the element and let the attribute array quietly override its own parameters.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataFiltersAttributes() {
		$button = new NotionComponentButton(
			'Filtered',
			'icon-sample',
			'btn-id',
			'additional-class',
			[
				'data-event-name' => 'notion-button-click',
				// Dropped because a null value means the attribute is absent, not empty.
				'aria-hidden' => null,
				// Dropped because each of these has its own dedicated parameter and template key.
				'id' => 'attribute-id',
				'class' => 'attribute-class',
				'href' => '/attribute-href',
				'tabindex' => '-1',
			],
			'quiet',
			'default',
			false,
			null
		);

		$templateData = $button->getTemplateData();
		$attributeKeys = array_column( $templateData['array-attributes'], 'key' );

		$this->assertNotContains( 'aria-hidden', $attributeKeys,
			'A null valued attribute is omitted rather than rendered with an empty value.' );
		$this->assertNotContains( 'id', $attributeKeys,
			'The id attribute is emitted from the id parameter, never from the attribute list.' );
		$this->assertNotContains( 'class', $attributeKeys,
			'The class attribute is composed by getClasses, never taken from the attribute list.' );
		$this->assertNotContains( 'href', $attributeKeys,
			'The href attribute is emitted from the href parameter, never from the attribute list.' );
		$this->assertSame(
			[
				[ 'key' => 'data-event-name', 'value' => 'notion-button-click' ],
				[ 'key' => 'tabindex', 'value' => '-1' ],
			],
			$templateData['array-attributes'],
			'Surviving attributes keep their insertion order and their key/value record shape.'
		);

		// The dedicated parameters, not the filtered-out attributes, are what reach the template.
		$this->assertSame( 'btn-id', $templateData['id'],
			'An id in the attribute array cannot displace the id parameter.' );
		$this->assertNull( $templateData['href'],
			'An href in the attribute array cannot turn a real button into a link.' );
		$this->assertStringNotContainsString( 'attribute-class', $templateData['class'],
			'A class in the attribute array cannot leak into the composed class attribute.' );
	}

	/**
	 * A null attribute array is treated as no attributes at all.
	 *
	 * The parameter is nullable, so a caller may pass null explicitly instead of relying on the
	 * default empty array. Iterating null would raise a warning on every render, so the component
	 * coalesces first; this test is what keeps that null safety in place.
	 *
	 * @covers ::getTemplateData
	 */
	public function testGetTemplateDataWithNullAttributes() {
		$button = new NotionComponentButton( 'No Attributes', null, null, null, null );
		$templateData = $button->getTemplateData();

		$this->assertSame( [], $templateData['array-attributes'],
			'A null attribute array yields an empty attribute list instead of a warning.' );
		$this->assertSame( 'No Attributes', $templateData['label'],
			'The label survives a null attribute array untouched.' );
		$this->assertSame( 'cdx-button', $templateData['class'],
			'A button with no options composes to the bare Codex base class.' );
		$this->assertNull( $templateData['icon'],
			'A null icon is emitted as null so the template renders no icon element.' );
		$this->assertNull( $templateData['id'],
			'A null id is emitted as null so the template omits the attribute.' );
		$this->assertNull( $templateData['href'],
			'A null href is emitted as null so the template renders a real button element.' );
	}

	/**
	 * The button satisfies the component contract the templates are rendered through.
	 *
	 * Every partial is fed by a `NotionComponent`, so conformance is what lets the button be
	 * composed into menus, the sticky header and the page toolbar interchangeably with any other
	 * component rather than being special-cased at its call sites.
	 *
	 * @covers ::__construct
	 */
	public function testImplementsComponentInterface() {
		$button = new NotionComponentButton( 'Label' );

		$this->assertInstanceOf( NotionComponent::class, $button,
			'The button is a NotionComponent, which is what every template partial consumes.' );
	}
}
