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

namespace MediaWiki\Skins\Blitzy\Tests\Unit;

use MediaWiki\Skins\Blitzy\BlitzyUrlValidator;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;

/**
 * Unit tests for the scheme allowlist that guards every configuration-sourced link target.
 *
 * WHAT THIS SUITE IS FOR
 * ======================
 * Rule R13 treats configuration as untrusted input and requires every configuration-sourced link
 * target to be scheme-validated before it can reach an href attribute. The skin has three such
 * targets, $wgBlitzyAnnounceLink, $wgBlitzyPrimaryActionLink and $wgBlitzySecondaryActionLink, and
 * all three are routed through one class so that no option is exempt. That makes this file the
 * unit-level enforcement site for the rule, which is why the allowlist is enumerated exhaustively
 * below rather than sampled: sampling two schemes would leave the other twenty-eight protocols
 * core recognises unasserted, and it is those that the interesting failure lives among.
 *
 * THE REGRESSION THIS FILE EXISTS TO PREVENT
 * ==========================================
 * BlitzyUrlValidator is a narrowing wrapper. UrlUtils::parse() accepts every protocol in
 * $wgUrlProtocols, whose default admits thirty entries including mailto:, ftp://, tel:, sms:,
 * ssh:// and the protocol-relative //, while the Blitzy allowlist admits site-relative paths plus
 * http and https and nothing else. An implementation that simply forwarded to the parser would
 * behave correctly on a javascript: payload, because that scheme is absent from the default list
 * and the parser rejects it on its own, and would still hand a foreign-origin mailto:, ftp:, tel:
 * or //host target straight through to a template. Those four cases are therefore the ones that
 * distinguish a real allowlist from a pass-through, and they are asserted as rejections here.
 *
 * WHY THE COLLABORATOR IS REAL RATHER THAN A TEST DOUBLE
 * =====================================================
 * UrlUtils is @newable with a constructor marked @stable to call, resolves no service, and takes
 * its protocol list from the plain class constant MainConfigSchema::UrlProtocols, so the genuine
 * object is constructible inside a unit test. Using it is not a convenience: a stubbed parser
 * would be programmed with this suite's own expectations, and the narrowing described above would
 * then be asserted against a fiction instead of against the protocol list the wiki really uses.
 *
 * WHY REJECTION IS ASSERTED AS null
 * =================================
 * The rejection value is load-bearing rather than cosmetic. BlitzyViewModel drops the whole
 * view-model key when validation returns null, which leaves the enclosing Mustache section falsy
 * so that no href attribute is emitted at all. An empty string would still render an element, and
 * an empty href resolves to the current page, so asserting the exact null is what pins the
 * mechanism that keeps a rejected target out of the DOM entirely.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * =============================
 * Two neighbouring concerns belong to other files and are not duplicated in this one. The positive
 * assertion that a configuration string survives to the page HTML-escaped, and the end-to-end
 * injection of a javascript: URL into every configuration option, both belong to
 * tests/phpunit/integration/BlitzyConfigSecurityTest.php, which can observe a rendered page. And
 * nothing here renders anything: Gate 1's end-to-end boundary is evidenced by committed captures
 * of a real article, and a unit test cannot stand in for one.
 *
 * @group Blitzy
 * @covers \MediaWiki\Skins\Blitzy\BlitzyUrlValidator
 */
class BlitzyUrlValidatorTest extends MediaWikiUnitTestCase {

	/**
	 * Server handed to the collaborator when it is constructed.
	 *
	 * UrlUtils reads this only when expanding a URL, which validation never asks it to do, so the
	 * value is inert. It is supplied all the same because omitting it makes four of the parser's
	 * other methods throw, and a collaborator that is only conditionally usable is a trap for the
	 * next person to extend this suite. Rule R5 forbids this suite from resolving any external
	 * host, and .example is reserved for exactly this purpose, so nothing here is contactable even
	 * by accident.
	 */
	private const TEST_SERVER = 'https://wiki.example';

	/**
	 * Schemes the allowlist must never admit.
	 *
	 * Three groups, for three different reasons. javascript, data and vbscript are the script
	 * execution vectors R13 names. file, about and blob are the local-context schemes the
	 * implementation calls out by name. mailto, ftp and tel are recognised by core's default
	 * protocol list, so they are the ones a widened allowlist would most plausibly let back in.
	 */
	private const FORBIDDEN_SCHEMES = [
		'javascript',
		'data',
		'vbscript',
		'file',
		'about',
		'blob',
		'mailto',
		'ftp',
		'tel',
	];

