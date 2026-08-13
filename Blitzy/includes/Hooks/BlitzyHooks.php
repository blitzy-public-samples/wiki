<?php

namespace MediaWiki\Skins\Blitzy\Hooks;

use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\User\Options\UserOptionsLookup;

/**
 * Presentation hook handlers for the Blitzy skin.
 *
 * Hook handler method names should be in the form of:
 *	on<HookName>()
 *
 * This class is the skin's entire hook footprint: the two handlers declared
 * under `Hooks` in skin.json, and nothing else. Both are deliberately narrow,
 * because everything the skin can express in a Mustache template or a
 * stylesheet is expressed there instead. What is left here is exactly what
 * templates and stylesheets cannot reach.
 *
 * `\Skin` and `\SkinTemplate` are spelled with their root namespace in the
 * docblocks below. The namespaced `MediaWiki\Skin\Skin` and
 * `MediaWiki\Skin\SkinTemplate` names only became canonical in MediaWiki 1.44,
 * while this skin supports 1.43 and later, so the root-namespace names are the
 * ones valid across the whole supported range. Neither type appears in
 * executable code: both hook interfaces declare their parameters without PHP
 * types, and the handlers below match those signatures exactly.
 *
 * @package Blitzy
 * @internal
 */
class BlitzyHooks implements
	BeforePageDisplayHook,
	SkinTemplateNavigation__UniversalHook
{
	/**
	 * Name of the user preference holding the colour theme.
	 *
	 * The preference and its default are declared under `DefaultUserOptions` in
	 * skin.json; this class is its only read site reachable from a page render,
	 * and that read happens in exactly one place, self::onBeforePageDisplay().
	 */
	private const THEME_PREFERENCE = 'blitzy-theme';

	/**
	 * Prefix of the theme class applied to the `<html>` element.
	 *
	 * MediaWiki's `.darkmode-override()` Less mixin and the client preferences
	 * script that ResourceLoader inlines both key off this
	 * `<name>-clientpref-<value>` shape, so it is a platform contract rather
	 * than a Blitzy convention and must not be renamed.
	 */
	private const THEME_CLASS_PREFIX = 'skin-theme-clientpref-';

	/**
	 * The colour themes the skin implements, and therefore the only values
	 * allowed to reach the `<html>` element:
	 *
	 *  - `day` matches no dark rule at all, forcing light even inside an
	 *    operating system that is set to dark;
	 *  - `night` forces dark irrespective of the operating system setting;
	 *  - `os` follows the `prefers-color-scheme` media query.
	 *
	 * `day` and `night` are what make the override explicit in both directions.
	 */
	private const THEMES = [ 'day', 'night', 'os' ];

	/**
	 * Theme used when the stored preference is not one of self::THEMES.
	 *
	 * This matches the default declared in skin.json on purpose: any other
	 * fallback would stop the operating system preference from engaging for
	 * visitors who never chose a theme.
	 */
	private const THEME_FALLBACK = 'os';

	/**
	 * Class appended to every navigation entry the skin renders, giving the
	 * skin's own stylesheets a hook on list items that MediaWiki generates.
	 */
	private const MENU_ITEM_CLASS = 'blitzy-menu-item';

	/**
	 * Navigation buckets the skin renders.
	 *
	 * This mirrors the `menus` argument in skin.json entry for entry and in the
	 * same order, so the two lists can be compared at a glance. Buckets outside
	 * this list are never inspected, and a listed bucket the running MediaWiki
	 * version does not provide is skipped rather than created.
	 */
	private const MENU_BUCKETS = [
		'user-menu',
		'user-interface-preferences',
		'notifications',
		'views',
		'actions',
		'variants',
		'associated-pages',
	];

	/**
	 * @param UserOptionsLookup $userOptionsLookup Injected by ObjectFactory from
	 *   the `HookHandlers.BlitzyHooks.services` list in skin.json. It is the only
	 *   service these handlers need, and it is passed in rather than fetched so
	 *   that skin code never reaches into the MediaWiki service container.
	 */
	public function __construct( private readonly UserOptionsLookup $userOptionsLookup ) {
	}

	/**
	 * Applies the Blitzy colour theme class to the `<html>` element.
	 *
	 * A Mustache skin may only template the contents of the `<body>` tag, so no
	 * template of this skin can reach the root element, and
	 * OutputPage::addHtmlClasses() is the only sanctioned seam that does. That
	 * method's own documentation prefers OutputPage::addBodyClasses(), and the
	 * preference is knowingly not followed here: the theme is expressed through
	 * `html.skin-theme-clientpref-*` selectors, both in MediaWiki's
	 * `.darkmode-override()` mixin and in this skin's stylesheets, and a class
	 * on `<body>` cannot satisfy a selector anchored to `<html>`.
	 *
	 * The class is emitted on every render, unconditionally. For unregistered
	 * visitors ResourceLoader inlines MediaWiki's client preferences script,
	 * which rewrites an existing `<name>-clientpref-<value>` class from a cookie
	 * but deliberately never adds one that is absent. Skipping the class here
	 * would therefore break the anonymous theme switch silently rather than
	 * loudly, so there is no condition around it.
	 *
	 * Emitting it server-side also means a stored theme survives with scripting
	 * disabled: the client script is an enhancement and never the mechanism.
	 *
	 * There is exactly one user-dependent read in this class, the theme
	 * preference, and no login state, group or user name is inspected anywhere.
	 * That is what keeps anonymous and logged-in chrome identical apart from the
	 * contents of the personal menu. One lookup serves both cases: for an
	 * unregistered visitor it resolves to the default declared in skin.json,
	 * which the client preferences script then swaps. The preference cookie is
	 * deliberately not read here, because varying the server-rendered markup by
	 * cookie is what the client-side swap exists to avoid.
	 *
	 * The resolved value is checked against self::THEMES before use. Preference
	 * values are externally influenced and are stored as opaque strings, so a
	 * value such as `night day` would otherwise place two theme classes on the
	 * root element. MediaWiki escapes the attribute, so this is hygiene in the
	 * spirit of treating stored input as untrusted, not an escaping fix.
	 *
	 * Nothing is added to the document head, and no class other than the theme
	 * class is contributed to the page.
	 *
	 * @param OutputPage $out
	 * @param \Skin $skin
	 * @return void This hook must not abort, it must return no value
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$theme = $this->userOptionsLookup->getOption( $out->getUser(), self::THEME_PREFERENCE );
		if ( !in_array( $theme, self::THEMES, true ) ) {
			$theme = self::THEME_FALLBACK;
		}
		$out->addHtmlClasses( self::THEME_CLASS_PREFIX . $theme );
	}

	/**
	 * Marks the navigation entries MediaWiki supplies with the Blitzy class.
	 *
	 * The navigation this skin renders is whatever MediaWiki supplies. This
	 * handler adds no entry, removes no entry, renames nothing and creates no
	 * bucket. It visits only the buckets in self::MENU_BUCKETS, the same buckets
	 * declared as the skin's `menus` in skin.json, and only where the running
	 * MediaWiki version actually provides them, so it is simply inert on a
	 * version whose navigation shape differs.
	 *
	 * For each entry already present it appends self::MENU_ITEM_CLASS to that
	 * entry's `class`, which MediaWiki renders onto the list item wrapping the
	 * link. Existing class names are preserved and keep their order; only the
	 * whitespace between them is normalised. No other key is read or written, so
	 * the `text`/`html` and `icon`/`href` invariants MediaWiki checks the moment
	 * this handler returns still hold, and entries carrying `html` rather than
	 * `text` are handled the same way as any other.
	 *
	 * Array keys are never touched. That matters more than it looks: MediaWiki
	 * derives the `#ca-*` element ids from these keys after this hook returns,
	 * and the personal tools derive their `#pt-*` ids the same way, so renaming
	 * a key would silently rename a preserved element id.
	 *
	 * Entry order within a bucket is preserved, and appending the class twice is
	 * a no-op, so identical navigation always produces identical markup.
	 *
	 * @param \SkinTemplate $sktemplate
	 * @param array &$links Structured navigation links keyed by bucket name,
	 *   each bucket an array of entries keyed by entry name
	 * @return void This hook must not abort, it must return no value
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		foreach ( self::MENU_BUCKETS as $bucket ) {
			$entries = $links[$bucket] ?? null;
			if ( !is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $key => $entry ) {
				// Anything that is not an entry array is left exactly as it was
				// found. MediaWiki validates entry shape after this hook
				// returns, and its report is more useful than a failure here.
				if ( !is_array( $entry ) ) {
					continue;
				}
				$links[$bucket][$key]['class'] = self::withMenuItemClass( $entry['class'] ?? null );
			}
		}
	}

	/**
	 * Returns an entry's `class` value with self::MENU_ITEM_CLASS added once.
	 *
	 * The value's shape is preserved rather than normalised to one form, because
	 * both shapes are live. MediaWiki accepts a class list as either a string or
	 * an array of names, and when it labels language variant entries it
	 * concatenates onto the string form itself, immediately after this hook
	 * returns; handing an array back where a string was given would break that
	 * concatenation outright. The `null`, `false` and absent cases all mean "no
	 * classes yet" and resolve to the string form.
	 *
	 * @param string|string[]|false|null $classes Existing value, if any
	 * @return string|string[] The value with the Blitzy class added, in the same
	 *   shape it arrived in
	 */
	private static function withMenuItemClass( $classes ) {
		if ( is_array( $classes ) ) {
			if ( !in_array( self::MENU_ITEM_CLASS, $classes, true ) ) {
				$classes[] = self::MENU_ITEM_CLASS;
			}
			return $classes;
		}

		$names = preg_split( '/\s+/', (string)$classes, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( !in_array( self::MENU_ITEM_CLASS, $names, true ) ) {
			$names[] = self::MENU_ITEM_CLASS;
		}
		return implode( ' ', $names );
	}

}
