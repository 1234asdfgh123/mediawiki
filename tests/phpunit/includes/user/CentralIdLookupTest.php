<?php

use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers CentralIdLookup
 * @group Database
 */
class CentralIdLookupTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	public function testFactory() {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$localIdLookupSpec = [
			'class' => LocalIdLookup::class,
			'services' => [
				'MainConfig',
				'DBLoadBalancer',
			]
		];
		$this->setMwGlobals( [
			'wgCentralIdLookupProviders' => [
				'local' => $localIdLookupSpec,
				'local2' => $localIdLookupSpec,
				'mock' => [ 'factory' => static function () use ( $mock ) {
					return $mock;
				} ],
				'bad' => [ 'class' => stdClass::class ],
			],
			'wgCentralIdLookupProvider' => 'mock',
		] );

		$this->assertSame( $mock, CentralIdLookup::factory() );
		$this->assertSame( $mock, CentralIdLookup::factory( 'mock' ) );
		$this->assertSame( 'mock', $mock->getProviderId() );

		$local = CentralIdLookup::factory( 'local' );
		$this->assertNotSame( $mock, $local );
		$this->assertInstanceOf( LocalIdLookup::class, $local );
		$this->assertSame( $local, CentralIdLookup::factory( 'local' ) );
		$this->assertSame( 'local', $local->getProviderId() );

		$local2 = CentralIdLookup::factory( 'local2' );
		$this->assertNotSame( $local, $local2 );
		$this->assertInstanceOf( LocalIdLookup::class, $local2 );
		$this->assertSame( 'local2', $local2->getProviderId() );

		$this->assertNull( CentralIdLookup::factory( 'unconfigured' ) );
		$this->assertNull( CentralIdLookup::factory( 'bad' ) );
	}

	public function testGetProviderId() {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->init( 'this is a test', $this->createNoOpMock( UserIdentityLookup::class ) );
		$this->assertSame( 'this is a test', $mock->getProviderId() );
	}

	public function testRepeatingInitThrows() {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->init( 'foo', $this->createNoOpMock( UserIdentityLookup::class ) );
		$this->expectException( LogicException::class );
		$mock->init( 'bar', $this->createNoOpMock( UserIdentityLookup::class ) );
	}

	public function testCheckAudience() {
		$mock = TestingAccessWrapper::newFromObject(
			$this->getMockForAbstractClass( CentralIdLookup::class )
		);

		$authority = $this->mockAnonUltimateAuthority();
		$this->assertSame( $authority, $mock->checkAudience( $authority ) );

		$authority = $mock->checkAudience( CentralIdLookup::AUDIENCE_PUBLIC );
		$this->assertInstanceOf( Authority::class, $authority );
		$this->assertSame( 0, $authority->getUser()->getId() );

		$this->assertNull( $mock->checkAudience( CentralIdLookup::AUDIENCE_RAW ) );

		try {
			$mock->checkAudience( 100 );
			$this->fail( 'Expected exception not thrown' );
		} catch ( InvalidArgumentException $ex ) {
			$this->assertSame( 'Invalid audience', $ex->getMessage() );
		}
	}

	public function testNameFromCentralId() {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->expects( $this->once() )->method( 'lookupCentralIds' )
			->with(
				[ 15 => null ],
				CentralIdLookup::AUDIENCE_RAW,
				CentralIdLookup::READ_LATEST
			)
			->willReturn( [ 15 => 'FooBar' ] );

		$this->assertSame(
			'FooBar',
			$mock->nameFromCentralId( 15, CentralIdLookup::AUDIENCE_RAW, CentralIdLookup::READ_LATEST )
		);
	}

	/**
	 * @dataProvider provideLocalUserFromCentralId
	 * @param string $name
	 * @param bool $succeeds
	 */
	public function testLocalUserFromCentralId( $name, $succeeds ) {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->method( 'isAttached' )
			->willReturn( true );
		$mock->expects( $this->once() )->method( 'lookupCentralIds' )
			->with(
				[ 42 => null ],
				CentralIdLookup::AUDIENCE_RAW,
				CentralIdLookup::READ_LATEST
			)
			->willReturn( [ 42 => $name ] );

		$mock->init( 'test', $this->getServiceContainer()->getUserIdentityLookup() );
		$user = $mock->localUserFromCentralId(
			42, CentralIdLookup::AUDIENCE_RAW, CentralIdLookup::READ_LATEST
		);
		if ( $succeeds ) {
			$this->assertInstanceOf( UserIdentity::class, $user );
			$this->assertSame( $name, $user->getName() );
		} else {
			$this->assertNull( $user );
		}

		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->method( 'isAttached' )
			->willReturn( false );
		$mock->expects( $this->once() )->method( 'lookupCentralIds' )
			->with(
				[ 42 => null ],
				CentralIdLookup::AUDIENCE_RAW,
				CentralIdLookup::READ_LATEST
			)
			->willReturn( [ 42 => $name ] );
		$mock->init( 'test', $this->getServiceContainer()->getUserIdentityLookup() );

		$this->assertNull(
			$mock->localUserFromCentralId( 42, CentralIdLookup::AUDIENCE_RAW, CentralIdLookup::READ_LATEST )
		);
	}

	public static function provideLocalUserFromCentralId() {
		return [
			[ 'UTSysop', true ],
			[ 'UTDoesNotExist', false ],
			[ null, false ],
			[ '', false ],
			[ '<X>', false ],
		];
	}

	public function testCentralIdFromName() {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->expects( $this->once() )->method( 'lookupUserNames' )
			->with(
				[ 'FooBar' => 0 ],
				CentralIdLookup::AUDIENCE_RAW,
				CentralIdLookup::READ_LATEST
			)
			->willReturn( [ 'FooBar' => 23 ] );

		$this->assertSame(
			23,
			$mock->centralIdFromName( 'FooBar', CentralIdLookup::AUDIENCE_RAW, CentralIdLookup::READ_LATEST )
		);
	}

	public function testCentralIdFromLocalUser() {
		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->method( 'isAttached' )
			->willReturn( true );
		$mock->expects( $this->once() )->method( 'lookupUserNames' )
			->with(
				[ 'FooBar' => 0 ],
				CentralIdLookup::AUDIENCE_RAW,
				CentralIdLookup::READ_LATEST
			)
			->willReturn( [ 'FooBar' => 23 ] );

		$this->assertSame(
			23,
			$mock->centralIdFromLocalUser(
				User::newFromName( 'FooBar' ), CentralIdLookup::AUDIENCE_RAW, CentralIdLookup::READ_LATEST
			)
		);

		$mock = $this->getMockForAbstractClass( CentralIdLookup::class );
		$mock->method( 'isAttached' )
			->willReturn( false );
		$mock->expects( $this->never() )->method( 'lookupUserNames' );

		$this->assertSame(
			0,
			$mock->centralIdFromLocalUser(
				User::newFromName( 'FooBar' ), CentralIdLookup::AUDIENCE_RAW, CentralIdLookup::READ_LATEST
			)
		);
	}

}