	/**
	 * Build the class under test over a real parser.
	 *
	 * @return BlitzyUrlValidator
	 */
	private function newValidator(): BlitzyUrlValidator {
		return new BlitzyUrlValidator(
			new UrlUtils( [ UrlUtils::SERVER => self::TEST_SERVER ] )
		);
	}

	/**
	 * Link targets the allowlist admits, each with the exact string validation must return.
	 *
	 * The expected value is spelled out rather than merely asserted to be non-null, because the
	 * return value is what reaches an href: a validator that accepted the right targets but
	 * returned a mangled string would pass a looser assertion and ship a broken link.
	 *
	 * Site-relative paths carry their own group here for a structural reason. UrlUtils::parse()
	 * returns null for any input without a scheme, so a path such as /wiki/Ada_Lovelace cannot be
	 * admitted through the parser at all; the validator has to recognise the single leading slash
	 * on its own, before it consults the parser. These cases are what prove that arm exists.
	 *
	 * @return array[] Test case name to [ untrusted target, expected return value ]
	 */
	public static function provideAcceptedTargets(): array {
		return [
			// Site-relative targets, admitted by the leading-slash arm.
			'site-relative article path' => [
				'/wiki/Ada_Lovelace',
				'/wiki/Ada_Lovelace',
			],
			'site-relative script path with a query' => [
				'/w/index.php?title=Special:Random',
				'/w/index.php?title=Special:Random',
			],
			'bare site root' => [
				'/',
				'/',
			],
			// An ampersand must survive untouched. Escaping happens once, in the template's
			// interpolation, so a validator that escaped or encoded here would double-escape the
			// href and break the link.
			'site-relative path with an unescaped ampersand' => [
				'/w/index.php?a=1&b=2',
				'/w/index.php?a=1&b=2',
			],
			// Absolute targets, admitted by the scheme allowlist.
			'absolute http target' => [
				'http://example.org/page',
				'http://example.org/page',
			],
			'absolute https target' => [
				'https://example.org/page',
				'https://example.org/page',
			],
			'absolute https target with no path' => [
				'https://example.org',
				'https://example.org',
			],
			'absolute https target with a fragment' => [
				'https://example.org/page#Section',
				'https://example.org/page#Section',
			],
			// The scheme comparison is case-insensitive, and the target is returned as it was
			// written rather than lowercased, because only the scheme is case-insensitive in a URL.
			'uppercase https scheme' => [
				'HTTPS://example.org/page',
				'HTTPS://example.org/page',
			],
			// Normalisation is observable on an accepted target: surrounding whitespace is trimmed
			// and control characters are stripped, and the trimmed, stripped string is what comes
			// back. These pair with the obfuscated rejections below, where the same normalisation
			// is what stops a padded or interrupted scheme from slipping past the comparison.
			'site-relative path padded with whitespace' => [
				'  /wiki/Ada_Lovelace  ',
				'/wiki/Ada_Lovelace',
			],
			'site-relative path interrupted by a null byte' => [
				"/wiki/Ada\x00_Lovelace",
				'/wiki/Ada_Lovelace',
			],
			'absolute https target wrapped in control characters' => [
				"\thttps://example.org/page\n",
				'https://example.org/page',
			],
		];
	}

