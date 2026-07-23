<?php

namespace Isekai\EditorDelete\Service;

use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\ILoadBalancer;

class EditorDeleteStore {

	private ILoadBalancer $loadBalancer;
	private ActorNormalization $actorNormalization;

	public function __construct(
		ILoadBalancer $loadBalancer,
		ActorNormalization $actorNormalization
	) {
		$this->loadBalancer = $loadBalancer;
		$this->actorNormalization = $actorNormalization;
	}

	public function recordDeletion(
		PageIdentity $page,
		Authority $deleter,
		int $pageId,
		?int $logId
	): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$actorId = $this->actorNormalization->acquireActorId( $deleter->getUser(), $dbw );
		$dbw->insert(
			'isekai_editor_delete_page',
			[
				'page_id' => $pageId,
				'page_namespace' => $page->getNamespace(),
				'page_title' => $page->getDBkey(),
				'log_id' => $logId,
				'deleter_actor_id' => $actorId,
				'deleted_at' => $dbw->timestamp(),
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
	}

	public function hasEditorDeleteRecord( PageIdentity $page ): bool {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		return (bool)$dbr->selectField(
			'isekai_editor_delete_page',
			'1',
			[
				'page_namespace' => $page->getNamespace(),
				'page_title' => $page->getDBkey(),
			],
			__METHOD__
		);
	}

	public function userWasArchivedEditor( UserIdentity $user, PageIdentity $page ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$actorId = $this->actorNormalization->findActorId( $user, $dbr );
		if ( !$actorId ) {
			return false;
		}

		$recordRows = $dbr->select(
			'isekai_editor_delete_page',
			[ 'page_id' ],
			[
				'page_namespace' => $page->getNamespace(),
				'page_title' => $page->getDBkey(),
			],
			__METHOD__
		);
		$pageIds = [];
		foreach ( $recordRows as $row ) {
			$pageIds[] = (int)$row->page_id;
		}
		if ( !$pageIds ) {
			return false;
		}

		return (bool)$dbr->selectField(
			'archive',
			'1',
			[
				'ar_page_id' => $pageIds,
				'ar_actor' => $actorId,
			],
			__METHOD__
		);
	}
}
