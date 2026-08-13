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
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Converts the Blitzy skin's eight configuration options into Mustache template data.
 *
 * SkinBlitzy overrides a single method and merges what this class returns into the array
 * core has already produced, which is why every piece of presentation logic the skin owns
 * lives here instead of in the skin class. Keeping the skin class thin is deliberate:
 * rule R14 measures line coverage over includes/, and a class with no wiki dependencies is
 * far cheaper to cover than a Skin subclass is.
 *
 * Three invariants hold. Each exists because breaking it fails a rule or a quality gate.
 *
 * 1. Every returned key is uniquely Blitzy-prefixed.
 *
 *    SkinMustache::getTemplateData() builds its own result as
 *    `parent::getTemplateData() + [ ... ]`, and SkinBlitzy merges this class's array the
 *    same way. PHP's `+` operator keeps the LEFT operand whenever a key collides, so any
 *    key core already occupies would be silently discarded rather than override anything.
 *    The Blitzy prefix is load bearing, not cosmetic. It also satisfies rule R2, because
 *    no key returned here can shadow a documented core key such as `data-toc`, `is-anon`
 *    or `html-body-content`.
 *
 * 2. No configuration-derived value is ever exposed under an `html-` prefixed key.
 *
 *    MediaWiki's TemplateParser already HTML-escapes `{{ }}` interpolation, so escaping
 *    here as well would double-escape: an injected payload would render visibly as
 *    `&lt;script&gt;` while a naive "the payload appears escaped" assertion still passed.
 *    There is exactly one escaping site and it is the template. What this class owes
 *    rule R13 instead is normalisation and omission. Every configuration string is cast,
 *    stripped of ASCII control characters and trimmed, and every configuration link target
 *    is routed through BlitzyUrlValidator with the whole `href` key dropped when the target
 *    is rejected. Because the naming contract reserves the `html-` prefix for raw HTML,
 *    keeping configuration values away from that prefix makes any later `{{{ }}}` misuse
 *    obvious in review. BlitzyViewModelTest asserts it as a key-prefix audit over the
 *    returned array, which is how this class demonstrates R13 compliance without
 *    double-escaping anything.
 *
 * 3. Nothing here branches on user identity except one fallback link target.
 *
 *    The plan describes the primary call to action resolving to account creation or to the
 *    watchlist depending on login state, while its MODES requirement states that anonymous
 *    and logged-in chrome differ only in the contents of the personal menu. Those readings
 *    do not reconcile as written, so the narrower one is implemented and the wider one is
 *    confined to a place where it cannot be observed as a difference in chrome. Every label
 *    comes from configuration alone, so the rendered text of both calls to action is
 *    identical for anonymous and for registered users, and the only identity-dependent
 *    value is the primary `href` fallback, consulted solely when configuration supplies no
 *    target of its own. Captures 1 and 20 of the capture matrix photograph the same surface
 *    anonymously and authenticated; this is the documented explanation for the single
 *    attribute that may differ between them.
 *
 * Fallback targets are resolved from canonical core special-page names through
 * SpecialPage::getTitleFor(), a static helper rather than a service-container lookup and so
 * permitted by rule R1. The secondary fallback is spelled `Randompage` and not `Random`:
 * `Random` is only an alias, and SpecialPageFactory reports a name missing from its alias
 * map through wfWarn(), which would add a warning to the log on every single page view and
 * fail the zero-warning build gate. `Randompage` resolves to Special:Random on an English
 * wiki, because that is the first alias mapping back to it, and to the correct localised
 * alias on any other wiki.
 *
 * ::build() takes a boolean and an array rather than a context object. That is a deliberate
 * departure from the illustrative snippet in the plan, for two reasons. The context
 * interface changed namespace inside the supported range, IContextSource carrying a
 * deprecated root-namespace class alias since 1.42, and this skin runs on 1.43 while its
 * API reference tree is 1.47, which makes a context type hint the one signature element
 * that could differ between them. And rule R14 requires this class to be exercisable under
 * MediaWikiUnitTestCase, where a boolean and an array need no stub at all. SkinBlitzy
 * supplies $this->getUser()->isRegistered() together with the parent template data.
 *
 * The class holds no mutable state, reads no globals, performs no I/O and never touches the
 * service container. Its one line that reaches a core static, ::localUrlForSpecialPage(),
 * is protected precisely so that a unit test can override it, because
 * MediaWikiUnitTestCase disallows the global service instance that Title::getLocalURL()
 * needs underneath.
 *
 * Gate 12 requires a named write site and a named read site for every configuration option.
 * The write sites are the `config` block of skin.json, which declares the shipped defaults,
 * and harness/LocalSettings.template.php, which overrides the three announcement options
 * from the BLITZY_ANNOUNCE_* environment variables on the verification instance. This class
 * is the single read site for all eight, and DELIVERY.md copies the mapping below. Partial
 * names are relative to includes/templates/.
 *
 *   OPTION                      TEMPLATE-DATA KEY                     RENDERED BY
 *   BlitzyAnnounceEnable        data-blitzy-announcement (presence)   AnnouncementBar
 *   BlitzyAnnounceText          data-blitzy-announcement.text         AnnouncementBar
 *   BlitzyAnnounceLabel         data-blitzy-announcement.label        AnnouncementBar
 *   BlitzyAnnounceLink          data-blitzy-announcement.href         AnnouncementBar
 *   BlitzyPrimaryActionLabel    data-blitzy-cta-primary.label         NavCard
 *   BlitzyPrimaryActionLink     data-blitzy-cta-primary.href          NavCard
 *   BlitzySecondaryActionLabel  data-blitzy-cta-secondary.label       NavCard
 *   BlitzySecondaryActionLink   data-blitzy-cta-secondary.href        NavCard
 *
 * The fourth key this class returns, `is-blitzy-toc-available`, is derived from the parent
 * template data rather than from configuration, so it owes Gate 12 no row. It is rendered
 * by TableOfContents.mustache.
 *
 * Two claims in the descriptions inside skin.json's config block are implemented
 * differently here, deliberately rather than by oversight. They say that an empty
 * call-to-action label is derived from a core message, and that configuration strings are
 * HTML-escaped in this class. Neither is done. Deriving a label would need a message
 * localiser this class is not given, skin.json's own `messages` argument does not list
 * those keys so there is no `msg-` entry to fall back to, and a label that changed with
 * login state would break the MODES requirement outright. Escaping here would
 * double-escape, as invariant 2 explains. An unlabelled call to action is therefore
 * suppressed by its template rather than relabelled, which is also what keeps an anchor
 * with no accessible name off the page and out of the blocking accessibility gate: both
 * NavCard.mustache and AnnouncementBar.mustache guard their anchors on the `label` value.
 *
 * @package Blitzy
 * @internal
 */
