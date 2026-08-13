'use strict';

/**
 * SVGO Configuration
 *
 * Written for the SVGO 3.x toolchain this skin pins in package.json (svgo 3.2.0). It is not
 * claimed to work on SVGO 4, which renamed and regrouped plugins; moving to 4 means revisiting
 * the overrides below rather than just bumping the dependency.
 *
 * Derived from the recommended options at:
 * https://www.mediawiki.org/wiki/Manual:Coding_conventions/SVG#Exemplified_safe_configuration
 */
module.exports = {
	plugins: [
		{
			// Set of built-in plugins enabled by default.
			name: 'preset-default',
			params: {
				overrides: {
					cleanupIds: false,
					removeDesc: false,
					removeTitle: false,
					removeViewBox: false,
					// Keep the leading XML declaration, for compatibility rather than because
					// anything in this pipeline still needs it. Two things are worth being
					// accurate about. MediaWiki's own CSSMin resolves an .svg file's MIME type
					// from its extension and only falls back to mime_content_type() for
					// extensions it does not know, so a missing declaration would not change
					// what CSSMin embeds. And current libmagic does type a bare `<svg …>` as
					// image/svg+xml, so the older behaviour of falling back to text/plain no
					// longer applies either. What remains is that older magic databases did
					// require the declaration, and that other consumers of these files still
					// reject an SVG without one, so removing it buys a handful of bytes in
					// exchange for a portability risk. The relevant magic rules live upstream in
					// the file(1) project:
					// https://github.com/file/file/blob/master/magic/Magdir/sgml
					removeXMLProcInst: false
				}
			}
		},
		'removeRasterImages',
		'sortAttrs'
	],
	// Set whitespace according to Wikimedia Coding Conventions. `indent`, `pretty` and
	// `finalNewline` each override an SVGO default (`4`, `false` and `false` respectively);
	// `eol` restates SVGO's own default and is kept explicit so the line ending is not left to
	// the platform.
	// @see https://github.com/svg/svgo/blob/v3.2.0/lib/stringifier.js for the available options.
	js2svg: {
		eol: 'lf',
		finalNewline: true,
		// Indent with tabs rather than spaces; this is the indent `pretty` applies.
		indent: '\t',
		pretty: true
	},
	multipass: true
};
