<?php

namespace Miraheze\RequestInterwiki\Hooks\Handlers;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . '/../../../sql';

		$updater->addExtensionTable( 'requestinterwiki_requests', "$dir/$dbType/tables-generated.sql" );
	}
}
