<?php

namespace MediaWiki\Skins\Notion;

use MediaWiki\Skins\Notion\Services\LanguageService;

/**
 * A service locator for services specific to Notion.
 *
 * Everything else in this skin receives its collaborators through MediaWiki's declarative
 * dependency injection, and that remains the rule: `Notion.FeatureManagerFactory` is named in
 * `skin.json` under `ValidSkinNames."notion".services` and in the skin's `HookHandlers` entry,
 * while `Notion.ConfigHelper` is injected into that factory by `includes/ServiceWiring.php`.
 * Neither is re-exposed here, because a second resolution path would compete with, and quietly
 * undermine, that design.
 *
 * The single capability that cannot be injected is the language service. It is consumed from a
 * ResourceLoader configuration callback: a static function whose signature is fixed by
 * ResourceLoader and which is therefore never handed the service container. A narrow static
 * locator is the only resolution path available there, and that one call site is the whole
 * justification for this class. Keep it that narrow.
 *
 * @package Notion
 * @internal
 * @since 1.0.0
 */
final class NotionServices {

	/**
	 * Gets the language service, which reports whether a language's words may be split
	 * arbitrarily, for example when highlighting the user's query in the search autocomplete
	 * widget.
	 *
	 * A fresh instance is returned on every call, deliberately neither cached nor shared:
	 * LanguageService is immutable and its only state is a hard-coded list of language codes
	 * assigned in its constructor, so construction is cheap and sharing would buy nothing while
	 * introducing process-lifetime state into a static locator.
	 *
	 * @return LanguageService
	 */
	public static function getLanguageService(): LanguageService {
		return new LanguageService();
	}
}
