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
 * @since 1.0.0
 */

namespace MediaWiki\Skins\Notion\FeatureManagement\Requirements;

use MediaWiki\Config\Config;
use MediaWiki\Request\WebRequest;
use MediaWiki\Skins\Notion\ConfigHelper;
use MediaWiki\Skins\Notion\Constants;
use MediaWiki\Skins\Notion\FeatureManagement\Requirement;
use MediaWiki\Title\Title;

/**
 * The `MaxWidthRequirement` for content.
 *
 * This requirement answers one question: may the page currently being rendered have its content
 * column constrained to a comfortable reading measure at all? It is a decision about the *page*,
 * taken on the server from configuration, and it is deliberately separate from the reader's own
 * choice, which lives in `Constants::REQUIREMENT_LIMITED_WIDTH` and is backed by a user
 * preference. The feature manager combines the two, so a page that opts out here stays full width
 * however the reader has set their preference. Diffs, history views, category listings and the
 * main page are the usual opt-outs: each needs the whole viewport rather than a narrow measure.
 *
 * The rules themselves are configuration rather than code. They are declared as
 * `$wgNotionMaxWidthOptions` in `skin.json` and evaluated by {@see ConfigHelper::shouldDisable},
 * which is also what makes them overridable per wiki without touching this class. Only two
 * responsibilities are left here: reading that configuration through `Config`, and inverting the
 * helper's answer — the helper reports whether the feature must be *disabled*, while a
 * requirement reports whether it is *met*.
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 */
final class LimitedWidthContentRequirement implements Requirement {

	/**
	 * This constructor accepts all dependencies needed to determine whether
	 * the overridable config is enabled for the current user and request.
	 *
	 * @param Config $config
	 * @param ConfigHelper $configHelper
	 * @param WebRequest $request
	 * @param Title|null $title can be null in testing environment
	 */
	public function __construct(
		private readonly Config $config,
		private readonly ConfigHelper $configHelper,
		private readonly WebRequest $request,
		private readonly ?Title $title = null,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return Constants::REQUIREMENT_LIMITED_WIDTH_CONTENT;
	}

	/**
	 * Per the $options configuration (for use with $wgNotionMaxWidthOptions)
	 * determine whether max-width should be disabled on the page.
	 * For the main page: Check the value of $options['exclude']['mainpage']
	 * For all other pages, the following will happen:
	 * - the array $options['include'] of canonical page names will be checked
	 *   against the current page. If a page has been listed there, function will return false
	 *   (max-width will not be disabled)
	 * Max width is disabled if:
	 *  1) The current namespace is listed in array $options['exclude']['namespaces']
	 *  OR
	 *  2) A query string parameter matches one of the regex patterns in $exclusions['querystring'].
	 *
	 * None of that evaluation lives here: it belongs to {@see ConfigHelper::shouldDisable}, which
	 * the whole skin shares with the other page-sensitive features, and duplicating it would let
	 * the two drift apart. This method exists purely to give the delegation one named seam that a
	 * test can drive directly.
	 *
	 * Note that the helper takes the request before the title, so the two are handed on in the
	 * opposite order to the one they arrive in. The transposition is deliberate; preserve it.
	 *
	 * @internal only for use inside tests.
	 * @param array $options
	 * @param Title $title
	 * @param WebRequest $request
	 * @return bool True when the configuration disables max-width on this page.
	 */
	private function shouldDisableMaxWidth( array $options, Title $title, WebRequest $request ): bool {
		return $this->configHelper->shouldDisable( $options, $request, $title );
	}

	/**
	 * Read the configured inclusion and exclusion rules for the current page and invert the
	 * helper's answer, because a page the configuration excludes is a page on which this
	 * requirement is not met.
	 *
	 * A request that carries no title reports the requirement as unmet and never reaches the
	 * helper. That is a real runtime case rather than a testing convenience: the factory builds
	 * this requirement from `IContextSource::getTitle()`, which is nullable. The short circuit is
	 * also what lets {@see self::shouldDisableMaxWidth} declare a non-nullable `Title` parameter.
	 *
	 * @inheritDoc
	 */
	public function isMet(): bool {
		return $this->title && !$this->shouldDisableMaxWidth(
			$this->config->get( Constants::CONFIG_KEY_MAX_WIDTH_OPTIONS ),
			$this->title,
			$this->request
		);
	}
}
