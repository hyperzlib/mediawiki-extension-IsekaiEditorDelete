<?php

use Isekai\EditorDelete\Service\EditorDeleteStore;
use MediaWiki\MediaWikiServices;

return [
	'IsekaiEditorDelete.Store' => static function ( MediaWikiServices $services ) {
		return new EditorDeleteStore(
			$services->getDBLoadBalancer(),
			$services->getActorNormalization(),
			$services->getMainWANObjectCache()
		);
	},
];
