<?php

class EchoAttributeManagerTest extends MediaWikiTestCase {

	public function testNewFromGlobalVars() {
		$this->assertInstanceOf( 'EchoAttributeManager', EchoAttributeManager::newFromGlobalVars() );
	}

	public function testGetCategoryEligibility() {
		$notif = array(
			'event_one' => array (
				'category' => 'category_one'
			),
		);
		$category = array(
			'category_one' => array (
				'priority' => 10
			)
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertTrue( $manager->getCategoryEligibility( $this->mockUser(), 'category_one' ) );
		$category = array(
			'category_one' => array (
				'priority' => 10,
				'usergroups' => array(
					'sysop'
				)
			)
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertFalse( $manager->getCategoryEligibility( $this->mockUser(), 'category_one' ) );
	}

	public function testGetNotificationCategory() {
		$notif = array(
			'event_one' => array (
				'category' => 'category_one'
			),
		);
		$category = array(
			'category_one' => array (
				'priority' => 10
			)
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertEquals( $manager->getNotificationCategory( 'event_one' ), 'category_one' );

		$manager = new EchoAttributeManager( $notif, array() );
		$this->assertEquals( $manager->getNotificationCategory( 'event_one' ), 'other' );

		$notif = array(
			'event_one' => array (
				'category' => 'category_two'
			),
		);
		$category = array(
			'category_one' => array (
				'priority' => 10
			)
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertEquals( $manager->getNotificationCategory( 'event_one' ), 'other' );
	}

	public function testGetCategoryPriority() {
		$notif = array(
			'event_one' => array (
				'category' => 'category_two'
			),
		);
		$category = array(
			'category_one' => array (
				'priority' => 6
			),
			'category_two' => array (
				'priority' => 100
			),
			'category_three' => array (
				'priority' => -10
			),
			'category_four' => array ()
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertEquals( 6, $manager->getCategoryPriority( 'category_one' ) );
		$this->assertEquals( 10, $manager->getCategoryPriority( 'category_two' ) );
		$this->assertEquals( 10, $manager->getCategoryPriority( 'category_three' ) );
		$this->assertEquals( 10, $manager->getCategoryPriority( 'category_four' ) );
	}

	public function testGetNotificationPriority() {
		$notif = array(
			'event_one' => array (
				'category' => 'category_one'
			),
			'event_two' => array (
				'category' => 'category_two'
			),
			'event_three' => array (
				'category' => 'category_three'
			),
			'event_four' => array (
				'category' => 'category_four'
			)
		);
		$category = array(
			'category_one' => array (
				'priority' => 6
			),
			'category_two' => array (
				'priority' => 100
			),
			'category_three' => array (
				'priority' => -10
			),
			'category_four' => array ()
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertEquals( 6, $manager->getNotificationPriority( 'event_one' ) );
		$this->assertEquals( 10, $manager->getNotificationPriority( 'event_two' ) );
		$this->assertEquals( 10, $manager->getNotificationPriority( 'event_three' ) );
		$this->assertEquals( 10, $manager->getNotificationPriority( 'event_four' ) );
	}

	public function testGetUserEnabledEvents() {
		$notif = array(
			'event_one' => array (
				'category' => 'category_one'
			),
			'event_two' => array (
				'category' => 'category_two'
			),
			'event_three' => array (
				'category' => 'category_three'
			),
		);
		$category = array(
			'category_one' => array (
				'priority' => 10,
				'usergroups' => array(
					'sysop'
				)
			),
			'category_two' => array (
				'priority' => 10,
				'usergroups' => array(
					'echo_group'
				)
			),
			'category_three' => array (
				'priority' => 10,
			),
		);
		$manager = new EchoAttributeManager( $notif, $category );
		$this->assertEquals( $manager->getUserEnabledEvents( $this->mockUser(), 'web' ), array( 'event_two', 'event_three' ) );
	}

	/**
	 * Mock object of User
	 */
	protected function mockUser() {
		$user = $this->getMockBuilder( 'User' )
			->disableOriginalConstructor()
			->getMock();
		$user->expects( $this->any() )
			->method( 'getID' )
			->will( $this->returnValue( 1 ) );
		$user->expects( $this->any() )
			->method( 'getOption' )
			->will( $this->returnValue( true ) );
		$user->expects( $this->any() )
			->method( 'getGroups' )
			->will( $this->returnValue( array( 'echo_group' ) ) );
		return $user;
	}
}
