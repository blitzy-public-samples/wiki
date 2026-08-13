/**
 * Exact-dependency-pinning enforcement for the Blitzy skin.
 *
 * This script is the enforcement site for R15 Exact Dependency Pinning. R15
 * delegates enforcement to "a CI step", and no continuous-integration definition
 * exists anywhere in the three submodules of this checkout — review for these
 * projects happens in an external Gerrit instance. Adding a pipeline definition
 * would place a file outside the skin path, breaching R1 Core Immutability, and
 * would introduce build tooling beyond the permitted stack. AAP §0.9.3 resolves
 * that conflict by shipping the enforcement as a package script instead, and this
 * file is that vehicle. R15 names CI only as the conventional carrier for an
 * outcome, so the comparison genuinely happens here and the run genuinely fails:
 * any violation sets a non-zero exit status. A script that reported without
 * failing would not satisfy the rule.
 *
 * WHAT IS CHECKED
 *
 *   package.json         every declared specifier is a bare exact version
 *   package-lock.json    lockfile shape, concrete resolutions, no drift
 *   composer.json        every declared constraint is an exact version
 *   composer.lock        content hash present, concrete resolutions, no drift
 *   harness/…-compose.yml every image carries an explicit tag AND an sha256
 *                        digest AND platform: linux/amd64
 *
 * SCOPE — AN EXPLICIT FIVE-FILE ALLOWLIST, NEVER A GLOB
 *
 * The five manifests above are hardcoded and resolved from this file's own
 * directory. Nothing globs, recurses, or walks a parent directory, and
 * node_modules/, vendor/ and the three submodule trees are never read. That is a
 * correctness requirement rather than a stylistic one. R15's scope deliberately
 * excludes MediaWiki core's own vendored dependencies, and core legitimately
 * declares range specifiers — mediawiki/package.json alone carries three caret
 * ranges and one file: specifier, and mediawiki/composer.json carries wildcard
 * platform constraints. A recursive implementation would fail the build on those
 * lawful declarations and would read outside the skin path against R1's spirit.
 * The reference skin's manifest is excluded for the same reason: it declares
 * composer/installers with a range, which is a defect this package corrects by
 * pinning exactly (AAP §0.9.2 R15) rather than a pattern to reproduce.
 *
 * DOCUMENTED CONFLICT — IMAGE COUNT, RESOLVED AS A SUPERSET
 *
 * R15 and AAP §0.8.6 both speak of "both base images" being digest-pinned, while
 * AAP §0.3.2.3 registers three images. The stated count cannot be reconciled with
 * the enumerated image surface, so this script enforces digest pinning on EVERY
 * image reference it finds in the Compose file. That is a strict superset: the
 * rule is satisfied under either reading and no guess is made about which image
 * was meant to be excluded. It mirrors the AAP's own conservative handling of the
 * identical count conflict in R13 (§0.1.3.1), where escaping was applied to every
 * configuration string rather than to a chosen subset. The platform assertion is
 * applied on the same superset basis for the reason recorded in AAP §0.3.2.3: the
 * capture image digest resolves a multi-architecture index and the MediaWiki and
 * MariaDB images are AMD64-only, so a digest alone does not fix the execution
 * environment and R9 byte-identity would break silently on an ARM host while
 * every version pin still looked correct.
 *
 * OTHER RULES THAT GOVERN THIS FILE
 *
 *   R1 Core Immutability      Reads only the five Blitzy-owned manifests and
 *                             writes no file anywhere; all output is stdout and
 *                             stderr.
 *   R5 Zero External Origins  No network access of any kind. Registry lookups
 *                             are deliberately absent: every input is a local
 *                             artefact, which also keeps the run deterministic
 *                             and usable offline.
 *   R12 No Scope Expansion    Self-contained by design. The tools directory is
 *                             capped at six scripts, so a helper another tool
 *                             also needs is duplicated locally rather than
 *                             extracted into a seventh file. Nothing here is
 *                             required from a sibling tool.
 *   R9 Deterministic Captures Its determinism discipline is applied to this
 *                             report: no timestamp, no run identifier, no
 *                             hostname, no absolute path, and every array and
 *                             object key ordered, so an unchanged tree produces
 *                             byte-identical output on every run.
 *
 * OUTPUT CONTRACT
 *
 * A single JSON document on stdout, and a short human-readable verdict on
 * stderr so a developer reading the terminal sees the outcome while stdout stays
 * cleanly parseable. AAP §0.8.5 requires DELIVERY.md to record every resolved
 * dependency version and the base image digests; the stdout document is the
 * artefact those figures are transcribed from, so it is complete rather than
 * decorative. Exit status is 0 only when the violations array is empty.
 *
 * INVOCATION — every step individually invocable (Gate 10)
 *
 *   node tools/check-pins.js        from anywhere; no arguments, no options
 *   npm run check-pins              the package-script spelling
 *
 * Node built-ins only. This script adds no dependency and needs none of the
 * fourteen the package declares.
 */

'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );

/**
 * The package root, resolved from this file's own directory rather than from the
 * working directory, so the script behaves identically however it is invoked.
 */
const PACKAGE_ROOT = path.resolve( __dirname, '..' );

/** Relative path of the npm manifest, used verbatim in report and messages. */
const NPM_MANIFEST = 'package.json';

/** Relative path of the npm lockfile. */
const NPM_LOCK = 'package-lock.json';

/** Relative path of the Composer manifest. */
const COMPOSER_MANIFEST = 'composer.json';

/** Relative path of the Composer lockfile. */
const COMPOSER_LOCK = 'composer.lock';

/** Relative path of the harness container stack definition. */
const COMPOSE_FILE = 'harness/docker-compose.yml';

/**
 * The scan surface. Hardcoded, in scan order, and never derived from the
 * filesystem. See the SCOPE note in the file header.
 */
const SCAN_SURFACE = [
	NPM_MANIFEST,
	NPM_LOCK,
	COMPOSER_MANIFEST,
	COMPOSER_LOCK,
	COMPOSE_FILE
];

/**
 * Dependency blocks inspected in package.json and in the lockfile's root entry.
 * All four are checked even though the package declares only devDependencies,
 * because a specifier smuggled into any of them is equally unpinned.
 */
const NPM_DEPENDENCY_BLOCKS = [
	'dependencies',
	'devDependencies',
	'optionalDependencies',
	'peerDependencies'
];

/** Dependency blocks inspected in composer.json. */
const COMPOSER_DEPENDENCY_BLOCKS = [ 'require', 'require-dev' ];

/**
 * The fourteen npm packages AAP §0.3.2.1 specifies, with the exact version each
 * must carry. Presence and version are both asserted: §0.3.4 records that these
 * pins are derived rather than incidental, and warns against upgrading them, so
 * a silent drift away from a recorded value is a finding rather than a routine
 * maintenance event.
 */
const EXPECTED_NPM = {
	'@axe-core/playwright': '4.13.0',
	'@playwright/test': '1.62.1',
	'@wikimedia/mw-node-qunit': '7.10.0',
	eslint: '8.57.1',
	'eslint-config-wikimedia': '0.32.5',
	'grunt-banana-checker': '0.13.0',
	karma: '6.4.1',
	'karma-chrome-launcher': '3.1.0',
	'karma-coverage': '2.2.1',
	'karma-qunit': '4.2.0',
	playwright: '1.62.1',
	qunit: '2.26.0',
	stylelint: '17.6.0',
	'stylelint-config-wikimedia': '0.19.3'
};

/**
 * The six Composer packages AAP §0.3.2.2 specifies, with the block each belongs
 * to and the exact version each must carry.
 */
const EXPECTED_COMPOSER = {
	'composer/installers': { block: 'require', version: '2.3.0' },
	'mediawiki/mediawiki-codesniffer': { block: 'require-dev', version: '52.0.0' },
	'mediawiki/mediawiki-phan-config': { block: 'require-dev', version: '0.20.0' },
	'mediawiki/minus-x': { block: 'require-dev', version: '2.0.1' },
	'php-parallel-lint/php-console-highlighter': { block: 'require-dev', version: '1.0.0' },
	'php-parallel-lint/php-parallel-lint': { block: 'require-dev', version: '1.4.0' }
};

