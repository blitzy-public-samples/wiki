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

use MediaWiki\Skins\Notion\FeatureManagement\Requirement;

/**
 * A requirement whose state is a fixed value supplied at construction time.
 *
 * This class stores the boolean it is handed and returns it unchanged from every call to
 * `isMet()`. It interrogates nothing - no `Config`, no `WebRequest`, no user options and
 * no `Title` - so the application state behind the requirement is evaluated once, by the
 * caller, at registration time and then permanently cached for the rest of the request.
 * Contrast {@see DynamicConfigRequirement}, which reinterrogates its `Config` object on
 * every call.
 *
 * `FeatureManager::registerSimpleRequirement()` is the intended entry point, and it is the
 * caller that reduces the state to a `bool` before handing it over, e.g.
 *
 * ```lang=php
 * $featureManager->registerSimpleRequirement(
 *   Constants::REQUIREMENT_IS_MAIN_PAGE,
 *   $title ? $title->isMainPage() : false
 * );
 * ```
 *
 * Nothing richer than a `bool` is accepted here by design: a requirement that has to stay
 * sensitive to changing state belongs in a lazily evaluating sibling instead.
 *
 * NOTE: This API hasn't settled. It may change at any time without warning. Please don't bind to
 * it unless you absolutely need to
 *
 * @unstable
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 * @internal
 */
class SimpleRequirement implements Requirement {

	/**
	 * @param string $name The name of the requirement
	 * @param bool $isMet Whether the requirement is met
	 */
	public function __construct(
		private readonly string $name,
		private readonly bool $isMet,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @inheritDoc
	 */
	public function isMet(): bool {
		return $this->isMet;
	}
}
