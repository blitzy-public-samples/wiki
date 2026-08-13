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
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Skins\Blitzy;

use MediaWiki\Config\Config;
use MediaWiki\Utils\UrlUtils;

/**
 * The Blitzy skin: MediaWiki content rendered in the Blitzy web design language.
 *
 * This is the class MediaWiki instantiates for `$wgDefaultSkin = 'blitzy'`, and it has a
 * single responsibility: hand the Mustache templates the data they need. It adds one
 * method to what it inherits and nothing else.
 *
 * The thinness is deliberate, for three separate reasons.
 *
 * Rule R14 sets a line-coverage floor of 80% and phpunit.xml.dist scopes coverage to
 * includes/, so every line here is a line a test must reach. A Skin subclass is expensive
 * to cover because ::getTemplateData() cannot run without a fully initialised request
 * context, so the presentation logic lives in BlitzyViewModel instead, where it is
 * exercisable under MediaWikiUnitTestCase with no wiki at all. What is left in this class
 * is one delegation, covered by an integration test that renders a real page.
 *
 * Gates 9 and 11 assert rendered output and computed styles, so anything expressible in a
 * template or a stylesheet is expressed there rather than here, where those gates cannot
 * observe it. Rule R12 then forbids widening the surface beyond that: there is no second
 * skin class, no Components/, Services/ or FeatureManagement/ directory, and no
 * ServiceWiring.php or Constants.php anywhere in includes/. Vector's feature-management
 * layer is knowingly not reproduced; this skin's behaviour is switched by plain manifest
 * configuration booleans, which BlitzyViewModel reads directly.
 *
 * Four things a reader might expect to find here are deliberately elsewhere:
 *
 *   - The dark-mode class on the `<html>` element. A Mustache skin may only template the
 *     contents of the `<body>` tag, so no template and no method of this class can reach
 *     the root element. Hooks\BlitzyHooks applies it through
 *     OutputPage::addHtmlClasses() instead, which is the only sanctioned seam.
 *   - The extra `<body>` class. That is the manifest's `bodyClasses` argument, so there is
 *     no ::getPageClasses() override.
 *   - The template parser and the root template name. SkinMustache already builds the
 *     parser against the manifest's `templateDirectory` with recursive partials enabled,
 *     and already resolves the root template from the `template` argument. Overriding
 *     either would only restate the manifest.
 *   - The print footer. SkinMustache builds `div.printfooter` itself and concatenates it
 *     into `html-body-content` through ::wrapHTML(). This class must not post-process that
 *     key, or rule R2's preserved print-footer contract would be broken by the skin that
 *     is supposed to honour it.
 *
 * Gate 12 requires a render-reachable path from every configuration option to the page.
 * That path runs `skin.json` config block, or harness/LocalSettings.template.php on the
 * verification instance, into BlitzyViewModel::build(), which this class calls from
 * ::getTemplateData(), and on into the partials under includes/templates/. This method is
 * the only link in that chain that runs inside a page render, so it is the row every
 * option in DELIVERY.md's propagation table passes through.
 *
 * The base class is spelled with its root namespace on purpose. MediaWiki 1.44 moved the
 * class to MediaWiki\Skin\SkinMustache and left `\SkinMustache` behind as an alias, while
 * skin.json declares support for 1.43 and later, where the root-namespace name is the real
 * class and the namespaced one does not exist. `\SkinMustache` is therefore the one
 * spelling that resolves across the whole supported range. The leading separator is
 * required rather than stylistic: an unqualified `SkinMustache` inside this namespace would
 * resolve to MediaWiki\Skins\Blitzy\SkinMustache and fail to load.
 *
 * @package Blitzy
 * @internal
 */
class SkinBlitzy extends \SkinMustache {

	/**
	 * Builds the skin's own template data. Constructed once here rather than injected,
	 * because ObjectFactory can only supply MediaWiki services and this is not one.
	 */
	private readonly BlitzyViewModel $viewModel;

	/**
	 * Services arrive before options because that is the order ObjectFactory assembles
	 * constructor arguments in: every name from the spec's `services` list first, then the
	 * spec's `args` entry last. `ValidSkinNames.blitzy` in skin.json must therefore declare
	 * `"services": [ "MainConfig", "UrlUtils" ]` in exactly that order. If the manifest and
	 * this signature ever disagree, the options array is passed where a service is expected
	 * and the skin fails to instantiate with a TypeError on the first page view, so the two
	 * have to be changed together.
	 *
	 * Nothing here touches the skin context, the output, the user, the title or a message.
	 * Skin::setContext() runs after construction, so none of them exists yet; the work that
	 * needs them happens in ::getTemplateData().
	 *
	 * @param Config $config Main configuration, injected from the manifest's `services`
	 *   list. Passed straight to the view model, which is the single read site for the
	 *   skin's eight configuration options; this class never reads one itself.
	 * @param UrlUtils $urlUtils Core URL parser, injected from the same list. Used only to
	 *   construct the validator that rule R13 requires every configuration-sourced link
	 *   target to pass through. Injecting it, rather than reaching into the service
	 *   container, is what keeps this class compliant with rule R1.
	 * @param array $options Skin options from the manifest's `args` entry: `name`,
	 *   `templateDirectory`, `template`, `responsive`, `bodyClasses`, `clientPrefEnabled`,
	 *   `toc`, `menus`, `styles`, `scripts` and `messages`. Forwarded to the parent
	 *   unmodified, because every one of those is the manifest's to declare.
	 */
	public function __construct( Config $config, UrlUtils $urlUtils, array $options ) {
		parent::__construct( $options );

		$this->viewModel = new BlitzyViewModel( $config, new BlitzyUrlValidator( $urlUtils ) );
	}

	/**
	 * @inheritDoc
	 *
	 * Merges the skin's own template data into the array core has already produced.
	 *
	 * The `+` operator keeps its LEFT operand whenever a key collides, so writing the
	 * parent data on the left makes every core key win. That is what upholds rule R2:
	 * `data-portlets`, `html-body-content`, `data-toc` and the rest reach the templates
	 * exactly as core built them, and no future view-model key can shadow one by accident.
	 * SkinMustache::getTemplateData() composes its own result the same way. array_merge()
	 * would invert that protection and would renumber integer keys as well, so it is not
	 * used here.
	 *
	 * The view model is given two values rather than the context object. The boolean is
	 * what lets it stay free of a context type hint, which matters because the context
	 * interface changed namespace inside the supported range. The parent data is what lets
	 * it derive `is-blitzy-toc-available` from core's `data-toc` instead of recomputing
	 * whether a table of contents exists.
	 *
	 * @return array Template data for the Mustache renderer: core's array, plus the four
	 *   Blitzy-prefixed keys the skin's own partials consume.
	 */
	public function getTemplateData(): array {
		$parentData = parent::getTemplateData();

		return $parentData + $this->viewModel->build(
			$this->getUser()->isRegistered(),
			$parentData
		);
	}
}