/**
 * The three container images AAP §0.3.2.3 specifies, keyed by repository, with
 * the tag and digest each must carry. Every image found is required to be fully
 * pinned; these three are additionally required to be present and to match.
 */
const EXPECTED_IMAGES = {
	mariadb: {
		digest: 'sha256:dbe56e20372fc6d6b8e0e396866ba89c4c7f128c38c4f59aaa54d957db95790c',
		tag: '10.11.14'
	},
	'mcr.microsoft.com/playwright': {
		digest: 'sha256:dcc5531e97840b9b5e794f2814476b21571c5124a3fca2267d73041f56e7580e',
		tag: 'v1.62.1-noble'
	},
	mediawiki: {
		digest: 'sha256:77a2dda59645bd41c9648eb131416363416208db5936957ae45db3b64d3690f8',
		tag: '1.43.9'
	}
};

/**
 * The repository whose tag encodes the Playwright version, and the npm packages
 * that version must agree with. A drift between them breaks R9 byte-identity
 * while every individual pin still looks correct, which is precisely the class of
 * failure this script exists to catch.
 */
const PLAYWRIGHT_IMAGE_REPOSITORY = 'mcr.microsoft.com/playwright';

/** npm packages whose version must equal the Playwright image tag version. */
const PLAYWRIGHT_NPM_PACKAGES = [ '@playwright/test', 'playwright' ];

/** The only platform value that satisfies the R9 execution-environment pin. */
const REQUIRED_PLATFORM = 'linux/amd64';

/**
 * The two pins that are deliberately not the newest published release. Both are
 * exact and therefore fully compliant, so neither produces a finding; they are
 * reported so a maintainer reading the delivery report does not "fix" them by
 * upgrading. The reasons are the derivations recorded in AAP §0.3.4.
 */
const INTENTIONAL_CEILINGS = [
	{
		manifest: NPM_MANIFEST,
		name: 'stylelint',
		reason: 'stylelint-config-wikimedia 0.19.3 depends on stylelint at exactly ' +
			'17.6.0; a newer release would install two incompatible copies of the ' +
			'linter and make rule resolution nondeterministic.',
		version: '17.6.0'
	},
	{
		manifest: NPM_MANIFEST,
		name: 'eslint',
		reason: 'eslint-config-wikimedia 0.32.5 declares a peer range of ^8.57.0, ' +
			'which every ESLint 9 and later release violates, so 8.57.1 is the ' +
			'highest valid version rather than an arbitrary one.',
		version: '8.57.1'
	}
];

/**
 * Stable identifiers for every finding this script can emit. They are part of the
 * output contract: DELIVERY.md and any future consumer can key on the code rather
 * than parse a prose message, and the message stays free to be readable.
 */
const CODE = {
	composerLockContentHashMissing: 'COMPOSER_LOCK_CONTENT_HASH_MISSING',
	composerLockDrift: 'COMPOSER_LOCK_DRIFT',
	composerLockEntryMissing: 'COMPOSER_LOCK_ENTRY_MISSING',
	composerLockEntryUnnamed: 'COMPOSER_LOCK_ENTRY_UNNAMED',
	composerLockVersionNotExact: 'COMPOSER_LOCK_VERSION_NOT_EXACT',
	expectedPackageMissing: 'EXPECTED_PACKAGE_MISSING',
	expectedVersionMismatch: 'EXPECTED_VERSION_MISMATCH',
	imageDigestMalformed: 'IMAGE_DIGEST_MALFORMED',
	imageDigestMismatch: 'IMAGE_DIGEST_MISMATCH',
	imageDigestMissing: 'IMAGE_DIGEST_MISSING',
	imageDuplicateKey: 'IMAGE_DUPLICATE_KEY',
	imageExpectedMissing: 'IMAGE_EXPECTED_MISSING',
	imageInterpolated: 'IMAGE_INTERPOLATED',
	imageNoneFound: 'IMAGE_NONE_FOUND',
	imagePlatformMissing: 'IMAGE_PLATFORM_MISSING',
	imagePlatformUnexpected: 'IMAGE_PLATFORM_UNEXPECTED',
	imageRepositoryMissing: 'IMAGE_REPOSITORY_MISSING',
	imageTagFloating: 'IMAGE_TAG_FLOATING',
	imageTagMismatch: 'IMAGE_TAG_MISMATCH',
	imageTagMissing: 'IMAGE_TAG_MISSING',
	manifestMalformed: 'MANIFEST_MALFORMED',
	manifestMissing: 'MANIFEST_MISSING',
	manifestUnreadable: 'MANIFEST_UNREADABLE',
	npmLockDrift: 'NPM_LOCK_DRIFT',
	npmLockEntryMissing: 'NPM_LOCK_ENTRY_MISSING',
	npmLockRootMissing: 'NPM_LOCK_ROOT_MISSING',
	npmLockShapeUnknown: 'NPM_LOCK_SHAPE_UNKNOWN',
	npmLockVersionMissing: 'NPM_LOCK_VERSION_MISSING',
	npmLockVersionNotExact: 'NPM_LOCK_VERSION_NOT_EXACT',
	npmLockUndeclaredDependency: 'NPM_LOCK_UNDECLARED_DEPENDENCY',
	npmLockfileVersionMissing: 'NPM_LOCKFILE_VERSION_MISSING',
	npmRuntimeDependencyDeclared: 'NPM_RUNTIME_DEPENDENCY_DECLARED',
	playwrightVersionDrift: 'PLAYWRIGHT_VERSION_DRIFT',
	specifierBranchAlias: 'SPECIFIER_BRANCH_ALIAS',
	specifierDistTag: 'SPECIFIER_DIST_TAG',
	specifierEmpty: 'SPECIFIER_EMPTY',
	specifierNonRegistry: 'SPECIFIER_NON_REGISTRY',
	specifierNotAString: 'SPECIFIER_NOT_A_STRING',
	specifierNotExact: 'SPECIFIER_NOT_EXACT',
	specifierRangeOperator: 'SPECIFIER_RANGE_OPERATOR',
	specifierStabilityFlag: 'SPECIFIER_STABILITY_FLAG',
	specifierUnion: 'SPECIFIER_UNION',
	specifierVersionPrefix: 'SPECIFIER_VERSION_PREFIX',
	specifierWhitespace: 'SPECIFIER_WHITESPACE',
	specifierWildcard: 'SPECIFIER_WILDCARD',
	upstreamRange: 'UPSTREAM_RANGE',
	upstreamUnversionedEntry: 'UPSTREAM_UNVERSIONED_ENTRY'
};

/**
 * Human-readable explanation for each specifier classification, used to build
 * violation messages that are actionable without re-running the script.
 */
const SPECIFIER_EXPLANATION = {
	[ CODE.specifierBranchAlias ]: 'names a development branch rather than a released ' +
		'version',
	[ CODE.specifierDistTag ]: 'is a dist-tag or alias, which resolves to a different ' +
		'version over time',
	[ CODE.specifierEmpty ]: 'is empty, which resolves to any published version',
	[ CODE.specifierNonRegistry ]: 'is a path, URL or repository reference rather than ' +
		'a published version',
	[ CODE.specifierNotAString ]: 'is not a string',
	[ CODE.specifierNotExact ]: 'is not a complete major.minor.patch version',
	[ CODE.specifierRangeOperator ]: 'contains a range operator',
	[ CODE.specifierStabilityFlag ]: 'carries a stability flag rather than naming one ' +
		'version',
	[ CODE.specifierUnion ]: 'is a union of several ranges',
	[ CODE.specifierVersionPrefix ]: 'carries a leading "v"; declare the bare version ' +
		'instead',
	[ CODE.specifierWhitespace ]: 'contains whitespace, so it is a hyphen range or a ' +
		'conjunction',
	[ CODE.specifierWildcard ]: 'contains a wildcard'
};

/** The numeric core of a version: three components, no leading zeroes. */
const SEMVER_CORE = /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/;

/**
 * One dot-separated pre-release or build identifier. Pre-release and build
 * sections are validated identifier by identifier rather than by one composite
 * pattern: the published semver regular expression nests a quantifier inside a
 * quantifier, which the project's lint profile rejects as an unsafe expression,
 * and inline suppression directives are prohibited by Gate 2 Zero-Warning Build.
 * Splitting on the dot separator is equivalent and has no nested quantifier.
 */
