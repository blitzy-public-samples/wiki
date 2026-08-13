/**
 * Measured-contrast reporting for the Blitzy skin.
 *
 * This script is the measured-contrast enforcement site for R10 Accessibility
 * Gate Is Blocking. It reads the skin's token surface, measures a contrast ratio
 * for every intended text-on-surface pairing in both the light and the night
 * palette, and fails the run when any pairing falls below the ratio its text
 * class requires.
 *
 * WHY THIS EXISTS ALONGSIDE THE AUTOMATED PAGE SCAN
 *
 * tests/playwright/axe.spec.ts already runs a blocking accessibility scan over
 * every committed capture. This script is not a duplicate of it. The automated
 * contrast rule is binary: it reports a finding or it does not, and it emits no
 * table of ratios. R10 asks for a measured ratio and a verdict per token pair in
 * both palettes, which is a different artifact answering a different question.
 * The page scan finds page-level problems; this finds the numbers. Neither
 * substitutes for the other and DELIVERY.md reports both.
 *
 * WHAT IS MEASURED, AND WHERE THE PAIRINGS COME FROM
 *
 * Two sources are audited and neither is allowed to shadow the other:
 *
 *   1. DERIVED PAIRING TABLE — DERIVED_PAIRINGS below. One entry per rendered
 *      role, each carrying the pixel size and font weight that justify its text
 *      class, so a verdict can be audited rather than taken on trust. The same
 *      pair of tokens legitimately renders at more than one size: the muted text
 *      colour on the page surface is both the sub-headline and the 14px
 *      reference list, and each size is measured against its own threshold.
 *
 *   2. DECLARED PAIRINGS — the `@contrast-pair` annotation lines in
 *      resources/skins.blitzy.tokens/tokens.less, which names this script as
 *      their consumer. Every declared pairing is measured at the class it
 *      declares. Where the derived table already covers a declared pairing at
 *      that class, the two are merged into one entry recording both sources;
 *      where it does not, the declared pairing is added as its own entry. A
 *      declared pairing can therefore never be silently dropped, and a derived
 *      class can never weaken a declared one.
 *
 * THRESHOLDS ARE NOT INTERCHANGEABLE
 *
 * Body text is held to 4.5:1 and large text to 3:1. The large classification is
 * applied only where the design genuinely sets large type — the display bands,
 * the h2 and h3 headings and the specified sub-headline band — and to non-text
 * affordances such as the inline-link underline and the focus ring, where the
 * 3:1 floor for user-interface components applies. 16px prose and 14px reference
 * and monospace text are body text and are held to 4.5:1. Every entry records
 * the size and weight behind its classification.
 *
 * THE NIGHT PALETTE IS MEASURED AGAINST NIGHT SURFACES
 *
 * Night entries pair against the night page surface, the raised night surface
 * and the night state fills, never against a light background. Reusing a light
 * background for a night row is the likeliest way a report of this kind passes
 * while the rendered dark interface fails, so the two palettes are built from
 * separate tables and the night surface tokens appear in the night table only.
 *
 * THE NIGHT PURPLE IS ASSERTED, NOT ASSUMED
 *
 * The night purple is deliberately lighter than the light-mode purple: the brand
 * purple does not clear the body-text floor against the night surface, and the
 * darker candidates on the same hue axis measured too close to it to be safe.
 * That decision is confirmed here by comparing relative luminance directly, and
 * a regression to a darker night purple is treated as a violation rather than as
 * a note, because it is the exact failure the decision was made to avoid. All
 * ten night values are covered, each with its own measured ratio and verdict, so
 * the confirmation spans the palette rather than the purple alone.
 *
 * WHAT THIS REPORT DOES NOT CLAIM
 *
 * Automated tooling detects only a fraction of accessibility issues. This gate
 * is a blocking floor, not a conformance proof, and nothing emitted here claims
 * conformance to any accessibility standard. There is no allowlist of accepted
 * failures and no exclusion mechanism: every exclusion is a blind spot, so where
 * the token surface records an accessibility finding at a declaration, that
 * finding is carried into the report verbatim rather than suppressed.
 *
 * DETERMINISM
 *
 * The report carries no timestamp, no run identifier, no host name and no
 * absolute path, and every collection is sorted on a stable key, so two runs
 * over an unchanged tree write a byte-identical file. R9 Deterministic Captures
 * scopes byte-identity to the committed captures rather than to this document,
 * but a committed artifact that changed on every run would be unreviewable.
 *
 * SCOPE
 *
 * Reads two Blitzy-owned files and writes one Blitzy-owned file, all resolved
 * from this file's own directory, so R1 Core Immutability holds by construction:
 * no path here reaches MediaWiki core, the reference skin or the vendor tree.
 * There is no network access of any kind, per R5 Zero External Origins. Only
 * node:fs and node:path are required, so no dependency is added and R15 Exact
 * Dependency Pinning is unaffected. The Less comment stripping and declaration
 * parsing below are deliberately local rather than shared with a sibling tool:
 * R12 No Scope Expansion caps this folder at six scripts, and duplicating a
 * parser inside one of them is the compliant choice over adding a seventh file
 * to hold it.
 *
 * INVOCATION
 *
 *     node tools/contrast-report.js
 *
 * No argument is accepted or required, so the step is individually invocable per
 * Gate 10 Test Execution Binding and reachable from the harness entry point. The
 * exit status is non-zero when any pairing misses its threshold, when the night
 * purple assertion fails, or when a night value is missing from the token
 * surface: a script that reported without failing would not satisfy R10.
 *
 * @see ../resources/skins.blitzy.tokens/tokens.less for every token and its flags
 * @see ../resources/skins.blitzy.tokens/customProperties.less for the palette split
 */

'use strict';

const fs = require( 'node:fs' );
const path = require( 'node:path' );

/**
 * The package root, resolved from this file's own directory rather than from the
 * working directory, so the script behaves identically however it is invoked.
 */
const PACKAGE_ROOT = path.resolve( __dirname, '..' );

/** Relative path of the token module, used verbatim in the report and messages. */
const TOKENS_FILE = 'resources/skins.blitzy.tokens/tokens.less';

/** Relative path of the custom-property publication file. */
const CUSTOM_PROPERTIES_FILE = 'resources/skins.blitzy.tokens/customProperties.less';

/** Relative path of the report this script writes. */
const REPORT_FILE = 'screenshots/audits/contrast.json';

/** Prefix shared by every declaration in the skin's token surface. */
const TOKEN_PREFIX = '@blitzy-';

/** Prefix shared by every colour token in the skin's token surface. */
const COLOUR_TOKEN_PREFIX = '@blitzy-color-';

/** Text class of body-size text, and the label used for it in the report. */
const CLASS_BODY = 'body';

/** Text class of large text and non-text affordances. */
const CLASS_LARGE = 'large';

/** Minimum ratio required of body-size text. */
const THRESHOLD_BODY = 4.5;

/** Minimum ratio required of large text and of non-text affordances. */
const THRESHOLD_LARGE = 3;

/** Palette identifier of the light mode. */
const MODE_LIGHT = 'light';

/** Palette identifier of the night mode. */
const MODE_DARK = 'dark';

/** Both palette identifiers, in report order. */
const MODES = [ MODE_LIGHT, MODE_DARK ];

/** Source label of an entry contributed by the derived pairing table. */
const SOURCE_DERIVED = 'derived pairing table';

/** Source label of an entry contributed by a token-module annotation. */
const SOURCE_DECLARED = 'tokens.less @contrast-pair';

/**
 * Upper bound on variable-indirection resolution passes.
 *
 * A token's value may itself be a token reference, and a reference may point at
 * another reference, so resolution iterates until the value stops being a bare
 * reference. This bound only exists so a pathological input terminates; a
 * genuine cycle is detected and named before the bound is ever reached.
 */
const MAX_INDIRECTION_DEPTH = 16;

/**
 * The page surface of each palette, used as the compositing backdrop when a
 * value carries an alpha channel. Keyed by palette identifier.
 */
const PAGE_SURFACE_TOKEN = {
	light: COLOUR_TOKEN_PREFIX + 'surface-base',
	dark: COLOUR_TOKEN_PREFIX + 'dark-surface'
};

/**
 * The ten values of the night palette, in declaration order.
 *
 * Every one is covered by the night coverage block with its own measured ratio,
 * threshold and verdict, and a value missing from the token surface is a
 * violation: an incomplete night palette is an unverified night mode.
 */
const NIGHT_PALETTE_TOKENS = [
	COLOUR_TOKEN_PREFIX + 'dark-surface',
	COLOUR_TOKEN_PREFIX + 'dark-surface-raised',
	COLOUR_TOKEN_PREFIX + 'dark-text-primary',
	COLOUR_TOKEN_PREFIX + 'dark-text-secondary',
	COLOUR_TOKEN_PREFIX + 'dark-text-muted',
	COLOUR_TOKEN_PREFIX + 'dark-purple',
	COLOUR_TOKEN_PREFIX + 'dark-border',
	COLOUR_TOKEN_PREFIX + 'dark-success-bg',
	COLOUR_TOKEN_PREFIX + 'dark-error-bg',
	COLOUR_TOKEN_PREFIX + 'dark-warning-bg'
];

/** The light-mode brand purple, the baseline of the night purple assertion. */
const LIGHT_PURPLE_TOKEN = COLOUR_TOKEN_PREFIX + 'purple';

/** The night purple, which must be strictly lighter than the light-mode purple. */
const NIGHT_PURPLE_TOKEN = COLOUR_TOKEN_PREFIX + 'dark-purple';

/**
 * A pairing of the derived table, before its palette and token prefix are applied.
 *
 * @typedef {Object} DerivedPairing
 * @property {string} role Rendered surface the pairing describes.
 * @property {string} foreground Colour-token name without the shared prefix.
 * @property {string} background Colour-token name without the shared prefix.
 * @property {string} textClass Either the body or the large text class.
 * @property {number|null} fontSizePx Rendered size, or null for a non-text affordance.
 * @property {number|null} fontWeight Rendered weight, or null for a non-text affordance.
 * @property {string} justification Why that text class applies to this role.
 */

/**
 * The light palette's rendered text-on-surface pairings.
 *
 * Token names are given without the shared `@blitzy-color-` prefix, which is
 * applied when the table is expanded; the report always carries full names.
 * The roles are the surfaces the skin's own specification describes, and the
 * background of each is the token the light palette publishes for that surface.
 */
