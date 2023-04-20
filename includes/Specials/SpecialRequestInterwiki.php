<?php

namespace Miraheze\RequestInterwiki\Specials;

use EchoEvent;
use ErrorPageError;
use ExtensionRegistry;
use FileRepo;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\User\UserFactory;
use Message;
use MimeAnalyzer;
use Miraheze\CreateWiki\RemoteWiki;
use PermissionsError;
use RepoGroup;
use SpecialPage;
use Status;
use UploadBase;
use UploadFromUrl;
use UploadStash;
use User;
use UserBlockedError;
use UserNotLoggedIn;
use WikiMap;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestInterwiki extends FormSpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var MimeAnalyzer */
	private $mimeAnalyzer;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param MimeAnalyzer $mimeAnalyzer
	 * @param RepoGroup $repoGroup
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		MimeAnalyzer $mimeAnalyzer,
		RepoGroup $repoGroup,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestInterwiki', 'request-import-dump' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->repoGroup = $repoGroup;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setParameter( $par );
		$this->setHeaders();

		if (
			$this->getConfig()->get( 'RequestInterwikiCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->getConfig()->get( 'RequestInterwikiCentralWiki' ) )
		) {
			throw new ErrorPageError( 'requestinterwiki-notcentral', 'requestinterwiki-notcentral-text' );
		}

		if ( !$this->getUser()->isRegistered() ) {
			$loginURL = SpecialPage::getTitleFor( 'Userlogin' )
				->getFullURL( [
					'returnto' => $this->getPageTitle()->getPrefixedText(),
				]
			);

			throw new UserNotLoggedIn( 'requestinterwiki-notloggedin', 'exception-nologin', [ $loginURL ] );
		}

		$this->checkPermissions();

		if ( $this->getConfig()->get( 'RequestInterwikiHelpUrl' ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( 'RequestInterwikiHelpUrl' ), true );
		}

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		$formDescriptor = [
			'source' => [
				'type' => 'url',
				'label-message' => 'requestinterwiki-label-source',
				'help-message' => 'requestinterwiki-help-source',
				'required' => true,
			],
			'target' => [
				'type' => 'text',
				'label-message' => 'requestinterwiki-label-target',
				'help-message' => 'requestinterwiki-help-target',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
			'interwiki-additions' => [
				'type' => 'cloner',
				'label-message' => 'requestinterwiki-label-additions',
				'fields' => [
						'value' => [
							'type' => 'text',
						],
						'delete' => [
							'type' => 'submit',
							'default' => wfMessage( 'htmlform-cloner-delete' )->escaped(),
							'flags' => [ 'destructive' ],
						],
					],
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'label-message' => 'requestinterwiki-label-reason',
				'help-message' => 'requestinterwiki-help-reason',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			],
		];

		return $formDescriptor;
	}

	/**
	 * @param array $data
	 * @return Status
	 */
	public function onSubmit( array $data ) {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();

		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if (
			$this->getUser()->pingLimiter( 'request-import-dump' ) ||
			UploadBase::isThrottled( $this->getUser() )
		) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$centralWiki = $this->getConfig()->get( 'RequestInterwikiCentralWiki' );
		if ( $centralWiki ) {
			$dbw = $this->dbLoadBalancerFactory->getMainLB(
				$centralWiki
			)->getConnection( DB_PRIMARY, [], $centralWiki );
		} else {
			$dbw = $this->dbLoadBalancerFactory->getMainLB()->getConnection( DB_PRIMARY );
		}

		$duplicate = $dbw->newSelectQueryBuilder()
			->table( 'requestinterwiki_requests' )
			->field( '*' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'requestinterwiki-duplicate-request' );
		}

		$timestamp = $dbw->timestamp();
		$fileName = $data['target'] . '-' . $timestamp . '.xml';

		$request = $this->getRequest();
		$request->setVal( 'wpDestFile', $fileName );

		$uploadBase = UploadBase::createFromRequest( $request, $data['UploadSourceType'] ?? 'File' );

		if ( !$uploadBase->isEnabled() ) {
			return Status::newFatal( 'uploaddisabled' );
		}

		$permission = $uploadBase->isAllowed( $this->getUser() );
		if ( $permission !== true ) {
			return User::newFatalPermissionDeniedStatus( $permission );
		}

		$status = $uploadBase->fetchFile();
		if ( !$status->isOK() ) {
			return $status;
		}

		$virus = UploadBase::detectVirus( $uploadBase->getTempPath() );
		if ( $virus ) {
			return Status::newFatal( 'uploadvirus', $virus );
		}

		$mime = $this->mimeAnalyzer->guessMimeType( $uploadBase->getTempPath() );
		if ( $mime !== 'application/xml' && $mime !== 'text/xml' ) {
			return Status::newFatal( 'filetype-mime-mismatch', 'xml', $mime );
		}

		$mimeExt = $this->mimeAnalyzer->getExtensionFromMimeTypeOrNull( $mime );
		if ( $mimeExt !== 'xml' ) {
			return Status::newFatal(
				'filetype-banned-type', $mimeExt ?? 'unknown', 'xml', 1, 1
			);
		}

		$status = $uploadBase->tryStashFile( $this->getUser() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$repo = $this->repoGroup->getLocalRepo();
		$uploadStash = new UploadStash( $repo, $this->getUser() );

		$fileKey = $status->getStatusValue()->getValue()->getFileKey();
		$file = $uploadStash->getFile( $fileKey );

		$status = $repo->publish(
			$file->getPath(),
			'/RequestInterwiki/' . $fileName,
			'/RequestInterwiki/archive/' . $fileName,
			FileRepo::DELETE_SOURCE
		);

		if ( !$status->isOK() ) {
			return $status;
		}

		$dbw->insert(
			'requestinterwiki_requests',
			[
				'request_source' => $data['source'],
				'request_target' => $data['target'],
				'request_reason' => $data['reason'],
				'request_status' => 'pending',
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestRequestInterwikiQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#{$requestID}" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'requestinterwiki-success' )->rawParams( $requestLink )->escaped()
			)
		);

		$logEntry = new ManualLogEntry( $this->getLogType( $data['target'] ), 'request' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $data['target'],
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) &&
			$this->getConfig()->get( 'RequestInterwikiUsersNotifiedOnAllRequests' )
		) {
			$this->sendNotifications( $data['reason'], $this->getUser()->getName(), $requestID, $data['target'] );
		}

		return Status::newGood();
	}

	/**
	 * @param string $target
	 * @return string
	 */
	public function getLogType( string $target ): string {
		if (
			!ExtensionRegistry::getInstance()->isLoaded( 'CreateWiki' ) ||
			!$this->getConfig()->get( 'CreateWikiUsePrivateWikis' )
		) {
			return 'requestinterwiki';
		}

		$remoteWiki = new RemoteWiki( $target );
		return $remoteWiki->isPrivate() ? 'requestinterwikiprivate' : 'requestinterwiki';
	}

	/**
	 * @param string $reason
	 * @param string $requester
	 * @param string $requestID
	 * @param string $target
	 */
	public function sendNotifications( string $reason, string $requester, string $requestID, string $target ) {
		$notifiedUsers = array_filter(
			array_map(
				function ( string $userName ): ?User {
					return $this->userFactory->newFromName( $userName );
				}, $this->getConfig()->get( 'RequestInterwikiUsersNotifiedOnAllRequests' )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestRequestInterwikiQueue', $requestID )->getFullURL();

		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-import-dump-requests' ) ||
				(
					$this->getLogType( $target ) === 'requestinterwikiprivate' &&
					!$receiver->isAllowed( 'view-private-import-dump-requests' )
				)
			) {
				continue;
			}

			EchoEvent::create( [
				'type' => 'requestinterwiki-new-request',
				'extra' => [
					'request-id' => $requestID,
					'request-url' => $requestLink,
					'reason' => $reason,
					'requester' => $requester,
					'target' => $target,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->getConfig()->get( 'LocalDatabases' ) ) ) {
			return Status::newFatal( 'requestinterwiki-invalid-target' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	public function checkPermissions() {
		parent::checkPermissions();

		$user = $this->getUser();
		$permissionRequired = UploadBase::isAllowed( $user );
		if ( $permissionRequired !== true ) {
			throw new PermissionsError( $permissionRequired );
		}

		$block = $user->getBlock();
		if (
			$block && (
				$user->isBlockedFromUpload() ||
				$block->appliesToRight( 'request-import-dump' )
			)
		) {
			throw new UserBlockedError( $block );
		}

		$globalBlock = $user->getGlobalBlock();
		if ( $globalBlock ) {
			throw new UserBlockedError( $globalBlock );
		}

		$this->checkReadOnly();
		if ( !UploadBase::isEnabled() ) {
			throw new ErrorPageError( 'uploaddisabled', 'uploaddisabledtext' );
		}
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
