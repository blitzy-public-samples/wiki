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

use MediaWiki\Request\WebRequest;
use MediaWiki\Skins\Notion\FeatureManagement\Requirement;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserIdentity;

/**
 * A requirement whose state is read from a user preference and may be overridden per request.
 *
 * This is the workhorse of the skin's feature management: every reader-controlled part of the
 * layout is gated by one instance of this class. `FeatureManagement\FeatureManagerFactory`
 * registers seven of them - page-tools pinning, table-of-contents pinning, main-menu pinning,
 * appearance pinning, limited width, font size and night mode - each with a different preference
 * name and a different requirement name, and nothing else about them differs. That is why neither
 * the preference key nor the requirement key is written here: both are injected, so a new
 * preference-backed feature costs one registration in the factory and no change to this file.
 *
 * Three behaviours are load bearing and must be preserved by anyone editing this class.
 *
 * - **Override precedence.** A querystring override is consulted first, through the composed
 *   {@see OverrideableRequirementHelper}, and it wins outright when present. The result is merged
 *   with `??` rather than with a truthiness test precisely so that an override of `false` beats an
 *   enabled preference; `||` or `?:` would silently discard it.
 * - **Loose truthiness.** The stored preference value is deliberately interpreted loosely, because
 *   the preferences involved are not all flags. `notion-limited-width` and the pinning preferences
 *   store `1`/`0`, `notion-font-size` stores a numeric step and `notion-theme` stores one of
 *   `day`, `night` or `os`. Anything falsy - `0`, `'0'`, `''` or `null` - is disabled, the literal
 *   string `'disabled'` is disabled even though PHP considers it truthy, and every other value,
 *   `'day'` included, is enabled.
 * - **Null-safe title.** The title is genuinely optional: the factory passes
 *   `IContextSource::getTitle()`, which is null on requests that resolve to no title at all. It is
 *   never dereferenced here, only tested, and a null title disables the requirement outright so
 *   that a titleless request cannot produce a layout decision.
 *
 * The class evaluates lazily - nothing is read at construction time - so it stays correct when a
 * caller builds the feature manager before the preference or the request is in its final state.
 * Contrast {@see SimpleRequirement}, whose value is fixed by the caller once and for all.
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 */
final class UserPreferenceRequirement implements Requirement {

	/**
	 * Resolves any per-request override of this requirement.
	 *
	 * Composed rather than inherited so that the overriding scheme has a single definition point
	 * shared with the skin's other overridable requirements. It is built once, in the constructor,
	 * from the request and the requirement name, which is the only thing the request is needed
	 * for - hence the constructor taking the request without promoting it to a property.
	 *
	 * @var OverrideableRequirementHelper
	 */
	private readonly OverrideableRequirementHelper $helper;

	/**
	 * This constructor accepts all dependencies needed to determine whether
	 * the overridable config is enabled for the current user and request.
	 *
	 * @param UserIdentity $user
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param string $optionName The name of the user preference.
	 * @param string $requirementName The name of the requirement presented to FeatureManager.
	 * @param WebRequest $request
	 * @param Title|null $title
	 */
	public function __construct(
		private readonly UserIdentity $user,
		private readonly UserOptionsLookup $userOptionsLookup,
		private readonly string $optionName,
		private readonly string $requirementName,
		WebRequest $request,
		private readonly ?Title $title = null,
	) {
		$this->helper = new OverrideableRequirementHelper( $request, $requirementName );
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->requirementName;
	}

	/**
	 * Checks whether the user preference is enabled or not. Returns true if
	 * enabled AND title is not null.
	 *
	 * @internal
	 *
	 * @return bool
	 */
	public function isPreferenceEnabled(): bool {
		$optionValue = $this->userOptionsLookup->getOption( $this->user, $this->optionName );
		// Check for 0, '0' or 'disabled'.
		// Any other value will be handled as enabled.
		$isEnabled = $optionValue && $optionValue !== 'disabled';

		return $this->title && $isEnabled;
	}

	/**
	 * @inheritDoc
	 */
	public function isMet(): bool {
		$override = $this->helper->isMet();
		return $override ?? $this->isPreferenceEnabled();
	}
}