const SEMVER_IDENTIFIER = /^[0-9A-Za-z-]+$/;

/** A fully-qualified content-addressable image digest. */
const IMAGE_DIGEST = /^sha256:[0-9a-f]{64}$/;

/** Any range or comparison operator. */
const RANGE_OPERATOR = /[\^~<>=!]/;

/** A wildcard, either bare or as an x-form version component. */
const VERSION_WILDCARD = /[*]|(?:^|\.)[xX](?:\.|$)/;

/** A dist-tag, alias or other word-shaped specifier such as latest or next. */
const DIST_TAG = /^[A-Za-z][A-Za-z0-9._-]*$/;

/** A Composer stability flag such as @stable or @dev. */
const STABILITY_FLAG = /@[A-Za-z]+$/;

/** A Composer branch alias such as dev-master or 1.x-dev. */
const BRANCH_ALIAS = /^dev-|-dev$/;

/** A leading v before a numeric version, as in v2.3.0. */
const VERSION_PREFIX = /^v[0-9]/;

/** An unresolved shell or Compose interpolation. */
const INTERPOLATION = /\$\{|\$[A-Za-z_]/;

/** A YAML mapping key line, capturing indentation, key and inline value. */
const YAML_KEY_LINE = /^([ \t]*)([A-Za-z0-9_][A-Za-z0-9_./-]*):[ \t]*(.*)$/;

/** A YAML comment line, whose content must never be parsed as configuration. */
const YAML_COMMENT_LINE = /^[ \t]*#/;

/** A trailing YAML end-of-line comment on an unquoted scalar. */
const YAML_TRAILING_COMMENT = /[ \t]+#.*$/;

/**
 * Creates the accumulator every check writes into.
 *
 * @return {Object} An empty report
 */
function createReport() {
	return {
		composer: null,
		images: [],
		informational: [],
		intentionalCeilings: [],
		npm: null,
		scanned: SCAN_SURFACE.slice().sort(),
		violations: []
	};
}

/**
 * Records a blocking finding. Every violation names the file it was found in, the
 * key path within that file, and the offending value, so the failure is
 * actionable without re-running the script.
 *
 * @param {Object} report The accumulator
 * @param {string} file Relative path of the manifest
 * @param {string} keyPath Dotted or bracketed path to the offending key
 * @param {string} code One of the CODE values
 * @param {string} message What is wrong and what is expected instead
 * @param {string} value The offending value, normalised to a string
 */
function addViolation( report, file, keyPath, code, message, value ) {
	report.violations.push( { code, file, keyPath, message, value: String( value ) } );
}

/**
 * Records a non-blocking observation. Used for facts that are true of upstream
 * manifests rather than of this package's declarations, which must be visible but
 * must never fail the run.
 *
 * @param {Object} report The accumulator
 * @param {string} file Relative path of the manifest
 * @param {string} keyPath Dotted or bracketed path to the observation
 * @param {string} code One of the CODE values
 * @param {string} message What was observed and why it is not a failure
 * @param {string} value The observed value, normalised to a string
 */
function addInformational( report, file, keyPath, code, message, value ) {
	report.informational.push( { code, file, keyPath, message, value: String( value ) } );
}

/**
 * Reads one of the five allowlisted files as text.
 *
 * A missing manifest is an enforcement gap rather than a pass, so it is recorded
 * as a violation under a distinct code. Reading continues afterwards so that one
 * absent file cannot mask problems in the remaining four.
 *
 * @param {Object} report The accumulator
 * @param {string} relativePath One of the SCAN_SURFACE entries
 * @return {string|null} File contents, or null when unavailable
 */
function readAllowlistedText( report, relativePath ) {
	const absolutePath = path.join( PACKAGE_ROOT, relativePath );
	if ( !fs.existsSync( absolutePath ) ) {
		addViolation(
			report,
			relativePath,
			'(file)',
			CODE.manifestMissing,
			'Required manifest is absent, so its pins cannot be enforced. Expected ' +
				'it at this path relative to the package root.',
			relativePath
		);
		return null;
	}
	try {
		return fs.readFileSync( absolutePath, 'utf8' );
	} catch ( error ) {
		addViolation(
			report,
			relativePath,
			'(file)',
			CODE.manifestUnreadable,
			'Required manifest could not be read: ' + error.message,
			relativePath
		);
		return null;
	}
}

/**
 * Reads and parses one of the allowlisted JSON manifests.
 *
 * @param {Object} report The accumulator
 * @param {string} relativePath One of the SCAN_SURFACE entries
 * @return {Object|null} Parsed document, or null when unavailable or malformed
 */
function readAllowlistedJson( report, relativePath ) {
	const text = readAllowlistedText( report, relativePath );
	if ( text === null ) {
		return null;
	}
	try {
		const parsed = JSON.parse( text );
		if ( parsed === null || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
			addViolation(
				report,
				relativePath,
				'(document)',
				CODE.manifestMalformed,
				'Manifest does not parse as a JSON object.',
				typeof parsed
			);
			return null;
		}
		return parsed;
	} catch ( error ) {
		addViolation(
			report,
			relativePath,
			'(document)',
			CODE.manifestMalformed,
			'Manifest is not valid JSON: ' + error.message,
			relativePath
		);
		return null;
	}
}

/**
 * Tests whether a value is a bare, fully-qualified exact version.
 *
 * Accepts three numeric components with an optional pre-release and an optional
 * build suffix, and nothing else. See SEMVER_IDENTIFIER for why validation is
 * split rather than expressed as one composite pattern.
 *
 * @param {string} value Candidate version
 * @return {boolean} Whether the value names exactly one version
 */
function isExactVersion( value ) {
	if ( typeof value !== 'string' || value === '' ) {
		return false;
	}
	const plus = value.indexOf( '+' );
	const build = plus === -1 ? '' : value.slice( plus + 1 );
	const withoutBuild = plus === -1 ? value : value.slice( 0, plus );
	if ( plus !== -1 && build === '' ) {
		return false;
	}
	const dash = withoutBuild.indexOf( '-' );
	const prerelease = dash === -1 ? '' : withoutBuild.slice( dash + 1 );
	const core = dash === -1 ? withoutBuild : withoutBuild.slice( 0, dash );
	if ( dash !== -1 && prerelease === '' ) {
		return false;
	}
	if ( !SEMVER_CORE.test( core ) ) {
		return false;
	}
	for ( const section of [ prerelease, build ] ) {
		if ( section === '' ) {
			continue;
		}
		for ( const identifier of section.split( '.' ) ) {
			if ( !SEMVER_IDENTIFIER.test( identifier ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Removes one leading v from a version string.
 *
 * Composer records the upstream tag name in its lockfile, so seventeen of the
 * thirty-eight entries in this package's lockfile legitimately read v2.3.0 rather
 * than 2.3.0. Normalising before validating and before comparing is what keeps
 * those lawful entries from being reported as drift. Declared constraints are not
 * normalised: the manifests are authored bare, and a v-prefixed declaration is
 * reported so the spelling stays uniform across both ecosystems.
 *
 * @param {string} value Candidate version
 * @return {string} The version without a leading v
 */
function withoutVersionPrefix( value ) {
	if ( typeof value === 'string' && VERSION_PREFIX.test( value ) ) {
		return value.slice( 1 );
	}
	return String( value );
}

/**
 * Classifies a declared dependency specifier.
 *
 * Exactness is decided first and decides the outcome on its own: a specifier that
 * names one version is compliant whatever else it may resemble, so no compliant
 * declaration can be misclassified by a later pattern. Only once a specifier is
 * known to be inexact do the remaining tests run, and they run from the most
 * specific construct to the least so that the code explains the actual defect
 * rather than merely reporting inexactness.
 *
 * @param {*} specifier The declared value
 * @return {string|null} A CODE value, or null when the specifier is exact
 */
function classifySpecifier( specifier ) {
	if ( typeof specifier !== 'string' ) {
		return CODE.specifierNotAString;
	}
	if ( isExactVersion( specifier ) ) {
		return null;
	}
	if ( specifier.trim() === '' ) {
		return CODE.specifierEmpty;
	}
	if ( /\s/.test( specifier ) ) {
		return CODE.specifierWhitespace;
	}
	if ( specifier.includes( '||' ) ) {
		return CODE.specifierUnion;
	}
	if ( specifier.includes( ':' ) || specifier.includes( '/' ) ) {
		return CODE.specifierNonRegistry;
	}
	if ( STABILITY_FLAG.test( specifier ) ) {
		return CODE.specifierStabilityFlag;
	}
	if ( BRANCH_ALIAS.test( specifier ) ) {
		return CODE.specifierBranchAlias;
	}
	if ( VERSION_WILDCARD.test( specifier ) ) {
		return CODE.specifierWildcard;
	}
	if ( RANGE_OPERATOR.test( specifier ) ) {
		return CODE.specifierRangeOperator;
	}
	if ( VERSION_PREFIX.test( specifier ) ) {
		return CODE.specifierVersionPrefix;
	}
	if ( DIST_TAG.test( specifier ) ) {
		return CODE.specifierDistTag;
	}
	return CODE.specifierNotExact;
}

/**
 * Builds the message for a classified specifier.
 *
 * @param {string} code The classification
 * @param {string} keyPath Where the specifier was declared
 * @return {string} A message naming the defect and the expectation
 */
function specifierMessage( code, keyPath ) {
	const explanation = SPECIFIER_EXPLANATION[ code ] || 'is not an exact version';
	return 'Specifier at ' + keyPath + ' ' + explanation +
		'. Declare one exact version such as 1.2.3.';
}

/**
 * Sorts an array of report records by the given keys, in place.
 *
 * Ordering is by string comparison on each key in turn, which is stable across
 * runs and across platforms because it never depends on insertion order or on a
 * locale-sensitive collation.
 *
 * @param {Array} records The records to order
 * @param {Array} keys Field names, in precedence order
 * @return {Array} The same array, ordered
 */
function sortRecords( records, keys ) {
	return records.sort( ( left, right ) => {
		for ( const key of keys ) {
			const a = String( left[ key ] === undefined ? '' : left[ key ] );
			const b = String( right[ key ] === undefined ? '' : right[ key ] );
			if ( a < b ) {
				return -1;
			}
			if ( a > b ) {
				return 1;
			}
		}
		return 0;
	} );
}

/**
 * Returns a structurally identical value with every object key ordered.
 *
 * Applied to the whole document immediately before serialisation so that key
 * order can never depend on the order in which checks happened to run. Arrays
 * keep the order their producing check assigned, which is already deterministic.
 *
 * @param {*} value Any JSON-serialisable value
 * @return {*} The value with ordered keys
 */
function withOrderedKeys( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( ( entry ) => withOrderedKeys( entry ) );
	}
	if ( value === null || typeof value !== 'object' ) {
		return value;
	}
	const ordered = {};
	for ( const key of Object.keys( value ).sort() ) {
		ordered[ key ] = withOrderedKeys( value[ key ] );
	}
	return ordered;
}

/**
 * Collects the named dependency blocks of a manifest into flat records.
 *
 * @param {Object} document The parsed manifest
 * @param {Array} blocks Block names to read
 * @return {Array} Records of { block, name, declared }
 */
function collectDeclarations( document, blocks ) {
	const records = [];
	for ( const block of blocks ) {
		const entries = document[ block ];
		if ( entries === null || typeof entries !== 'object' || Array.isArray( entries ) ) {
			continue;
		}
		for ( const name of Object.keys( entries ) ) {
			records.push( { block, declared: entries[ name ], name } );
		}
	}
	return records;
}

/**
 * Indexes an npm lockfile's resolved versions.
 *
 * Both lockfile shapes are handled. Version 2 and 3 carry a packages map keyed by
 * install path, where the empty key is the project itself and legitimately has no
 * version, so it is excluded from the version requirement rather than reported.
 * Version 1 carries a nested dependencies tree instead. Entries marked as links
 * point at a local path and hold their version elsewhere, so they are noted
 * without failing.
 *
 * @param {Object} report The accumulator
 * @param {Object} lock The parsed lockfile
 * @return {Object} { shape, entryCount, topLevel, resolved }
 */
function indexNpmLock( report, lock ) {
	const topLevel = new Map();
	const resolved = [];
	let shape = 'unknown';
	let entryCount = 0;
	const hasPackages = lock.packages !== null && typeof lock.packages === 'object' &&
		!Array.isArray( lock.packages );
	const hasDependencies = lock.dependencies !== null &&
		typeof lock.dependencies === 'object' && !Array.isArray( lock.dependencies );
	if ( hasPackages ) {
		shape = 'packages';
		const keys = Object.keys( lock.packages );
		entryCount = keys.length;
		if ( !keys.includes( '' ) ) {
			addViolation(
				report,
				NPM_LOCK,
				'packages[""]',
				CODE.npmLockRootMissing,
				'Lockfile has no root project entry, so the declarations it records ' +
					'cannot be compared with the manifest.',
				'(absent)'
			);
		}
		for ( const key of keys ) {
			if ( key === '' ) {
				continue;
			}
			const entry = lock.packages[ key ];
			const name = key.slice( key.lastIndexOf( 'node_modules/' ) + 'node_modules/'.length );
			const isNested = key.indexOf( 'node_modules/' ) !==
				key.lastIndexOf( 'node_modules/' );
			if ( entry === null || typeof entry !== 'object' ) {
				addViolation(
					report,
					NPM_LOCK,
					'packages["' + key + '"]',
					CODE.npmLockVersionMissing,
					'Lockfile entry is not an object, so no resolved version can be read.',
					'(absent)'
				);
				continue;
			}
			if ( entry.link === true ) {
				addInformational(
					report,
					NPM_LOCK,
					'packages["' + key + '"]',
					CODE.upstreamUnversionedEntry,
					'Entry is a link to a local path, which records its version at the ' +
						'link target rather than here. Not a pinning defect.',
					key
				);
				continue;
			}
			if ( typeof entry.version !== 'string' || entry.version === '' ) {
				addViolation(
					report,
					NPM_LOCK,
					'packages["' + key + '"].version',
					CODE.npmLockVersionMissing,
					'Lockfile entry carries no resolved version, so the install is not ' +
						'reproducible.',
					'(absent)'
				);
				continue;
			}
			if ( !isExactVersion( entry.version ) ) {
				addViolation(
					report,
					NPM_LOCK,
					'packages["' + key + '"].version',
					CODE.npmLockVersionNotExact,
					'Resolved version is not a complete major.minor.patch version.',
					entry.version
				);
			}
			resolved.push( { name, version: entry.version } );
			if ( !isNested && !topLevel.has( name ) ) {
				topLevel.set( name, entry.version );
			}
		}
	} else if ( hasDependencies ) {
		// Lockfile version 1 shape. Retained so an older lockfile is validated
		// rather than silently skipped, even though this package commits version 3.
		shape = 'dependencies';
		const queue = [ { node: lock.dependencies, prefix: 'dependencies' } ];
		while ( queue.length > 0 ) {
			const current = queue.shift();
			for ( const name of Object.keys( current.node ) ) {
				const entry = current.node[ name ];
				const keyPath = current.prefix + '.' + name;
				entryCount += 1;
				if ( entry === null || typeof entry !== 'object' ) {
					continue;
				}
				if ( typeof entry.version !== 'string' || entry.version === '' ) {
					addViolation(
						report,
						NPM_LOCK,
						keyPath + '.version',
						CODE.npmLockVersionMissing,
						'Lockfile entry carries no resolved version, so the install is ' +
							'not reproducible.',
						'(absent)'
					);
					continue;
				}
				resolved.push( { name, version: entry.version } );
				if ( current.prefix === 'dependencies' && !topLevel.has( name ) ) {
					topLevel.set( name, entry.version );
				}
				if ( entry.dependencies !== null && typeof entry.dependencies === 'object' ) {
					queue.push( {
						node: entry.dependencies,
						prefix: keyPath + '.dependencies'
					} );
				}
			}
		}
	} else {
		addViolation(
			report,
			NPM_LOCK,
			'(document)',
			CODE.npmLockShapeUnknown,
			'Lockfile has neither a packages map nor a dependencies tree, so no ' +
				'resolved version can be read from it.',
			'(absent)'
		);
	}
	return { entryCount, resolved, shape, topLevel };
}

/**
 * Notes upstream range specifiers that mention a package this skin declares.
 *
 * A dependency edge inside a third-party lockfile entry is a property of that
 * upstream manifest, not of this package's declarations. The lint configuration
 * this skin uses declares a peer range on ESLint, for instance, and thirty-odd
 * comparable edges exist across the installed tree. Reporting any of them as a
 * failure would fail every run, so they are recorded as observations only. The
 * declarations that are this package's own — the manifest, and the root entry the
 * lockfile mirrors it into — are checked strictly elsewhere.
 *
 * @param {Object} report The accumulator
 * @param {Object} lock The parsed lockfile
 * @param {Set} declaredNames Packages this skin declares
 */
function noteUpstreamNpmRanges( report, lock, declaredNames ) {
	if ( lock.packages === null || typeof lock.packages !== 'object' ) {
		return;
	}
	for ( const key of Object.keys( lock.packages ) ) {
		if ( key === '' ) {
			continue;
		}
		const entry = lock.packages[ key ];
		if ( entry === null || typeof entry !== 'object' ) {
			continue;
		}
		for ( const block of NPM_DEPENDENCY_BLOCKS ) {
			const edges = entry[ block ];
			if ( edges === null || typeof edges !== 'object' || Array.isArray( edges ) ) {
				continue;
			}
			for ( const name of Object.keys( edges ) ) {
				if ( !declaredNames.has( name ) || classifySpecifier( edges[ name ] ) === null ) {
					continue;
				}
				addInformational(
					report,
					NPM_LOCK,
					'packages["' + key + '"].' + block + '.' + name,
					CODE.upstreamRange,
					'Upstream package declares a range for a package this skin pins. ' +
						'That is a property of the upstream manifest and does not affect ' +
						'the resolved install, so it is reported rather than enforced.',
					edges[ name ]
				);
			}
		}
	}
}

/**
 * Validates the npm manifest and lockfile.
 *
 * @param {Object} report The accumulator
 */
function checkNpm( report ) {
	const manifest = readAllowlistedJson( report, NPM_MANIFEST );
	const lock = readAllowlistedJson( report, NPM_LOCK );
	const section = {
		declared: [],
		isPrivate: false,
		lock: { entryCount: 0, lockfileVersion: null, shape: 'unavailable' },
		lockFile: NPM_LOCK,
		manifest: NPM_MANIFEST,
		resolved: []
	};
	report.npm = section;
	if ( manifest === null ) {
		return;
	}
	section.isPrivate = manifest.private === true;
	const declarations = collectDeclarations( manifest, NPM_DEPENDENCY_BLOCKS );
	const declaredNames = new Set( declarations.map( ( entry ) => entry.name ) );
	// A runtime dependency contradicts the declared posture: this package is
	// private, ships no npm-delivered component and has no runtime npm dependency
	// whatsoever, so an entry here is a finding in its own right and not merely a
	// specifier to inspect.
	const runtimeCount = declarations.filter( ( entry ) => entry.block === 'dependencies' ).length;
	if ( runtimeCount > 0 ) {
		addViolation(
			report,
			NPM_MANIFEST,
			'dependencies',
			CODE.npmRuntimeDependencyDeclared,
			'Manifest declares runtime npm dependencies. This package has none by ' +
				'design; every tool it uses belongs in devDependencies.',
			String( runtimeCount ) + ' entries'
		);
	}
	for ( const entry of declarations ) {
		const keyPath = entry.block + '.' + entry.name;
		const code = classifySpecifier( entry.declared );
		if ( code !== null ) {
			addViolation(
				report,
				NPM_MANIFEST,
				keyPath,
				code,
				specifierMessage( code, keyPath ),
				entry.declared
			);
		}
	}
	let index = { entryCount: 0, resolved: [], shape: 'unavailable', topLevel: new Map() };
	if ( lock !== null ) {
		if ( lock.lockfileVersion === undefined || lock.lockfileVersion === null ) {
			addViolation(
				report,
				NPM_LOCK,
				'lockfileVersion',
				CODE.npmLockfileVersionMissing,
				'Lockfile declares no lockfileVersion, so its shape cannot be trusted.',
				'(absent)'
			);
		} else {
			section.lock.lockfileVersion = lock.lockfileVersion;
		}
		index = indexNpmLock( report, lock );
		section.lock.entryCount = index.entryCount;
		section.lock.shape = index.shape;
		noteUpstreamNpmRanges( report, lock, declaredNames );
		checkNpmLockRoot( report, lock, manifest );
	}
	for ( const entry of declarations ) {
		const resolved = index.topLevel.get( entry.name );
		if ( resolved === undefined ) {
			if ( lock !== null ) {
				addViolation(
					report,
					NPM_LOCK,
					'packages["node_modules/' + entry.name + '"]',
					CODE.npmLockEntryMissing,
					'Declared package has no top-level lockfile entry, so its install is ' +
						'not reproducible from the lockfile.',
					entry.name
				);
			}
			section.declared.push( {
				block: entry.block,
				declared: String( entry.declared ),
				name: entry.name,
				resolved: null
			} );
			continue;
		}
		if ( isExactVersion( entry.declared ) && resolved !== entry.declared ) {
			addViolation(
				report,
				NPM_LOCK,
				'packages["node_modules/' + entry.name + '"].version',
				CODE.npmLockDrift,
				'Lockfile resolves this package to a version the manifest does not ' +
					'declare. Declared ' + entry.declared + '; reinstall so the two agree.',
				resolved
			);
		}
		section.declared.push( {
			block: entry.block,
			declared: String( entry.declared ),
			name: entry.name,
			resolved
		} );
	}
	checkExpectedNpm( report, declarations );
	section.declared = sortRecords( section.declared, [ 'name', 'block' ] );
	section.resolved = sortRecords( index.resolved, [ 'name', 'version' ] );
}

/**
 * Validates the declarations the lockfile mirrors from the manifest.
 *
 * The root entry of a version 2 or 3 lockfile restates this package's own
 * dependency blocks, so those specifiers are this package's declarations and are
 * held to the same exactness requirement as the manifest. Divergence between the
 * two is reported because a lockfile out of step with its manifest defeats the
 * reproducibility the pinning rule exists to protect.
 *
 * @param {Object} report The accumulator
 * @param {Object} lock The parsed lockfile
 * @param {Object} manifest The parsed manifest
 */
function checkNpmLockRoot( report, lock, manifest ) {
	if ( lock.packages === null || typeof lock.packages !== 'object' ) {
		return;
	}
	const root = lock.packages[ '' ];
	if ( root === null || typeof root !== 'object' ) {
		return;
	}
	for ( const block of NPM_DEPENDENCY_BLOCKS ) {
		const mirrored = root[ block ];
		if ( mirrored === null || typeof mirrored !== 'object' || Array.isArray( mirrored ) ) {
			continue;
		}
		const declaredBlock = manifest[ block ];
		for ( const name of Object.keys( mirrored ) ) {
			const keyPath = 'packages[""].' + block + '.' + name;
			const code = classifySpecifier( mirrored[ name ] );
			if ( code !== null ) {
				addViolation(
					report,
					NPM_LOCK,
					keyPath,
					code,
					specifierMessage( code, keyPath ),
					mirrored[ name ]
				);
			}
			if ( declaredBlock === null || typeof declaredBlock !== 'object' ||
				!Object.hasOwn( declaredBlock, name ) ) {
				addViolation(
					report,
					NPM_LOCK,
					keyPath,
					CODE.npmLockUndeclaredDependency,
					'Lockfile records a declaration the manifest does not contain. ' +
						'Reinstall so the lockfile mirrors ' + NPM_MANIFEST + '.',
					mirrored[ name ]
				);
			} else if ( declaredBlock[ name ] !== mirrored[ name ] ) {
				addViolation(
					report,
					NPM_LOCK,
					keyPath,
					CODE.npmLockDrift,
					'Lockfile records a different specifier from the manifest. Manifest ' +
						'declares ' + String( declaredBlock[ name ] ) + '.',
					mirrored[ name ]
				);
			}
		}
	}
}

/**
 * Confirms the npm packages the specification enumerates, at the versions it
 * records.
 *
 * Presence and version are both asserted. The recorded versions are derivations
 * rather than incidental choices — two of them are documented ceilings that a
 * newer release would break — so a silent move away from one is reported.
 *
 * @param {Object} report The accumulator
 * @param {Array} declarations Records from collectDeclarations
 */
function checkExpectedNpm( report, declarations ) {
	const byName = new Map( declarations.map( ( entry ) => [ entry.name, entry ] ) );
	for ( const name of Object.keys( EXPECTED_NPM ) ) {
		const expected = EXPECTED_NPM[ name ];
		const entry = byName.get( name );
		if ( entry === undefined ) {
			addViolation(
				report,
				NPM_MANIFEST,
				'devDependencies.' + name,
				CODE.expectedPackageMissing,
				'Specified package is not declared. The verification toolchain requires ' +
					'it at exactly ' + expected + '.',
				'(absent)'
			);
			continue;
		}
		if ( entry.declared !== expected ) {
			addViolation(
				report,
				NPM_MANIFEST,
				entry.block + '.' + name,
				CODE.expectedVersionMismatch,
				'Declared version differs from the version the specification records. ' +
					'Expected exactly ' + expected + '.',
				entry.declared
			);
		}
	}
}

/**
 * Tests whether a Composer requirement names the platform rather than a package.
 *
 * Platform requirements — the PHP version itself, PHP extensions, shared
 * libraries and the Composer runtime — describe the environment a package needs
 * and have no published release that could be pinned, so they are outside the
 * pinning requirement and are recorded rather than validated. MediaWiki core's own
 * manifest illustrates the shape: it declares the PHP version as a lower bound and
 * every extension as a wildcard, both of which are correct and neither of which is
 * a package pin.
 *
 * @param {string} name The requirement name
 * @return {boolean} Whether the requirement names the platform
 */
function isPlatformRequirement( name ) {
	// A platform requirement is never vendor-namespaced, and this test has to run
	// first: php-parallel-lint/php-parallel-lint would otherwise be read as a PHP
	// platform requirement on its prefix alone and escape validation entirely.
	if ( name.includes( '/' ) ) {
		return false;
	}
	if ( name === 'php' || name === 'hhvm' || name === 'composer' ) {
		return true;
	}
	return name.startsWith( 'php-' ) || name.startsWith( 'ext-' ) ||
		name.startsWith( 'lib-' ) || name.startsWith( 'composer-' );
}

/**
 * Indexes a Composer lockfile's resolved versions.
 *
 * @param {Object} report The accumulator
 * @param {Object} lock The parsed lockfile
 * @return {Object} { resolved, byName, packageCount, packageDevCount }
 */
function indexComposerLock( report, lock ) {
	const byName = new Map();
	const resolved = [];
	const counts = { packageCount: 0, packageDevCount: 0 };
	const blocks = [
		{ items: lock.packages, key: 'packages' },
		{ items: lock[ 'packages-dev' ], key: 'packages-dev' }
	];
	for ( const block of blocks ) {
		if ( !Array.isArray( block.items ) ) {
			continue;
		}
		if ( block.key === 'packages' ) {
			counts.packageCount = block.items.length;
		} else {
			counts.packageDevCount = block.items.length;
		}
		for ( let position = 0; position < block.items.length; position++ ) {
			const entry = block.items[ position ];
			const keyPath = block.key + '[' + String( position ) + ']';
			if ( entry === null || typeof entry !== 'object' ||
				typeof entry.name !== 'string' || entry.name === '' ) {
				addViolation(
					report,
					COMPOSER_LOCK,
					keyPath + '.name',
					CODE.composerLockEntryUnnamed,
					'Lockfile entry has no package name, so its resolution cannot be ' +
						'attributed.',
					'(absent)'
				);
				continue;
			}
			if ( typeof entry.version !== 'string' || entry.version === '' ) {
				addViolation(
					report,
					COMPOSER_LOCK,
					keyPath + '.version',
					CODE.composerLockVersionNotExact,
					'Lockfile entry for ' + entry.name + ' carries no resolved version.',
					'(absent)'
				);
				continue;
			}
			// Composer records the upstream tag name, so a leading v is normal here
			// and is normalised before validating rather than reported.
			if ( !isExactVersion( withoutVersionPrefix( entry.version ) ) ) {
				addViolation(
					report,
					COMPOSER_LOCK,
					keyPath + '.version',
					CODE.composerLockVersionNotExact,
					'Resolved version for ' + entry.name + ' does not name one released ' +
						'version. A branch or alias makes the install unreproducible.',
					entry.version
				);
			}
			resolved.push( { block: block.key, name: entry.name, version: entry.version } );
			if ( !byName.has( entry.name ) ) {
				byName.set( entry.name, entry.version );
			}
		}
	}
	return { byName, packageCount: counts.packageCount, packageDevCount: counts.packageDevCount,
		resolved };
}

/**
 * Validates the Composer manifest and lockfile.
 *
 * @param {Object} report The accumulator
 */
function checkComposer( report ) {
	const manifest = readAllowlistedJson( report, COMPOSER_MANIFEST );
	const lock = readAllowlistedJson( report, COMPOSER_LOCK );
	const section = {
		declared: [],
		lock: { contentHash: null, packageCount: 0, packageDevCount: 0 },
		lockFile: COMPOSER_LOCK,
		manifest: COMPOSER_MANIFEST,
		platformRequirements: [],
		resolved: []
	};
	report.composer = section;
	if ( manifest === null ) {
		return;
	}
	const declarations = collectDeclarations( manifest, COMPOSER_DEPENDENCY_BLOCKS );
	let index = { byName: new Map(), packageCount: 0, packageDevCount: 0, resolved: [] };
	if ( lock !== null ) {
		const contentHash = lock[ 'content-hash' ];
		if ( typeof contentHash !== 'string' || contentHash === '' ) {
			addViolation(
				report,
				COMPOSER_LOCK,
				'content-hash',
				CODE.composerLockContentHashMissing,
				'Lockfile carries no content hash, so it cannot be shown to correspond ' +
					'to the manifest that produced it.',
				'(absent)'
			);
		} else {
			section.lock.contentHash = contentHash;
		}
		index = indexComposerLock( report, lock );
		section.lock.packageCount = index.packageCount;
		section.lock.packageDevCount = index.packageDevCount;
	}
	for ( const entry of declarations ) {
		const keyPath = entry.block + '.' + entry.name;
		if ( isPlatformRequirement( entry.name ) ) {
			section.platformRequirements.push( {
				block: entry.block,
				constraint: String( entry.declared ),
				name: entry.name
			} );
			continue;
		}
		const code = classifySpecifier( entry.declared );
		if ( code !== null ) {
			addViolation(
				report,
				COMPOSER_MANIFEST,
				keyPath,
				code,
				specifierMessage( code, keyPath ),
				entry.declared
			);
		}
		const resolved = index.byName.get( entry.name );
		if ( resolved === undefined ) {
			if ( lock !== null ) {
				addViolation(
					report,
					COMPOSER_LOCK,
					'packages[].' + entry.name,
					CODE.composerLockEntryMissing,
					'Declared package has no lockfile entry, so its install is not ' +
						'reproducible from the lockfile.',
					entry.name
				);
			}
			section.declared.push( {
				block: entry.block,
				declared: String( entry.declared ),
				name: entry.name,
				resolved: null
			} );
			continue;
		}
		if ( code === null && withoutVersionPrefix( resolved ) !== entry.declared ) {
			addViolation(
				report,
				COMPOSER_LOCK,
				'packages[].' + entry.name + '.version',
				CODE.composerLockDrift,
				'Lockfile resolves this package to a version the manifest does not ' +
					'declare. Declared ' + String( entry.declared ) + '; run composer ' +
					'update so the two agree.',
				resolved
			);
		}
		section.declared.push( {
			block: entry.block,
			declared: String( entry.declared ),
			name: entry.name,
			resolved
		} );
	}
	checkExpectedComposer( report, declarations );
	section.declared = sortRecords( section.declared, [ 'name', 'block' ] );
	section.platformRequirements = sortRecords(
		section.platformRequirements,
		[ 'name', 'block' ]
	);
	section.resolved = sortRecords( index.resolved, [ 'name', 'version' ] );
}

/**
 * Confirms the Composer packages the specification enumerates, at the versions and
 * in the blocks it records.
 *
 * A package declared in the other block is reported as an observation rather than
 * a failure: it is a posture question rather than a pinning defect, and the
 * pinning rule is what this script enforces.
 *
 * @param {Object} report The accumulator
 * @param {Array} declarations Records from collectDeclarations
 */
function checkExpectedComposer( report, declarations ) {
	const byName = new Map( declarations.map( ( entry ) => [ entry.name, entry ] ) );
	for ( const name of Object.keys( EXPECTED_COMPOSER ) ) {
		const expected = EXPECTED_COMPOSER[ name ];
		const entry = byName.get( name );
		if ( entry === undefined ) {
			addViolation(
				report,
				COMPOSER_MANIFEST,
				expected.block + '.' + name,
				CODE.expectedPackageMissing,
				'Specified package is not declared. The PHP toolchain requires it at ' +
					'exactly ' + expected.version + '.',
				'(absent)'
			);
			continue;
		}
		if ( entry.declared !== expected.version ) {
			addViolation(
				report,
				COMPOSER_MANIFEST,
				entry.block + '.' + name,
				CODE.expectedVersionMismatch,
				'Declared version differs from the version the specification records. ' +
					'Expected exactly ' + expected.version + '.',
				entry.declared
			);
		}
		if ( entry.block !== expected.block ) {
			addInformational(
				report,
				COMPOSER_MANIFEST,
				entry.block + '.' + name,
				CODE.expectedPackageMissing,
				'Package is declared in ' + entry.block + ' rather than the expected ' +
					expected.block + '. The pin itself is intact.',
				entry.block
			);
		}
	}
}

/**
 * Reduces a YAML scalar to its value.
 *
 * Handles the two forms the Compose file uses: a double-quoted scalar, which the
 * three image references are written as so that a digest-pinned reference fits the
 * project's line-length limit without an inline lint suppression, and a plain
 * scalar, which may carry a trailing end-of-line comment.
 *
 * @param {string} raw Everything after the key and its colon
 * @return {string} The scalar value
 */
function yamlScalar( raw ) {
	const trimmed = raw.trim();
	const isDoubleQuoted = trimmed.length > 1 && trimmed.startsWith( '"' ) &&
		trimmed.endsWith( '"' );
	const isSingleQuoted = trimmed.length > 1 && trimmed.startsWith( "'" ) &&
		trimmed.endsWith( "'" );
	if ( isDoubleQuoted || isSingleQuoted ) {
		return trimmed.slice( 1, -1 ).trim();
	}
	return trimmed.replace( YAML_TRAILING_COMMENT, '' ).trim();
}

/**
 * Extracts the service blocks of the Compose file, with their image and platform
 * declarations.
 *
 * The scan is line-oriented and deliberately adds no YAML parser: the pinning rule
 * governs what is declared and the technology stack governs what may be added, and
 * no YAML library is among the fourteen dependencies this package declares. Three
 * properties of the file being read make that sound. Comment lines are skipped, so
 * the commented example of a platform declaration inside the file cannot be read
 * as configuration. The top-level section is tracked, so the volume definitions —
 * which sit at the same indentation as service names — are never mistaken for
 * services. And the file deliberately avoids anchors, aliases, merge keys and
 * extension fields, keeping the pin surface literally parseable.
 *
 * @param {string} text The Compose file contents
 * @return {Array} Service records in file order
 */
function parseComposeServices( text ) {
	const lines = text.split( '\n' );
	const services = [];
	let inServices = false;
	let serviceIndent = -1;
	let current = null;
	for ( let position = 0; position < lines.length; position++ ) {
		const line = lines[ position ];
		if ( line.trim() === '' || YAML_COMMENT_LINE.test( line ) ) {
			continue;
		}
		const match = YAML_KEY_LINE.exec( line );
		if ( match === null ) {
			continue;
		}
		const indent = match[ 1 ].length;
		const key = match[ 2 ];
		const value = match[ 3 ];
		if ( indent === 0 ) {
			inServices = key === 'services';
			serviceIndent = -1;
			current = null;
			continue;
		}
		if ( !inServices ) {
			continue;
		}
		if ( serviceIndent === -1 ) {
			serviceIndent = indent;
		}
		if ( indent === serviceIndent ) {
			current = { images: [], name: key, platforms: [] };
			services.push( current );
			continue;
		}
		if ( current === null ) {
			continue;
		}
		if ( key === 'image' ) {
			current.images.push( { line: position + 1, value: yamlScalar( value ) } );
		} else if ( key === 'platform' ) {
			current.platforms.push( { line: position + 1, value: yamlScalar( value ) } );
		}
	}
	return services;
}

/**
 * Splits an image reference into repository, tag and digest.
 *
 * The tag separator is the last colon that follows the last slash, so a registry
 * host carrying a port or a dotted domain is not mistaken for a tag.
 *
 * @param {string} reference The image reference
 * @return {Object} { repository, tag, digest }
 */
function parseImageReference( reference ) {
	const at = reference.lastIndexOf( '@' );
	const digest = at === -1 ? '' : reference.slice( at + 1 ).trim();
	const named = at === -1 ? reference : reference.slice( 0, at );
	const lastSlash = named.lastIndexOf( '/' );
	const lastColon = named.lastIndexOf( ':' );
	if ( lastColon > lastSlash ) {
		return {
			digest,
			repository: named.slice( 0, lastColon ),
			tag: named.slice( lastColon + 1 )
		};
	}
	return { digest, repository: named, tag: '' };
}

/**
 * Validates one image reference and its service's platform declaration.
 *
 * Every image found is required to carry a repository, an explicit tag, an sha256
 * digest and the required platform. See the image-count note in the file header for
 * why the requirement is applied to every image rather than to a named subset.
 *
 * @param {Object} report The accumulator
 * @param {Object} service A record from parseComposeServices
 * @return {Object} The report record for this image
 */
function checkComposeImage( report, service ) {
	const declaration = service.images[ 0 ];
	const keyPath = 'services.' + service.name + '.image';
	const reference = declaration.value;
	const parsed = parseImageReference( reference );
	const record = {
		digest: parsed.digest === '' ? null : parsed.digest,
		platform: null,
		repository: parsed.repository === '' ? null : parsed.repository,
		service: service.name,
		tag: parsed.tag === '' ? null : parsed.tag
	};
	if ( service.images.length > 1 ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageDuplicateKey,
			'Service declares more than one image, so the effective pin is ambiguous.',
			String( service.images.length ) + ' declarations'
		);
	}
	if ( INTERPOLATION.test( reference ) ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageInterpolated,
			'Image reference is interpolated from the environment, so the image it ' +
				'resolves to is not fixed by this file.',
			reference
		);
	}
	if ( parsed.repository === '' ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageRepositoryMissing,
			'Image reference names no repository.',
			reference
		);
	}
	if ( parsed.tag === '' ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageTagMissing,
			'Image reference carries no explicit tag. Declare repository:tag@sha256:… ' +
				'so the reference is both readable and content-addressed.',
			reference
		);
	} else if ( parsed.tag === 'latest' ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageTagFloating,
			'Image reference uses a floating tag, which resolves to a different image ' +
				'over time.',
			parsed.tag
		);
	}
	if ( parsed.digest === '' ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageDigestMissing,
			'Image reference carries no digest. A tag alone is mutable, so the ' +
				'execution environment is not fixed.',
			reference
		);
	} else if ( !IMAGE_DIGEST.test( parsed.digest ) ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageDigestMalformed,
			'Image digest is not an sha256 digest of sixty-four lower-case hex ' +
				'characters.',
			parsed.digest
		);
	}
	const expected = Object.hasOwn( EXPECTED_IMAGES, parsed.repository ) ?
		EXPECTED_IMAGES[ parsed.repository ] :
		null;
	if ( expected !== null ) {
		if ( parsed.tag !== expected.tag ) {
			addViolation(
				report,
				COMPOSE_FILE,
				keyPath,
				CODE.imageTagMismatch,
				'Image tag differs from the tag the specification records. Expected ' +
					expected.tag + '.',
				parsed.tag
			);
		}
		if ( parsed.digest !== expected.digest ) {
			addViolation(
				report,
				COMPOSE_FILE,
				keyPath,
				CODE.imageDigestMismatch,
				'Image digest differs from the digest the specification records. ' +
					'Expected ' + expected.digest + '.',
				parsed.digest
			);
		}
	}
	checkComposePlatform( report, service, record );
	return record;
}

