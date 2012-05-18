<?php

class EchoHooks {
	public static function getSchemaUpdates($updater) {
		$dir = dirname(__FILE__);
		$baseSQLFile = "$dir/echo.sql";
		$updater->addExtensionTable( 'echo_subscription', $baseSQLFile );
		$updater->addExtensionTable( 'echo_event', $baseSQLFile );
		$updater->addExtensionTable( 'echo_notification', $baseSQLFile );

		$updater->modifyField( 'echo_event', 'event_agent',
			"$dir/db_patches/patch-event_agent-split.sql", true );
		$updater->modifyField( 'echo_event', 'event_variant',
			"$dir/db_patches/patch-event_variant_nullability.sql", true );
		return true;
	}

	public static function getDefaultNotifiedUsers($event, &$users) {
		switch( $event->getType() ) {
			case 'edit-user-talk':
				if ( !$event->getTitle() || !$event->getTitle()->getNamespace() == NS_USER_TALK ) {
					break;
				}

				$username = $event->getTitle()->getText();
				$user = User::newFromName( $username );
				if ($user->getId()) {
					$user = User::newFromName($username);
					$users[$user->getId()] = $user;
				}
			break;
		}

		return true;
	}

	public static function getPreferences( $user, &$preferences ) {
		$preferences['echo-notify-watchlist'] = array(
			'type' => 'toggle',
			'label-message' => 'echo-pref-notify-watchlist',
			'section' => 'echo',
		);
		return true;
	}

	public static function onWatch( $user, $article ) {
		if ( ! $user->getOption('echo-notify-watchlist') ) {
			return true;
		}

		$subscription = new EchoSubscription( $user, 'edit', $article->getTitle() );
		$subscription->enableNotification('notify');
		$subscription->save();
		return true;
	}

	public static function onUnwatch( $user, $article ) {
		$subscription = new EchoSubscription( $user, 'edit', $article->getTitle() );
		$subscription->disableNotification('notify');
		$subscription->save();
		return true;
	}

	public static function onArticleSaved( &$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status ) {	
		if ( $revision ) {
			$event = EchoEvent::create( array(
				'type' => 'edit',
				'title' => $article->getTitle(),
				'extra' => array('revid' => $revision->getID()),
				'agent' => $user,
			) );

			$possibleUser = $article->getTitle()->getText();

			if (
				$article->getTitle()->getNamespace() === NS_USER_TALK &&
				User::newFromName($possibleUser)->getID()
			) {
				$event = EchoEvent::create( array(
					'type' => 'edit-user-talk',
					'title' => $article->getTitle(),
					'extra' => array('revid' => $revision->getID()),
					'agent' => $user,
				) );
			}
		}

		return true;
	}
}