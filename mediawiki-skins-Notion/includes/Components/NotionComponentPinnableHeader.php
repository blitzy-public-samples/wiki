<?php

namespace MediaWiki\Skins\Notion\Components;

use InvalidArgumentException;
use MediaWiki\Language\MessageLocalizer;

/**
 * NotionComponentPinnableHeader component
 */
class NotionComponentPinnableHeader implements NotionComponent {

	/**
	 * The element names the region label may be rendered as.
	 *
	 * A pinnable region's label is a plain `div` -- it is a control's caption rather than a
	 * document heading -- except for the table of contents, whose label is an `h2` so the
	 * outline stays reachable through a screen reader's heading list while it is pinned beside
	 * the article. Nothing else is a legitimate label element here, and because the value
	 * reaches a tag-name position in the template, "nothing else" has to be enforced rather
	 * than merely documented.
	 */
	private const PERMITTED_LABEL_TAG_NAMES = [ 'div', 'h2' ];

	/**
	 * @param MessageLocalizer $localizer Resolves this header's interface messages.
	 * @param bool $pinned Whether the pinnable element is currently pinned. Both the pin and the
	 * unpin affordance are always described in the template data, and this flag only selects which
	 * one the stylesheet reveals. What that buys without JavaScript is a header that renders
	 * completely and reads correctly in whichever placement the server chose -- the region is where
	 * it says it is, and no control is missing or half-drawn. It does not make the header
	 * interactive: the two affordances are bare `<button>` elements with no form and no href, so
	 * they do nothing until the client script binds them. Moving a region between placements is a
	 * progressive enhancement.
	 * @param string $id Pinnable element id, by convention this should include the `notion-`
	 * prefix e.g. `notion-page-tools` or `notion-toc`. The human readable label is resolved
	 * from this id by appending `-label`, so a matching `<id>-label` message must exist in
	 * i18n/en.json for every pinnable region.
	 * @param string $featureName The registered feature whose pinned state this header toggles.
	 * `features.js` stores the new value and rewrites the corresponding class on the document
	 * element — `document.documentElement.classList`, not `<body>`. How far the state persists is
	 * decided by the feature, not by this component: the table of contents and the appearance panel
	 * are client preferences and so are remembered for anonymous readers too, while the main menu
	 * and the page tools are rendered with the `-enabled`/`-disabled` pair and persist for
	 * logged-in users only. See `FeatureManager::getFeatureBodyClass()` for the two spellings.
	 * This name should NOT contain the "notion-" prefix.
	 * @param string $unpinAriaLabel i18n message key for the aria-label on the unpin (hide) button.
	 * @param string $pinAriaLabel i18n message key for the aria-label on the pin (move to sidebar) button.
	 * @param string $labelTagName Element type of the label. Either a 'div' or a 'h2'
	 *   in the case of the pinnable ToC. Those two are the only permitted values, the parameter is
	 *   deliberately NOT nullable, and the constructor rejects anything else, because
	 *   `PinnableHeader.mustache` interpolates this value into BOTH an opening and a closing tag
	 *   position: a null would emit the malformed pair `<></>` rather than fall back to anything,
	 *   and a value carrying whitespace or attribute syntax would escape the tag context altogether
	 *   and inject markup. Every caller passes one of the two documented values, and anything else
	 *   now fails at construction, where it is diagnosable, instead of in the rendered markup.
	 *   Validating here rather than in the template is what keeps the template a plain
	 *   interpolation and leaves exactly one place to audit.
	 * @throws InvalidArgumentException If $labelTagName is neither 'div' nor 'h2'.
	 */
	public function __construct(
		private readonly MessageLocalizer $localizer,
		private readonly bool $pinned,
		private readonly string $id,
		private readonly string $featureName,
		private readonly string $unpinAriaLabel,
		private readonly string $pinAriaLabel,
		private readonly string $labelTagName = 'div',
	) {
		if ( !in_array( $labelTagName, self::PERMITTED_LABEL_TAG_NAMES, true ) ) {
			throw new InvalidArgumentException(
				'$labelTagName must be one of ' .
					implode( ', ', self::PERMITTED_LABEL_TAG_NAMES ) .
					", got '$labelTagName'."
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		$messageLocalizer = $this->localizer;
		$data = [
			'is-pinned' => $this->pinned,
			// The label message key is derived from the element id, which is what binds each
			// pinnable region to its own `notion-<region>-label` message.
			'label' => $messageLocalizer->msg( $this->id . '-label' )->text(),
			'label-tag-name' => $this->labelTagName,
			'pin-label' => $messageLocalizer->msg( 'notion-pin-element-label' )->text(),
			'unpin-label' => $messageLocalizer->msg( 'notion-unpin-element-label' )->text(),
			// The resolved label is passed as the $1 parameter of both aria-label messages so
			// that assistive technology announces e.g. "Move Tools to sidebar" and "Hide Tools"
			// rather than the bare button text.
			'pin-aria-label' => $messageLocalizer->msg(
				$this->pinAriaLabel,
				$messageLocalizer->msg( $this->id . '-label' )->text()
			)->text(),
			'unpin-aria-label' => $messageLocalizer->msg(
				$this->unpinAriaLabel,
				$messageLocalizer->msg( $this->id . '-label' )->text()
			)->text(),
			'data-pinnable-element-id' => $this->id,
			'data-feature-name' => $this->featureName,
			// Assumes consistent naming standard for pinnable elements and their containers
			'data-unpinned-container-id' => $this->id . '-unpinned-container',
			'data-pinned-container-id' => $this->id . '-pinned-container'
		];
		return $data;
	}
}
