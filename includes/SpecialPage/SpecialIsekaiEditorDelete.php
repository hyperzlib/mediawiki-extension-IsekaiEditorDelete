<?php

namespace Isekai\EditorDelete\SpecialPage;

use Isekai\EditorDelete\Hooks\MainHooks;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\DeletePage;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use OOUI\ButtonInputWidget;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\HorizontalLayout;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use OOUI\PanelLayout;
use OOUI\TextInputWidget;
use OOUI\Widget;
use SpecialPage;

class SpecialIsekaiEditorDelete extends SpecialPage {

	public function __construct() {
		parent::__construct( 'IsekaiEditorDelete', '', false );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $par ) {
		$this->useTransactionalTimeLimit();
		$this->setHeaders();

		$this->getOutput()->enableOOUI();
		$this->outputHeader();

		$title = $this->getTargetTitle( $par );
		if ( !$title ) {
			$this->getOutput()->addWikiMsg( 'isekai-editor-delete-no-target' );
			return;
		}
		if ( !$title->canExist() ) {
			$this->getOutput()->addWikiMsg( 'isekai-editor-delete-invalid-target' );
			return;
		}
		if ( !$title->exists() ) {
			$this->getOutput()->addWikiMsg( 'isekai-editor-delete-missing-page' );
			return;
		}

		$this->getOutput()->setPageTitleMsg(
			$this->msg( 'isekai-editor-delete-special-title', $title->getPrefixedText() )
		);
		$this->getSkin()->setRelevantTitle( $title );

		$status = $this->checkEditorDeletePermission( $title );
		if ( !$status->isOK() ) {
			$this->getOutput()->addWikiMsg( 'isekai-editor-delete-permission-denied' );
			return;
		}

		if ( $this->getRequest()->wasPosted() ) {
			$this->submitDelete( $title );
			return;
		}
		$this->showConfirmForm( $title );
	}

	private function getTargetTitle( $par ): ?Title {
		if ( $par !== null && $par !== '' ) {
			return Title::newFromText( $par );
		}
		$target = $this->getRequest()->getVal( 'target' );
		if ( $target === null || $target === '' ) {
			return null;
		}
		return Title::newFromText( $target );
	}

	private function checkEditorDeletePermission( Title $title ): PermissionStatus {
		$status = PermissionStatus::newEmpty();

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		
		if ( $permissionManager->userCan( 'delete', $this->getUser(), $title ) ) {
			// 如果已有全局删除权限则直接允许
			return $status;
		}

		try {
			$permissionManager = MediaWikiServices::getInstance()
				->getService( 'IsekaiLitePageACL.PermissionManager' );
		} catch ( \Throwable $e ) {
			$status->fatal( 'isekai-editor-delete-error-missing-lpacl' );
			return $status;
		}

		$status = $permissionManager->userHasPermission( $this->getUser(), $title, 'editor-delete' );
		if ( $status->isOK() ) {
			return $status;
		}
		if ( $title->isSubpage() ) {
			return $permissionManager->userHasParentPermissionAtTarget(
				$this->getUser(),
				$title,
				'editor-delete-subpage',
				'isekai-editor-delete-permission-denied',
				false
			);
		}
		return $status;
	}

	private function showConfirmForm( Title $title ): void {
		$services = MediaWikiServices::getInstance();
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$deletePage = $services->getDeletePageFactory()->newDeletePage( $wikiPage, $this->getAuthority() );

		$fieldsetItems = [
			new FieldLayout( new TextInputWidget( [
				'name' => 'wpReason',
				'id' => 'wpReason',
				'autofocus' => true,
				'infusable' => true,
			] ), [
				'label' => $this->msg( 'isekai-editor-delete-reason' )->text(),
				'align' => 'top',
			] ),
		];
		if ( $deletePage->canProbablyDeleteAssociatedTalk()->isGood() ) {
			$fieldsetItems[] = new FieldLayout( new CheckboxInputWidget( [
				'name' => 'wpDeleteTalk',
				'id' => 'wpDeleteTalk',
				'value' => '1',
			] ), [
				'label' => $this->msg( 'deletepage-deletetalk' )->text(),
				'align' => 'inline',
			] );
		}

		
		$fieldsetItems[] = $this->wrapByFieldLayout( new MessageWidget( [
			'type' => 'notice',
			'inline' => true,
			'label' => $this->msg( 'isekai-editor-delete-editor-history-notice' )->text()
		] ) );

		$panelContent =
			new FieldsetLayout( [
				'label' => $this->msg( 'isekai-editor-delete-special-title', $title->getPrefixedText() )->text(),
				'items' => $fieldsetItems,
			] ) .
			$this->wrapByFieldLayout( new HorizontalLayout( [
				'items' => [
					new ButtonInputWidget( [
						'name' => 'confirm',
						'type' => 'submit',
						'label' => $this->msg( 'isekai-editor-delete-submit' )->text(),
						'flags' => [ 'primary', 'destructive' ],
					] ),
					new ButtonWidget( [
						'label' => $this->msg( 'isekai-editor-delete-cancel' )->text(),
						'href' => $title->getLocalURL(),
					] ),
				],
			] ) );

		$content = (string)new PanelLayout( [
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'content' => new HtmlSnippet( $panelContent ),
		] );

		$this->getOutput()->addHTML(
			$this->renderForm(
				$title,
				$content,
				[
					'wpConfirmationRevId' => (string)$wikiPage->getLatest(),
				]
			)
		);
	}

