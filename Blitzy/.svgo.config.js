/**
 * SVGO optimisation profile for the Blitzy skin.
 *
 * The skin's only shipped vector asset is the navigation-card logo at
 * `resources/images/blitzy-logo.svg`, which is wired through `$wgLogos` and is
 * never inlined into a template. This file is the authoritative, reproducible
 * record of how that asset was optimised, so its committed bytes are a
 * deliberate result rather than an incidental one. The profile is scoped to
 * that asset alone; generated artefacts that happen to contain SVG, such as the
 * icons inside the committed coverage reports, are left exactly as their
 * generator emitted them.
 *
 * The plugin selection follows the "Exemplified safe configuration" published
 * with MediaWiki's SVG coding conventions. That profile departs from SVGO's own
 * defaults in the few places where those defaults are unsafe for MediaWiki's
 * asset pipeline, and each departure is justified inline below. The options used
 * are valid for SVGO 3.x; the profile was exercised against 3.3.3, the version
 * MediaWiki core itself pins.
 *
 * SVGO is deliberately not declared as a package dependency. The skin exposes no
 * SVG minification script and the shipped skin adds no build step, so
 * optimisation is a one-time authoring activity carried out with this profile.
 * That keeps the dependency surface at exactly the set the specification
 * enumerates, and it means no build step depends on this file: when the logo is
 * absent the navigation card degrades to a wordmark with no mark instead of
 * failing the build.
 *
 * Nothing here reads from, writes to, or resolves against a network origin.
 * Optimisation is purely local, rewriting a committed file in place.
 *
 * @see https://www.mediawiki.org/wiki/Manual:Coding_conventions/SVG
 */

'use strict';

module.exports = {
	// Re-run the plugin set until the output stops changing. A single pass can
	// leave structures that only become reducible once an earlier plugin has
	// run, so one pass is not guaranteed to land on a fixed point and a later
	// run could still alter the file. Converging here is what makes the
	// optimisation idempotent, which matters because the logo is rendered in the
	// navigation card of every committed capture: bytes that shifted between
	// runs would surface as capture drift.
	multipass: true,

	// Serialisation is pinned rather than left to defaults, so that identical
	// input yields identical output on any host.
	js2svg: {
		// Unix line endings always; never inherit the platform convention.
		eol: 'lf',

		// Terminate the final line, consistent with the rest of the package.
		finalNewline: true,

		// Indent with tabs rather than SVGO's four spaces, per Wikimedia
		// whitespace conventions. This governs the `pretty` output below.
		indent: '\t',

		// Keep the markup formatted so the asset stays reviewable in a diff.
		// The extra whitespace is recovered by gzip on the wire.
		pretty: true
	},

	// The list below is exhaustive. Nothing that rewrites geometry, path
	// precision or colour is enabled, because any of those could shift the
	// rendered mark away from the design reference it is asserted against. The
	// profile is likewise scoped to this single asset: it declares no extra
	// input paths and stands up no wider image pipeline.
	plugins: [
		{
			// SVGO's default plugin set, with the unsafe entries switched off.
			name: 'preset-default',
			params: {
				overrides: {
					// Internal references such as gradient stops, clip paths
					// and masks are addressed by id. Rewriting those ids
					// silently breaks whatever resolves against them, and the
					// failure shows up as a blank or mis-filled mark rather
					// than as an error.
					cleanupIds: false,

					// `<title>` and `<desc>` carry the mark's accessible text.
					// The logo sits inside the navigation landmark on every
					// audited page, so discarding them would cost an accessible
					// name that the accessibility gate treats as blocking.
					removeDesc: false,
					removeTitle: false,

					// Without a viewBox the mark cannot scale, and the
					// navigation card renders it at a fixed height.
					removeViewBox: false,

					// MediaWiki-specific, and the inverse of SVGO's default.
					// libmagic keys its SVG detection on the leading XML
					// declaration, so dropping that instruction causes the file
					// to be typed `text/plain` instead of `image/svg+xml`. The
					// misdetection carries into MediaWiki's CSSMin minifier and
					// quietly stops the asset being handled as an image, with
					// no diagnostic to explain it.
					removeXMLProcInst: false
				}
			}
		},

		// A bitmap embedded in a nominally vector logo defeats the purpose and
		// inflates the asset payload, so discard any that are present.
		'removeRasterImages',

		// Emit attributes in a fixed order. Stable ordering is what keeps the
		// output byte-comparable between runs, which the committed captures and
		// their two-run comparison depend on.
		'sortAttrs'
	]
};