/**
 * Validates the platform declaration of a service that declares an image.
 *
 * @param {Object} report The accumulator
 * @param {Object} service A record from parseComposeServices
 * @param {Object} record The report record being built, updated in place
 */
function checkComposePlatform( report, service, record ) {
	const keyPath = 'services.' + service.name + '.platform';
	if ( service.platforms.length === 0 ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imagePlatformMissing,
			'Service declares an image but no platform. A digest can resolve a ' +
				'multi-architecture index, so without ' + REQUIRED_PLATFORM + ' the ' +
				'execution environment is not fixed and byte-identical captures are ' +
				'not guaranteed.',
			'(absent)'
		);
		return;
	}
	if ( service.platforms.length > 1 ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imageDuplicateKey,
			'Service declares more than one platform, so the effective platform is ' +
				'ambiguous.',
			String( service.platforms.length ) + ' declarations'
		);
	}
	const value = service.platforms[ 0 ].value;
	record.platform = value;
	if ( value !== REQUIRED_PLATFORM ) {
		addViolation(
			report,
			COMPOSE_FILE,
			keyPath,
			CODE.imagePlatformUnexpected,
			'Platform is not ' + REQUIRED_PLATFORM + '. The wiki and database images ' +
				'are published for that architecture only.',
			value
		);
	}
}

