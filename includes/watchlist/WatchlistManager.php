<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author DannyS712
 */

namespace MediaWiki\Watchlist;

use DeferredUpdates;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageReference;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\User\TalkPageNotificationManager;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use NamespaceInfo;
use ReadOnlyMode;
use TitleValue;
use WatchedItemStoreInterface;

/**
 * WatchlistManager service
 *
 * @since 1.35
 */
class WatchlistManager {

	/**
	 * @internal For use by ServiceWiring
	 */
	public const CONSTRUCTOR_OPTIONS = [
		'UseEnotif',
		'ShowUpdatedMarker',
	];

	/** @var ServiceOptions */
	private $options;

	/** @var HookRunner */
	private $hookRunner;

	/** @var ReadOnlyMode */
	private $readOnlyMode;

	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var TalkPageNotificationManager */
	private $talkPageNotificationManager;

	/** @var WatchedItemStoreInterface */
	private $watchedItemStore;

	/** @var UserFactory */
	private $userFactory;

	/** @var NamespaceInfo */
	private $nsInfo;

	/**
	 * @var array
	 *
	 * Cache for getTitleNotificationTimestamp
	 *
	 * Keys need to reflect both the specific user and the title:
	 *
	 * Since only users have watchlists, the user is represented with `u⧼user id⧽`
	 *
	 * Since the method accepts LinkTarget objects, cannot rely on the object's toString,
	 *     since it is different for TitleValue and Title. Implement a simplified string
	 *     representation of the string that TitleValue uses: `⧼namespace number⧽:⧼db key⧽`
	 *
	 * Entries are in the form of
	 *     u⧼user id⧽-⧼namespace number⧽:⧼db key⧽ => ⧼timestamp or false⧽
	 */
	private $notificationTimestampCache = [];

	/**
	 * @param ServiceOptions $options
	 * @param HookContainer $hookContainer
	 * @param ReadOnlyMode $readOnlyMode
	 * @param RevisionLookup $revisionLookup
	 * @param TalkPageNotificationManager $talkPageNotificationManager
	 * @param WatchedItemStoreInterface $watchedItemStore
	 * @param UserFactory $userFactory
	 * @param NamespaceInfo $nsInfo
	 */
	public function __construct(
		ServiceOptions $options,
		HookContainer $hookContainer,
		ReadOnlyMode $readOnlyMode,
		RevisionLookup $revisionLookup,
		TalkPageNotificationManager $talkPageNotificationManager,
		WatchedItemStoreInterface $watchedItemStore,
		UserFactory $userFactory,
		NamespaceInfo $nsInfo
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->hookRunner = new HookRunner( $hookContainer );
		$this->readOnlyMode = $readOnlyMode;
		$this->revisionLookup = $revisionLookup;
		$this->talkPageNotificationManager = $talkPageNotificationManager;
		$this->watchedItemStore = $watchedItemStore;
		$this->userFactory = $userFactory;
		$this->nsInfo = $nsInfo;
	}

	/**
	 * Resets all of the given user's page-change notification timestamps.
	 * If e-notif e-mails are on, they will receive notification mails on
	 * the next change of any watched page.
	 *
	 * @note If the user doesn't have 'editmywatchlist', this will do nothing.
	 *
	 * @param Authority|UserIdentity $performer deprecated passing UserIdentity since 1.37
	 */
	public function clearAllUserNotifications( $performer ) {
		if ( $this->readOnlyMode->isReadOnly() ) {
			// Cannot change anything in read only
			return;
		}

		if ( !$performer instanceof Authority ) {
			$performer = $this->userFactory->newFromUserIdentity( $performer );
		}

		if ( !$performer->isAllowed( 'editmywatchlist' ) ) {
			// User isn't allowed to edit the watchlist
			return;
		}

		$user = $performer->getUser();

		if ( !$this->options->get( 'UseEnotif' ) &&
			!$this->options->get( 'ShowUpdatedMarker' )
		) {
			$this->talkPageNotificationManager->removeUserHasNewMessages( $user );
			return;
		}

		$userId = $user->getId();
		if ( !$userId ) {
			return;
		}

		$this->watchedItemStore->resetAllNotificationTimestampsForUser( $user );

		// We also need to clear here the "you have new message" notification for the own
		// user_talk page; it's cleared one page view later in WikiPage::doViewUpdates().
	}

