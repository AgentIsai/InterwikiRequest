<?php

namespace Miraheze\RequestInterwiki\Specials;

use HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use Miraheze\RequestInterwiki\RequestInterwikiRequestManager;
use Miraheze\RequestInterwiki\RequestInterwikiRequestQueuePager;
use Miraheze\RequestInterwiki\RequestInterwikiRequestViewer;
use SpecialPage;
use Wikimedia\Rdbms\ILBFactory;

class SpecialRequestInterwikiQueue extends SpecialPage {

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var RequestInterwikiRequestManager */
	private $requestInterwikiRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param RequestInterwikiRequestManager $requestInterwikiRequestManager
	 * @param PermissionManager $permissionManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ILBFactory $dbLoadBalancerFactory,
		RequestInterwikiRequestManager $requestInterwikiRequestManager,
		PermissionManager $permissionManager,
		UserFactory $userFactory
	) {
		parent::__construct( 'RequestInterwikiQueue' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->requestInterwikiRequestManager = $requestInterwikiRequestManager;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->setHeaders();

		if ( $par ) {
			$this->lookupRequest( $par );
			return;
		}

		$this->doPagerStuff();
	}

	private function doPagerStuff() {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$target = $this->getRequest()->getText( 'target' );

		$formDescriptor = [
			'target' => [
				'type' => 'text',
				'name' => 'target',
				'label-message' => 'requestinterwiki-label-target',
				'default' => $target,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'requestinterwiki-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestinterwiki-label-status',
				'options-messages' => [
					'requestinterwiki-label-pending' => 'pending',
					'requestinterwiki-label-inprogress' => 'inprogress',
					'requestinterwiki-label-complete' => 'complete',
					'requestinterwiki-label-declined' => 'declined',
					'requestinterwiki-label-all' => '*',
				],
				'default' => $status ?: 'pending',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' )->prepareForm()->displayForm( false );

		$pager = new RequestInterwikiRequestQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->dbLoadBalancerFactory,
			$this->getLinkRenderer(),
			$this->userFactory,
			$requester,
			$status,
			$target
		);

		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	/**
	 * @param string $par
	 */
	private function lookupRequest( $par ) {
		$requestViewer = new RequestInterwikiRequestViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->requestInterwikiRequestManager,
			$this->permissionManager
		);

		$htmlForm = $requestViewer->getForm( (int)$par );

		if ( $htmlForm ) {
			$htmlForm->show();
		}
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}
