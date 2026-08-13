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
use MediaWiki\Skins\Notion\FeatureManagement\Requirement;

/**
 * A requirement that reads a single configuration variable, lazily, every time it is asked.
 *
 * Some application state changes throughout the lifetime of the application, e.g. `wgSitename` or
 * `wgFullyInitialised`, which signals whether the application boot process has finished and
 * critical resources like database connections are available. `$wgFullyInitialised` is the reason
 * this class exists: core sets it in `mediawiki/includes/Setup.php` once all service wiring has
 * executed, so it is false during part of the very request in which a feature manager may be
 * built, and a requirement that read it eagerly would answer for a boot state that has since
 * moved on.
 *
 * The `DynamicConfigRequirement` allows us to define requirements that lazily evaluate the
 * application state, e.g.
 *
 * ```lang=php
 * $featureManager->registerRequirement(
 *   new DynamicConfigRequirement(
 *     $config,
 *     Constants::CONFIG_KEY_FULLY_INITIALISED,
 *     Constants::REQUIREMENT_FULLY_INITIALISED
 *   )
 * );
 * ```
 *
 * registers a requirement that will evaluate to true only when `mediawiki/includes/Setup.php` has
 * finished executing (after all service wiring has executed). I.e., every call to
 * `Requirement->isMet()` reinterrogates the Config object for the current state and returns it.
 * Contrast to
 *
 * ```lang=php
 * $featureManager->registerSimpleRequirement(
 *   Constants::REQUIREMENT_FULLY_INITIALISED,
 *   (bool)$config->get( Constants::CONFIG_KEY_FULLY_INITIALISED )
 * );
 * ```
 *
 * wherein state is evaluated only once, by the caller, at registration time and then permanently
 * cached for the rest of the request by {@see SimpleRequirement}. Prefer that form for state that
 * cannot change once the feature manager has been built, and this one for state that can.
 *
 * Nothing is memoised here by design: no property caches the answer and the constructor evaluates
 * nothing, so the class holds no stale copy of a value the rest of the request may still change.
 *
 * The two names the class is constructed with are deliberately independent. `$configName`
 * addresses the variable to read, while `$requirementName` is the identifier the feature manager
 * and every `FeatureManager::registerFeature()` call know the requirement by, which is what lets
 * a requirement be renamed without renaming the setting behind it.
 *
 * NOTE: This API hasn't settled. It may change at any time without warning. Please don't bind to
 * it unless you absolutely need to
 *
 * @unstable
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 * @internal
 */
final class DynamicConfigRequirement implements Requirement {

	/**
	 * @param Config $config
	 * @param string $configName Any `Config` key. This name is used to query `$config` state. E.g.,
	 *   `'DBname'`. See https://www.mediawiki.org/wiki/Manual:Configuration_settings
	 *   The key must be one the injected `Config` can resolve: `Config::get()` raises a
	 *   `ConfigException` for a variable that was never declared, so a skin-owned variable has to
	 *   appear in the `config` block of `skin.json` before a requirement may read it.
	 * @param string $requirementName The name of the requirement presented to FeatureManager.
	 *   This name _usually_ matches the `$configName` parameter for simplicity but allows for
	 *   abstraction as needed. See `Requirement->getName()`.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly string $configName,
		private readonly string $requirementName,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->requirementName;
	}

	/**
	 * @inheritDoc
	 */
	public function isMet(): bool {
		return (bool)$this->config->get( $this->configName );
	}
}