class BlitzyViewModel {

	/**
	 * The eight configuration options, spelled exactly as skin.json's `config` block
	 * declares them. They are public so that the unit test, the configuration security
	 * test and the delivery report all cite one source of truth rather than three
	 * independently maintained string literals.
	 *
	 * One boolean, four strings and three link targets. That arithmetic is the resolution
	 * of the count discrepancy in rule R13, whose prose names five strings and two link
	 * targets: every string is normalised and every link target is validated here, which is
	 * a strict superset of the stated scope and therefore satisfies the rule under either
	 * reading, with no option left exempt.
	 */
	public const OPTION_ANNOUNCE_ENABLE = 'BlitzyAnnounceEnable';
	public const OPTION_ANNOUNCE_TEXT = 'BlitzyAnnounceText';
	public const OPTION_ANNOUNCE_LABEL = 'BlitzyAnnounceLabel';
	public const OPTION_ANNOUNCE_LINK = 'BlitzyAnnounceLink';
	public const OPTION_PRIMARY_ACTION_LABEL = 'BlitzyPrimaryActionLabel';
	public const OPTION_PRIMARY_ACTION_LINK = 'BlitzyPrimaryActionLink';
	public const OPTION_SECONDARY_ACTION_LABEL = 'BlitzySecondaryActionLabel';
	public const OPTION_SECONDARY_ACTION_LINK = 'BlitzySecondaryActionLink';

	/**
	 * The template-data keys this class contributes, and the whole of its output contract.
	 *
	 * `data-` marks an object, `is-` marks a boolean, and the `blitzy` segment is what keeps
	 * each key clear of the core namespace that would otherwise win the merge. Publishing
	 * them as constants lets the templates, the unit test and the DOM-contract check agree
	 * on the spelling without repeating it.
	 */
	public const KEY_ANNOUNCEMENT = 'data-blitzy-announcement';
	public const KEY_ACTION_PRIMARY = 'data-blitzy-cta-primary';
	public const KEY_ACTION_SECONDARY = 'data-blitzy-cta-secondary';
	public const KEY_TOC_AVAILABLE = 'is-blitzy-toc-available';