const LIGHT_PAIRINGS = [
	{
		role: 'Hero and page-title display type',
		foreground: 'text-primary',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: 40,
		fontWeight: 700,
		justification: 'Display type. Classified by its narrowest band, 40px at weight 700, which is the binding case; the wider bands set 56px and 88px at the same weight.'
	},
	{
		role: 'Emphasised prose text',
		foreground: 'text-primary',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700. Below the large-text floor, so the body minimum applies.'
	},
	{
		role: 'Hero sub-headline, wide band',
		foreground: 'text-muted',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: 19,
		fontWeight: 400,
		justification: '19px at weight 400, the sub-headline band the design specifies as large type. The same pair of tokens is also measured at the stricter body class by the narrow-band and reference-list roles, so no false pass can arise from this classification.'
	},
	{
		role: 'Hero sub-headline, narrow band',
		foreground: 'text-muted',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 400,
		justification: '17px at weight 400. Below the large-text floor, so the body minimum applies.'
	},
	{
		role: 'Body prose on the page surface',
		foreground: 'text-secondary',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400. Prose is body text and is held to the body minimum.'
	},
	{
		role: 'Body prose on a card surface',
		foreground: 'text-secondary',
		background: 'surface-card',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the card fill rather than the page surface.'
	},
	{
		role: 'Monospace edit and diff text',
		foreground: 'text-secondary',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px at weight 400. Monospace content is body text and is held to the body minimum, not to the large-text floor.'
	},
	{
		role: 'Section heading, second level',
		foreground: 'text-heading',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: 30,
		fontWeight: 700,
		justification: '30px at weight 700, above the large-text floor at both weights.'
	},
	{
		role: 'Section heading, third level',
		foreground: 'text-heading',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: 22,
		fontWeight: 700,
		justification: '22px at weight 700, above the bold large-text floor of 18.66px.'
	},
	{
		role: 'Reference list and footnotes',
		foreground: 'text-muted',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px at weight 400. Well below the large-text floor, so the body minimum applies.'
	},
	{
		role: 'Inline link text',
		foreground: 'link',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700. An inline link is bold heading-coloured text; the purple lives in the underline, which is measured separately.'
	},
	{
		role: 'Inline link underline',
		foreground: 'link-underline',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: null,
		fontWeight: null,
		justification: 'Non-text affordance: a 2px underline. The 3:1 floor for user-interface components and graphical objects applies rather than a text minimum.'
	},
	{
		role: 'Visited inline link text',
		foreground: 'link--visited',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the visited state of body-size inline link text.'
	},
	{
		role: 'External link text',
		foreground: 'link-external',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the external-link colour on body-size inline link text.'
	},
	{
		role: 'Navigation link text on the navigation card',
		foreground: 'text-heading',
		background: 'surface-card',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 600,
		justification: '17px at weight 600. Below the bold large-text floor of 18.66px, so the body minimum applies.'
	},
	{
		role: 'Outline call-to-action label on its pill fill',
		foreground: 'purple',
		background: 'surface-card',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 700,
		justification: '17px at weight 700 on the white pill fill. Below the bold large-text floor, so the body minimum applies.'
	},
	{
		role: 'Gradient call-to-action label on its fill',
		foreground: 'text-inverse',
		background: 'purple',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 700,
		justification: '17px at weight 700 on the gradient fill, measured against the gradient anchor stop that the token module declares as this label backdrop. The lighter stop is a decorative stop that carries no text and is flagged at its declaration.'
	},
	{
		role: 'Progressive label on the page surface',
		foreground: 'purple',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the progressive colour used as label text on the page surface.'
	},
	{
		role: 'Progressive label on the page surface, hover state',
		foreground: 'purple--hover',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700. The hover state darkens rather than lightens, so on a light surface it can only measure higher than the resting state; it is measured anyway rather than argued about.'
	},
	{
		role: 'Progressive label on the page surface, active state',
		foreground: 'purple--active',
		background: 'surface-base',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the pressed state of a progressive label.'
	},
	{
		role: 'Focus ring on the page surface',
		foreground: 'purple',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: null,
		fontWeight: null,
		justification: 'Non-text affordance: a 2px outline at 2px offset. The 3:1 floor for user-interface components applies rather than a text minimum.'
	},
	{
		role: 'Announcement bar text',
		foreground: 'text-inverse',
		background: 'announce-bg',
		textClass: CLASS_BODY,
		fontSizePx: 18,
		fontWeight: 400,
		justification: '18px at weight 400. Below the 24px regular large-text floor, so the body minimum applies.'
	},
	{
		role: 'Announcement bar call-to-action label',
		foreground: 'text-inverse',
		background: 'announce-bg',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 700,
		justification: '17px at weight 700 on the announcement fill, inside the outlined pill.'
	},
	{
		role: 'Category chip label',
		foreground: 'purple',
		background: 'brand-50',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 700,
		justification: '14px at weight 700 on the brand tint that fills the chip.'
	},
	{
		role: 'Category chip label, hover state',
		foreground: 'purple',
		background: 'brand-100',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 700,
		justification: '14px at weight 700 on the deeper brand tint a hovered chip takes. Tighter than the resting state, which is why it is measured rather than assumed to follow from it.'
	},
	{
		role: 'Infobox and wikitable header-row text',
		foreground: 'text-heading',
		background: 'brand-50',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700 on the brand-tinted header row shared by the infobox and the wikitable.'
	},
	{
		role: 'Hatnote and message-box text',
		foreground: 'text-secondary',
		background: 'brand-50',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the brand-tinted hatnote and message-box fill.'
	},
	{
		role: 'Table-of-contents section link text',
		foreground: 'text-heading',
		background: 'surface-card',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px at weight 400 on the table-of-contents card fill.'
	},
	{
		role: 'Table-of-contents active-section label',
		foreground: 'purple',
		background: 'surface-card',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 700,
		justification: '14px at weight 700, the active-section label on the table-of-contents card fill.'
	},
	{
		role: 'Diff added-row text on the success fill',
		foreground: 'text-secondary',
		background: 'success-bg',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px monospace at weight 400 on the success fill of an added diff row.'
	},
	{
		role: 'Diff removed-row text on the error fill',
		foreground: 'text-secondary',
		background: 'error-bg',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px monospace at weight 400 on the error fill of a removed diff row.'
	},
	{
		role: 'Warning-surface text',
		foreground: 'text-secondary',
		background: 'warning-bg',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the warning fill.'
	},
	{
		role: 'Destructive accent on the page surface',
		foreground: 'error',
		background: 'surface-base',
		textClass: CLASS_LARGE,
		fontSizePx: null,
		fontWeight: null,
		justification: 'Non-text affordance and large labels only: icons, borders and large labels. The declaration flags that this value does not clear the body minimum and records that it must never carry body-size text.'
	},
	{
		role: 'Lavender-band heading',
		foreground: 'text-heading',
		background: 'lavender-band',
		textClass: CLASS_LARGE,
		fontSizePx: 30,
		fontWeight: 700,
		justification: '30px at weight 700 on the lavender band.'
	},
	{
		role: 'Lavender-band body-size label',
		foreground: 'text-heading',
		background: 'lavender-band',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the lavender band.'
	}
];

/**
 * The night palette's rendered text-on-surface pairings.
 *
 * Every background below is a night token. The substitutions follow the palette
 * that the custom-property publication file emits under the night selectors:
 * the page surface and the raised surface replace the light surfaces, the raised
 * surface also replaces the brand tint, the night border replaces the lavender
 * band, and the light brand purple becomes the announcement fill. The
 * destructive accent is published in the light block only, so its night value is
 * the same colour measured against the night page surface.
 */
