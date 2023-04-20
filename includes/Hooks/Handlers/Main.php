<?php

namespace Miraheze\RequestInterwiki\Hooks\Handlers;

use Config;
use ConfigFactory;
use EchoAttributeManager;
use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Miraheze\RequestInterwiki\Notifications\EchoNewRequestPresentationModel;
use Miraheze\RequestInterwiki\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\RequestInterwiki\Notifications\EchoRequestStatusUpdatePresentationModel;
use WikiMap;

class Main implements
	GetAllBlockActionsHook,
	UserGetReservedNamesHook
{

	/** @var Config */
	private $config;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct( ConfigFactory $configFactory ) {
		$this->config = $configFactory->makeConfig( 'RequestInterwiki' );
	}

	/**
	 * @param array &$reservedUsernames
	 */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'RequestInterwiki Extension';
		$reservedUsernames[] = 'RequestInterwiki Status Update';
	}

	/**
	 * @param array &$actions
	 */
	public function onGetAllBlockActions( &$actions ) {
		if (
			$this->config->get( 'RequestInterwikiCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'RequestInterwikiCentralWiki' ) )
		) {
			return;
		}

		$actions[ 'request-import-dump' ] = 200;
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
		if (
			$this->config->get( 'RequestInterwikiCentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'RequestInterwikiCentralWiki' ) )
		) {
			return;
		}

		$notificationCategories['requestinterwiki-new-request'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestinterwiki-new-request',
		];

		$notificationCategories['requestinterwiki-request-comment'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestinterwiki-request-comment',
		];

		$notificationCategories['requestinterwiki-request-status-update'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-requestinterwiki-request-status-update',
		];

		$notifications['requestinterwiki-new-request'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'requestinterwiki-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestinterwiki-request-comment'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'requestinterwiki-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['requestinterwiki-request-status-update'] = [
			EchoAttributeManager::ATTR_LOCATORS => [
				'EchoUserLocator::locateEventAgent'
			],
			'category' => 'requestinterwiki-request-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