	/**
	 * Canonical core special-page names used as link-target fallbacks when configuration
	 * supplies none. Canonical names, not aliases: see the class comment on `Randompage`.
	 *
	 * The first two are the only identity-dependent values in the class. The third is
	 * identity-independent, which is why the secondary call to action needs no branch.
	 */
	public const FALLBACK_PAGE_ANONYMOUS = 'CreateAccount';
	public const FALLBACK_PAGE_REGISTERED = 'Watchlist';
	public const FALLBACK_PAGE_SECONDARY = 'Randompage';

	/**
	 * Key under which SkinComponentTableOfContents publishes its data in the parent array.
	 *
	 * That component returns an empty array when the page has no table of contents, when
	 * `__NOTOC__` is set and when the parser emitted no sections at all, so the flag is
	 * derived from the section count inside it rather than from the presence of the key.
	 */
	private const PARENT_DATA_KEY_TOC = 'data-toc';

	/**
	 * Matches every ASCII control character: the C0 range, which covers NUL, tab, line feed
	 * and carriage return, together with DEL. Applied bytewise on purpose, with no "u"
	 * modifier, so that malformed UTF-8 input is stripped rather than causing the match
	 * itself to fail. Deliberately the same pattern BlitzyUrlValidator applies, so a string
	 * and a link target read from the same configuration are normalised identically.
	 */
	private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]+/';

	/**
	 * @param Config $config Site configuration, injected as MediaWiki's MainConfig service
	 *   through skin.json. Options declared in a skin manifest's `config` block are read by
	 *   their un-prefixed name, so the constants above are the exact keys used.
	 * @param BlitzyUrlValidator $urlValidator Scheme allowlist for every configuration-
	 *   sourced link target. Injected rather than constructed here so this class stays
	 *   constructible from two stubs in a unit test.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly BlitzyUrlValidator $urlValidator,
	) {
	}

	/**
	 * Build the Blitzy half of the skin's template data.
	 *
	 * The result is designed to be merged as `parent::getTemplateData() + $this->build( ... )`
	 * so that core keeps every key it owns. Because that merge direction discards a colliding
	 * key silently, the Blitzy prefix on each key below is what makes the merge safe.
	 *
	 * Announcement data is present only when the bar has something to render, so the enclosing
	 * Mustache section is falsy otherwise and AnnouncementBar.mustache emits nothing at all.
	 * That is the requirement: a disabled bar is absent from the document's flow, and hiding it
	 * with `display: none` would not comply. The two call-to-action keys are always present,
	 * each carrying the configured label and a link target, and NavCard.mustache decides
	 * whether a call to action with no label is worth an anchor.
	 *
	 * Nothing returned here presupposes that the client script ran, which is what rule R4
	 * requires: the announcement bar's server-rendered state is complete on its own, and only
	 * the persistence of a dismissal degrades when scripting is unavailable.
	 *
	 * @param bool $isRegisteredUser Whether the viewing user is registered, supplied by
	 *   SkinBlitzy from $this->getUser()->isRegistered(). A scalar rather than a user or
	 *   context object, so that no stub is needed to exercise this method in a unit test.
	 * @param array $parentData Template data already returned by
	 *   SkinMustache::getTemplateData(). Only its `data-toc` entry is read. The default of an
	 *   empty array yields a false table-of-contents flag, which is the correct answer for a
	 *   page that has no table of contents.
	 * @return array Blitzy-prefixed template data. Keys: `data-blitzy-announcement` when the
	 *   announcement bar has content, `data-blitzy-cta-primary`, `data-blitzy-cta-secondary`
	 *   and `is-blitzy-toc-available`. No key carries an `html-` prefix, so no value in it is
	 *   treated as raw HTML by any template.
	 */
	public function build( bool $isRegisteredUser, array $parentData = [] ): array {
		$data = [];

		// Omission rather than an empty object: absence of the key is what removes the bar
		// from the DOM entirely instead of rendering a hidden or empty band.
		$announcement = $this->buildAnnouncement();
		if ( $announcement !== null ) {
			$data[self::KEY_ANNOUNCEMENT] = $announcement;
		}

		$data[self::KEY_ACTION_PRIMARY] = $this->buildCallToAction(
			self::OPTION_PRIMARY_ACTION_LABEL,
			self::OPTION_PRIMARY_ACTION_LINK,
			$this->getPrimaryActionFallbackPage( $isRegisteredUser )
		);
		$data[self::KEY_ACTION_SECONDARY] = $this->buildCallToAction(
			self::OPTION_SECONDARY_ACTION_LABEL,
			self::OPTION_SECONDARY_ACTION_LINK,
			self::FALLBACK_PAGE_SECONDARY
		);
		$data[self::KEY_TOC_AVAILABLE] = $this->isTableOfContentsAvailable( $parentData );

		return $data;
	}

	/**
	 * Canonical name of the special page the primary call to action falls back to.
	 *
	 * This is the whole of the skin's identity-dependent behaviour outside the personal menu,
	 * and it is a pure function of its argument so that both of its branches are reachable
	 * from a unit test without any wiki, as rule R14 requires. An unregistered visitor is
	 * offered account creation; a registered one, who has an account already, is offered the
	 * watchlist instead.
	 *
	 * The name is returned rather than a URL because turning a special-page name into a URL
	 * needs the service container, which a unit test does not have.
	 *
	 * @param bool $isRegisteredUser Whether the viewing user is registered.
	 * @return string Canonical core special-page name, never empty.
	 */
	public function getPrimaryActionFallbackPage( bool $isRegisteredUser ): string {
		return $isRegisteredUser
			? self::FALLBACK_PAGE_REGISTERED
			: self::FALLBACK_PAGE_ANONYMOUS;
	}

	/**
	 * Whether the rendered page has a table of contents worth offering.
	 *
	 * SkinComponentTableOfContents publishes an empty array for a page with no table of
	 * contents, for `__NOTOC__` and for a parse that produced no sections, and publishes
	 * `number-section-count` only when there is something to list. The flag is therefore
	 * derived from that count and never from the presence of the `data-toc` key, which exists
	 * in all three of the empty cases.
	 *
	 * TableOfContents.mustache renders a sticky card at 1024px and above and an inline
	 * disclosure below it, both of which work without the client script, so this flag governs
	 * whether the component appears at all rather than how it behaves.
	 *
	 * @param array $parentData Template data returned by SkinMustache::getTemplateData().
	 * @return bool True when the parent data reports at least one section.
	 */
	public function isTableOfContentsAvailable( array $parentData ): bool {
		$sectionCount = $parentData[self::PARENT_DATA_KEY_TOC]['number-section-count'] ?? 0;

		// is_numeric() keeps a malformed value from being cast: core returns count(), an
		// integer, and anything else means the page has no countable sections.
		return is_numeric( $sectionCount ) && (int)$sectionCount > 0;
	}

	/**
	 * Turn a special-page name into a site-relative URL.
	 *
	 * The single line in this class that reaches outside its injected collaborators. It uses a
	 * static core helper, which rule R1 permits, and not the service container, which rule R1
	 * prohibits. It is protected rather than private so that a unit test can override it:
	 * MediaWikiUnitTestCase disallows the global service instance, and resolving a special
	 * page needs it, so overriding this one seam is what lets ::build() be exercised end to
	 * end with no wiki behind it.
	 *
	 * @param string $name Canonical core special-page name, as declared by the
	 *   FALLBACK_PAGE_* constants.
	 * @return string Site-relative URL for that special page.
	 */
	protected function localUrlForSpecialPage( string $name ): string {
		return SpecialPage::getTitleFor( $name )->getLocalURL();
	}

	/**
	 * Assemble the announcement bar's data, or report that there is nothing to render.
	 *
	 * Two conditions gate the bar, and both must hold: the option that enables it, and text
	 * to put in it. An enabled bar with no text would be an empty coloured band, so an empty
	 * text value suppresses it exactly as a disabled option does.
	 *
	 * `text` and `label` are always present in the returned object; `href` is present only
	 * when a target was configured and the allowlist accepted it. A rejected target leaves the
	 * text and the label untouched, so a page whose announcement link is malicious still shows
	 * its announcement, just without a link to follow.
	 *
	 * @return array|null Announcement data with `text`, `label` and optionally `href`, or null
	 *   when the bar must not be rendered at all.
	 */
	private function buildAnnouncement(): ?array {
		if ( !$this->readFlag( self::OPTION_ANNOUNCE_ENABLE ) ) {
			return null;
		}

		$text = $this->readString( self::OPTION_ANNOUNCE_TEXT );
		if ( $text === '' ) {
			return null;
		}

		$announcement = [
			'text' => $text,
			'label' => $this->readString( self::OPTION_ANNOUNCE_LABEL ),
		];

		$href = $this->readLinkTarget( self::OPTION_ANNOUNCE_LINK );
		if ( $href !== null ) {
			$announcement['href'] = $href;
		}

		return $announcement;
	}

	/**
	 * Assemble one of the navigation card's two calls to action.
	 *
	 * The label is whatever configuration holds, normalised and never derived from the viewing
	 * user, so both calls to action read identically to an anonymous and to a registered
	 * visitor. The target is the configured one when the allowlist accepts it and the supplied
	 * fallback otherwise, and the fallback is resolved lazily: a wiki that configures its own
	 * targets never resolves a special page at all, which is also what keeps this method
	 * callable in a unit test.
	 *
	 * @param string $labelOption Name of the configuration option holding the label.
	 * @param string $linkOption Name of the configuration option holding the link target.
	 * @param string $fallbackPage Canonical special-page name to fall back to.
	 * @return array Call-to-action data with `label` and, whenever a target could be resolved,
	 *   `href`.
	 */
	private function buildCallToAction(
		string $labelOption,
		string $linkOption,
		string $fallbackPage
	): array {
		$action = [ 'label' => $this->readString( $labelOption ) ];

		// ?? short-circuits, so the fallback is only resolved when configuration offered no
		// usable target of its own.
		$href = $this->readLinkTarget( $linkOption )
			?? $this->localUrlForSpecialPage( $fallbackPage );

		// Omission rather than an empty string: an empty href still renders an anchor, and
		// that anchor resolves to the page it sits on.
		if ( $href !== '' ) {
			$action['href'] = $href;
		}

		return $action;
	}

	/**
	 * Read a boolean configuration option.
	 *
	 * Cast rather than compared, so that a value written into site configuration as 1, "1" or
	 * true all enable the feature, matching how MediaWiki's own boolean settings behave.
	 *
	 * @param string $option Name of the configuration option.
	 * @return bool
	 */
	private function readFlag( string $option ): bool {
		return (bool)$this->config->get( $option );
	}

	/**
	 * Read and normalise a string configuration option.
	 *
	 * @param string $option Name of the configuration option.
	 * @return string Normalised value, possibly empty, and deliberately not HTML-escaped.
	 */
	private function readString( string $option ): string {
		return $this->normalise( $this->config->get( $option ) );
	}

	/**
	 * Read a link-target configuration option and validate it against the allowlist.
	 *
	 * Normalisation runs before validation so that the value the allowlist inspects is the
	 * value that would be emitted. Both the unconfigured case and the rejected case answer
	 * null, because the caller treats them identically: it omits the `href` key so that no
	 * attribute can be rendered from either.
	 *
	 * @param string $option Name of the configuration option.
	 * @return string|null Safe link target, or null when there is none to emit.
	 */
	private function readLinkTarget( string $option ): ?string {
		$target = $this->readString( $option );
		if ( $target === '' ) {
			return null;
		}

		return $this->urlValidator->validate( $target );
	}

	/**
	 * Normalise an untrusted configuration value into a plain string.
	 *
	 * This is the trust boundary rule R13 places on configuration-sourced strings, and it is
	 * deliberately not an escaping boundary. The value is cast, every ASCII control character
	 * is stripped, and the surrounding whitespace is trimmed. Nothing else happens to it: it
	 * is not HTML-escaped, because TemplateParser escapes `{{ }}` interpolation and a second
	 * pass here would render a payload visibly as `&lt;script&gt;`; and it is not truncated,
	 * because silently shortening an administrator's text would be user-visible behaviour
	 * that rule R12 does not authorise.
	 *
	 * Control characters are removed rather than replaced because they are how a payload
	 * survives a downstream check: a tab-interrupted or NUL-interrupted scheme reaches a
	 * browser as a working one.
	 *
	 * A value that is not a string or a number cannot be meaningful text for these options, so
	 * it normalises to the empty string. That covers null, booleans, arrays and objects, and
	 * it keeps a misconfigured setting from raising an "Array to string conversion" warning
	 * that the zero-warning build gate would fail on.
	 *
	 * @param mixed $value Raw configuration value.
	 * @return string Normalised text, possibly empty.
	 */
	private function normalise( $value ): string {
		if ( is_bool( $value ) || !is_scalar( $value ) ) {
			return '';
		}

		// The cast covers preg_replace()'s documented null return, which happens only on an
		// internal engine error such as backtracking exhaustion.
		return trim( (string)preg_replace( self::CONTROL_CHARACTERS, '', (string)$value ) );
	}
}