/**
 * Confirms the Playwright image tag agrees with the Playwright npm pins.
 *
 * The browser inside the capture container and the driver on the host must be the
 * same version. A drift between them breaks byte-identical captures while every
 * individual pin still reads as correct, so it is reported as a failure.
 *
 * @param {Object} report The accumulator
 * @param {Array} images The image records collected from the Compose file
 */
function checkPlaywrightAlignment( report, images ) {
	const image = images.find( ( entry ) => entry.repository === PLAYWRIGHT_IMAGE_REPOSITORY );
	if ( image === undefined || image.tag === null || report.npm === null ) {
		return;
	}
	const tagVersion = withoutVersionPrefix( image.tag ).split( '-' )[ 0 ];
	for ( const name of PLAYWRIGHT_NPM_PACKAGES ) {
		const declaration = report.npm.declared.find( ( entry ) => entry.name === name );
		if ( declaration === undefined || declaration.declared === tagVersion ) {
			continue;
		}
		// An inexact declaration is already reported against the manifest, and
		// comparing a range with a tag would only restate that finding here.
		if ( !isExactVersion( declaration.declared ) ) {
			continue;
		}
		addViolation(
			report,
			COMPOSE_FILE,
			'services.' + image.service + '.image',
			CODE.playwrightVersionDrift,
			'Capture image tag encodes Playwright ' + tagVersion + ', but ' +
				NPM_MANIFEST + ' declares ' + name + ' at ' + declaration.declared +
				'. The browser in the container and the driver on the host must match.',
			image.tag
		);
	}
}

