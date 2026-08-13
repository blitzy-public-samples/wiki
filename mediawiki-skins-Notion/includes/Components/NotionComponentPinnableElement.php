<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * NotionComponentPinnableElement component
 *
 * Wraps the content of a pinnable region so that PinnableElement/Open.mustache can emit the one
 * element that both the pinned and the unpinned CSS states target. That template renders the id
 * twice, as the HTML id attribute and as a class, which is what keeps a pinnable region correctly
 * styled before any script runs and lets the pinning enhancement locate it afterwards.
 *
 * The identifier is injected rather than derived here. Appearance, MainMenu, PageTools and
 * TableOfContents each construct this component with their own `self::ID`, and by convention that
 * value carries the `notion-` prefix. The same string is reused beyond this class:
 * NotionComponentPinnableHeader appends `-unpinned-container` and `-pinned-container` to it, and
 * the `notion-*-label` and `notion-*-unpinned-popup` message keys are named after it. Deriving it
 * locally would fork that shared convention, so the callers own it.
 *
 * Kept deliberately separate from NotionComponentPinnableContainer: the element is the pinnable
 * content wrapper and carries only an id, while the container is the mount point and additionally
 * reports whether it is pinned. Callers union the two with PHP's left-biased `+` operator and put
 * this component first, so the id emitted here wins. Any extra key added below would leak into all
 * four composites, so the returned array stays at exactly one entry.
 */
class NotionComponentPinnableElement implements NotionComponent {
	/**
	 * @param string $id Pinnable element id, by convention this should include the `notion-`
	 * prefix e.g. `notion-page-tools` or `notion-toc`.
	 */
	public function __construct(
		private readonly string $id,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData(): array {
		return [
			'id' => $this->id,
		];
	}
}