	/**
	 * Link targets the allowlist must reject, each of which validation must report as null.
	 *
	 * Grouped by the reason each one is rejected, because the groups exercise different arms of
	 * the validator and a reader needs to see that all of them are covered.
	 *
	 * @return array[] Test case name to [ untrusted target ]
	 */
	public static function provideRejectedTargets(): array {
		return [
			// Script execution vectors. The scheme is absent from core's protocol list, so the
			// parser refuses these before the allowlist is even consulted.
			'javascript scheme' => [ 'javascript:alert(1)' ],
			'mixed case javascript scheme' => [ 'JavaScript:alert(1)' ],
			'data uri carrying markup' => [ 'data:text/html;base64,PHNjcmlwdD4=' ],
			'vbscript scheme' => [ 'vbscript:msgbox(1)' ],
			// Obfuscated spellings of the same vector. A browser discards control characters from
			// a URL before acting on it, so each of these reaches the browser as a working
			// javascript: scheme and has to be stripped before any comparison is made.
			'newline inside the scheme' => [ "java\nscript:alert(1)" ],
			'tab inside the scheme' => [ "java\tscript:alert(1)" ],
			'null byte inside the scheme' => [ "java\x00script:alert(1)" ],
			'javascript scheme padded with whitespace' => [ '  javascript:alert(1)  ' ],
			// Local-context schemes, named by the implementation as falling through to rejection.
			'file scheme' => [ 'file:///etc/passwd' ],
			'about scheme' => [ 'about:blank' ],
			'blob scheme' => [ 'blob:https://example.org/x' ],
			// Schemes core's default protocol list accepts and this allowlist does not. Without
			// these four the suite would pass against a validator that only forwarded to the
			// parser, which is the regression described in the class docblock.
			'mailto scheme recognised by core' => [ 'mailto:someone@example.org' ],
			'ftp scheme recognised by core' => [ 'ftp://example.org/file' ],
			'tel scheme recognised by core' => [ 'tel:+15551234' ],
			'protocol-relative target recognised by core' => [ '//evil.example/x' ],
			// Backslash spellings of the same protocol-relative target. A browser normalises a
			// backslash to a forward slash in the authority position, so all three resolve exactly
			// like //evil.example/x and all three must be rejected with it.
			'protocol-relative target spelled slash backslash' => [ '/\\evil.example/x' ],
			'protocol-relative target spelled backslash slash' => [ '\\/evil.example/x' ],
			'protocol-relative target spelled with two backslashes' => [ '\\\\evil.example/x' ],
			// A lone backslash is neither a site-relative path nor a parseable absolute URL, so it
			// must not be mistaken for either.
			'target prefixed with a single backslash' => [ '\\evil.example/x' ],
			// Nothing actionable is left after normalisation.
			'empty string' => [ '' ],
			'whitespace only' => [ '   ' ],
			'control characters only' => [ "\t\r\n" ],
			// Relative forms with no leading slash and no scheme. The parser rejects each of
			// these, and none of them may be promoted to a site-relative path.
			'host with no scheme' => [ 'example.org/page' ],
			'fragment with no path' => [ '#section' ],
			'relative path with no leading slash' => [ 'wiki/Ada_Lovelace' ],
		];
	}

	/**
	 * @dataProvider provideAcceptedTargets
	 */
	public function testValidateReturnsNormalisedTargetForAllowlistedInput(
		string $target,
		string $expected
	) {
		$this->assertSame(
			$expected,
			$this->newValidator()->validate( $target ),
			'An allowlisted link target must come back as the exact string that is safe to emit.'
		);
	}

	/**
	 * @dataProvider provideRejectedTargets
	 */
	public function testValidateReturnsNullForTargetOutsideAllowlist( string $target ) {
		$this->assertNull(
			$this->newValidator()->validate( $target ),
			'A target outside the allowlist must be rejected as null, so that the view model omits '
				. 'the key and no href is emitted at all.'
		);
	}

	/**
	 * @dataProvider provideAcceptedTargets
	 */
	public function testIsValidReportsTrueForAllowlistedInput( string $target, string $expected ) {
		$this->assertTrue(
			$this->newValidator()->isValid( $target ),
			"An allowlisted link target must also be reported as valid; it validates to $expected."
		);
	}

	/**
	 * @dataProvider provideRejectedTargets
	 */
	public function testIsValidReportsFalseForTargetOutsideAllowlist( string $target ) {
		$this->assertFalse(
			$this->newValidator()->isValid( $target ),
			'A target outside the allowlist must be reported as invalid, in agreement with the '
				. 'null that validation returns for it.'
		);
	}

	/**
	 * The allowlist itself is asserted, not just its effects.
	 *
	 * Every case above exercises the allowlist indirectly, so widening it would show up as a
	 * failing rejection somewhere. Asserting the constant directly names the change instead: a
	 * fourth entry fails here, in one obvious place, rather than as a puzzling pass in a rejection
	 * case someone might then be tempted to delete.
	 */
	public function testAllowedSchemesAdmitsHttpAndHttpsOnly() {
		$this->assertSame(
			[ 'http', 'https' ],
			BlitzyUrlValidator::ALLOWED_SCHEMES,
			'The allowlist must admit http and https, in that order, and nothing else.'
		);

		$this->assertSame(
			[],
			array_intersect( BlitzyUrlValidator::ALLOWED_SCHEMES, self::FORBIDDEN_SCHEMES ),
			'No script-execution, local-context or foreign-origin scheme may appear in the allowlist.'
		);
	}
}