/**
 * Validates the container images declared by the harness stack.
 *
 * @param {Object} report The accumulator
 */
function checkCompose( report ) {
	const text = readAllowlistedText( report, COMPOSE_FILE );
	if ( text === null ) {
		return;
	}
	const services = parseComposeServices( text );
	const records = [];
	for ( const service of services ) {
		if ( service.images.length === 0 ) {
			continue;
		}
		records.push( checkComposeImage( report, service ) );
	}
	if ( records.length === 0 ) {
		addViolation(
			report,
			COMPOSE_FILE,
			'services',
			CODE.imageNoneFound,
			'No service declares an image, so there is no pinned execution ' +
				'environment to verify.',
			'(absent)'
		);
	}
	const found = new Set( records.map( ( entry ) => entry.repository ) );
	for ( const repository of Object.keys( EXPECTED_IMAGES ) ) {
		if ( found.has( repository ) ) {
			continue;
		}
		addViolation(
			report,
			COMPOSE_FILE,
			'services',
			CODE.imageExpectedMissing,
			'Specified image is not declared by any service. The harness requires ' +
				repository + ' at ' + EXPECTED_IMAGES[ repository ].tag + '.',
			repository
		);
	}
	checkPlaywrightAlignment( report, records );
	report.images = sortRecords( records, [ 'service', 'repository' ] );
}