	private function renderForm( Title $title, string $content, array $hiddenFields = [] ): string {
		$hidden = Html::hidden(
			'wpEditToken',
			$this->getUser()->getEditToken( [ 'delete', $title->getPrefixedText() ] )
		);
		foreach ( $hiddenFields as $name => $value ) {
			$hidden .= Html::hidden( $name, $value );
		}
		return Html::rawElement(
			'form',
			[
				'method' => 'post',
				'action' => $this->getPageTitle( $title->getPrefixedText() )->getLocalURL(),
				'class' => 'ext-isekai-editor-delete-form',
			],
			$hidden . $content
		);
	}

	private function wrapByFieldLayout( $html, array $fieldLayoutOpts = [ 'align' => 'top' ] ) {
		if ( is_string( $html ) ) {
			$html = new HtmlSnippet( $html );
		}
		return new FieldLayout( new Widget( [
			'content' => $html,
		] ), $fieldLayoutOpts );
	}

	private function submitDelete( Title $title ): void {
		$request = $this->getRequest();
		
		if ( !$this->getContext()->getCsrfTokenSet()->matchToken(
			$request->getVal( 'wpEditToken' ),
			[ 'delete', $title->getPrefixedText() ]
		) ) {
			$this->getOutput()->addWikiMsg( 'sessionfailure' );
			$this->showConfirmForm( $title );
			return;
		}

		$services = MediaWikiServices::getInstance();
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$confirmationRevId = $request->getIntOrNull( 'wpConfirmationRevId' );
		if ( $confirmationRevId !== null && $wikiPage->getLatest() !== $confirmationRevId ) {
			$this->getOutput()->addWikiMsg( 'isekai-editor-delete-page-changed' );
			$this->showConfirmForm( $title );
			return;
		}

		$deletePage = $services->getDeletePageFactory()->newDeletePage( $wikiPage, $this->getAuthority() );
		$shouldDeleteTalk = $request->getCheck( 'wpDeleteTalk' ) &&
			$deletePage->canProbablyDeleteAssociatedTalk()->isGood();
		$deletePage->setDeleteAssociatedTalk( $shouldDeleteTalk );
		$status = $deletePage
			->setTags( [ MainHooks::TAG ] )
			->deleteIfAllowed( $request->getText( 'wpReason' ) );

		if ( !$status->isOK() ) {
			$statusFormatter = $services->getFormatterFactory()
				->getStatusFormatter( $this->getContext() );
			$this->getOutput()->addWikiTextAsInterface(
				$statusFormatter->getWikiText( $status, [
					'lang' => $this->getLanguage() 
				] )
			);
			$this->showConfirmForm( $title );
			return;
		}

		$this->getOutput()->setPageTitleMsg( $this->msg( 'actioncomplete' ) );
		$this->getOutput()->addWikiMsg( 'isekai-editor-delete-action-complete' );
		$logIds = $deletePage->getSuccessfulDeletionsIDs();
		$logId = $logIds[DeletePage::PAGE_BASE] ?? null;
		if ( $logId ) {
			$logTitle = SpecialPage::getTitleFor( 'Log', 'delete' );
			$this->getOutput()->addHTML(
				Html::rawElement( 'p', [], $this->getLinkRenderer()->makeKnownLink(
					$logTitle,
					$this->msg( 'deletionlog' )->text(),
					[],
					[ 'page' => $title->getPrefixedText() ]
				) )
			);
		}
	}
}
