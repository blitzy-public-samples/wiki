<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentPinnableContainer component
 * To be used with the PinnableContainer/Pinned or PinnableContainer/Unpinned templates.
 */
class NotionComponentPinnableContainer implements NotionComponent {
	/**
	 * @param string $id Pinnable element id, by convention this should include the `notion-`
	 *   prefix e.g. `notion-page-tools` or `notion-toc`. The paired templates derive the
	 *   rendered container id from it, as `<id>-pinned-container` in the pinned position and
	 *   `<id>-unpinned-container` in the unpinned one, so callers pass the id of the element
	 *   being wrapped rather than a container id of their own.
	 * @param bool $isPinned Whether the pinnable element is currently pinned, defaulting to
	 *   pinned. Templates branch on this flag alone to render one position or the other,
	 *   which is what keeps both states reachable with JavaScript disabled: the stylesheets
	 *   lay out the pinned and the unpinned position, and the client-side script only moves
	 *   the element between markup that is already styled.
	 */
	public function __construct(
		private readonly string $id,
		private readonly bool $isPinned = true,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		return [
			'id' => $this->id,
			'is-pinned' => $this->isPinned,
		];
	}
}
