<?php

namespace Isekai\EditorDelete\Service;

use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class EditorDeleteStore {

	private const CACHE_TTL = 3600;
	private const CACHE_VERSION = 'v1';

	private ILoadBalancer $loadBalancer;
	private ActorNormalization $actorNormalization;
	private WANObjectCache $cache;

	public function __construct(
		ILoadBalancer $loadBalancer,
		ActorNormalization $actorNormalization,
		WANObjectCache $cache
	) {
		$this->loadBalancer = $loadBalancer;
		$this->actorNormalization = $actorNormalization;
		$this->cache = $cache;
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

		$this->cache->delete( $this->getHasRecordCacheKey( $page ) );
	}

	public function hasEditorDeleteRecord( PageIdentity $page ): bool {
		return $this->cache->getWithSetCallback(
			$this->getHasRecordCacheKey( $page ),
			self::CACHE_TTL,
			function () use ( $page ): bool {
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
		);
	}

	public function userWasArchivedEditor( UserIdentity $user, PageIdentity $page ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		return $this->cache->getWithSetCallback(
			$this->getWasArchivedEditorCacheKey( $user, $page ),
			self::CACHE_TTL,
			function () use ( $user, $page ): bool {
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
		);
	}

	private function getHasRecordCacheKey( PageIdentity $page ): string {
		return $this->cache->makeKey(
			'isekai-editor-delete',
			'has-record',
			self::CACHE_VERSION,
			$page->getNamespace(),
			$page->getDBkey()
		);
	}

	private function getWasArchivedEditorCacheKey( UserIdentity $user, PageIdentity $page ): string {
		return $this->cache->makeKey(
			'isekai-editor-delete',
			'was-archived-editor',
			self::CACHE_VERSION,
			$user->getId(),
			$page->getNamespace(),
			$page->getDBkey()
		);
	}
}
