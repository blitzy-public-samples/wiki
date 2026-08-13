<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Linker\Linker;

/**
 * NotionComponentLink component
 *
 * Builds one link of skin chrome from its parts, for the places where the skin invents a link
 * rather than being handed one: a link the skin composes itself has no portlet record behind it.
 *
 * It emits the skin's CANONICAL link shape, which is core's own portlet-link record: `text`,
 * `icon` and `array-attributes`, the last being a list of `{ key, value }` records covering every
 * attribute of the anchor, `href` included. That shape is the one `Link.mustache` reads, and it is
 * the one `MediaWiki\Skin\Components\SkinComponentLink::makeLink()` delivers inside
 * `data-portlets`, so a link this class builds and a link core built are interchangeable at the
 * template. Keeping a single shape is the whole point: `NotionComponentMenu` passes core's records
 * through untouched, and if this class emitted a different vocabulary -- a pre-serialised attribute
 * string beside a separate `href`, say -- then whichever of the two the template happened to be
 * written against would silently drop the other's attributes, taking navigation with it when the
 * dropped one is `href`.
 *
 * Attribute order is deliberate and stable: `href` first, then the tooltip and access-key
 * attributes core's `Linker` derives, then the accessible name. It is stable because the emitted
 * data is snapshotted in tests, and readable because `href` is the attribute a person scanning
 * rendered markup looks for first.
 *
 * There is no call site in today's rendering path: the links a page renders are core's own
 * `SkinComponentLink` records, and no template includes `>Link` while `MenuListItem.mustache` and
 * `MenuContents.mustache` are still to be written. The class is kept for parity with the reference
 * skin's component set and is exercised through `NotionComponentMenuListItem` and
 * `NotionComponentLinkTest`; emitting core's canonical shape is what makes it usable the moment
 * those partials land, rather than something to be reconciled then.
 */
class NotionComponentLink implements NotionComponent {
	/**
	 * @param string $href Target of the link, emitted as the first attribute record.
	 * @param string $text Visible label, escaped by the template.
	 * @param null|string $icon Icon NAME from the skin's icon pack, or null for a label-only link.
	 * @param null|MessageLocalizer $localizer for generation of tooltip and access keys. Without
	 *   it no tooltip, access key or accessible name is derived, which is the right outcome for a
	 *   link that has no message behind it rather than a degraded one.
	 * @param null|string $accessKeyHint will be used to derive HTML attributes such as title, accesskey
	 *   and aria-label ("$accessKeyHint-label")
	 */
	public function __construct(
		private readonly string $href,
		private readonly string $text,
		private readonly ?string $icon = null,
		private readonly ?MessageLocalizer $localizer = null,
		private readonly ?string $accessKeyHint = null,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		$localizer = $this->localizer;
		$accessKeyHint = $this->accessKeyHint;
		$attributes = [ 'href' => $this->href ];
		if ( $localizer && $accessKeyHint ) {
			$attributes += Linker::tooltipAndAccesskeyAttribs(
				$accessKeyHint,
				[],
				[],
				$localizer
			);
			$msg = $localizer->msg( $accessKeyHint . '-label' );
			if ( $msg->exists() ) {
				$attributes['aria-label'] = $msg->text();
			}
		}

		return [
			'icon' => $this->icon,
			'text' => $this->text,
			// The map is inverted into the list of records the template expands, and null-valued
			// attributes are dropped: `Linker::titleAttrib()` and `accesskey()` return null when
			// the message behind them is absent or empty, and an attribute rendered as the literal
			// text "null" would be worse than one left out.
			'array-attributes' => array_values( array_map(
				static function ( $key, $value ) {
					return [ 'key' => $key, 'value' => (string)$value ];
				},
				array_keys( array_filter( $attributes, static fn ( $value ) => $value !== null ) ),
				array_filter( $attributes, static fn ( $value ) => $value !== null )
			) ),
		];
	}
}
