<?php
/**
 * Updater for secondary data after a page edit.
 *
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
 */

use MediaWiki\Deferred\DataUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\EnqueueableDataUpdate;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Deferred\TransactionRoundAwareUpdate;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\DerivedPageDataUpdater;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\ILBFactory;

/**
 * Update object handling the cleanup of secondary data after a page was edited.
 *
 * This makes it possible for DeferredUpdates to have retry logic using a
 * single refreshLinks job if any of the bundled updates fail.
 *
 * @since 1.34
 */
class RefreshSecondaryDataUpdate extends DataUpdate
	implements TransactionRoundAwareUpdate, EnqueueableDataUpdate
{
	/** @var ILBFactory */
	private $lbFactory;
	/** @var WikiPage */
	private $page;
	/** @var DerivedPageDataUpdater */
	private $updater;
	/** @var bool */
	private $recursive;

	/** @var RevisionRecord */
	private $revisionRecord;
	/** @var UserIdentity */
	private $user;

	/**
	 * @param ILBFactory $lbFactory
	 * @param UserIdentity $user
	 * @param WikiPage $page Page we are updating
	 * @param RevisionRecord $revisionRecord
	 * @param DerivedPageDataUpdater $updater
	 * @param array $options Options map; supports "recursive"
	 */
	public function __construct(
		ILBFactory $lbFactory,
		UserIdentity $user,
		WikiPage $page,
		RevisionRecord $revisionRecord,
		DerivedPageDataUpdater $updater,
		array $options
	) {
		parent::__construct();

		$this->lbFactory = $lbFactory;
		$this->user = $user;
		$this->page = $page;
		$this->revisionRecord = $revisionRecord;
		$this->updater = $updater;
		$this->recursive = !empty( $options['recursive'] );
	}

	public function getTransactionRoundRequirement() {
		return self::TRX_ROUND_ABSENT;
	}

	public function doUpdate() {
		$updates = $this->updater->getSecondaryDataUpdates( $this->recursive );
		foreach ( $updates as $update ) {
			if ( $update instanceof LinksUpdate ) {
				$update->setRevisionRecord( $this->revisionRecord );
				$update->setTriggeringUser( $this->user );
			}
			if ( $update instanceof DataUpdate ) {
				$update->setCause( $this->causeAction, $this->causeAgent );
			}
		}

		// T221577, T248003: flush any transaction; each update needs outer transaction scope
		// and the code above may have implicitly started one.
		$this->lbFactory->commitPrimaryChanges( __METHOD__ );

		$e = null;
		foreach ( $updates as $update ) {
			try {
				DeferredUpdates::attemptUpdate( $update );
			} catch ( Exception $e ) {
				// Try as many updates as possible on the first pass
				MWExceptionHandler::rollbackPrimaryChangesAndLog( $e );
			}
		}

		if ( $e instanceof Exception ) {
			throw $e; // trigger RefreshLinksJob enqueue via getAsJobSpecification()
		}
	}

	public function getAsJobSpecification() {
		return [
			'domain' => $this->lbFactory->getLocalDomainID(),
			'job' => new JobSpecification(
				'refreshLinksPrioritized',
				[
					'namespace' => $this->page->getTitle()->getNamespace(),
					'title' => $this->page->getTitle()->getDBkey(),
					// Reuse the parser cache if it was saved
					'rootJobTimestamp' => $this->revisionRecord->getTimestamp(),
					'useRecursiveLinksUpdate' => $this->recursive,
					'triggeringUser' => [
						'userId' => $this->user->getId(),
						'userName' => $this->user->getName()
					],
					'triggeringRevisionId' => $this->revisionRecord->getId(),
					'causeAction' => $this->getCauseAction(),
					'causeAgent' => $this->getCauseAgent()
				],
				[ 'removeDuplicates' => true ]
			)
		];
	}
}
