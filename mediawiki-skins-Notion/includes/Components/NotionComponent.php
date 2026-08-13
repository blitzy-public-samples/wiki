<?php

namespace MediaWiki\Skins\Notion\Components;

/**
 * Component interface for managing Notion-modified components
 *
 * @internal
 */
interface NotionComponent {
	/**
	 * @return array of Mustache compatible data
	 */
	public function getTemplateData(): array;
}
