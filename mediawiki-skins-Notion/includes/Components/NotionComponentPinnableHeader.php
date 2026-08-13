<?php

namespace MediaWiki\Skins\Notion\Components;

use MediaWiki\Language\MessageLocalizer;

/**
 * NotionComponentPinnableHeader component
 */
class NotionComponentPinnableHeader implements NotionComponent {
	/**
	 * @param MessageLocalizer $localizer Resolves this header's interface messages.
	 * @param bool $pinned Whether the pinnable element is currently pinned. Both the pin and
	 * the unpin affordance are always described in the template data, so the header remains
	 * usable without JavaScript; this flag only selects which one the template presents.
	 * @param string $id Pinnable element id, by convention this should include the `notion-`
	 * prefix e.g. `notion-page-tools` or `notion-toc`. The human readable label is resolved
	 * from this id by appending `-label`, so a matching `<id>-label` message must exist in
	 * i18n/en.json for every pinnable region.
	 * @param string $featureName Pinned and unpinned states will
	 * persist for logged-in users by leveraging features.js to manage the user
	 * preference storage and the toggling of the body class. This name should NOT
	 * contain the "notion-" prefix.
	 * @param string $unpinAriaLabel i18n message key for the aria-label on the unpin (hide) button.
	 * @param string $pinAriaLabel i18n message key for the aria-label on the pin (move to sidebar) button.
	 * @param string|null $labelTagName Element type of the label. Either a 'div' or a 'h2'
	 *   in the case of the pinnable ToC.
	 */
	public function __construct(
		private readonly MessageLocalizer $localizer,
		private readonly bool $pinned,
		private readonly string $id,
		private readonly string $featureName,
		private readonly string $unpinAriaLabel,
		private readonly string $pinAriaLabel,
		private readonly ?string $labelTagName = 'div',
	) {
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