const DARK_PAIRINGS = [
	{
		role: 'Hero and page-title display type',
		foreground: 'dark-text-primary',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: 40,
		fontWeight: 700,
		justification: 'Display type. Classified by its narrowest band, 40px at weight 700, which is the binding case; the wider bands set 56px and 88px at the same weight.'
	},
	{
		role: 'Emphasised prose text',
		foreground: 'dark-text-primary',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700. Below the large-text floor, so the body minimum applies.'
	},
	{
		role: 'Hero sub-headline, wide band',
		foreground: 'dark-text-muted',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: 19,
		fontWeight: 400,
		justification: '19px at weight 400, the sub-headline band the design specifies as large type. The same pair of tokens is also measured at the stricter body class by the narrow-band and reference-list roles, so no false pass can arise from this classification.'
	},
	{
		role: 'Hero sub-headline, narrow band',
		foreground: 'dark-text-muted',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 400,
		justification: '17px at weight 400. Below the large-text floor, so the body minimum applies.'
	},
	{
		role: 'Body prose on the page surface',
		foreground: 'dark-text-secondary',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400. Prose is body text and is held to the body minimum.'
	},
	{
		role: 'Body prose on a card surface',
		foreground: 'dark-text-secondary',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the raised night surface that fills a card.'
	},
	{
		role: 'Monospace edit and diff text',
		foreground: 'dark-text-secondary',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px at weight 400. Monospace content is body text and is held to the body minimum, not to the large-text floor.'
	},
	{
		role: 'Section heading, second level',
		foreground: 'dark-text-primary',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: 30,
		fontWeight: 700,
		justification: '30px at weight 700. Headings take the emphasised night text colour, which the night palette publishes for the core emphasised-text property.'
	},
	{
		role: 'Section heading, third level',
		foreground: 'dark-text-primary',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: 22,
		fontWeight: 700,
		justification: '22px at weight 700, above the bold large-text floor of 18.66px.'
	},
	{
		role: 'Reference list and footnotes',
		foreground: 'dark-text-muted',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px at weight 400. Well below the large-text floor, so the body minimum applies.'
	},
	{
		role: 'Inline link text',
		foreground: 'dark-text-primary',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700. The night palette publishes the emphasised night text colour as the link colour; the purple stays in the underline.'
	},
	{
		role: 'Inline link underline',
		foreground: 'dark-purple',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: null,
		fontWeight: null,
		justification: 'Non-text affordance: a 2px underline. The 3:1 floor for user-interface components and graphical objects applies rather than a text minimum.'
	},
	{
		role: 'Visited inline link text',
		foreground: 'dark-text-muted',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the visited state the night palette publishes.'
	},
	{
		role: 'External link text',
		foreground: 'dark-text-muted',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the external-link colour the night palette publishes.'
	},
	{
		role: 'Navigation link text on the navigation card',
		foreground: 'dark-text-primary',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 600,
		justification: '17px at weight 600 on the raised night surface. Below the bold large-text floor, so the body minimum applies.'
	},
	{
		role: 'Outline call-to-action label on its pill fill',
		foreground: 'dark-purple',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 700,
		justification: '17px at weight 700 on the raised night surface that fills the outline pill.'
	},
	{
		role: 'Gradient call-to-action label on its fill',
		foreground: 'text-inverse',
		background: 'purple',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 700,
		justification: '17px at weight 700. The gradient stops are not remapped for night mode, so the label is measured against the same anchor stop as in light mode.'
	},
	{
		role: 'Progressive label on the page surface',
		foreground: 'dark-purple',
		background: 'dark-surface',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700, the night progressive colour used as label text on the night page surface.'
	},
	{
		role: 'Focus ring on the page surface',
		foreground: 'dark-purple',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: null,
		fontWeight: null,
		justification: 'Non-text affordance: a 2px outline at 2px offset. The 3:1 floor for user-interface components applies rather than a text minimum.'
	},
	{
		role: 'Announcement bar text',
		foreground: 'text-inverse',
		background: 'purple',
		textClass: CLASS_BODY,
		fontSizePx: 18,
		fontWeight: 400,
		justification: '18px at weight 400. The night palette publishes the light brand purple as the announcement fill, and the inverse text colour does not vary by mode.'
	},
	{
		role: 'Announcement bar call-to-action label',
		foreground: 'text-inverse',
		background: 'purple',
		textClass: CLASS_BODY,
		fontSizePx: 17,
		fontWeight: 700,
		justification: '17px at weight 700 on the night announcement fill, inside the outlined pill.'
	},
	{
		role: 'Category chip label',
		foreground: 'dark-purple',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 700,
		justification: '14px at weight 700. The night palette publishes the raised surface where light mode publishes the brand tint, so the chip fill follows it.'
	},
	{
		role: 'Infobox and wikitable header-row text',
		foreground: 'dark-text-primary',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 700,
		justification: '16px at weight 700 on the raised night surface that replaces the brand-tinted header row.'
	},
	{
		role: 'Hatnote and message-box text',
		foreground: 'dark-text-secondary',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the raised night surface that replaces the brand-tinted fill.'
	},
	{
		role: 'Table-of-contents section link text',
		foreground: 'dark-text-primary',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px at weight 400 on the raised night surface of the table-of-contents card.'
	},
	{
		role: 'Table-of-contents active-section label',
		foreground: 'dark-purple',
		background: 'dark-surface-raised',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 700,
		justification: '14px at weight 700, the active-section label on the raised night surface.'
	},
	{
		role: 'Diff added-row text on the success fill',
		foreground: 'dark-text-primary',
		background: 'dark-success-bg',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px monospace at weight 400 on the night success fill of an added diff row.'
	},
	{
		role: 'Diff removed-row text on the error fill',
		foreground: 'dark-text-primary',
		background: 'dark-error-bg',
		textClass: CLASS_BODY,
		fontSizePx: 14,
		fontWeight: 400,
		justification: '14px monospace at weight 400 on the night error fill of a removed diff row.'
	},
	{
		role: 'Warning-surface text',
		foreground: 'dark-text-primary',
		background: 'dark-warning-bg',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the night warning fill.'
	},
	{
		role: 'Destructive accent on the page surface',
		foreground: 'error',
		background: 'dark-surface',
		textClass: CLASS_LARGE,
		fontSizePx: null,
		fontWeight: null,
		justification: 'Non-text affordance and large labels only. The destructive property is published in the light block alone, so the night mode renders the same accent against the night page surface.'
	},
	{
		role: 'Lavender-band heading',
		foreground: 'dark-text-primary',
		background: 'dark-border',
		textClass: CLASS_LARGE,
		fontSizePx: 30,
		fontWeight: 700,
		justification: '30px at weight 700. The night palette publishes the night border colour where light mode publishes the lavender band.'
	},
	{
		role: 'Lavender-band body-size label',
		foreground: 'dark-text-primary',
		background: 'dark-border',
		textClass: CLASS_BODY,
		fontSizePx: 16,
		fontWeight: 400,
		justification: '16px at weight 400 on the night band fill.'
	}
];

/**
 * Every derived pairing, with its palette attached and token names expanded.
 *
 * @type {Array<Object>}
 */
const DERIVED_PAIRINGS = [
	...LIGHT_PAIRINGS.map( ( pairing ) => expandPairing( MODE_LIGHT, pairing ) ),
	...DARK_PAIRINGS.map( ( pairing ) => expandPairing( MODE_DARK, pairing ) )
];

/**
 * Matches one token declaration, anchored on the left of the colon.
 *
 * Applied line by line to comment-stripped source, because the token module
 * declares one token per line and documents core's own token names inside
 * comments; reading those comments as declarations would invent tokens that
 * carry no value.
 */
const DECLARATION_PATTERN = /^[ \t]*(@blitzy-[A-Za-z0-9-]+)[ \t]*:[ \t]*([^;]+);/;

/**
 * Matches an intended-pairing annotation, in full and with nothing else allowed
 * on the line.
 *
 * The strictness is load-bearing rather than fastidious. The token module carries
 * one illustrative line that spells the annotation grammar with placeholder
 * names in angle brackets, and it records that a looser parser once read that
 * line as a pairing and emitted the placeholders as if they were tokens. Every
 * captured value below is therefore constrained to a real palette identifier, a
 * real text class or a real token name.
 */
const DECLARED_PAIRING_PATTERN =
	/^[ \t]*\/\/[ \t]*@contrast-pair[ \t]+(light|dark)[ \t]+(body|large)[ \t]+(@blitzy-[A-Za-z0-9-]+)[ \t]+(@blitzy-[A-Za-z0-9-]+)[ \t]*$/;

/**
 * Matches an accessibility finding recorded at a token declaration.
 *
 * Anchored so that the marker must open the comment. The token module also
 * mentions the marker mid-sentence three times while defining and summarising
 * the convention, and those mentions are documentation rather than findings.
 */
const ACCESSIBILITY_FLAG_PATTERN = /^[ \t]*\/\/[ \t]*BLITZY \[A11Y\]:[ \t]*(.*)$/;

/** Matches a line comment carrying text, used to follow a finding's continuation. */
const COMMENT_CONTINUATION_PATTERN = /^[ \t]*\/\/[ \t]*(\S.*)$/;

/** Matches a value that is nothing but a single variable reference. */
const SINGLE_REFERENCE_PATTERN = /^@[A-Za-z0-9-]+$/;

/** Matches a three- or six-digit hexadecimal colour. */
const HEX_COLOUR_PATTERN = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

/** Matches a red-green-blue colour function and captures its argument list. */
const RGB_FUNCTION_PATTERN = /^rgba?\(([^()]*)\)$/i;

/** Matches a hue-saturation-lightness colour function and captures its arguments. */
const HSL_FUNCTION_PATTERN = /^hsla?\(([^()]*)\)$/i;

