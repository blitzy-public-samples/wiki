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

use MediaWiki\Skins\Notion\FeatureManagement\Requirement;
use MediaWiki\User\UserIdentity;

/**
 * A requirement that is met when the request is made by a registered user.
 *
 * Features whose state is persisted in user preferences compose this requirement so that
 * they are only offered to a user the wiki can store a preference against. The pinned main
 * menu and the pinned page tools do exactly that: each feature lists this requirement
 * alongside its own user-preference requirement, so an anonymous reader is served the
 * unpinned default regardless of what a preference lookup would otherwise report.
 *
 * The identity is supplied once at construction time, conventionally `$context->getUser()`,
 * and `isMet()` delegates straight to `UserIdentity::isRegistered()`, which is equivalent
 * to the user having a local user ID. Nothing is cached, because the identity behind a
 * single request does not change, and nothing else about the user is read: this requirement
 * deliberately knows nothing about *which* user it is looking at beyond whether an account
 * exists. A temporary account has a local user ID and therefore satisfies the requirement;
 * telling temporary accounts apart would mean taking a service dependency that this
 * requirement has no reason to hold.
 *
 * The requirement name is injected rather than derived, so its single spelling lives in
 * `Constants::REQUIREMENT_LOGGED_IN` and is resolved by the caller that registers the
 * requirement with the feature manager.
 *
 * @package MediaWiki\Skins\Notion\FeatureManagement\Requirements
 * @internal
 */
class LoggedInRequirement implements Requirement {

	/**
	 * Both dependencies are supplied by the caller that registers this requirement with the
	 * feature manager; neither is derived here.
	 *
	 * @param UserIdentity $user
	 * @param string $name The name of the requirement
	 */
	public function __construct(
		private readonly UserIdentity $user,
		private readonly string $name,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Returns true if the user is logged-in and false otherwise.
	 *
	 * @inheritDoc
	 */
	public function isMet(): bool {
		return $this->user->isRegistered();
	}
}
