<?php

namespace Miraheze\RequestInterwiki;

use Config;
use Html;
use HTMLForm;
use IContextSource;
use Linker;
use MediaWiki\Permissions\PermissionManager;
use Status;
use User;
use UserNotLoggedIn;
use WikiMap;

class RequestInterwikiRequestViewer {

	/** @var Config */
	private $config;

	/** @var IContextSource */
	private $context;

	/** @var RequestInterwikiRequestManager */
	private $requestInterwikiRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param Config $config
	 * @param IContextSource $context
	 * @param RequestInterwikiRequestManager $requestInterwikiRequestManager
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		Config $config,
		IContextSource $context,
		RequestInterwikiRequestManager $requestInterwikiRequestManager,
		PermissionManager $permissionManager
	) {
		$this->config = $config;
		$this->context = $context;
		$this->requestInterwikiRequestManager = $requestInterwikiRequestManager;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @return array
	 */
	public function getFormDescriptor(): array {
		$user = $this->context->getUser();

		if (
			$this->requestInterwikiRequestManager->isPrivate() &&
			$user->getName() !== $this->requestInterwikiRequestManager->getRequester()->getName() &&
			!$this->permissionManager->userHasRight( $user, 'view-private-import-dump-requests' )
		) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'requestinterwiki-private' )->escaped() )
			);

			return [];
		}

		if ( $this->requestInterwikiRequestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'requestinterwiki-request-locked' )->escaped() )
			);
		}

		$formDescriptor = [
			'source' => [
				'label-message' => 'requestinterwiki-label-source',
				'type' => 'url',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestInterwikiRequestManager->getSource(),
			],
			'target' => [
				'label-message' => 'requestinterwiki-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestInterwikiRequestManager->getTarget(),
			],
			'requester' => [
				'label-message' => 'requestinterwiki-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->requestInterwikiRequestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->requestInterwikiRequestManager->getRequester()->getId(),
						$this->requestInterwikiRequestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestinterwiki-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestInterwikiRequestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'requestinterwiki-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'requestinterwiki-label-' . $this->requestInterwikiRequestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestinterwiki-label-reason',
				'default' => $this->requestInterwikiRequestManager->getReason(),
				'raw' => true,
				'cssclass' => 'requestinterwiki-infuse',
				'section' => 'details',
			],
		];

		foreach ( $this->requestInterwikiRequestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 4,
				'label-message' => [
					'requestinterwiki-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->timeanddate( $comment['timestamp'], true ),
				],
				'default' => $comment['comment'],
			];
		}

		if (
			$this->permissionManager->userHasRight( $user, 'handle-import-dump-requests' ) ||
			$user->getActorId() === $this->requestInterwikiRequestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestinterwiki-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this, 'isValidComment' ],
					'disabled' => $this->requestInterwikiRequestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'requestinterwiki-label-add-comment' )->text(),
					'section' => 'comments',
					'disabled' => $this->requestInterwikiRequestManager->isLocked(),
				],
				'edit-source' => [
					'label-message' => 'requestinterwiki-label-source',
					'type' => 'url',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestInterwikiRequestManager->getSource(),
					'disabled' => $this->requestInterwikiRequestManager->isLocked(),
				],
				'edit-target' => [
					'label-message' => 'requestinterwiki-label-target',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestInterwikiRequestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
					'disabled' => $this->requestInterwikiRequestManager->isLocked(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestinterwiki-label-reason',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestInterwikiRequestManager->getReason(),
					'validation-callback' => [ $this, 'isValidReason' ],
					'disabled' => $this->requestInterwikiRequestManager->isLocked(),
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'requestinterwiki-label-edit-request' )->text(),
					'section' => 'editing',
					'disabled' => $this->requestInterwikiRequestManager->isLocked(),
				],
			];
		}

		if ( $this->permissionManager->userHasRight( $user, 'handle-import-dump-requests' ) ) {
			$validRequest = true;
			$status = $this->requestInterwikiRequestManager->getStatus();

			if ( $this->requestInterwikiRequestManager->fileExists() ) {
				$fileInfo = $this->context->msg( 'requestinterwiki-info-command' )->plaintextParams(
					$this->requestInterwikiRequestManager->getCommand()
				)->parse();

				$fileInfo .= Html::element( 'button', [
						'type' => 'button',
						'onclick' => 'navigator.clipboard.writeText( $( \'.mw-message-box-notice code\' ).text() );',
					],
					$this->context->msg( 'requestinterwiki-button-copy' )->text()
				);

				if ( $this->requestInterwikiRequestManager->getFileSize() > 0 ) {
					$fileInfo .= Html::element( 'br' );
					$fileInfo .= $this->context->msg( 'requestinterwiki-info-filesize' )->sizeParams(
						$this->requestInterwikiRequestManager->getFileSize()
					)->parse();
				}

				$info = Html::noticeBox( $fileInfo, '' );
			} else {
				$info = Html::errorBox(
					$this->context->msg( 'requestinterwiki-info-no-file-found',
						$this->requestInterwikiRequestManager->getFilePath()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			$info .= Html::noticeBox(
				$this->context->msg( 'requestinterwiki-info-groups',
					$this->requestInterwikiRequestManager->getRequester()->getName(),
					$this->requestInterwikiRequestManager->getTarget(),
					$this->context->getLanguage()->commaList(
						$this->requestInterwikiRequestManager->getUserGroupsFromTarget()
					)
				)->escaped(),
				''
			);

			if ( $this->requestInterwikiRequestManager->isPrivate() ) {
				$info .= Html::warningBox(
					$this->context->msg( 'requestinterwiki-info-request-private' )->escaped()
				);
			}

			if ( $this->requestInterwikiRequestManager->getRequester()->getBlock() ) {
				$info .= Html::warningBox(
					$this->context->msg( 'requestinterwiki-info-requester-locally-blocked',
						$this->requestInterwikiRequestManager->getRequester()->getName(),
						WikiMap::getCurrentWikiId()
					)->escaped()
				);
			}

			if ( $this->requestInterwikiRequestManager->getRequester()->getGlobalBlock() ) {
				$info .= Html::errorBox(
					$this->context->msg( 'requestinterwiki-info-requester-globally-blocked',
						$this->requestInterwikiRequestManager->getRequester()->getName()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			if ( $this->requestInterwikiRequestManager->getRequester()->isLocked() ) {
				$info .= Html::errorBox(
					$this->context->msg( 'requestinterwiki-info-requester-locked',
						$this->requestInterwikiRequestManager->getRequester()->getName()
					)->escaped()
				);

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			if ( !$this->requestInterwikiRequestManager->getInterwikiPrefix() ) {
				$info .= Html::errorBox(
					$this->context->msg( 'requestinterwiki-info-no-interwiki-prefix',
						$this->requestInterwikiRequestManager->getTarget(),
						parse_url( $this->requestInterwikiRequestManager->getSource(), PHP_URL_HOST )
					)->escaped()
				);
			}

			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => $info,
					'raw' => true,
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'requestinterwiki-label-lock',
					'default' => $this->requestInterwikiRequestManager->isLocked(),
					'section' => 'handling',
				],
			];

			if ( $this->permissionManager->userHasRight( $user, 'view-private-import-dump-requests' ) ) {
				$formDescriptor += [
					'handle-private' => [
						'type' => 'check',
						'label-message' => 'requestinterwiki-label-private',
						'default' => $this->requestInterwikiRequestManager->isPrivate(),
						'disabled' => $this->requestInterwikiRequestManager->isPrivate( true ),
						'section' => 'handling',
					],
				];
			}

			if (
				!$this->requestInterwikiRequestManager->getInterwikiPrefix() &&
				$this->permissionManager->userHasRight( $user, 'handle-import-dump-interwiki' )
			) {
				$source = $this->requestInterwikiRequestManager->getSource();
				$target = $this->requestInterwikiRequestManager->getTarget();

				$formDescriptor += [
					'handle-interwiki-info' => [
						'type' => 'info',
						'default' => $this->context->msg( 'requestinterwiki-info-interwiki', $target )->text(),
						'section' => 'handling',
					],
					'handle-interwiki-prefix' => [
						'type' => 'text',
						'label-message' => 'requestinterwiki-label-interwiki-prefix',
						'default' => '',
						'validation-callback' => [ $this, 'isValidInterwikiPrefix' ],
						'section' => 'handling',
					],
					'handle-interwiki-url' => [
						'type' => 'url',
						'label-message' => [
							'requestinterwiki-label-interwiki-url',
							( parse_url( $source, PHP_URL_SCHEME ) ?: 'https' ) . '://' .
							( parse_url( $source, PHP_URL_HOST ) ?: 'www.example.com' ) .
							'/wiki/$1',
						],
						'default' => '',
						'validation-callback' => [ $this, 'isValidInterwikiUrl' ],
						'section' => 'handling',
					],
					'submit-interwiki' => [
						'type' => 'submit',
						'default' => $this->context->msg( 'htmlform-submit' )->text(),
						'section' => 'handling',
					],
				];
			}

			$formDescriptor += [
				'handle-status' => [
					'type' => 'select',
					'label-message' => 'requestinterwiki-label-update-status',
					'options-messages' => [
						'requestinterwiki-label-pending' => 'pending',
						'requestinterwiki-label-inprogress' => 'inprogress',
						'requestinterwiki-label-complete' => 'complete',
						'requestinterwiki-label-declined' => 'declined',
					],
					'default' => $status,
					'disabled' => !$validRequest,
					'cssclass' => 'requestinterwiki-infuse',
					'section' => 'handling',
				],
				'handle-comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestinterwiki-label-status-updated-comment',
					'section' => 'handling',
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'htmlform-submit' )->text(),
					'section' => 'handling',
				],
			];
		}

		return $formDescriptor;
	}

	/**
	 * @param ?string $comment
	 * @param array $alldata
	 * @return string|bool
	 */
	public function isValidComment( ?string $comment, array $alldata ) {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $target
	 * @return string|bool
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->config->get( 'LocalDatabases' ) ) ) {
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

	/**
	 * @param ?string $prefix
	 * @param array $alldata
	 * @return string|bool
	 */
	public function isValidInterwikiPrefix( ?string $prefix, array $alldata ) {
		if ( isset( $alldata['submit-interwiki'] ) && ( !$prefix || ctype_space( $prefix ) ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		return true;
	}

	/**
	 * @param ?string $url
	 * @param array $alldata
	 * @return string|bool
	 */
	public function isValidInterwikiUrl( ?string $url, array $alldata ) {
		if ( !isset( $alldata['submit-interwiki'] ) ) {
			return true;
		}

		if ( !$url || ctype_space( $url ) ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		if (
			!parse_url( $url, PHP_URL_SCHEME ) ||
			!parse_url( $url, PHP_URL_HOST )
		) {
			return Status::newFatal( 'requestinterwiki-invalid-interwiki-url' )->getMessage();
		}

		return true;
	}

	/**
	 * @param int $requestID
	 * @return ?RequestInterwikiOOUIForm
	 */
	public function getForm( int $requestID ): ?RequestInterwikiOOUIForm {
		$this->requestInterwikiRequestManager->fromID( $requestID );
		$out = $this->context->getOutput();

		if ( $requestID === 0 || !$this->requestInterwikiRequestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'requestinterwiki-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.requestinterwiki.oouiform' ] );
		$out->addModuleStyles( [ 'ext.requestinterwiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new RequestInterwikiOOUIForm( $formDescriptor, $this->context, 'requestinterwiki-section' );

		$htmlForm->setId( 'requestinterwiki-request-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	/**
	 * @param array $formData
	 * @param HTMLForm $form
	 */
	protected function submitForm(
		array $formData,
		HTMLForm $form
	) {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$out = $form->getContext()->getOutput();

		if ( isset( $formData['submit-comment'] ) ) {
			$this->requestInterwikiRequestManager->addComment( $formData['comment'], $user );
			$out->addHTML( Html::successBox( $this->context->msg( 'requestinterwiki-comment-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-edit'] ) ) {
			$this->requestInterwikiRequestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->requestInterwikiRequestManager->getReason() !== $formData['edit-reason'] ) {
				$changes[] = $this->context->msg( 'requestinterwiki-request-edited-reason' )->plaintextParams(
					$this->requestInterwikiRequestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->requestInterwikiRequestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->requestInterwikiRequestManager->getSource() !== $formData['edit-source'] ) {
				$changes[] = $this->context->msg( 'requestinterwiki-request-edited-source' )->plaintextParams(
					$this->requestInterwikiRequestManager->getSource(),
					$formData['edit-source']
				)->escaped();

				$this->requestInterwikiRequestManager->setSource( $formData['edit-source'] );
			}

			if ( $this->requestInterwikiRequestManager->getTarget() !== $formData['edit-target'] ) {
				$changes[] = $this->context->msg(
					'requestinterwiki-request-edited-target',
					$this->requestInterwikiRequestManager->getTarget(),
					$formData['edit-target']
				)->escaped();

				$this->requestInterwikiRequestManager->setTarget( $formData['edit-target'] );
			}

			if ( !$changes ) {
				$this->requestInterwikiRequestManager->endAtomic( __METHOD__ );

				$out->addHTML( Html::errorBox( $this->context->msg( 'requestinterwiki-no-changes' )->escaped() ) );

				return;
			}

			if ( $this->requestInterwikiRequestManager->getStatus() === 'declined' ) {
				$this->requestInterwikiRequestManager->setStatus( 'pending' );

				$comment = $this->context->msg( 'requestinterwiki-request-reopened', $user->getName() )->rawParams(
					implode( "\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestInterwikiRequestManager->logStatusUpdate( $comment, 'pending', $user );

				$this->requestInterwikiRequestManager->addComment( $comment, User::newSystemUser( 'RequestInterwiki Extension' ) );

				$this->requestInterwikiRequestManager->sendNotification(
					$comment, 'requestinterwiki-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'requestinterwiki-request-edited', $user->getName() )->rawParams(
					implode( "\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestInterwikiRequestManager->addComment( $comment, User::newSystemUser( 'RequestInterwiki Extension' ) );
			}

			$this->requestInterwikiRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'requestinterwiki-edit-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-interwiki'] ) ) {
			if ( $this->requestInterwikiRequestManager->insertInterwikiPrefix(
				$formData['handle-interwiki-prefix'],
				$formData['handle-interwiki-url'],
				$user
			) ) {
				$out->addHTML( Html::successBox(
					$this->context->msg( 'requestinterwiki-interwiki-success',
						$this->requestInterwikiRequestManager->getTarget()
					)->escaped() )
				);

				return;
			}

			$out->addHTML( Html::errorBox(
				$this->context->msg( 'requestinterwiki-interwiki-failed',
					$this->requestInterwikiRequestManager->getTarget()
				)->escaped() )
			);

			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->requestInterwikiRequestManager->startAtomic( __METHOD__ );
			$changes = [];

			if ( $this->requestInterwikiRequestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->requestInterwikiRequestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->requestInterwikiRequestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->requestInterwikiRequestManager->isPrivate() !== (bool)$formData['handle-private']
			) {
				$changes[] = $this->requestInterwikiRequestManager->isPrivate() ?
					'public' : 'private';

				$this->requestInterwikiRequestManager->setPrivate( (int)$formData['handle-private'] );
			}

			if ( $this->requestInterwikiRequestManager->getStatus() === $formData['handle-status'] ) {
				$this->requestInterwikiRequestManager->endAtomic( __METHOD__ );

				if ( !$changes ) {
					$out->addHTML( Html::errorBox( $this->context->msg( 'requestinterwiki-no-changes' )->escaped() ) );
					return;
				}

				if ( in_array( 'private', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'requestinterwiki-success-private' )->escaped() )
					);
				}

				if ( in_array( 'public', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'requestinterwiki-success-public' )->escaped() )
					);
				}

				if ( in_array( 'locked', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'requestinterwiki-success-locked' )->escaped() )
					);
				}

				if ( in_array( 'unlocked', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'requestinterwiki-success-unlocked' )->escaped() )
					);
				}

				return;
			}

			$this->requestInterwikiRequestManager->setStatus( $formData['handle-status'] );

			$statusMessage = $this->context->msg( 'requestinterwiki-label-' . $formData['handle-status'] )
				->inContentLanguage()
				->text();

			$comment = $this->context->msg( 'requestinterwiki-status-updated', strtolower( $statusMessage ) )
				->inContentLanguage()
				->escaped();

			if ( $formData['handle-comment'] ) {
				$commentUser = User::newSystemUser( 'RequestInterwiki Status Update' );

				$comment .= "\n" . $this->context->msg( 'requestinterwiki-comment-given', $user->getName() )
					->inContentLanguage()
					->escaped();

				$comment .= ' ' . $formData['handle-comment'];
			}

			$this->requestInterwikiRequestManager->addComment( $comment, $commentUser ?? $user );
			$this->requestInterwikiRequestManager->logStatusUpdate(
				$formData['handle-comment'], $formData['handle-status'], $user
			);

			$this->requestInterwikiRequestManager->sendNotification( $comment, 'requestinterwiki-request-status-update', $user );

			$this->requestInterwikiRequestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'requestinterwiki-status-updated-success' )->escaped() ) );
		}
	}
}