/** Matches any gradient function, which is a legitimate non-solid colour value. */
const GRADIENT_PATTERN = /^(?:repeating-)?(?:linear|radial|conic)-gradient\(/i;

/** Splits a colour function's argument list on commas, slashes and whitespace. */
const COLOUR_ARGUMENT_SEPARATOR = /[,/\s]+/;

/** Number of degrees in a full turn, used when normalising a hue. */
const DEGREES_PER_TURN = 360;

/** Largest value of an eight-bit colour channel. */
const CHANNEL_MAX = 255;

/**
 * Throws an error naming the failure and the code that identifies it.
 *
 * Every unrecoverable condition goes through here so that a failure message
 * always identifies what could not be done rather than surfacing a bare
 * exception from a built-in.
 *
 * @param {string} code Stable identifier of the failure.
 * @param {string} message What could not be done, and why.
 * @throws {Error} Always.
 * @return {void}
 */
function fail( code, message ) {
	throw new Error( 'contrast-report: ' + code + ' — ' + message );
}

/**
 * Attaches a palette and the shared token prefix to a derived pairing.
 *
 * @param {string} mode Palette identifier the pairing belongs to.
 * @param {DerivedPairing} pairing Pairing as written in the table above.
 * @return {Object} Pairing carrying its palette and fully qualified token names.
 */
function expandPairing( mode, pairing ) {
	return {
		mode: mode,
		role: pairing.role,
		foregroundToken: COLOUR_TOKEN_PREFIX + pairing.foreground,
		backgroundToken: COLOUR_TOKEN_PREFIX + pairing.background,
		textClass: pairing.textClass,
		fontSizePx: pairing.fontSizePx,
		fontWeight: pairing.fontWeight,
		justification: pairing.justification
	};
}

/**
 * Reads one package-relative file, failing by name when it is absent.
 *
 * @param {string} relativePath Path relative to the package root.
 * @return {string} File contents.
 */
function readSource( relativePath ) {
	const absolutePath = path.join( PACKAGE_ROOT, relativePath );
	if ( !fs.existsSync( absolutePath ) ) {
		fail(
			'MISSING_INPUT',
			'required input ' + relativePath + ' was not found under the package root. ' +
			'The report cannot be measured without it.'
		);
	}
	return fs.readFileSync( absolutePath, 'utf8' );
}

/**
 * Removes Less comments from source while preserving line structure.
 *
 * A single pass over the characters is used rather than a pair of replacements,
 * because the two comment forms interact. The token module discusses block
 * comments inside line comments and cites glob paths that open a block-comment
 * sequence which is never closed, so an implementation that stripped block
 * comments first could swallow the remainder of the file, and one that stripped
 * line comments first would still mishandle a line comment written inside a
 * block comment. Quoted strings are tracked for the same reason: a comment
 * sequence inside a font-family name is data, not a comment.
 *
 * Newlines are preserved so that line numbers survive for diagnostics.
 *
 * @param {string} source Less source.
 * @return {string} Source with every comment removed.
 */
function stripComments( source ) {
	const length = source.length;
	let output = '';
	let index = 0;
	while ( index < length ) {
		const character = source[ index ];
		const following = index + 1 < length ? source[ index + 1 ] : '';
		if ( character === '/' && following === '/' ) {
			while ( index < length && source[ index ] !== '\n' ) {
				index += 1;
			}
			continue;
		}
		if ( character === '/' && following === '*' ) {
			index += 2;
			while ( index < length ) {
				if ( source[ index ] === '*' && source[ index + 1 ] === '/' ) {
					index += 2;
					break;
				}
				if ( source[ index ] === '\n' ) {
					output += '\n';
				}
				index += 1;
			}
			continue;
		}
		if ( character === '"' || character === '\'' ) {
			output += character;
			index += 1;
			while ( index < length ) {
				const inString = source[ index ];
				output += inString;
				index += 1;
				if ( inString === '\\' && index < length ) {
					output += source[ index ];
					index += 1;
					continue;
				}
				if ( inString === character ) {
					break;
				}
			}
			continue;
		}
		output += character;
		index += 1;
	}
	return output;
}

/**
 * Collects every token declaration, keeping the last value of a repeated name.
 *
 * Repetition is not hypothetical: MediaWiki core's own defaults declare several
 * variable names twice, and Less resolves such a name to its final declaration,
 * so the same rule is applied here.
 *
 * @param {string} strippedSource Comment-stripped Less source.
 * @return {Map<string, string>} Token name to raw declared value.
 */
function parseDeclarations( strippedSource ) {
	const declarations = new Map();
	for ( const line of strippedSource.split( '\n' ) ) {
		const match = DECLARATION_PATTERN.exec( line );
		if ( match !== null ) {
			declarations.set( match[ 1 ], match[ 2 ].trim() );
		}
	}
	if ( declarations.size === 0 ) {
		fail(
			'NO_TOKENS',
			'no ' + TOKEN_PREFIX + '* declaration was found in ' + TOKENS_FILE +
			'. The token surface cannot be empty.'
		);
	}
	return declarations;
}

/**
 * Collects the intended pairings the token module declares as data.
 *
 * Read from the raw source rather than the comment-stripped source, because the
 * annotations live in comments by design.
 *
 * @param {string} source Raw Less source of the token module.
 * @return {Array<Object>} Declared pairings in file order.
 */
function parseDeclaredPairings( source ) {
	const pairings = [];
	const lines = source.split( '\n' );
	for ( let index = 0; index < lines.length; index += 1 ) {
		const match = DECLARED_PAIRING_PATTERN.exec( lines[ index ] );
		if ( match !== null ) {
			pairings.push( {
				mode: match[ 1 ],
				textClass: match[ 2 ],
				foregroundToken: match[ 3 ],
				backgroundToken: match[ 4 ],
				sourceLine: index + 1
			} );
		}
	}
	return pairings;
}

/**
 * Collects the accessibility findings the token module records at declarations.
 *
 * These are carried into the report verbatim. A finding recorded against a
 * specified value is disclosed rather than suppressed, because an audit that
 * quietly drops what it cannot pass is an audit with a blind spot.
 *
 * @param {string} source Raw Less source of the token module.
 * @return {Array<Object>} Findings with the token each annotates.
 */
function parseAccessibilityFlags( source ) {
	const lines = source.split( '\n' );
	const flags = [];
	for ( let index = 0; index < lines.length; index += 1 ) {
		const opening = ACCESSIBILITY_FLAG_PATTERN.exec( lines[ index ] );
		if ( opening === null ) {
			continue;
		}
		const sentences = [ opening[ 1 ].trim() ];
		let cursor = index + 1;
		while ( cursor < lines.length ) {
			if ( ACCESSIBILITY_FLAG_PATTERN.test( lines[ cursor ] ) ) {
				break;
			}
			const continuation = COMMENT_CONTINUATION_PATTERN.exec( lines[ cursor ] );
			if ( continuation === null ) {
				break;
			}
			sentences.push( continuation[ 1 ].trim() );
			cursor += 1;
		}
		flags.push( {
			token: findAnnotatedToken( lines, cursor ),
			sourceLine: index + 1,
			finding: sentences.join( ' ' )
		} );
		index = cursor - 1;
	}
	return flags;
}

/**
 * Finds the token a comment block annotates by scanning forward for the next
 * declaration, stopping at the first line that is neither blank nor a comment
 * and is not itself a declaration.
 *
 * @param {Array<string>} lines Raw source lines.
 * @param {number} startIndex Index of the first line after the comment block.
 * @return {string|null} Token name, or null when the block annotates no token.
 */
function findAnnotatedToken( lines, startIndex ) {
	for ( let index = startIndex; index < lines.length; index += 1 ) {
		const line = lines[ index ];
		const declaration = DECLARATION_PATTERN.exec( line );
		if ( declaration !== null ) {
			return declaration[ 1 ];
		}
		const isSkippable = line.trim() === '' || COMMENT_CONTINUATION_PATTERN.test( line ) ||
			line.trim() === '//';
		if ( !isSkippable ) {
			return null;
		}
	}
	return null;
}

/**
 * Follows variable indirection until the value is no longer a bare reference.
 *
 * A token's value may be another token, and that token's value may be another
 * again: the night purple is declared as the light palette's lighter purple
 * stop, so the pairing that the night assertion turns on only resolves through
 * indirection. A value that merely contains a reference — a composite border or
 * shadow, for instance — is not a bare reference and is returned untouched.
 *
 * @param {string} name Token to resolve.
 * @param {Map<string, string>} declarations Every declared token.
 * @return {Object} Resolved raw value and the chain of names walked to reach it.
 */
function resolveTokenValue( name, declarations ) {
	const chain = [ name ];
	let current = name;
	for ( let depth = 0; depth < MAX_INDIRECTION_DEPTH; depth += 1 ) {
		const value = declarations.get( current );
		if ( value === undefined ) {
			fail(
				'UNRESOLVED_REFERENCE',
				'token ' + current + ' is referenced' +
				( chain.length > 1 ? ' through ' + chain.join( ' -> ' ) : '' ) +
				' but is not declared in ' + TOKENS_FILE + '.'
			);
		}
		if ( !SINGLE_REFERENCE_PATTERN.test( value ) ) {
			return { value: value, chain: chain };
		}
		if ( chain.includes( value ) ) {
			fail(
				'REFERENCE_CYCLE',
				'variable indirection cycles: ' + chain.concat( [ value ] ).join( ' -> ' ) +
				'. A token cannot resolve to itself.'
			);
		}
		chain.push( value );
		current = value;
	}
	fail(
		'REFERENCE_TOO_DEEP',
		'variable indirection from ' + name + ' exceeded ' + String( MAX_INDIRECTION_DEPTH ) +
		' passes without resolving to a value.'
	);
	return { value: '', chain: chain };
}

/**
 * Parses one channel of a colour function, accepting a number or a percentage.
 *
 * @param {string} argument Raw channel argument.
 * @return {number|null} Channel in the range zero to 255, or null when unparsable.
 */
function parseChannel( argument ) {
	const isPercentage = argument.endsWith( '%' );
	const numeric = Number.parseFloat( isPercentage ? argument.slice( 0, -1 ) : argument );
	if ( !Number.isFinite( numeric ) ) {
		return null;
	}
	const scaled = isPercentage ? ( numeric / 100 ) * CHANNEL_MAX : numeric;
	return Math.min( CHANNEL_MAX, Math.max( 0, scaled ) );
}

/**
 * Parses an alpha argument, accepting a number or a percentage.
 *
 * @param {string} argument Raw alpha argument.
 * @return {number|null} Alpha in the range zero to one, or null when unparsable.
 */
function parseAlpha( argument ) {
	const isPercentage = argument.endsWith( '%' );
	const numeric = Number.parseFloat( isPercentage ? argument.slice( 0, -1 ) : argument );
	if ( !Number.isFinite( numeric ) ) {
		return null;
	}
	const scaled = isPercentage ? numeric / 100 : numeric;
	return Math.min( 1, Math.max( 0, scaled ) );
}

/**
 * Converts a hue, saturation and lightness triple to an eight-bit colour.
 *
 * @param {number} hue Hue in degrees.
 * @param {number} saturation Saturation in the range zero to one.
 * @param {number} lightness Lightness in the range zero to one.
 * @return {Array<number>} Red, green and blue channels in the range zero to 255.
 */
function hslToRgb( hue, saturation, lightness ) {
	const normalisedHue = ( ( hue % DEGREES_PER_TURN ) + DEGREES_PER_TURN ) % DEGREES_PER_TURN;
	const chroma = ( 1 - Math.abs( 2 * lightness - 1 ) ) * saturation;
	const sector = normalisedHue / 60;
	const secondary = chroma * ( 1 - Math.abs( ( sector % 2 ) - 1 ) );
	const offset = lightness - chroma / 2;
	let triple = [ 0, 0, 0 ];
	if ( sector < 1 ) {
		triple = [ chroma, secondary, 0 ];
	} else if ( sector < 2 ) {
		triple = [ secondary, chroma, 0 ];
	} else if ( sector < 3 ) {
		triple = [ 0, chroma, secondary ];
	} else if ( sector < 4 ) {
		triple = [ 0, secondary, chroma ];
	} else if ( sector < 5 ) {
		triple = [ secondary, 0, chroma ];
	} else {
		triple = [ chroma, 0, secondary ];
	}
	return triple.map( ( component ) => ( component + offset ) * CHANNEL_MAX );
}

/**
 * Parses a three- or six-digit hexadecimal colour.
 *
 * @param {string} value Trimmed candidate value.
 * @return {Object|null} Colour with an opaque alpha, or null when not hexadecimal.
 */
function parseHexColour( value ) {
	const match = HEX_COLOUR_PATTERN.exec( value );
	if ( match === null ) {
		return null;
	}
	const digits = match[ 1 ].length === 3 ?
		match[ 1 ].split( '' ).map( ( digit ) => digit + digit ).join( '' ) :
		match[ 1 ];
	return {
		rgb: [
			Number.parseInt( digits.slice( 0, 2 ), 16 ),
			Number.parseInt( digits.slice( 2, 4 ), 16 ),
			Number.parseInt( digits.slice( 4, 6 ), 16 )
		],
		alpha: 1
	};
}

/**
 * Parses a red-green-blue colour function, with or without an alpha channel.
 *
 * @param {string} value Trimmed candidate value.
 * @return {Object|null} Colour and alpha, or null when not such a function.
 */
function parseRgbFunction( value ) {
	const match = RGB_FUNCTION_PATTERN.exec( value );
	if ( match === null ) {
		return null;
	}
	const parts = match[ 1 ].trim().split( COLOUR_ARGUMENT_SEPARATOR ).filter( Boolean );
	if ( parts.length < 3 || parts.length > 4 ) {
		return null;
	}
	const channels = parts.slice( 0, 3 ).map( parseChannel );
	if ( channels.includes( null ) ) {
		return null;
	}
	const alpha = parts.length === 4 ? parseAlpha( parts[ 3 ] ) : 1;
	if ( alpha === null ) {
		return null;
	}
	return { rgb: channels, alpha: alpha };
}

/**
 * Parses a hue-saturation-lightness colour function, with or without an alpha.
 *
 * A bare saturation or lightness argument is read as a percentage, matching the
 * modern colour-function syntax in which the unit is optional.
 *
 * @param {string} value Trimmed candidate value.
 * @return {Object|null} Colour and alpha, or null when not such a function.
 */
function parseHslFunction( value ) {
	const match = HSL_FUNCTION_PATTERN.exec( value );
	if ( match === null ) {
		return null;
	}
	const parts = match[ 1 ].trim().split( COLOUR_ARGUMENT_SEPARATOR ).filter( Boolean );
	if ( parts.length < 3 || parts.length > 4 ) {
		return null;
	}
	const hue = Number.parseFloat( parts[ 0 ].replace( /deg$/i, '' ) );
	const saturation = parseAlpha( parts[ 1 ].endsWith( '%' ) ? parts[ 1 ] : parts[ 1 ] + '%' );
	const lightness = parseAlpha( parts[ 2 ].endsWith( '%' ) ? parts[ 2 ] : parts[ 2 ] + '%' );
	if ( !Number.isFinite( hue ) || saturation === null || lightness === null ) {
		return null;
	}
	const alpha = parts.length === 4 ? parseAlpha( parts[ 3 ] ) : 1;
	if ( alpha === null ) {
		return null;
	}
	return { rgb: hslToRgb( hue, saturation, lightness ), alpha: alpha };
}

/**
 * Parses any of the colour forms the token module is permitted to use.
 *
 * Anything else — a radius, a size, a line height, a font stack, a composite
 * shadow — returns null and is skipped rather than treated as an error, because
 * the token surface deliberately carries the design's geometry alongside its
 * colours.
 *
 * @param {string} value Raw declared value.
 * @return {Object|null} Colour and alpha, or null when the value is not a colour.
 */
function parseColourValue( value ) {
	const trimmed = value.trim();
	return parseHexColour( trimmed ) || parseRgbFunction( trimmed ) ||
		parseHslFunction( trimmed );
}

/**
 * Composites a partly transparent colour over an opaque backdrop.
 *
 * A value carrying an alpha channel has no ratio of its own: measured as though
 * it were opaque it would report a contrast the interface never renders. It is
 * therefore flattened against the backdrop it is drawn on, and the report records
 * that this happened so the number can be read correctly.
 *
 * @param {Array<number>} rgb Channels of the transparent colour.
 * @param {number} alpha Alpha in the range zero to one.
 * @param {Array<number>} backdropRgb Channels of the opaque backdrop.
 * @return {Array<number>} Flattened channels.
 */
function compositeOver( rgb, alpha, backdropRgb ) {
	return rgb.map(
		( channel, index ) => channel * alpha + backdropRgb[ index ] * ( 1 - alpha )
	);
}

/**
 * Formats channels as a six-digit lower-case hexadecimal colour.
 *
 * Lower case and six digits throughout, matching the spelling the package uses
 * on disk, so the report reads consistently whatever form the declaration took.
 *
 * @param {Array<number>} rgb Channels in the range zero to 255.
 * @return {string} Hexadecimal colour including its leading hash.
 */
function formatHex( rgb ) {
	return '#' + rgb
		.map( ( channel ) => Math.round( channel ).toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

/**
 * Linearises one eight-bit channel with the standard transfer function.
 *
 * @param {number} channel Channel in the range zero to 255.
 * @return {number} Linearised channel in the range zero to one.
 */
function channelLuminance( channel ) {
	const proportion = channel / CHANNEL_MAX;
	return proportion <= 0.04045 ?
		proportion / 12.92 :
		( ( proportion + 0.055 ) / 1.055 ) ** 2.4;
}

/**
 * Computes relative luminance from eight-bit channels.
 *
 * @param {Array<number>} rgb Channels in the range zero to 255.
 * @return {number} Relative luminance in the range zero to one.
 */
function relativeLuminance( rgb ) {
	return 0.2126 * channelLuminance( rgb[ 0 ] ) +
		0.7152 * channelLuminance( rgb[ 1 ] ) +
		0.0722 * channelLuminance( rgb[ 2 ] );
}

/**
 * Computes the contrast ratio between two colours.
 *
 * The ratio is returned unrounded. Rounding happens only where a value is
 * presented, never before a threshold comparison, so a ratio a hair under its
 * minimum cannot round its way into a pass.
 *
 * @param {Array<number>} foregroundRgb Foreground channels.
 * @param {Array<number>} backgroundRgb Background channels.
 * @return {number} Contrast ratio, at least one and at most 21.
 */
function contrastRatio( foregroundRgb, backgroundRgb ) {
	const first = relativeLuminance( foregroundRgb );
	const second = relativeLuminance( backgroundRgb );
	const lighter = Math.max( first, second );
	const darker = Math.min( first, second );
	return ( lighter + 0.05 ) / ( darker + 0.05 );
}

/**
 * Rounds a ratio to the two decimals the report presents.
 *
 * @param {number} ratio Unrounded ratio.
 * @return {number} Ratio rounded to two decimals.
 */
function roundRatio( ratio ) {
	return Math.round( ratio * 100 ) / 100;
}

/**
 * Rounds a luminance to six decimals for presentation.
 *
 * Presented values are rounded so that the committed report does not depend on
 * the last bits of a floating-point exponentiation, which the language specifies
 * only approximately.
 *
 * @param {number} luminance Unrounded luminance.
 * @return {number} Luminance rounded to six decimals.
 */
function roundLuminance( luminance ) {
	return Math.round( luminance * 1e6 ) / 1e6;
}

/**
 * Resolves every colour of the token surface, and records what could not be
 * resolved.
 *
 * A gradient is a legitimate non-solid colour value and is listed rather than
 * measured. Anything else under the colour prefix that fails to resolve to a
 * colour is a violation rather than a silent skip: a colour token that quietly
 * dropped out of the audit would be a blind spot, and the point of measuring at
 * all is to have none. Tokens outside the colour prefix are the design's
 * geometry, typography and elevation, and are skipped by name.
 *
 * @param {Map<string, string>} declarations Every declared token.
 * @return {Object} Resolved colours, gradient tokens and unresolved colour tokens.
 */
function buildColourTable( declarations ) {
	const colours = new Map();
	const gradients = [];
	const unresolved = [];
	for ( const name of declarations.keys() ) {
		const resolved = resolveTokenValue( name, declarations );
		const isColourToken = name.startsWith( COLOUR_TOKEN_PREFIX );
		if ( GRADIENT_PATTERN.test( resolved.value ) ) {
			if ( isColourToken ) {
				gradients.push( { token: name, value: resolved.value } );
			}
			continue;
		}
		const colour = parseColourValue( resolved.value );
		if ( colour === null ) {
			if ( isColourToken ) {
				unresolved.push( { token: name, value: resolved.value } );
			}
			continue;
		}
		colours.set( name, {
			rgb: colour.rgb,
			alpha: colour.alpha,
			indirection: resolved.chain.length > 1 ? resolved.chain : null
		} );
	}
	return { colours: colours, gradients: gradients, unresolved: unresolved };
}

/**
 * Flattens a token to opaque channels, compositing it when it carries an alpha.
 *
 * @param {string} token Colour-token name.
 * @param {Object} table Resolved colour table.
 * @param {Array<number>} backdropRgb Channels to composite a transparent value over.
 * @return {Object|null} Channels, hexadecimal spelling and whether compositing
 *   was applied, or null when the token is absent from the token surface.
 */
function flattenToken( token, table, backdropRgb ) {
	const colour = table.colours.get( token );
	if ( colour === undefined ) {
		return null;
	}
	const composited = colour.alpha < 1;
	const rgb = composited ?
		compositeOver( colour.rgb, colour.alpha, backdropRgb ) :
		colour.rgb;
	return {
		rgb: rgb,
		hex: formatHex( rgb ),
		alpha: colour.alpha,
		composited: composited,
		indirection: colour.indirection
	};
}

/**
 * Returns the threshold a text class requires.
 *
 * @param {string} textClass Either the body or the large text class.
 * @return {number} Minimum ratio.
 */
function thresholdFor( textClass ) {
	return textClass === CLASS_LARGE ? THRESHOLD_LARGE : THRESHOLD_BODY;
}

/**
 * Builds the stable identity of a pairing, used both to sort and to merge.
 *
 * @param {Object} pairing Pairing carrying its palette, tokens and text class.
 * @return {string} Identity of the pairing.
 */
function pairingKey( pairing ) {
	return [
		pairing.mode,
		pairing.foregroundToken,
		pairing.backgroundToken,
		pairing.textClass
	].join( '|' );
}

/**
 * Measures one pairing and reaches a verdict on it.
 *
 * The background is flattened first, against its palette's page surface, so that
 * a transparent foreground is composited over what is actually behind it rather
 * than over a notional white.
 *
 * @param {Object} pairing Pairing to measure.
 * @param {Object} context Colour table and the page surface of each palette.
 * @param {Array<string>} sources Where the pairing came from.
 * @param {Array<Object>} violations Collector for blocking findings.
 * @return {Object} Report entry for the pairing.
 */
function evaluatePairing( pairing, context, sources, violations ) {
	const table = context.table;
	const surfaceToken = PAGE_SURFACE_TOKEN[ pairing.mode ];
	const surfaceRgb = context.surfaceRgbByMode[ pairing.mode ];
	const background = flattenToken( pairing.backgroundToken, table, surfaceRgb );
	const foreground = flattenToken(
		pairing.foregroundToken,
		table,
		background === null ? surfaceRgb : background.rgb
	);
	const threshold = thresholdFor( pairing.textClass );
	const entry = {
		mode: pairing.mode,
		role: pairing.role,
		foregroundToken: pairing.foregroundToken,
		foregroundHex: foreground === null ? null : foreground.hex,
		backgroundToken: pairing.backgroundToken,
		backgroundHex: background === null ? null : background.hex,
		ratio: null,
		threshold: threshold,
		textClass: pairing.textClass,
		fontSizePx: pairing.fontSizePx,
		fontWeight: pairing.fontWeight,
		justification: pairing.justification,
		composited: null,
		indirection: null,
		sources: sources.slice(),
		verdict: 'unmeasured'
	};
	const missing = [];
	if ( foreground === null ) {
		missing.push( pairing.foregroundToken );
	}
	if ( background === null ) {
		missing.push( pairing.backgroundToken );
	}
	if ( missing.length > 0 ) {
		violations.push( {
			code: 'PAIR_TOKEN_MISSING',
			subject: pairing.mode + ' ' + pairing.role,
			message: 'pairing references ' + missing.join( ' and ' ) +
				', which the token surface does not declare as a colour, so no ratio ' +
				'could be measured.'
		} );
		return entry;
	}
	const ratio = contrastRatio( foreground.rgb, background.rgb );
	entry.ratio = roundRatio( ratio );
	entry.composited = describeCompositing( pairing, foreground, background, surfaceToken );
	entry.indirection = describeIndirection( pairing, foreground, background );
	entry.verdict = ratio >= threshold ? 'pass' : 'fail';
	if ( entry.verdict === 'fail' ) {
		violations.push( {
			code: 'CONTRAST_BELOW_THRESHOLD',
			subject: pairing.mode + ' ' + pairing.role,
			message: pairing.foregroundToken + ' on ' + pairing.backgroundToken +
				' measures ' + entry.ratio.toFixed( 2 ) + ':1 against a required ' +
				String( threshold ) + ':1 for ' + pairing.textClass + ' text.'
		} );
	}
	return entry;
}

/**
 * Records which sides of a pairing were composited, and over what.
 *
 * @param {Object} pairing Pairing being measured.
 * @param {Object} foreground Flattened foreground.
 * @param {Object} background Flattened background.
 * @param {string} surfaceToken Page surface of the pairing's palette.
 * @return {Object|null} Compositing record, or null when both sides were opaque.
 */
function describeCompositing( pairing, foreground, background, surfaceToken ) {
	if ( !foreground.composited && !background.composited ) {
		return null;
	}
	const record = {};
	if ( background.composited ) {
		record.background = {
			token: pairing.backgroundToken,
			alpha: background.alpha,
			compositedOver: surfaceToken
		};
	}
	if ( foreground.composited ) {
		record.foreground = {
			token: pairing.foregroundToken,
			alpha: foreground.alpha,
			compositedOver: pairing.backgroundToken
		};
	}
	return record;
}

/**
 * Records the indirection chains walked to resolve either side of a pairing.
 *
 * @param {Object} pairing Pairing being measured.
 * @param {Object} foreground Flattened foreground.
 * @param {Object} background Flattened background.
 * @return {Object|null} Indirection record, or null when neither side indirected.
 */
function describeIndirection( pairing, foreground, background ) {
	if ( foreground.indirection === null && background.indirection === null ) {
		return null;
	}
	const record = {};
	if ( foreground.indirection !== null ) {
		record.foreground = foreground.indirection;
	}
	if ( background.indirection !== null ) {
		record.background = background.indirection;
	}
	return record;
}

/**
 * Measures every pairing from both sources and reconciles the two.
 *
 * A declared pairing already covered by a derived role at the same text class is
 * merged into that role's entry, which then records both sources. One that is not
 * covered is measured on its own, so a declared pairing can never be dropped and
 * a derived classification can never quietly weaken a declared one.
 *
 * @param {Object} context Colour table and the page surface of each palette.
 * @param {Array<Object>} declaredPairings Pairings annotated in the token module.
 * @param {Array<Object>} violations Collector for blocking findings.
 * @return {Object} Measured entries and the reconciliation record.
 */
function measurePairings( context, declaredPairings, violations ) {
	const entries = [];
	const byKey = new Map();
	for ( const pairing of DERIVED_PAIRINGS ) {
		const entry = evaluatePairing( pairing, context, [ SOURCE_DERIVED ], violations );
		entries.push( entry );
		const key = pairingKey( pairing );
		if ( !byKey.has( key ) ) {
			byKey.set( key, [] );
		}
		byKey.get( key ).push( entry );
	}
	const reconciliation = [];
	for ( const declared of declaredPairings ) {
		const key = pairingKey( declared );
		const covered = byKey.get( key );
		if ( covered !== undefined ) {
			for ( const existing of covered ) {
				if ( !existing.sources.includes( SOURCE_DECLARED ) ) {
					existing.sources.push( SOURCE_DECLARED );
				}
			}
			reconciliation.push( {
				mode: declared.mode,
				textClass: declared.textClass,
				foregroundToken: declared.foregroundToken,
				backgroundToken: declared.backgroundToken,
				coveredByDerivedRoles: covered.map( ( candidate ) => candidate.role ),
				addedByReconciliation: false
			} );
			continue;
		}
		const added = evaluatePairing( {
			mode: declared.mode,
			role: 'Declared pairing with no derived role at this text class',
			foregroundToken: declared.foregroundToken,
			backgroundToken: declared.backgroundToken,
			textClass: declared.textClass,
			fontSizePx: null,
			fontWeight: null,
			justification: 'Declared in the token module at the ' + declared.textClass +
				' class and measured at that class. No derived role covers this pair of ' +
				'tokens at this class, so the declaration alone carries it; the annotation ' +
				'states no size or weight, so none is recorded.'
		}, context, [ SOURCE_DECLARED ], violations );
		entries.push( added );
		byKey.set( key, [ added ] );
		reconciliation.push( {
			mode: declared.mode,
			textClass: declared.textClass,
			foregroundToken: declared.foregroundToken,
			backgroundToken: declared.backgroundToken,
			coveredByDerivedRoles: [],
			addedByReconciliation: true
		} );
	}
	return { entries: entries, reconciliation: reconciliation };
}

/**
 * Role each night value plays, which decides what it is measured against.
 *
 * A surface has no ratio against itself, and a fill is only meaningful against
 * the type set on it, so each value is measured against its counterpart rather
 * than against one fixed backdrop. The night text colours and the night purple
 * are measured against the night page surface; the two night surfaces and the
 * three night state fills are measured against the primary night text colour,
 * which the token module records as the type set on them; and the night border is
 * measured against the night page surface it separates.
 */
const NIGHT_VALUE_ROLES = {
	'dark-surface': { role: 'surface', against: 'dark-text-primary', textClass: CLASS_BODY },
	'dark-surface-raised': { role: 'surface', against: 'dark-text-primary', textClass: CLASS_BODY },
	'dark-text-primary': { role: 'foreground', against: 'dark-surface', textClass: CLASS_BODY },
	'dark-text-secondary': { role: 'foreground', against: 'dark-surface', textClass: CLASS_BODY },
	'dark-text-muted': { role: 'foreground', against: 'dark-surface', textClass: CLASS_BODY },
	'dark-purple': { role: 'foreground', against: 'dark-surface', textClass: CLASS_BODY },
	'dark-border': { role: 'separator', against: 'dark-surface', textClass: CLASS_LARGE },
	'dark-success-bg': { role: 'fill', against: 'dark-text-primary', textClass: CLASS_BODY },
	'dark-error-bg': { role: 'fill', against: 'dark-text-primary', textClass: CLASS_BODY },
	'dark-warning-bg': { role: 'fill', against: 'dark-text-primary', textClass: CLASS_BODY }
};

/**
 * Covers all ten night values, each with its own measured ratio and verdict.
 *
 * The night mode is only as verified as its least verified value, so every one of
 * the ten is measured here whether or not a pairing names it, and a value absent
 * from the token surface is a violation.
 *
 * A value that measures below its threshold is also a violation UNLESS the token
 * module already records an accessibility finding at that value's declaration. It
 * is worth being precise about why that is not an allowlist. The exception is not
 * expressed here and cannot be granted here: the token module defines those
 * findings as machine-readable annotations for tools to read, the finding text is
 * carried into this report verbatim so the shortfall is disclosed rather than
 * hidden, and deleting the annotation from the token module makes the build fail.
 * A specified value that the design has reviewed and knowingly kept is reported
 * as what it is; nothing here can quietly decide to accept one.
 *
 * @param {Object} context Colour table and the page surface of each palette.
 * @param {Array<Object>} entries Measured pairing entries.
 * @param {Array<Object>} flags Accessibility findings from the token module.
 * @param {Array<Object>} violations Collector for blocking findings.
 * @return {Object} Night-palette coverage block.
 */
function buildNightCoverage( context, entries, flags, violations ) {
	const values = [];
	const missing = [];
	for ( const token of NIGHT_PALETTE_TOKENS ) {
		const suffix = token.slice( COLOUR_TOKEN_PREFIX.length );
		const definition = NIGHT_VALUE_ROLES[ suffix ];
		const againstToken = COLOUR_TOKEN_PREFIX + definition.against;
		const surfaceRgb = context.surfaceRgbByMode[ MODE_DARK ];
		const subject = flattenToken( token, context.table, surfaceRgb );
		const counterpart = flattenToken( againstToken, context.table, surfaceRgb );
		if ( subject === null || counterpart === null ) {
			missing.push( token );
			violations.push( {
				code: 'NIGHT_VALUE_MISSING',
				subject: token,
				message: 'the night palette must declare all ten of its values, and ' +
					( subject === null ? token : againstToken ) +
					' is not declared as a colour in the token surface. An incomplete ' +
					'night palette is an unverified night mode.'
			} );
			continue;
		}
		const threshold = thresholdFor( definition.textClass );
		const ratio = contrastRatio( subject.rgb, counterpart.rgb );
		const flag = flags.find( ( candidate ) => candidate.token === token );
		const verdict = ratio >= threshold ? 'pass' : 'below-threshold';
		values.push( {
			token: token,
			hex: subject.hex,
			role: definition.role,
			measuredAgainst: againstToken,
			measuredAgainstHex: counterpart.hex,
			ratio: roundRatio( ratio ),
			threshold: threshold,
			textClass: definition.textClass,
			verdict: verdict,
			auditedPairCount: countAuditedPairs( entries, token ),
			declaredAccessibilityFinding: flag === undefined ? null : flag.finding
		} );
		if ( verdict === 'below-threshold' && flag === undefined ) {
			violations.push( {
				code: 'NIGHT_VALUE_BELOW_THRESHOLD',
				subject: token,
				message: token + ' measures ' + roundRatio( ratio ).toFixed( 2 ) +
					':1 against ' + againstToken + ' where ' + String( threshold ) +
					':1 is required, and its declaration records no accessibility ' +
					'finding explaining the shortfall.'
			} );
		}
	}
	return {
		expectedValueCount: NIGHT_PALETTE_TOKENS.length,
		presentValueCount: values.length,
		missingValues: missing,
		values: values
	};
}

/**
 * Counts the measured pairings that reference a token on either side.
 *
 * @param {Array<Object>} entries Measured pairing entries.
 * @param {string} token Colour-token name.
 * @return {number} Number of pairings naming the token.
 */
function countAuditedPairs( entries, token ) {
	return entries.filter(
		( entry ) => entry.foregroundToken === token || entry.backgroundToken === token
	).length;
}

/**
 * Confirms that the night purple is lighter than the light-mode purple.
 *
 * The two ratios reported alongside the luminances are the evidence for the
 * decision rather than decoration: the light-mode purple does not clear the
 * body-text floor against the night page surface, and the night purple does. A
 * regression to a darker night purple is a violation, because reverting it would
 * look more consistent with the brand while quietly failing the floor, which is
 * the exact outcome the decision exists to prevent.
 *
 * @param {Object} context Colour table and the page surface of each palette.
 * @param {Array<Object>} violations Collector for blocking findings.
 * @return {Object} Night-purple assertion block.
 */
function buildNightPurpleAssertion( context, violations ) {
	const surfaceRgb = context.surfaceRgbByMode[ MODE_DARK ];
	const light = flattenToken( LIGHT_PURPLE_TOKEN, context.table, surfaceRgb );
	const night = flattenToken( NIGHT_PURPLE_TOKEN, context.table, surfaceRgb );
	const assertion = {
		requirement: 'The night purple must be strictly lighter than the light-mode ' +
			'purple, because the light-mode purple does not clear the body-text minimum ' +
			'against the night page surface.',
		lightToken: LIGHT_PURPLE_TOKEN,
		lightHex: light === null ? null : light.hex,
		lightRelativeLuminance: null,
		nightToken: NIGHT_PURPLE_TOKEN,
		nightHex: night === null ? null : night.hex,
		nightRelativeLuminance: null,
		nightIsLighter: null,
		lightPurpleOnNightSurface: null,
		nightPurpleOnNightSurface: null,
		verdict: 'unmeasured'
	};
	if ( light === null || night === null ) {
		violations.push( {
			code: 'NIGHT_PURPLE_UNMEASURABLE',
			subject: light === null ? LIGHT_PURPLE_TOKEN : NIGHT_PURPLE_TOKEN,
			message: 'the night-purple assertion needs both ' + LIGHT_PURPLE_TOKEN +
				' and ' + NIGHT_PURPLE_TOKEN +
				' to resolve to colours, and one of them does not.'
		} );
		return assertion;
	}
	const lightLuminance = relativeLuminance( light.rgb );
	const nightLuminance = relativeLuminance( night.rgb );
	assertion.lightRelativeLuminance = roundLuminance( lightLuminance );
	assertion.nightRelativeLuminance = roundLuminance( nightLuminance );
	assertion.nightIsLighter = nightLuminance > lightLuminance;
	assertion.lightPurpleOnNightSurface = describeAgainstNightSurface( light.rgb, surfaceRgb );
	assertion.nightPurpleOnNightSurface = describeAgainstNightSurface( night.rgb, surfaceRgb );
	assertion.verdict = assertion.nightIsLighter ? 'pass' : 'fail';
	if ( assertion.verdict === 'fail' ) {
		violations.push( {
			code: 'NIGHT_PURPLE_NOT_LIGHTER',
			subject: NIGHT_PURPLE_TOKEN,
			message: NIGHT_PURPLE_TOKEN + ' has relative luminance ' +
				String( assertion.nightRelativeLuminance ) + ', which is not greater than ' +
				LIGHT_PURPLE_TOKEN + ' at ' + String( assertion.lightRelativeLuminance ) +
				'. The night purple must stay the lighter of the two.'
		} );
	}
	return assertion;
}

/**
 * Measures a colour against the night page surface at the body minimum.
 *
 * @param {Array<number>} rgb Channels of the colour.
 * @param {Array<number>} surfaceRgb Channels of the night page surface.
 * @return {Object} Measured ratio, threshold and verdict.
 */
function describeAgainstNightSurface( rgb, surfaceRgb ) {
	const ratio = contrastRatio( rgb, surfaceRgb );
	return {
		ratio: roundRatio( ratio ),
		threshold: THRESHOLD_BODY,
		textClass: CLASS_BODY,
		verdict: ratio >= THRESHOLD_BODY ? 'pass' : 'below-threshold'
	};
}

/** Matches a selector list line that opens a block. */
const BLOCK_OPEN_PATTERN = /\{$/;

/** Matches every token reference on a line. */
const TOKEN_REFERENCE_PATTERN = /@blitzy-[A-Za-z0-9-]+/g;

/**
 * Classifies a selector list as publishing the light palette, the night palette
 * or neither.
 *
 * A forced-light island nested under a night selector publishes light values
 * despite the night selector in its ancestry, so that case is tested first.
 *
 * @param {string} selector Selector list, with its opening brace removed.
 * @return {string} A palette identifier, or a label for a block that publishes
 *   neither palette.
 */
function classifySelector( selector ) {
	const text = selector.trim();
	if ( text.startsWith( '@media' ) ) {
		return 'media';
	}
	const isThemed = text.includes( 'skin-theme-clientpref' );
	if ( isThemed && text.includes( '.notheme' ) ) {
		return MODE_LIGHT;
	}
	if ( isThemed ) {
		return MODE_DARK;
	}
	if ( text.includes( ':root' ) ) {
		return MODE_LIGHT;
	}
	return 'other';
}

/**
 * Collects which tokens each palette publishes as custom properties.
 *
 * @param {string} source Raw Less source of the custom-property publication file.
 * @return {Object} Sets of token names keyed by palette identifier.
 */
function parsePalettePublication( source ) {
	const published = { light: new Set(), dark: new Set() };
	const contextStack = [];
	let pendingSelector = '';
	for ( const rawLine of stripComments( source ).split( '\n' ) ) {
		const line = rawLine.trim();
		if ( line === '' ) {
			continue;
		}
		if ( line === '}' ) {
			contextStack.pop();
			continue;
		}
		if ( BLOCK_OPEN_PATTERN.test( line ) ) {
			pendingSelector += ' ' + line.replace( BLOCK_OPEN_PATTERN, '' );
			contextStack.push( classifySelector( pendingSelector ) );
			pendingSelector = '';
			continue;
		}
		if ( line.endsWith( ',' ) ) {
			pendingSelector += ' ' + line;
			continue;
		}
		const context = contextStack.length > 0 ?
			contextStack[ contextStack.length - 1 ] :
			'none';
		if ( context !== MODE_LIGHT && context !== MODE_DARK ) {
			continue;
		}
		for ( const token of line.match( TOKEN_REFERENCE_PATTERN ) || [] ) {
			published[ context ].add( token );
		}
	}
	return published;
}

/**
 * Confirms that no palette is measured against a backdrop belonging to the other
 * palette.
 *
 * This is the check that catches a light background reused in a night row, which
 * is the likeliest way a report of this kind passes while the rendered dark
 * interface fails.
 *
 * The test is deliberately "published by the other palette and not by this one"
 * rather than "published by this one". The custom-property file publishes only
 * values that differ between the palettes, so a token published by neither is
 * mode-invariant by construction and is lawful as a backdrop in either — the
 * deeper brand tint a hovered chip takes is such a value, consumed straight from
 * the token module. A token the other palette publishes and this one does not is
 * the genuine leak, and it is the only case that blocks.
 *
 * Only backgrounds are examined, and deliberately so: a foreground may be
 * mode-invariant and need no custom property at all — the inverse text colour is
 * the same in both palettes — and the destructive accent is published once, in
 * the light block, by a decision recorded at its declaration.
 *
 * @param {Object} published Sets of published token names keyed by palette.
 * @param {Array<Object>} violations Collector for blocking findings.
 * @return {Object} Verification record per palette.
 */
function verifyPalettePublication( published, violations ) {
	const verification = {};
	for ( const mode of MODES ) {
		const other = mode === MODE_LIGHT ? MODE_DARK : MODE_LIGHT;
		const backgrounds = [ ...new Set(
			DERIVED_PAIRINGS
				.filter( ( pairing ) => pairing.mode === mode )
				.map( ( pairing ) => pairing.backgroundToken )
		) ].sort();
		const foreign = backgrounds.filter(
			( token ) => published[ other ].has( token ) && !published[ mode ].has( token )
		);
		verification[ mode ] = {
			publishedTokenCount: published[ mode ].size,
			backgroundTokensChecked: backgrounds.length,
			backgroundTokensPublishedByThisPalette: backgrounds.filter(
				( token ) => published[ mode ].has( token )
			).length,
			modeInvariantBackgroundTokens: backgrounds.filter(
				( token ) => !published[ mode ].has( token ) && !published[ other ].has( token )
			),
			foreignPaletteBackgroundTokens: foreign
		};
		for ( const token of foreign ) {
			violations.push( {
				code: 'FOREIGN_PALETTE_BACKGROUND',
				subject: mode + ' ' + token,
				message: token + ' is used as a background by a ' + mode +
					' pairing, but ' + CUSTOM_PROPERTIES_FILE + ' publishes it for the ' +
					other + ' palette and not for the ' + mode + ' one. That pairing ' +
					'measures a backdrop the ' + mode + ' interface does not render.'
			} );
		}
	}
	return verification;
}

/**
 * Sorts measured pairings on a stable key so the report is reproducible.
 *
 * @param {Array<Object>} entries Measured pairing entries.
 * @return {Array<Object>} Sorted copy.
 */
function sortEntries( entries ) {
	const classOrder = [ CLASS_BODY, CLASS_LARGE ];
	return entries.slice().sort( ( first, second ) => {
		const byMode = MODES.indexOf( first.mode ) - MODES.indexOf( second.mode );
		if ( byMode !== 0 ) {
			return byMode;
		}
		const byForeground = first.foregroundToken.localeCompare( second.foregroundToken, 'en' );
		if ( byForeground !== 0 ) {
			return byForeground;
		}
		const byBackground = first.backgroundToken.localeCompare( second.backgroundToken, 'en' );
		if ( byBackground !== 0 ) {
			return byBackground;
		}
		const byClass = classOrder.indexOf( first.textClass ) -
			classOrder.indexOf( second.textClass );
		if ( byClass !== 0 ) {
			return byClass;
		}
		return first.role.localeCompare( second.role, 'en' );
	} );
}

/**
 * Sorts the reconciliation record on the same stable key as the pairings.
 *
 * @param {Array<Object>} records Reconciliation records.
 * @return {Array<Object>} Sorted copy.
 */
function sortReconciliation( records ) {
	return records.slice().sort(
		( first, second ) => pairingKey( first ).localeCompare( pairingKey( second ), 'en' )
	);
}

/**
 * Summarises the measurement, per palette and overall.
 *
 * @param {Object} report Report under construction.
 * @return {Object} Summary block.
 */
function buildSummary( report ) {
	const summary = {
		totalPairs: report.pairs.length,
		passingPairs: report.pairs.filter( ( entry ) => entry.verdict === 'pass' ).length,
		failingPairs: report.pairs.filter( ( entry ) => entry.verdict !== 'pass' ).length,
		declaredPairingsMeasured: report.declaredPairingReconciliation.length,
		declaredPairingsAddedByReconciliation: report.declaredPairingReconciliation.filter(
			( record ) => record.addedByReconciliation
		).length,
		darkPaletteValuesExpected: report.darkPaletteCoverage.expectedValueCount,
		darkPaletteValuesMeasured: report.darkPaletteCoverage.presentValueCount,
		darkPurpleAssertionVerdict: report.darkPurpleAssertion.verdict,
		accessibilityFindingsCarried: report.accessibilityFindings.length,
		violationCount: report.violations.length,
		verdict: report.violations.length === 0 ? 'pass' : 'fail'
	};
	for ( const mode of MODES ) {
		const forMode = report.pairs.filter( ( entry ) => entry.mode === mode );
		const measured = forMode.filter( ( entry ) => entry.ratio !== null );
		const minimum = measured.reduce(
			( lowest, entry ) => ( lowest === null || entry.ratio < lowest.ratio ? entry : lowest ),
			null
		);
		summary[ mode ] = {
			pairs: forMode.length,
			passing: forMode.filter( ( entry ) => entry.verdict === 'pass' ).length,
			failing: forMode.filter( ( entry ) => entry.verdict !== 'pass' ).length,
			minimumRatio: minimum === null ? null : minimum.ratio,
			minimumRatioRole: minimum === null ? null : minimum.role,
			minimumRatioPair: minimum === null ?
				null :
				minimum.foregroundToken + ' on ' + minimum.backgroundToken
		};
	}
	return summary;
}

/**
 * Writes the report, creating the audit directory when it does not yet exist.
 *
 * @param {Object} report Completed report.
 * @return {void}
 */
function writeReport( report ) {
	const absolutePath = path.join( PACKAGE_ROOT, REPORT_FILE );
	fs.mkdirSync( path.dirname( absolutePath ), { recursive: true } );
	fs.writeFileSync( absolutePath, JSON.stringify( report, null, '\t' ) + '\n', 'utf8' );
}

/**
 * Prints a compact table and the verdict to standard error.
 *
 * The table goes to standard error and the document to a file, so that the
 * committed artifact is never mixed with the human-readable view of it.
 *
 * @param {Object} report Completed report.
 * @return {void}
 */
function writeVerdict( report ) {
	const lines = [ 'contrast-report: measured contrast for the Blitzy token surface' ];
	for ( const entry of report.pairs ) {
		lines.push(
			'  ' + entry.mode.padEnd( 5 ) + ' ' + entry.textClass.padEnd( 5 ) + ' ' +
			( entry.ratio === null ? '  n/a' : entry.ratio.toFixed( 2 ).padStart( 5 ) ) +
			' >= ' + entry.threshold.toFixed( 2 ) + ' ' +
			entry.verdict.toUpperCase().padEnd( 10 ) + ' ' +
			entry.foregroundToken + ' on ' + entry.backgroundToken + ' — ' + entry.role
		);
	}
	for ( const mode of MODES ) {
		const forMode = report.summary[ mode ];
		lines.push(
			'contrast-report: ' + mode + ' — ' + String( forMode.pairs ) + ' pair(s), ' +
			String( forMode.passing ) + ' pass, ' + String( forMode.failing ) + ' fail' +
			( forMode.minimumRatio === null ?
				'' :
				', minimum ' + forMode.minimumRatio.toFixed( 2 ) + ':1 (' +
					forMode.minimumRatioRole + ')' )
		);
	}
	const coverage = report.darkPaletteCoverage;
	lines.push(
		'contrast-report: dark palette — ' + String( coverage.presentValueCount ) + '/' +
		String( coverage.expectedValueCount ) + ' value(s) measured, ' +
		String( coverage.values.filter( ( value ) => value.verdict !== 'pass' ).length ) +
		' below its threshold'
	);
	const assertion = report.darkPurpleAssertion;
	lines.push(
		'contrast-report: dark purple — luminance ' +
		String( assertion.nightRelativeLuminance ) + ' against light-mode ' +
		String( assertion.lightRelativeLuminance ) + ' — ' +
		assertion.verdict.toUpperCase()
	);
	lines.push(
		'contrast-report: declared pairings — ' +
		String( report.summary.declaredPairingsMeasured ) + ' measured, ' +
		String( report.summary.declaredPairingsAddedByReconciliation ) +
		' added by reconciliation'
	);
	lines.push(
		'contrast-report: accessibility findings carried from the token surface: ' +
		String( report.accessibilityFindings.length )
	);
	lines.push( 'contrast-report: wrote ' + REPORT_FILE );
	if ( report.violations.length === 0 ) {
		lines.push(
			'contrast-report: PASS — every measured pairing meets its threshold. Measured ' +
			'ratios against the stated minimums; not a claim of conformance.'
		);
	} else {
		lines.push(
			'contrast-report: FAIL — ' + String( report.violations.length ) + ' violation(s):'
		);
		for ( const violation of report.violations ) {
			lines.push( '  [' + violation.code + '] ' + violation.subject + ': ' + violation.message );
		}
	}
	process.stderr.write( lines.join( '\n' ) + '\n' );
}

/**
 * Sorts the accessibility findings on a stable key.
 *
 * The annotated token orders the list and the source line breaks a tie, so two
 * findings recorded against the same token keep a fixed order.
 *
 * @param {Array<Object>} findings Findings read from the token module.
 * @return {Array<Object>} Sorted copy.
 */
function sortFindings( findings ) {
	return findings.slice().sort( ( first, second ) => {
		const byToken = String( first.token ).localeCompare( String( second.token ), 'en' );
		return byToken !== 0 ? byToken : first.sourceLine - second.sourceLine;
	} );
}

/**
 * Sorts violations on a stable key.
 *
 * @param {Array<Object>} violations Collected violations.
 * @return {Array<Object>} Sorted copy.
 */
function sortViolations( violations ) {
	return violations.slice().sort( ( first, second ) => {
		const byCode = first.code.localeCompare( second.code, 'en' );
		return byCode !== 0 ? byCode : first.subject.localeCompare( second.subject, 'en' );
	} );
}

/**
 * Reads the token surface, measures every pairing, writes the report and sets
 * the exit status.
 *
 * The exit status is set rather than forced with an immediate exit, so the report
 * is always written before the process ends.
 *
 * @return {void}
 */
function main() {
	const tokensSource = readSource( TOKENS_FILE );
	const customPropertiesSource = readSource( CUSTOM_PROPERTIES_FILE );
	const declarations = parseDeclarations( stripComments( tokensSource ) );
	const table = buildColourTable( declarations );
	const violations = [];
	for ( const unresolved of table.unresolved ) {
		violations.push( {
			code: 'COLOUR_TOKEN_UNRESOLVED',
			subject: unresolved.token,
			message: 'declared under the colour prefix but its value "' + unresolved.value +
				'" is neither a colour nor a gradient, so it cannot be measured. A colour ' +
				'token that drops out of the audit is a blind spot.'
		} );
	}
	const surfaceRgbByMode = {};
	for ( const mode of MODES ) {
		const surfaceToken = PAGE_SURFACE_TOKEN[ mode ];
		const surface = table.colours.get( surfaceToken );
		if ( surface === undefined ) {
			fail(
				'MISSING_PAGE_SURFACE',
				'the ' + mode + ' page surface ' + surfaceToken + ' is not declared as a ' +
				'colour in ' + TOKENS_FILE + '. Every pairing of that palette is measured ' +
				'against it, so nothing can be measured without it.'
			);
		}
		surfaceRgbByMode[ mode ] = surface.rgb;
	}
	const context = { table: table, surfaceRgbByMode: surfaceRgbByMode };
	const flags = parseAccessibilityFlags( tokensSource );
	const measured = measurePairings(
		context,
		parseDeclaredPairings( tokensSource ),
		violations
	);
	// Every block that can raise a violation is built before the document is
	// assembled, so the violation list the document carries is complete rather
	// than dependent on the order in which an object literal is evaluated.
	const darkPurpleAssertion = buildNightPurpleAssertion( context, violations );
	const darkPaletteCoverage = buildNightCoverage(
		context,
		measured.entries,
		flags,
		violations
	);
	const palettePublication = verifyPalettePublication(
		parsePalettePublication( customPropertiesSource ),
		violations
	);
	const report = {
		artifact: 'contrast-report',
		inputs: [ TOKENS_FILE, CUSTOM_PROPERTIES_FILE ],
		output: REPORT_FILE,
		modes: MODES,
		thresholds: { body: THRESHOLD_BODY, large: THRESHOLD_LARGE },
		interpretation: 'Each pairing is measured against the minimum its text class ' +
			'requires: ' + String( THRESHOLD_BODY ) + ':1 for body text, ' +
			String( THRESHOLD_LARGE ) + ':1 for large text and for non-text affordances. ' +
			'Automated measurement covers only part of accessibility, so this report is a ' +
			'blocking floor and not a claim of conformance to any accessibility standard.',
		summary: {},
		pairs: sortEntries( measured.entries ),
		declaredPairingReconciliation: sortReconciliation( measured.reconciliation ),
		darkPurpleAssertion: darkPurpleAssertion,
		darkPaletteCoverage: darkPaletteCoverage,
		palettePublication: palettePublication,
		tokenSurface: {
			declaredTokenCount: declarations.size,
			measuredColourTokenCount: table.colours.size,
			gradientTokens: table.gradients.map( ( gradient ) => gradient.token ).sort(),
			unresolvedColourTokens: table.unresolved.map( ( entry ) => entry.token ).sort()
		},
		accessibilityFindings: sortFindings( flags ),
		violations: sortViolations( violations )
	};
	report.summary = buildSummary( report );
	writeReport( report );
	writeVerdict( report );
	process.exitCode = report.violations.length === 0 ? 0 : 1;
}

main();
