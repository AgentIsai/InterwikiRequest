<?php

namespace Miraheze\RequestInterwiki\Notifications;

use EchoDiscussionParser;
use EchoEventPresentationModel;
use Message;

class EchoNewRequestPresentationModel extends EchoEventPresentationModel {

	/**
	 * @return string
	 */
	public function getIconType() {
		return 'global';
	}

	/**
	 * @return Message
	 */
	public function getHeaderMessage() {
		return $this->msg(
			'requestinterwiki-notification-header-new-request',
			$this->event->getExtraParam( 'request-id' )
		);
	}

	/**
	 * @return Message
	 */
	public function getBodyMessage() {
		$reason = EchoDiscussionParser::getTextSnippet(
			$this->event->getExtraParam( 'reason' ),
			$this->language
		);

		return $this->msg( 'requestinterwiki-notification-body-new-request',
			$reason,
			$this->event->getExtraParam( 'requester' ),
			$this->event->getExtraParam( 'target' )
		);
	}

	/**
	 * @return bool
	 */
	public function getPrimaryLink() {
		return false;
	}

	/**
	 * @return array
	 */
	public function getSecondaryLinks() {
		$visitLink = [
			'url' => $this->event->getExtraParam( 'request-url', 0 ),
			'label' => $this->msg( 'requestinterwiki-notification-visit-request' )->text(),
			'prioritized' => true,
		];

		return [ $visitLink ];
	}
}
