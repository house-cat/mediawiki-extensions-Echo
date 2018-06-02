<?php
use MediaWiki\MediaWikiServices;

/**
 * Entity that represents a notification target user
 */
class MWEchoNotifUser {

	/**
	 * Notification target user
	 * @var User
	 */
	private $mUser;

	/**
	 * Object cache
	 * @var WANObjectCache
	 */
	private $cache;

	/**
	 * Database access gateway
	 * @var EchoUserNotificationGateway
	 */
	private $userNotifGateway;

	/**
	 * Notification mapper
	 * @var EchoNotificationMapper
	 */
	private $notifMapper;

	/**
	 * Target page mapper
	 * @var EchoTargetPageMapper
	 */
	private $targetPageMapper;

	/**
	 * @var EchoForeignNotifications
	 */
	private $foreignNotifications = null;

	/**
	 * @var array|null
	 */
	private $localCountsAndTimestamps;

	/**
	 * @var array|null
	 */
	private $globalCountsAndTimestamps;

	/**
	 * @var array|null
	 */
	private $mForeignData = null;

	// The max notification count shown in badge

	// The max number shown in bundled message, eg, <user> and 99+ others <action>.
	// This is really a totally separate thing, and could be its own constant.

	// WARNING: If you change this, you should also change all references in the
	// i18n messages (100 and 99) in all repositories using Echo.
	const MAX_BADGE_COUNT = 99;

	const CACHE_TTL = 86400;
	const CACHE_KEY = 'echo-notification-counts';
	const CHECK_KEY = 'echo-notification-updated';

	/**
	 * Usually client code doesn't need to initialize the object directly
	 * because it could be obtained from factory method newFromUser()
	 * @param User $user
	 * @param WANObjectCache $cache
	 * @param EchoUserNotificationGateway $userNotifGateway
	 * @param EchoNotificationMapper $notifMapper
	 * @param EchoTargetPageMapper $targetPageMapper
	 */
	public function __construct(
		User $user,
		WANObjectCache $cache,
		EchoUserNotificationGateway $userNotifGateway,
		EchoNotificationMapper $notifMapper,
		EchoTargetPageMapper $targetPageMapper
	) {
		$this->mUser = $user;
		$this->userNotifGateway = $userNotifGateway;
		$this->cache = $cache;
		$this->notifMapper = $notifMapper;
		$this->targetPageMapper = $targetPageMapper;
	}

	/**
	 * Factory method
	 * @param User $user
	 * @throws MWException
	 * @return MWEchoNotifUser
	 */
	public static function newFromUser( User $user ) {
		if ( $user->isAnon() ) {
			throw new MWException( 'User must be logged in to view notification!' );
		}

		return new MWEchoNotifUser(
			$user,
			MediaWikiServices::getInstance()->getMainWANObjectCache(),
			new EchoUserNotificationGateway( $user, MWEchoDbFactory::newFromDefault() ),
			new EchoNotificationMapper(),
			new EchoTargetPageMapper()
		);
	}

	/**
	 * Clear talk page notification when users visit their talk pages.  This
	 * only resets if the notification count is less than max notification
	 * count. If the user has 99+ notifications, decrementing 1 bundled talk
	 * page notification would not really affect the count
	 */
	public function clearTalkNotification() {
		// There is no new talk notification
		if ( $this->cache->get( $this->getTalkNotificationCacheKey() ) === '0' ) {
			return;
		}

		// Do nothing if the count display meets the max 99+
		if ( $this->notifCountHasReachedMax() ) {
			return;
		}

		// Mark the talk page notification as read
		$this->markRead(
			$this->userNotifGateway->getUnreadNotifications(
				'edit-user-talk'
			)
		);

		$this->flagCacheWithNoTalkNotification();
	}

	/**
	 * Flag the cache with new talk notification
	 */
	public function flagCacheWithNewTalkNotification() {
		$this->cache->set( $this->getTalkNotificationCacheKey(), '1', 86400 );
	}

	/**
	 * Flag the cache with no talk notification
	 */
	public function flagCacheWithNoTalkNotification() {
		$this->cache->set( $this->getTalkNotificationCacheKey(), '0', 86400 );
	}