	/**
	 * Clear the user's notification timestamp for the given title.
	 * If e-notif e-mails are on, they will receive notification mails on
	 * the next change of the page if it's watched etc.
	 *
	 * @note If the user doesn't have 'editmywatchlist', this will do nothing.
	 *
	 * @param Authority|UserIdentity $performer deprecated passing UserIdentity since 1.37
	 * @param LinkTarget|PageIdentity $title deprecated passing LinkTarget since 1.37
	 * @param int $oldid The revision id being viewed. If not given or 0, latest revision is assumed.
	 */
	public function clearTitleUserNotifications(
		$performer,
		$title,
		int $oldid = 0
	) {
		if ( $this->readOnlyMode->isReadOnly() ) {
			// Cannot change anything in read only
			return;
		}

		if ( !$performer instanceof Authority ) {
			$performer = $this->userFactory->newFromUserIdentity( $performer );
		}

		if ( !$performer->isAllowed( 'editmywatchlist' ) ) {
			// User isn't allowed to edit the watchlist
			return;
		}

		$userIdentity = $performer->getUser();
		$userTalkPage = (
			$title->getNamespace() === NS_USER_TALK &&
			$title->getDBkey() === strtr( $userIdentity->getName(), ' ', '_' )
		);

		if ( $userTalkPage ) {
			// If we're working on user's talk page, we should update the talk page message indicator
			if ( !$this->hookRunner->onUserClearNewTalkNotification(
				$this->userFactory->newFromAuthority( $performer ),
				$oldid
			) ) {
				return;
			}

			// Try to update the DB post-send and only if needed...
			$talkPageNotificationManager = $this->talkPageNotificationManager;
			$revisionLookup = $this->revisionLookup;
			DeferredUpdates::addCallableUpdate( static function () use (
				$userIdentity,
				$oldid,
				$talkPageNotificationManager,
				$revisionLookup
			) {
				if ( !$talkPageNotificationManager->userHasNewMessages( $userIdentity ) ) {
					// no notifications to clear
					return;
				}
				// Delete the last notifications (they stack up)
				$talkPageNotificationManager->removeUserHasNewMessages( $userIdentity );

				// If there is a new, unseen, revision, use its timestamp
				if ( !$oldid ) {
					return;
				}

				$oldRev = $revisionLookup->getRevisionById(
					$oldid,
					RevisionLookup::READ_LATEST
				);
				if ( !$oldRev ) {
					return;
				}

				$newRev = $revisionLookup->getNextRevision( $oldRev );
				if ( $newRev ) {
					$talkPageNotificationManager->setUserHasNewMessages(
						$userIdentity,
						$newRev
					);
				}
			} );
		}

		if ( !$this->options->get( 'UseEnotif' ) &&
			!$this->options->get( 'ShowUpdatedMarker' )
		) {
			return;
		}

		if ( !$userIdentity->isRegistered() ) {
			// Nothing else to do
			return;
		}

		// Only update the timestamp if the page is being watched.
		// The query to find out if it is watched is cached both in memcached and per-invocation,
		// and when it does have to be executed, it can be on a replica DB
		// If this is the user's newtalk page, we always update the timestamp
		$force = $userTalkPage ? 'force' : '';
		$this->watchedItemStore->resetNotificationTimestamp( $userIdentity, $title, $force, $oldid );
	}

	/**
	 * Get the timestamp when this page was updated since the user last saw it.
	 *
	 * @param UserIdentity $user
	 * @param LinkTarget|PageIdentity $title deprecated passing LinkTarget since 1.37
	 * @return string|bool|null String timestamp, false if not watched, null if nothing is unseen
	 */
	public function getTitleNotificationTimestamp( UserIdentity $user, $title ) {
		$userId = $user->getId();

		if ( !$userId ) {
			return false;
		}

		$cacheKey = 'u' . (string)$userId . '-' .
			(string)$title->getNamespace() . ':' . $title->getDBkey();

		// avoid isset here, as it'll return false for null entries
		if ( array_key_exists( $cacheKey, $this->notificationTimestampCache ) ) {
			return $this->notificationTimestampCache[ $cacheKey ];
		}

		$watchedItem = $this->watchedItemStore->getWatchedItem( $user, $title );
		if ( $watchedItem ) {
			$timestamp = $watchedItem->getNotificationTimestamp();
		} else {
			$timestamp = false;
		}

		$this->notificationTimestampCache[ $cacheKey ] = $timestamp;
		return $timestamp;
	}

	/**
	 * @since 1.37
	 * @param PageReference $target
	 * @return bool
	 */
	public function isWatchable( PageReference $target ): bool {
		if ( !$this->nsInfo->isWatchable( $target->getNamespace() ) ) {
			return false;
		}

		if ( $target instanceof PageIdentity && !$target->canExist() ) {
			// Catch "improper" Title instances
			return false;
		}

		return true;
	}

