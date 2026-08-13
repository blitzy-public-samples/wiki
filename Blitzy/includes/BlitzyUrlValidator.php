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

use MediaWiki\Utils\UrlUtils;

/**
 * Scheme allowlist for configuration-sourced link targets.
 *
 * This class is the single validation site for every link target the Blitzy skin reads out of
 * site configuration. Rule R13, config input is untrusted, requires each such target to be
 * scheme-validated before it can reach an href attribute, and the skin manifest names this class
 * as the validator for all three of them: $wgBlitzyAnnounceLink, $wgBlitzyPrimaryActionLink and
 * $wgBlitzySecondaryActionLink. Those are the read sites the Gate 12 configuration-propagation
 * table points at. Routing all three through one class is deliberate: the rule text names only
 * two link-target options, so validating every one of them is a strict superset that satisfies
 * the rule under either reading and leaves no option exempt.
 *
 * The allowlist admits site-relative paths and the http and https schemes, and nothing else.
 * A rejected target is reported as null rather than as an empty string or a placeholder such as
 * a bare fragment, because the view model omits the whole key when this returns null, which makes
 * the enclosing Mustache section falsy so no href attribute is emitted at all. An empty href
 * would still render an element, and it would resolve to the current page.
 *
 * Two attacks motivate the order of the checks in ::validate():
 *
 *   - Protocol-relative targets. UrlUtils::parse() prepends a scheme to a "//host" target and
 *     returns a populated array for it, so a foreign origin can survive a scheme-only allowlist.
 *     Rejecting the protocol-relative form outright is what upholds R5, zero external origins,
 *     for the skin chrome. Editorial hyperlinks inside article content are exempt from R5 and
 *     never pass through this class.
 *   - Control characters. Browsers discard tabs, newlines and other control characters from a URL
 *     before acting on it, so a tab-interrupted or NUL-interrupted "javascript:" reaches the
 *     browser as a working scheme. Stripping them before any scheme comparison is mandatory.
 *
 * The class holds no mutable state, performs no I/O and never touches the service container: its
 * one collaborator arrives through the constructor, which keeps it constructible in a unit test
 * from a single stub.
 */
class BlitzyUrlValidator {

	/**
	 * Schemes an absolute link target is permitted to use.
	 *
	 * Deliberately public so the unit test and the delivery report can both cite it, giving the
	 * allowlist exactly one source of truth.
	 */
	public const ALLOWED_SCHEMES = [ 'http', 'https' ];

	/**
	 * Matches every ASCII control character: the C0 range, which covers NUL, tab, line feed and
	 * carriage return, together with DEL. Applied bytewise on purpose, with no "u" modifier, so
	 * that malformed UTF-8 input is stripped rather than causing the match itself to fail.
	 */
	private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]+/';

	/**
	 * @param UrlUtils $urlUtils Core URL parser, used only to read the scheme of an absolute
	 *   target. Injected rather than looked up, so this class never reaches into the service
	 *   container.
	 */
	public function __construct(
		private readonly UrlUtils $urlUtils,
	) {
	}

	/**
	 * Validate a configuration-sourced link target against the allowlist.
	 *
	 * @param string $target Untrusted link target, exactly as read from site configuration.
	 * @return string|null The normalised target when it is safe to emit, otherwise null. The
	 *   returned value is neither HTML-escaped nor URL-encoded: escaping happens once, in the
	 *   template's interpolation, and escaping here as well would double-escape the href.
	 */
	public function validate( string $target ): ?string {
		// Strip control characters before anything else, then trim the surrounding whitespace, so
		// that no later test can be fooled by an interrupted or a padded scheme. A cast covers the
		// documented null return of preg_replace, which only occurs on an internal engine error.
		$normalised = trim( (string)preg_replace( self::CONTROL_CHARACTERS, '', $target ) );

		// A target with nothing left to link to is not actionable.
		if ( $normalised === '' ) {
			return null;
		}

		// Reject protocol-relative targets, which would silently introduce a foreign origin.
		// Browsers normalise a backslash to a forward slash in the authority position of an
		// http or https URL, so "/\host", "\/host" and "\\host" all resolve exactly like
		// "//host". Testing a separator-normalised copy rejects every spelling with a single
		// comparison; that copy is used for this test alone and is never returned.
		if ( str_starts_with( strtr( $normalised, '\\', '/' ), '//' ) ) {
			return null;
		}

		// A single leading slash is a site-relative path, the ordinary case for a target such as
		// /wiki/Special:CreateAccount. It cannot leave this origin, so it is safe. This test has
		// to precede the parse below, because parse() rejects relative URLs outright and would
		// otherwise turn every legitimate site-relative target into a rejection.
		if ( str_starts_with( $normalised, '/' ) ) {
			return $normalised;
		}

		// Anything that is left has to be a well-formed absolute URL.
		$bits = $this->urlUtils->parse( $normalised );
		if ( $bits === null ) {
			return null;
		}

		// Accept an allowlisted scheme only. parse() already lowercases the scheme it reports, but
		// lowercasing again keeps the comparison correct whatever parser is injected and makes the
		// check independent of that internal detail. Every unlisted scheme falls through to the
		// rejection below, javascript, data, vbscript, file, about, blob and ftp among them.
		$scheme = strtolower( (string)( $bits['scheme'] ?? '' ) );
		if ( !in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
			return null;
		}

		return $normalised;
	}

	/**
	 * Report whether a configuration-sourced link target passes the allowlist.
	 *
	 * A convenience for callers that want the verdict without the value. ::validate() remains the
	 * method to call whenever the normalised target itself is needed.
	 *
	 * @param string $target Untrusted link target, exactly as read from site configuration.
	 * @return bool True when the target is safe to emit, false when it is rejected.
	 */
	public function isValid( string $target ): bool {
		return $this->validate( $target ) !== null;
	}
}
