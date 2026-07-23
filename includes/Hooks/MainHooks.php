<?php

namespace Isekai\EditorDelete\Hooks;

use Isekai\EditorDelete\Service\EditorDeleteStore;
use ManualLogEntry;
use MediaWiki\Actions\ActionFactory;
use MediaWiki\ChangeTags\Hook\ChangeTagsAllowedAddHook;
use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\UserGetRightsHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\User;
use Skin;
use SkinTemplate;
use SpecialPage;
use Wikimedia\Rdbms\ILoadBalancer;

class MainHooks implements
	PageDeleteCompleteHook,
	SidebarBeforeOutputHook,
	SkinTemplateNavigation__UniversalHook,
	UserGetRightsHook,
	ChangeTagsAllowedAddHook,
	ChangeTagsListActiveHook
{
	public const TAG = 'editor-delete';

	private EditorDeleteStore $store;
	private ILoadBalancer $loadBalancer;
	private ActorNormalization $actorNormalization;
	private ActionFactory $actionFactory;
	private TitleFactory $titleFactory;

	public function __construct(
		EditorDeleteStore $store,
		ILoadBalancer $loadBalancer,
		ActorNormalization $actorNormalization,
		ActionFactory $actionFactory,
		TitleFactory $titleFactory
	) {
		$this->store = $store;
		$this->loadBalancer = $loadBalancer;
		$this->actorNormalization = $actorNormalization;
		$this->actionFactory = $actionFactory;
		$this->titleFactory = $titleFactory;
	}

	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		if ( !in_array( self::TAG, $logEntry->getTags(), true ) ) {
			return;
		}
		$this->store->recordDeletion( $page, $deleter, $pageID, null );
	}

	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		
	}

	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$title = $sktemplate->getTitle();
		if ( !$title || !$title->canExist() || !$title->exists() ) {
			return;
		}
		if ( !$this->userCanEditorDelete( $sktemplate->getUser(), $title ) && !$sktemplate->getAuthority()->probablyCan( 'delete', $title ) ) {
			return;
		}

		$hasCoreDelete = $sktemplate->getAuthority()->probablyCan( 'delete', $title );
		$links['actions']['isekai-editor-delete'] = [
			'icon' => 'trash',
			'text' => $sktemplate->msg(
				$hasCoreDelete ? 'isekai-editor-delete-toolbox-editor-delete' : 'isekai-editor-delete-toolbox-delete'
			)->text(),
			'href' => $this->getSpecialTitle( $title )->getLocalURL(),
		];
	}

	public function onUserGetRights( $user, &$rights ) {
		if ( $this->isEditorDeleteSubmitRequest() ) {
			$rights[] = 'delete';
			$rights[] = 'applychangetags';
		}

		$title = $this->getUndeleteTargetFromRequest();
		
		if ( !$title ) {
			return;
		}
		
		if (
			$this->store->hasEditorDeleteRecord( $title ) &&
			$this->store->userWasArchivedEditor( $user, $title )
		) {
			$rights[] = 'deletedhistory';
			$rights[] = 'deletedtext';
		}
	}

	public function onChangeTagsAllowedAdd( &$allowedTags, $addTags, $user ) {
		if ( in_array( self::TAG, $addTags, true ) && $this->isEditorDeleteSubmitRequest() ) {
			$allowedTags[] = self::TAG;
		}
	}

	public function onChangeTagsListActive( &$tags ) {
		$tags[] = self::TAG;
	}

	private function userCanEditorDelete( User $user, Title $title ): bool {
		try {
			$services = MediaWikiServices::getInstance();
			/** @var \Isekai\LitePageACL\Service\PageAclPermissionManager */
			$permissionManager = $services->getService( 'IsekaiLitePageACL.PermissionManager' );
		} catch ( \Throwable $e ) {
			return false;
		}
		$status = $permissionManager->userHasPermissionCascading(
			$user,
			$title,
			'editor-delete',
			'editor-delete-subpage',
			'isekai-editor-delete-permission-denied',
			true
		);

		return $status->isOK();
	}

	private function getSpecialTitle( Title $title ): Title {
		return SpecialPage::getTitleFor( 'IsekaiEditorDelete', $title->getPrefixedText() );
	}

	private function isEditorDeleteSubmitRequest(): bool {
		$context = \RequestContext::getMain();
		$title = $context->getTitle();
		return $context->getRequest()->wasPosted() &&
			$title &&
			$title->isSpecial( 'IsekaiEditorDelete' );
	}

	private function getUndeleteTargetFromRequest(): ?Title {
		$context = \RequestContext::getMain();
		$title = $context->getTitle();

		if ( !$title ) {
			return null;
		}
		
		$request = $context->getRequest();

		if ( $title->isSpecial( 'Undelete' ) ) {
			$target = $request->getVal( 'target' );
			if ( $target === null || $target === '' ) {
				$parts = explode( '/', $title->getDBkey(), 2 );
				$target = $parts[1] ?? '';
			}
			if ( $target === '' ) {
				return null;
			}
			return $this->titleFactory->newFromText( $target );
		}

		$actionName = $request->getRawVal( 'action' ) ?? 'view';
		if ( $actionName === 'view' ) {
			return $title;
		}

		return null;
	}
}