/**
 * Records the two pins that are deliberately not the newest published release.
 *
 * Both are exact and therefore compliant, so neither is a finding and neither
 * affects the exit status. They are reported with the derivation behind them so a
 * maintainer reading the delivery report does not upgrade them and break the
 * toolchain. The value actually declared is included alongside the specified one,
 * so the report stays truthful if the two ever diverge — a divergence the expected
 * version check reports separately.
 *
 * @param {Object} report The accumulator
 */
function recordIntentionalCeilings( report ) {
	const declarations = report.npm === null ? [] : report.npm.declared;
	const ceilings = INTENTIONAL_CEILINGS.map( ( ceiling ) => {
		const declaration = declarations.find( ( entry ) => entry.name === ceiling.name );
		return {
			declared: declaration === undefined ? null : declaration.declared,
			manifest: ceiling.manifest,
			name: ceiling.name,
			reason: ceiling.reason,
			version: ceiling.version
		};
	} );
	report.intentionalCeilings = sortRecords( ceilings, [ 'name' ] );
}

/**
 * Builds the counts that head the report.
 *
 * @param {Object} report The accumulator
 * @return {Object} The summary block
 */
function buildSummary( report ) {
	return {
		composerDeclared: report.composer === null ? 0 : report.composer.declared.length,
		composerResolved: report.composer === null ? 0 : report.composer.resolved.length,
		images: report.images.length,
		informational: report.informational.length,
		manifestsScanned: report.scanned.length,
		npmDeclared: report.npm === null ? 0 : report.npm.declared.length,
		npmResolved: report.npm === null ? 0 : report.npm.resolved.length,
		status: report.violations.length === 0 ? 'pass' : 'fail',
		violations: report.violations.length
	};
}