	/**
	 * Check if the page is watched by the user.
	 * @since 1.37
	 * @param UserIdentity $userIdentity
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function isWatchedIgnoringRights( UserIdentity $userIdentity, PageIdentity $target ) : bool {
		if ( $this->isWatchable( $target ) ) {
			return $this->watchedItemStore->isWatched( $userIdentity, $target );
		}
		return false;
	}

	/**
	 * Check if the page is watched by the user and the user has permission to view their
	 * watchlist.
	 * @since 1.37
	 * @param Authority $performer
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function isWatched( Authority $performer, PageIdentity $target ) : bool {
		if ( $performer->isAllowed( 'viewmywatchlist' ) ) {
			return $this->isWatchedIgnoringRights( $performer->getUser(), $target );
		}
		return false;
	}

	/**
	 * Check if the article is temporarily watched by the user.
	 * @since 1.37
	 * @param UserIdentity $userIdentity
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function isTempWatchedIgnoringRights( UserIdentity $userIdentity, PageIdentity $target ) : bool {
		if ( $this->isWatchable( $target ) ) {
			return $this->watchedItemStore->isTempWatched( $userIdentity, $target );
		}
		return false;
	}

	/**
	 * Check if the page is temporarily watched by the user and the user has permission to view
	 * their watchlist.
	 * @since 1.37
	 * @param Authority $performer
	 * @param PageIdentity $target
	 * @return bool
	 */
	public function isTempWatched( Authority $performer, PageIdentity $target ) : bool {
		if ( $performer->isAllowed( 'viewmywatchlist' ) ) {
			return $this->isTempWatchedIgnoringRights( $performer->getUser(), $target );
		}
		return false;
	}

	/**
	 * Watch a page.
	 * @since 1.37
	 * @param UserIdentity $user
	 * @param PageIdentity $target
	 * @param string|null $expiry Optional expiry timestamp in any format acceptable to wfTimestamp(),
	 *   null will not create expiries, or leave them unchanged should they already exist.
	 */
	public function addWatchIgnoringRights(
		UserIdentity $user,
		PageIdentity $target,
		?string $expiry = null
	) {
		if ( !$this->isWatchable( $target ) ) {
			return;
		}

		$linkTarget = TitleValue::castPageToLinkTarget( $target );

		$this->watchedItemStore->addWatch( $user, $this->nsInfo->getSubjectPage( $linkTarget ), $expiry );
		if ( $this->nsInfo->canHaveTalkPage( $linkTarget ) ) {
			$this->watchedItemStore->addWatch( $user, $this->nsInfo->getTalkPage( $linkTarget ), $expiry );
		}

		// eventually user_touched should be factored out of User and this should be replaced
		$this->userFactory->newFromUserIdentity( $user )->invalidateCache();
	}

	/**
	 * Watch a page if the user has permission to edit their watchlist.
	 * @since 1.37
	 * @param Authority $performer
	 * @param PageIdentity $target
	 * @param string|null $expiry Optional expiry timestamp in any format acceptable to wfTimestamp(),
	 *   null will not create expiries, or leave them unchanged should they already exist.
	 */
	public function addWatch(
		Authority $performer,
		PageIdentity $target,
		?string $expiry = null
	) {
		if ( $performer->isAllowed( 'editmywatchlist' ) ) {
			$this->addWatchIgnoringRights( $this->userFactory->newFromAuthority( $performer ), $target, $expiry );
		}
	}

	/**
	 * Stop watching a page if the user has permission to edit their watchlist.
	 * @since 1.37
	 * @param UserIdentity $user
	 * @param PageIdentity $target
	 */
	public function removeWatchIgnoringRights(
		UserIdentity $user,
		PageIdentity $target
	) {
		if ( !$this->isWatchable( $target ) ) {
			return;
		}

		$linkTarget = TitleValue::castPageToLinkTarget( $target );

		$this->watchedItemStore->removeWatch( $user, $this->nsInfo->getSubjectPage( $linkTarget ) );
		if ( $this->nsInfo->canHaveTalkPage( $linkTarget ) ) {
			$this->watchedItemStore->removeWatch( $user, $this->nsInfo->getTalkPage( $linkTarget ) );
		}

		// eventually user_touched should be factored out of User and this should be replaced
		$this->userFactory->newFromUserIdentity( $user )->invalidateCache();
	}

	/**
	 * Stop watching a page if the user has permission to edit their watchlist.
	 * @since 1.37
	 * @param Authority $performer
	 * @param PageIdentity $target
	 */
	public function removeWatch(
		Authority $performer,
		PageIdentity $target
	) {
		if ( $performer->isAllowed( 'editmywatchlist' ) ) {
			$this->removeWatchIgnoringRights( $this->userFactory->newFromAuthority( $performer ), $target );
		}
	}
}

/**
 * Retain the old class name for backwards compatibility.
 * @deprecated since 1.36
 */
class_alias( WatchlistManager::class, 'MediaWiki\User\WatchlistNotificationManager' );
