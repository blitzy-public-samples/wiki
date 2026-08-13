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
use MediaWiki\Skins\Notion\FeatureManagement\Requirement;
use MediaWiki\User\UserIdentity;

/**
 * A requirement backed by a configuration variable that a querystring parameter may override.
 *
 * The requirement is registered with the *name* of a `Config` key rather than with a value, e.g.
 *
 * ```lang=php
 * $featureManager->registerRequirement(
 *   new OverridableConfigRequirement(
 *     $config,
 *     $user,
 *     $request,
 *     Constants::CONFIG_KEY_LANGUAGE_IN_HEADER,
 *     Constants::REQUIREMENT_LANGUAGE_IN_HEADER
 *   )
 * );
 * ```
 *
 * Nothing is resolved while that registration runs. Every call to `Requirement->isMet()`
 * re-interrogates the request for an override, the user for their authentication status and the
 * config object for its current value, then reports what it finds. Two consequences follow, and
 * both are the point of the class:
 *
 * - a request may flip the requirement for itself alone with a querystring parameter, which makes
 *   a feature inspectable on a running wiki without changing any configuration;
 * - the requirement may be registered before the state it reports has settled, because none of
 *   that state is captured at registration time.
 *
 * Contrast the eager sibling, where the caller reduces the state to a `bool` itself and that one
 * answer is then cached for the remainder of the request:
 *
 * ```lang=php
 * $featureManager->registerSimpleRequirement(
 *   Constants::REQUIREMENT_IS_MAIN_PAGE,
 *   $title ? $title->isMainPage() : false
 * );
 * ```
 *
 * Lazy evaluation is not free - each call costs a config lookup and up to two request lookups -
 * so it is reserved for requirements that genuinely have to stay sensitive to the request.
 *
 * Recognising an override is not implemented here. It is delegated wholesale to
 * {@see OverrideableRequirementHelper}, which owns the naming scheme for the parameters and is
 * the single place that scheme is defined.
 *
 * NOTE: This API hasn't settled. It may change at any time without warning. Please don't bind to
 * it unless you absolutely need to
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 */
class OverridableConfigRequirement implements Requirement {

	/**
	 * Delegate that reports whether the current request overrides this requirement.
	 *
	 * Composed once in the constructor from the injected request and requirement name. It is
	 * derived rather than injected, which is why it is a declared property here instead of a
	 * promoted constructor parameter.
	 *
	 * @var OverrideableRequirementHelper
	 */
	private readonly OverrideableRequirementHelper $helper;

	/**
	 * This constructor accepts all dependencies needed to determine whether
	 * the overridable config is enabled for the current user and request.
	 *
	 * @param Config $config
	 * @param UserIdentity $user
	 * @param WebRequest $request
	 * @param string $configName Any `Config` key. This name is used to query `$config` state.
	 * @param string $requirementName The name of the requirement presented to FeatureManager.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly UserIdentity $user,
		WebRequest $request,
		private readonly string $configName,
		private readonly string $requirementName,
	) {
		// $request is deliberately not promoted to a property: this class never reads the request
		// itself, it only needs it to compose the helper that does.
		$this->helper = new OverrideableRequirementHelper( $request, $requirementName );
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->requirementName;
	}

	/**
	 * Check the request for an override of this requirement, and fall back to the configuration
	 * value when it carries none.
	 *
	 * Three shapes of configuration value are accepted, because the variables this class gates
	 * have been set in all three in production over time:
	 *
	 * - a plain `bool`, which applies to everybody;
	 * - `[ 'default' => bool ]`, which also applies to everybody and wins over any sibling key
	 *   present in the same array;
	 * - `[ 'logged_in' => bool, 'logged_out' => bool ]`, which distinguishes registered users
	 *   from anonymous ones. Either key may be absent and is then read as `false`.
	 *
	 * The selected value is returned through the declared `bool` return type, which coerces a
	 * truthy scalar such as `1`; that is why no explicit cast appears below.
	 *
	 * @inheritDoc
	 */
	public function isMet(): bool {
		// An override carried by the current request wins outright. Testing against null rather
		// than truthiness is essential: null is the helper's "no override requested" answer and
		// the only one that may fall through, so an explicit override of false is honoured here
		// instead of being mistaken for the absence of an override.
		$isMet = $this->helper->isMet();
		if ( $isMet !== null ) {
			return $isMet;
		}

		// The request carries no override, so evaluate the configured value instead.
		$thisConfig = $this->config->get( $this->configName );

		// Backwards compatibility with config variables that have been set in production. Each
		// branch normalises one accepted shape into a lookup keyed by audience so that the
		// selection below never has to ask which shape it was handed.
		if ( is_bool( $thisConfig ) ) {
			// One value for everybody.
			$thisConfig = [
				'logged_in' => $thisConfig,
				'logged_out' => $thisConfig
			];
		} elseif ( array_key_exists( 'default', $thisConfig ) ) {
			// An explicit default applies to everybody, so discard any sibling key.
			$thisConfig = [
				'default' => $thisConfig['default'],
			];
		} else {
			// Per-audience values. An absent key resolves to false rather than raising a warning.
			$thisConfig = [
				'logged_in' => $thisConfig['logged_in'] ?? false,
				'logged_out' => $thisConfig['logged_out'] ?? false
			];
		}

		// Fallback to config, selecting the value for this request's audience.
		$userConfig = array_key_exists( 'default', $thisConfig ) ?
			$thisConfig[ 'default' ] :
			$thisConfig[ $this->user->isRegistered() ? 'logged_in' : 'logged_out' ];
		return $userConfig;
	}
}