/**
 * Writes the human-readable verdict to stderr.
 *
 * stdout carries only the JSON document so it stays machine-parseable; this is
 * what a developer reads in the terminal. Every violation is listed rather than
 * summarised, because the point of the report is to be actionable without a second
 * run.
 *
 * @param {Object} report The completed report
 */
function writeVerdict( report ) {
	const lines = [];
	lines.push(
		'check-pins: scanned ' + String( report.summary.manifestsScanned ) +
		' manifests; ' + String( report.summary.npmDeclared ) + ' npm and ' +
		String( report.summary.composerDeclared ) + ' Composer declarations; ' +
		String( report.summary.images ) + ' container images.'
	);
	if ( report.violations.length === 0 ) {
		lines.push(
			'check-pins: PASS — every declared version is exact and every image is ' +
			'pinned by digest and platform.'
		);
	} else {
		lines.push(
			'check-pins: FAIL — ' + String( report.violations.length ) +
			' pinning violation(s):'
		);
		for ( const violation of report.violations ) {
			lines.push(
				'  ' + violation.file + ' → ' + violation.keyPath + ' [' +
				violation.code + '] ' + violation.message + ' Found: ' + violation.value
			);
		}
	}
	if ( report.informational.length > 0 ) {
		lines.push(
			'check-pins: ' + String( report.informational.length ) + ' informational ' +
			'note(s) recorded in the JSON report; none affects the exit status.'
		);
	}
	for ( const ceiling of report.intentionalCeilings ) {
		lines.push(
			'check-pins: intentional ceiling — ' + ceiling.name + ' ' + ceiling.version +
			' is exact and must not be upgraded.'
		);
	}
	process.stderr.write( lines.join( '\n' ) + '\n' );
}

/**
 * Runs every check, emits the report and sets the exit status.
 *
 * The exit status is set rather than forced with an immediate exit, so the JSON
 * document on stdout is always flushed before the process ends.
 */
function main() {
	const report = createReport();
	checkNpm( report );
	checkComposer( report );
	checkCompose( report );
	recordIntentionalCeilings( report );
	report.violations = sortRecords(
		report.violations,
		[ 'file', 'keyPath', 'code', 'value' ]
	);
	report.informational = sortRecords(
		report.informational,
		[ 'file', 'keyPath', 'code', 'value' ]
	);
	report.summary = buildSummary( report );
	process.stdout.write( JSON.stringify( withOrderedKeys( report ), null, '\t' ) + '\n' );
	writeVerdict( report );
	process.exitCode = report.violations.length === 0 ? 0 : 1;
}

main();