	/**
	 * Memcache key for talk notification
	 * @return string
	 */
	public function getTalkNotificationCacheKey() {
		global $wgEchoCacheVersion;
		return wfMemcKey( 'echo-new-talk-notification', $this->mUser->getId(), $wgEchoCacheVersion );
	}

	/**
	 * Check if the user has more notification count than max count display
	 * @return bool
	 */
	public function notifCountHasReachedMax() {
		if ( $this->getLocalNotificationCount() >= self::MAX_BADGE_COUNT ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get message count for this user.
	 *
	 * @param bool $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_REPLICA)
	 * @return int
	 */
	public function getMessageCount( $cached = true, $dbSource = DB_REPLICA ) {
		return $this->getNotificationCount( $cached, $dbSource, EchoAttributeManager::MESSAGE );
	}

	/**
	 * Get alert count for this user.
	 *
	 * @param bool $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_REPLICA)
	 * @return int
	 */
	public function getAlertCount( $cached = true, $dbSource = DB_REPLICA ) {
		return $this->getNotificationCount( $cached, $dbSource, EchoAttributeManager::ALERT );
	}

	public function getLocalNotificationCount( $cached = true, $dbSource = DB_REPLICA, $section = EchoAttributeManager::ALL ) {
		return $this->getNotificationCount( $cached, $dbSource, $section, false );
	}

	/**
	 * Retrieves number of unread notifications that a user has, would return
	 * MWEchoNotifUser::MAX_BADGE_COUNT + 1 at most.
	 *
	 * If $wgEchoCrossWikiNotifications is disabled, the $global parameter is ignored.
	 *
	 * @param bool $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_REPLICA)
	 * @param string $section Notification section
	 * @param bool|string $global Whether to include foreign notifications. If set to 'preference', uses the user's preference.
	 * @return int
	 */
	public function getNotificationCount( $cached = true, $dbSource = DB_REPLICA, $section = EchoAttributeManager::ALL, $global = 'preference' ) {
		if ( $this->mUser->isAnon() ) {
			return 0;
		}

		global $wgEchoCrossWikiNotifications;
		if ( !$wgEchoCrossWikiNotifications ) {
			// Ignore the $global parameter
			$global = false;
		}

		if ( $global === 'preference' ) {
			$global = $this->getForeignNotifications()->isEnabledByUser();
		}

		$data = $this->getCountsAndTimestamps( $global );
		$count = $data[$global ? 'global' : 'local'][$section]['count'];
		return (int)$count;
	}

	/**
	 * Get the timestamp of the latest unread alert
	 *
	 * @param bool $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_REPLICA)
	 * @return bool|MWTimestamp Timestamp of latest unread alert, or false if there are no unread alerts.
	 */
	public function getLastUnreadAlertTime( $cached = true, $dbSource = DB_REPLICA ) {
		return $this->getLastUnreadNotificationTime( $cached, $dbSource, EchoAttributeManager::ALERT );
	}

	/**
	 * Get the timestamp of the latest unread message
	 *
	 * @param bool $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_REPLICA)
	 * @return bool|MWTimestamp
	 */
	public function getLastUnreadMessageTime( $cached = true, $dbSource = DB_REPLICA ) {
		return $this->getLastUnreadNotificationTime( $cached, $dbSource, EchoAttributeManager::MESSAGE );
	}

	/**
	 * Returns the timestamp of the last unread notification.
	 *
	 * If $wgEchoCrossWikiNotifications is disabled, the $global parameter is ignored.
	 *
	 * @param bool $cached Set to false to bypass the cache. (Optional. Defaults to true)
	 * @param int $dbSource Use master or slave database to pull count (Optional. Defaults to DB_REPLICA)
	 * @param string $section Notification section
	 * @param bool|string $global Whether to include foreign notifications. If set to 'preference', uses the user's preference.
	 * @return bool|MWTimestamp Timestamp of latest unread message, or false if there are no unread messages.
	 */
	public function getLastUnreadNotificationTime( $cached = true, $dbSource = DB_REPLICA, $section = EchoAttributeManager::ALL, $global = 'preference' ) {
		if ( $this->mUser->isAnon() ) {
			return false;
		}

		global $wgEchoCrossWikiNotifications;
		if ( !$wgEchoCrossWikiNotifications ) {
			// Ignore the $global parameter
			$global = false;
		}

		if ( $global === 'preference' ) {
			$global = $this->getForeignNotifications()->isEnabledByUser();
		}

		$data = $this->getCountsAndTimestamps( $global );
		$timestamp = $data[$global ? 'global' : 'local'][$section]['timestamp'];
		return $timestamp === -1 ? false : new MWTimestamp( $timestamp );
	}

	/**
	 * Mark one or more notifications read for a user.
	 * @param array $eventIds Array of event IDs to mark read
	 * @return bool Returns true when data has been updated in DB, false on
	 *   failure, or when there was nothing to update
	 */
	public function markRead( $eventIds ) {
		$eventIds = array_filter( (array)$eventIds, 'is_numeric' );
		if ( !$eventIds || wfReadOnly() ) {
			return false;
		}

		$updated = $this->userNotifGateway->markRead( $eventIds );
		if ( $updated ) {
			// Update notification count in cache
			$this->resetNotificationCount();

			// After this 'mark read', is there any unread edit-user-talk
			// remaining?  If not, we should clear the newtalk flag.
			if ( $this->mUser->getNewtalk() ) {
				$attributeManager = EchoAttributeManager::newFromGlobalVars();
				$categoryMap = $attributeManager->getEventsByCategory();
				$usertalkTypes = $categoryMap['edit-user-talk'];
				$unreadEditUserTalk = $this->notifMapper->fetchUnreadByUser( $this->mUser, 1, null, $usertalkTypes, null, DB_MASTER );
				if ( count( $unreadEditUserTalk ) === 0 ) {
					$this->mUser->setNewtalk( false );
				}
			}
		}

		return $updated;
	}

	/**
	 * Mark one or more notifications unread for a user.
	 * @param array $eventIds Array of event IDs to mark unread
	 * @return bool Returns true when data has been updated in DB, false on
	 *   failure, or when there was nothing to update
	 */
	public function markUnRead( $eventIds ) {
		$eventIds = array_filter( (array)$eventIds, 'is_numeric' );
		if ( !$eventIds || wfReadOnly() ) {
			return false;
		}

		$updated = $this->userNotifGateway->markUnRead( $eventIds );
		if ( $updated ) {
			// Update notification count in cache
			$this->resetNotificationCount();

			// After this 'mark unread', is there any unread edit-user-talk?
			// If so, we should add the edit-user-talk flag
			if ( !$this->mUser->getNewtalk() ) {
				$attributeManager = EchoAttributeManager::newFromGlobalVars();
				$categoryMap = $attributeManager->getEventsByCategory();
				$usertalkTypes = $categoryMap['edit-user-talk'];
				$unreadEditUserTalk = $this->notifMapper->fetchUnreadByUser( $this->mUser, 1, null, $usertalkTypes, null, DB_MASTER );
				if ( count( $unreadEditUserTalk ) > 0 ) {
					$this->mUser->setNewtalk( true );
				}
			}
		}

		return $updated;
	}

	/**
	 * Attempt to mark all or sections of notifications as read, this only
	 * updates up to $wgEchoMaxUpdateCount records per request, see more
	 * detail about this in Echo.php, the other reason is that mediawiki
	 * database interface doesn't support updateJoin() that would update
	 * across multiple tables, we would visit this later
	 *
	 * @param string[] $sections
	 * @return bool
	 */
	public function markAllRead( array $sections = [ EchoAttributeManager::ALL ] ) {
		if ( wfReadOnly() ) {
			return false;
		}

		global $wgEchoMaxUpdateCount;

		// Mark all sections as read if this is the case
		if ( in_array( EchoAttributeManager::ALL, $sections ) ) {
			$sections = EchoAttributeManager::$sections;
		}

		$attributeManager = EchoAttributeManager::newFromGlobalVars();
		$eventTypes = $attributeManager->getUserEnabledEventsbySections( $this->mUser, 'web', $sections );

		$notifs = $this->notifMapper->fetchUnreadByUser( $this->mUser, $wgEchoMaxUpdateCount, null, $eventTypes );

		$eventIds = array_filter(
			array_map( function ( EchoNotification $notif ) {
				// This should not happen at all, but use 0 in
				// such case so to keep the code running
				if ( $notif->getEvent() ) {
					return $notif->getEvent()->getId();
				} else {
					return 0;
				}
			}, $notifs )
		);

		$updated = $this->markRead( $eventIds );
		if ( $updated ) {
			// Delete records from echo_target_page
			/**
			 * Keep the 'echo_target_page' records so they can be used for moderation.
			 */
			// $this->targetPageMapper->deleteByUserEvents( $this->mUser, $eventIds );
			if ( count( $notifs ) < $wgEchoMaxUpdateCount ) {
				$this->flagCacheWithNoTalkNotification();
			}
		}

		return $updated;
	}

	/**
	 * Invalidate cache and update echo_unread_wikis if x-wiki notifications is enabled.
	 *
	 * This updates the user's touched timestamp, as well as the value returned by getGlobalUpdateTime().
	 *
	 * NOTE: Consider calling this function from a deferred update, since it will read from and write to
	 * the master DB if cross-wiki notifications are enabled.
	 *
	 * @param int $dbSource Use master or replica database to pull count.
	 *  Only used if $wgEchoCrossWikiNotifications is enabled.
	 *  Do not set this to DB_REPLICA unless you know what you're doing.
	 */
	public function resetNotificationCount( $dbSource = DB_MASTER ) {
		global $wgEchoCrossWikiNotifications;

		// Delete cached local counts and timestamps
		$localMemcKey = $this->getMemcKey( self::CACHE_KEY );
		$this->cache->delete( $localMemcKey );

		// Update the user touched timestamp for the local user
		$this->mUser->invalidateCache();

		if ( $wgEchoCrossWikiNotifications ) {
			// Delete cached global counts and timestamps
			$globalMemcKey = $this->getGlobalMemcKey( self::CACHE_KEY );
			if ( $globalMemcKey !== false ) {
				$this->cache->delete( $globalMemcKey );
			}

			$uw = EchoUnreadWikis::newFromUser( $this->mUser );
			if ( $uw ) {
				// Immediately compute new local counts and timestamps
				$newLocalData = $this->computeLocalCountsAndTimestamps( $dbSource );
				// Write the new values to the echo_unread_wikis table
				$alertTs = $newLocalData[EchoAttributeManager::ALERT]['timestamp'];
				$messageTs = $newLocalData[EchoAttributeManager::MESSAGE]['timestamp'];
				$uw->updateCount(
					wfWikiID(),
					$newLocalData[EchoAttributeManager::ALERT]['count'],
					$alertTs === -1 ? false : new MWTimestamp( $alertTs ),
					$newLocalData[EchoAttributeManager::MESSAGE]['count'],
					$messageTs === -1 ? false : new MWTimestamp( $messageTs )
				);
				// We could set() $newLocalData into the cache here, but we don't because that seems risky;
				// instead we let it be recomputed on demand
			}

			// Update the global touched timestamp
			$checkKey = $this->getGlobalMemcKey( self::CHECK_KEY );
			if ( $checkKey ) {
				$this->cache->touchCheckKey( $checkKey );
			}
		}
	}

	/**
	 * Get the timestamp of the last time the global notification counts/timestamps were updated, if available.
	 *
	 * If the timestamp of the last update is not known, this will return the current timestamp.
	 * If the user is not attached, this will return false.
	 *
	 * @return string|false MW timestamp of the last update, or false if the user is not attached
	 */
	public function getGlobalUpdateTime() {
		$key = $this->getGlobalMemcKey( self::CHECK_KEY );
		if ( $key === false ) {
			return false;
		}
		return wfTimestamp( TS_MW, $this->cache->getCheckKeyTime( $key ) );
	}

	/**
	 * Get the number of notifications in each section, and the timestamp of the latest notification in
	 * each section. This returns the raw data structure that is stored in the cache; unless you want
	 * all of this information, you're probably looking for getNotificationCount(),
	 * getLastUnreadNotificationTime() or one of its wrappers.
	 *
	 * The returned data structure looks like:
	 * [
	 *   'local' => [
	 *     'alert' => [ 'count' => N, 'timestamp' => TS ],
	 *     'message' => [ 'count' = N, 'timestamp' => TS ],
	 *     'all' => [ 'count' => N, 'timestamp' => TS ],
	 *   ],
	 *   'global' => [
	 *     'alert' => [ 'count' => N, 'timestamp' => TS ],
	 *     'message' => [ 'count' = N, 'timestamp' => TS ],
	 *     'all' => [ 'count' => N, 'timestamp' => TS ],
	 *   ],
	 * ]
	 * Where N is a number and TS is a timestamp in TS_MW format or -1. If $includeGlobal is false,
	 * the 'global' key will not be present.
	 *
	 * @param bool $includeGlobal Whether to include cross-wiki notifications as well
	 * @return array
	 */
	public function getCountsAndTimestamps( $includeGlobal = false ) {
		if ( $this->localCountsAndTimestamps === null ) {
			$this->localCountsAndTimestamps = $this->cache->getWithSetCallback(
				$this->getMemcKey( self::CACHE_KEY ),
				self::CACHE_TTL,
				function ( $oldValue, &$ttl, array &$setOpts ) {
					$dbr = $this->userNotifGateway->getDB( DB_REPLICA );
					$setOpts += Database::getCacheSetOptions( $dbr );
					return $this->computeLocalCountsAndTimestamps();
				}
			);
		}
		$result = [ 'local' => $this->localCountsAndTimestamps ];

		if ( $includeGlobal ) {
			if ( $this->globalCountsAndTimestamps === null ) {
				$memcKey = $this->getGlobalMemcKey( self::CACHE_KEY );
				// If getGlobalMemcKey returns false, we don't have a global user ID
				// In that case, don't compute data that we can't cache or store
				if ( $memcKey !== false ) {
					$this->globalCountsAndTimestamps = $this->cache->getWithSetCallback(
						$memcKey,
						self::CACHE_TTL,
						function ( $oldValue, &$ttl, array &$setOpts ) {
							$dbr = $this->userNotifGateway->getDB( DB_REPLICA );
							$setOpts += Database::getCacheSetOptions( $dbr );
							return $this->computeGlobalCountsAndTimestamps();
						}
					);
				}
			}
			$result['global'] = $this->globalCountsAndTimestamps;
		}
		return $result;
	}

	/**
	 * Compute the counts and timestamps for the local notifications in each section.
	 * @param int $dbSource DB_REPLICA or DB_MASTER
	 * @return array [ 'alert' => [ 'count' => N, 'timestamp' => TS ], ... ]
	 */
	protected function computeLocalCountsAndTimestamps( $dbSource = DB_REPLICA ) {
		$attributeManager = EchoAttributeManager::newFromGlobalVars();
		$result = [];
		$totals = [ 'count' => 0, 'timestamp' => -1 ];

		foreach ( EchoAttributeManager::$sections as $section ) {
			$eventTypesToLoad = $attributeManager->getUserEnabledEventsbySections( $this->mUser, 'web', [ $section ] );

			$count = (int)$this->userNotifGateway->getCappedNotificationCount( $dbSource, $eventTypesToLoad, self::MAX_BADGE_COUNT + 1 );
			$result[$section]['count'] = $count;
			$totals['count'] += $count;

			$notifications = $this->notifMapper->fetchUnreadByUser( $this->mUser, 1, null, $eventTypesToLoad, null, $dbSource );
			if ( $notifications ) {
				$notification = reset( $notifications );
				$timestamp = $notification->getTimestamp();
			} else {
				$timestamp = -1;
			}
			$result[$section]['timestamp'] = $timestamp;
			$totals['timestamp'] = max( $totals['timestamp'], $timestamp );
		}
		$totals['count'] = self::capNotificationCount( $totals['count'] );
		$result[EchoAttributeManager::ALL] = $totals;
		return $result;
	}

	/**
	 * Compute the global counts and timestamps for each section.
	 *
	 * This calls getCountsAndTimestamps() to get data about local notifications, which may end up
	 * calling computeLocalCountsAndTimestamps() if there's a cache miss.
	 * @return array [ 'alert' => [ 'count' => N, 'timestamp' => TS ], ... ]
	 */
	protected function computeGlobalCountsAndTimestamps() {
		$localData = $this->getCountsAndTimestamps()['local'];
		$result = [];
		$totals = [ 'count' => 0, 'timestamp' => -1 ];
		foreach ( EchoAttributeManager::$sections as $section ) {
			$localCount = $localData[$section]['count'];
			$globalCount = self::capNotificationCount( $localCount + $this->getForeignCount( $section ) );
			$result[$section]['count'] = $globalCount;
			$totals['count'] += $globalCount;

			$localTimestamp = $localData[$section]['timestamp'];
			$foreignTimestamp = $this->getForeignTimestamp( $section );
			$globalTimestamp = max( $localTimestamp, $foreignTimestamp ? $foreignTimestamp->getTimestamp( TS_MW ) : -1 );
			$result[$section]['timestamp'] = $globalTimestamp;
			$totals['timestamp'] = max( $totals['timestamp'], $globalTimestamp );
		}
		$totals['count'] = self::capNotificationCount( $totals['count'] );
		$result[EchoAttributeManager::ALL] = $totals;
		return $result;
	}

	/**
	 * Get the user's email notification format
	 * @return string
	 */
	public function getEmailFormat() {
		global $wgAllowHTMLEmail;

		if ( $wgAllowHTMLEmail ) {
			return $this->mUser->getOption( 'echo-email-format' );
		} else {
			return EchoEmailFormat::PLAIN_TEXT;
		}
	}

	/**
	 * Build a memcached key.
	 * @param string $key Key, typically prefixed with echo-notification-
	 * @param bool $global If true, return a global memc key; if false, return one local to this wiki
	 * @return string|false Memcached key, or false if one could not be generated (can only happen for global keys)
	 */
	protected function getMemcKey( $key, $global = false ) {
		global $wgEchoCacheVersion;
		if ( !$global ) {
			return wfMemcKey( $key, $this->mUser->getId(), $wgEchoCacheVersion );
		}

		$lookup = CentralIdLookup::factory();
		$globalId = $lookup->centralIdFromLocalUser( $this->mUser, CentralIdLookup::AUDIENCE_RAW );
		if ( !$globalId ) {
			return false;
		}
		return wfGlobalCacheKey( $key, $globalId, $wgEchoCacheVersion );
	}

	protected function getGlobalMemcKey( $key ) {
		return $this->getMemcKey( $key, true );
	}

	/**
	 * Lazy-construct an EchoForeignNotifications instance. This instance is force-enabled, so it
	 * returns information about cross-wiki notifications even if the user has them disabled.
	 * @return EchoForeignNotifications
	 */
	protected function getForeignNotifications() {
		if ( !$this->foreignNotifications ) {
			$this->foreignNotifications = new EchoForeignNotifications( $this->mUser, true );
		}
		return $this->foreignNotifications;
	}

	/**
	 * Get data about foreign notifications from the foreign wikis' APIs.
	 *
	 * This is used when $wgEchoSectionTransition or $wgEchoBundleTransition is enabled,
	 * to deal with untrustworthy echo_unread_wikis entries. This method fetches the list of
	 * wikis that have any unread notifications at all from the echo_unread_wikis table, then
	 * queries their APIs to find the per-section counts and timestamps for those wikis.
	 *
	 * The results of this function are cached in the NotifUser object.
	 * @return array [ (str) wiki => [ (str) section => [ 'count' => (int) count, 'timestamp' => (str) ts ] ] ]
	 */
	protected function getForeignData() {
		if ( $this->mForeignData ) {
			return $this->mForeignData;
		}

		$potentialWikis = $this->getForeignNotifications()->getWikis( EchoAttributeManager::ALL );
		$foreignReq = new EchoForeignWikiRequest(
			$this->mUser,
			[
				'action' => 'query',
				'meta' => 'notifications',
				'notprop' => 'count|list',
				'notgroupbysection' => '1',
				'notunreadfirst' => '1',
			],
			$potentialWikis,
			'notwikis'
		);
		$foreignResults = $foreignReq->execute();

		$this->mForeignData = [];
		foreach ( $foreignResults as $wiki => $result ) {
			if ( !isset( $result['query']['notifications'] ) ) {
				continue;
			}
			$data = $result['query']['notifications'];
			foreach ( EchoAttributeManager::$sections as $section ) {
				if ( isset( $data[$section]['rawcount'] ) ) {
					$this->mForeignData[$wiki][$section]['count'] = $data[$section]['rawcount'];
				}
				if ( isset( $data[$section]['list'][0] ) ) {
					$this->mForeignData[$wiki][$section]['timestamp'] = $data[$section]['list'][0]['timestamp']['mw'];
				}
			}
		}
		return $this->mForeignData;
	}

	protected function getForeignCount( $section = EchoAttributeManager::ALL ) {
		global $wgEchoSectionTransition, $wgEchoBundleTransition;
		$count = 0;
		if (
			// In section transition mode, we don't trust the individual echo_unread_wikis rows
			// but we do trust that alert+message=all. In bundle transition mode, we don't trust
			// that either, but we do trust that wikis with rows in the table have unread notifications
			// and wikis without rows in the table don't.
			( $wgEchoSectionTransition && $section !== EchoAttributeManager::ALL ) ||
			$wgEchoBundleTransition
		) {
			$foreignData = $this->getForeignData();
			foreach ( $foreignData as $data ) {
				if ( $section === EchoAttributeManager::ALL ) {
					foreach ( $data as $subData ) {
						if ( isset( $subData['count'] ) ) {
							$count += $subData['count'];
						}
					}
				} elseif ( isset( $data[$section]['count'] ) ) {
					$count += $data[$section]['count'];
				}
			}
		} else {
			$count += $this->getForeignNotifications()->getCount( $section );
		}
		return self::capNotificationCount( $count );
	}

	protected function getForeignTimestamp( $section = EchoAttributeManager::ALL ) {
		global $wgEchoSectionTransition, $wgEchoBundleTransition;

		if (
			// In section transition mode, we don't trust the individual echo_unread_wikis rows
			// but we do trust that alert+message=all. In bundle transition mode, we don't trust
			// that either, but we do trust that wikis with rows in the table have unread notifications
			// and wikis without rows in the table don't.
			( $wgEchoSectionTransition && $section !== EchoAttributeManager::ALL ) ||
			$wgEchoBundleTransition
		) {
			$foreignTime = false;
			$foreignData = $this->getForeignData();
			foreach ( $foreignData as $data ) {
				if ( $section === EchoAttributeManager::ALL ) {
					foreach ( $data as $subData ) {
						if ( isset( $subData['timestamp'] ) ) {
							$wikiTime = new MWTimestamp( $data[$section]['timestamp'] );
							// $wikiTime > $foreignTime = invert 1
							if ( $foreignTime === false || $wikiTime->diff( $foreignTime )->invert === 1 ) {
								$foreignTime = $wikiTime;
							}
						}
					}
				} elseif ( isset( $data[$section]['timestamp'] ) ) {
					$wikiTime = new MWTimestamp( $data[$section]['timestamp'] );
					// $wikiTime > $foreignTime = invert 1
					if ( $foreignTime === false || $wikiTime->diff( $foreignTime )->invert === 1 ) {
						$foreignTime = $wikiTime;
					}
				}
			}
		} else {
			$foreignTime = $this->getForeignNotifications()->getTimestamp( $section );
		}
		return $foreignTime;
	}

	/**
	 * Helper function to produce the capped number of notifications
	 * based on the value of MWEchoNotifUser::MAX_BADGE_COUNT
	 *
	 * @param int $number Raw notification count to cap
	 * @return int Capped notification count
	 */
	public static function capNotificationCount( $number ) {
		return min( $number, self::MAX_BADGE_COUNT + 1 );
	}
}
