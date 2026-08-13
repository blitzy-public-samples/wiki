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
 * @since 1.0.0
 */

namespace MediaWiki\Skins\Notion\FeatureManagement\Requirements;

use MediaWiki\Request\WebRequest;

/**
 * The `OverrideableRequirementHelper` allows us to define requirements that can be
 * overridden with querystring parameters.
 *
 * Requirements that support such overrides compose this helper and consult it before
 * evaluating their own state. `isMet()` reports the overridden value when the current
 * request carries an override for the requirement, and `null` when it carries none. That
 * is what lets the composing requirement tell an explicit override of `false` apart from
 * no override at all, and fall back to its own evaluation only in the latter case.
 *
 * Both parameters are derived from the requirement name injected at construction time, so
 * a requirement named `FooBar` is overridden with either `?notionfoobar=1`, the
 * all-lower-case form, or `?NotionFooBar=1`, the form that mirrors the `$wgNotion*`
 * configuration setting the requirement gates.
 *
 * NOTE: This API hasn't settled. It may change at any time without warning. Please don't bind to
 * it unless you absolutely need to
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 */
class OverrideableRequirementHelper {

	/**
	 * The all-lower-case parameter that overrides this requirement, derived once from the
	 * requirement name at construction time, e.g. `notionfoobar` for `FooBar`.
	 *
	 * @var string
	 */
	private readonly string $overrideName;

	/**
	 * This constructor accepts all dependencies needed to determine whether
	 * the overridable config is enabled for the current user and request.
	 *
	 * @param WebRequest $request
	 * @param string $requirementName The name of the requirement presented to FeatureManager.
	 */
	public function __construct(
		private readonly WebRequest $request,
		private readonly string $requirementName,
	) {
		$this->overrideName = 'notion' . strtolower( $requirementName );
	}

	/**
	 * Check whether the current request overrides this requirement.
	 *
	 * The all-lower-case parameter is checked first, then the capitalised form. Whichever
	 * of the two is present on the request wins and its value is returned as a boolean, so
	 * an override of `false` such as `?notionfoobar=0` is honoured rather than being read
	 * as no override having been requested.
	 *
	 * @return bool|null The overridden value, or `null` when the request carries no
	 *   override for this requirement, in which case the caller falls back to its own
	 *   evaluation.
	 */
	public function isMet(): ?bool {
		// Check query parameter.
		if ( $this->request->getCheck( $this->overrideName ) ) {
			return $this->request->getBool( $this->overrideName );
		}
		$notionReq = 'Notion' . $this->requirementName;
		if ( $this->request->getCheck( $notionReq ) ) {
			return $this->request->getBool( $notionReq );
		}
		return null;
	}
}
