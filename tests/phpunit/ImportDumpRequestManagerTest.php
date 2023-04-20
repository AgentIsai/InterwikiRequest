<?php

namespace Miraheze\RequestInterwiki\Tests;

use MediaWikiIntegrationTestCase;
use Miraheze\RequestInterwiki\RequestInterwikiRequestManager;
use ReflectionClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group RequestInterwiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\RequestInterwiki\RequestInterwikiRequestManager
 */
class RequestInterwikiRequestManagerTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'requestinterwiki_requests';
	}

	public function addDBData() {
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$this->db->insert(
			'requestinterwiki_requests',
			[
				'request_source' => 'https://requestinterwikitest.com',
				'request_target' => 'requestinterwikitest',
				'request_reason' => 'test',
				'request_status' => 'pending',
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
	}

	private function getRequestInterwikiRequestManager(): RequestInterwikiRequestManager {
		$services = $this->getServiceContainer();
		$manager = $services->getService( 'RequestInterwikiRequestManager' );

		$manager->fromID( 1 );

		return $manager;
	}

	/**
	 * @covers ::__construct
	 * @covers ::fromID
	 */
	public function testFromID() {
		$manager = $this->getRequestInterwikiRequestManager();

		$reflectedClass = new ReflectionClass( $manager );
		$reflection = $reflectedClass->getProperty( 'ID' );
		$reflection->setAccessible( true );

		$ID = $reflection->getValue( $manager );

		$this->assertSame( 1, $ID );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists() {
		$manager = $this->getRequestInterwikiRequestManager();

		$this->assertTrue( $manager->exists() );
	}
}
