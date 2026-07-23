<?php

namespace Isekai\EditorDelete\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class UpdaterHooks implements LoadExtensionSchemaUpdatesHook {

	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( dirname( __DIR__ ) ) . '/sql/';
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'isekai_editor_delete_page',
			$dir . $type . '/isekai_editor_delete_page.sql'
		);
	}
}
