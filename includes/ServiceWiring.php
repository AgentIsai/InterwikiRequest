<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use Miraheze\RequestInterwiki\RequestInterwikiRequestManager;

return [
	'RequestInterwikiRequestManager' => static function ( MediaWikiServices $services ): RequestInterwikiRequestManager {
		return new RequestInterwikiRequestManager(
			$services->getConfigFactory()->makeConfig( 'RequestInterwiki' ),
			$services->getDBLoadBalancerFactory(),
			$services->getInterwikiLookup(),
			$services->getLinkRenderer(),
			$services->getRepoGroup(),
			RequestContext::getMain(),
			new ServiceOptions(
				RequestInterwikiRequestManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'RequestInterwiki' )
			),
			$services->getUserFactory(),
			$services->getUserGroupManagerFactory()
		);
	},
];
