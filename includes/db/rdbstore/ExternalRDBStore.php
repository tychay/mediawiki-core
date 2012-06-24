<?php
/**
 * This file deals with sharded RDBMs stores.
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
 * @ingroup RDBStore
 * @author Aaron Schulz
 */

/**
 * Class representing an external DB storage system.
 * Tables are sharded vertically based on the wiki ID and
 * horizontally based on a table column used as a "shard key".
 *
 * The shard key determines what cluster a table partition maps to.
 * We use cluster = (integerhash(column value) mod (# of clusters)) + 1.
 * The 1 is added to the remainder so the cluster names start at "1".
 *
 * The number of clusters must be a power of 2. This makes the re-balancing required
 * with the addition of new clusters fairly straightforward and avoids downtime.
 * For example, say we have four clusters:
 *   cluster1 [hash has remainder 0]
 *   cluster2 [hash has remainder 1]
 *   cluster3 [hash has remainder 2]
 *   cluster4 [hash has remainder 3]
 * We can add four new clusters, resulting in the following:
 *   cluster1 [remainder (0 now, 0 before)]
 *   cluster2 [remainder (1 now, 1 before)]
 *   cluster3 [remainder (2 now, 2 before)]
 *   cluster4 [remainder (3 now, 3 before)]
 *   cluster5 [start as replica of cluster1] [remainder (4 now, 0 before)]
 *   cluster6 [start as replica of cluster2] [remainder (5 now, 1 before)]
 *   cluster7 [start as replica of cluster3] [remainder (6 now, 2 before)]
 *   cluster8 [start as replica of cluster4] [remainder (7 now, 3 before)]
 * What was in cluster1 is now split between cluster1 and cluster5.
 * Since cluster5 started as a full clone of cluster1 (via MySQL replication),
 * the only disruption will be a brief read-only period where cluster5 is
 * caught up and the ExternalRDBStore cluster config is updated on the servers.
 * After the change is done, there will be outdated, duplicate, partition tables
 * that are on the wrong shard and no longer used. These can be dropped as needed.
 *
 * The same trick can be used to keep doubling the amount of storage.
 *
 * @ingroup RDBStore
 * @since 1.20
 */
class ExternalRDBStore extends RDBStore {
	/** @var Array */
	protected $clusterLBs = array();

	protected $clusterCount; // integer
	protected $clusterPrefix; // string
	protected $hasTransaction = false; // boolean

	const SHARDS = 1024; // integer; number of partitions per table/wiki

	/**
	 * @param $options array
	 * @throws MWException
	 */
	public function __construct( array $options ) {
		parent::__construct( $options );

		if ( !strlen( $options['clusterPrefix'] ) ) {
			throw new MWException( "Option 'clusterPrefix' is not valid." );
		}
		$this->clusterPrefix = $options['clusterPrefix'];

		$logB2 = log( $options['clusterCount'], 2 ); // float
		if ( $logB2 != floor( $logB2 ) ) {
			throw new MWException( "Option 'clusterCount' must be a power of 2." );
		}
		$this->clusterCount = $options['clusterCount'];

		for ( $i = 1; $i <= $options['clusterCount']; $i++ ) {
			$cluster = $options['clusterPrefix'] . $i;
			$this->clusterLBs[$cluster] = wfGetLBFactory()->getExternalLB( $cluster );
		}
	}

	/**
	 * @see RDBStore::beginTransaction()
	 * @see DatabaseBase::begin()
	 * @return bool
	 */
	public function beginTransaction() {
		$funcBegin = function( DatabaseBase $conn ) {
			if ( !$conn->trxLevel() ) {
				$conn->begin( __METHOD__ ); // start transaction
			}
		};

		$this->hasTransaction = true; // require begin() on new connections
		foreach ( $this->clusterLBs as $cluster => $lb ) {
			$lb->forEachOpenConnection( $funcBegin ); // existing connections
		}

		return true;
	}

	/**
	 * @see RDBStore::commitTransaction()
	 * @see DatabaseBase::commit()
	 * @return bool
	 */
	public function commitTransaction() {
		$funcCommit = function( DatabaseBase $conn ) {
			if ( $conn->trxLevel() ) {
				$conn->commit( __METHOD__ ); // finish transaction
			}
		};

		$this->hasTransaction = false; // don't require begin() on new connections
		foreach ( $this->clusterLBs as $cluster => $lb ) {
			$lb->forEachOpenConnection( $funcCommit );
		}

		return true;
	}

	/**
	 * @see RDBStore::rollbackTransaction()
	 * @see DatabaseBase::rollback()
	 * @return bool
	 */
	public function rollbackTransaction() {
		$funcRollback = function( DatabaseBase $conn ) {
			if ( $conn->trxLevel() ) {
				$conn->rollback( __METHOD__ ); // cancel transaction
			}
		};

		$this->hasTransaction = false; // don't require begin() on new connections
		foreach ( $this->clusterLBs as $cluster => $lb ) {
			$lb->forEachOpenConnection( $funcBegin );
		}

		return true;
	}

	/**
	 * Check if the store is currently in a DB transaction.
	 * Outside callers should generally not need this and should avoid using it.
	 *
	 * @return bool
	 */
	public function hasTransaction() {
		return $this->hasTransaction;
	}

	/**
	 * Get a map of DB cluster names to shard indexes they serve.
	 * Outside callers should generally not need this and should avoid using it.
	 *
	 * @return array
	 */
	public function getClusterMapping() {
		$map = array();
		for ( $index = 0; $index < self::SHARDS; $index++ ) {
			$map[$this->getClusterForIndex( $index )][] = $index;
		}
		return $map;
	}

	/**
	 * Get a map of DB cluster names to the names of the partition
	 * tables they serve for a given virtual DB table and shard column.
	 * Outside callers should generally not need this and should avoid using it.
	 *
	 * @param $table string Virtual DB table
	 * @param $column string Column the table is sharded on
	 * @return Array
	 */
	public function getPartitionTablesByCluster( $table, $column ) {
		$map = array();
		for ( $index = 0; $index < self::SHARDS; $index++ ) {
			$shard = $this->formatShardIndex( $index ); // e.g "0033"
			$map[$this->getClusterForIndex( $index )][] = "{$table}__{$shard}__{$column}";
		}
		return $map;
	}

	/**
	 * Format a shard number by padding out the digits as needed.
	 * Outside callers should generally not need this and should avoid using it.
	 *
	 * @param $index integer
	 * @return string
	 */
	public function formatShardIndex( $index ) {
		$decimals = strlen( self::SHARDS - 1 );
		return sprintf( "%0{$decimals}d", $index ); // e.g "0033"
	}

	/**
	 * @param $index integer
	 * @return string
	 */
	protected function getClusterForIndex( $index ) {
		return $this->clusterPrefix . (( $index % $this->clusterCount ) + 1);
	}

	/**
	 * Get an object representing a shard of a virtual DB table.
	 * Each table is sharded on at least one column key, and possibly
	 * denormalized and sharded on muliple column keys (e.g. rev ID, page ID, user ID).
	 *
	 * @see RDBStore::doGetTablePartition()
	 * @return ExternalRDBStoreTablePartition
	 */
	protected function doGetTablePartition( $table, $column, $value, $wiki = false ) {
		$wiki = ( $wiki === false ) ? wfWikiID() : $wiki;

		// Map this row to a consistent table shard
		$hash = substr( md5( $value ), 0, 4 ); // 65535 possible values
		$index = (int)base_convert( $hash, 16, 10 ) % self::SHARDS; // [0,1023]
		$shard = $this->formatShardIndex( $index ); // e.g "0033"
		$sTable = "{$table}__{$shard}__{$column}"; // consistent table name

		// Map this row to a cluster
		$lb = $this->clusterLBs[$this->getClusterForIndex( $index )];

		return new ExternalRDBStoreTablePartition( $this, $lb, $sTable, $column, $value, $wiki );
	}
}

/**
 * Class representing a single partition of a virtual DB table
 *
 * @ingroup RDBStore
 * @since 1.20
 */
class ExternalRDBStoreTablePartition extends RDBStoreTablePartition {
	/** @var ExternalRDBStore */
	protected $rdbStore;
	/** @var LoadBalancer */
	protected $lb;

	/**
	 * @param $rdbStore ExternalRDBStore
	 * @param $lb LoadBalancer
	 * @param $sTable string Partition table name
	 * @param $key string Shard key column name
	 * @param $value Array Shard key column value
	 * @param $wiki string Wiki ID
	 */
	public function __construct(
		ExternalRDBStore $rdbStore, LoadBalancer $lb, $sTable, $key, $value, $wiki
	) {
		$this->rdbStore = $rdbStore;
		$this->lb       = $lb;
		$this->sTable   = $sTable;
		$this->key      = $key;
		$this->value    = $value;
		$this->wiki     = $wiki;
	}

	/**
	 * @see RDBStoreTablePartition
	 * @return DatabaseBase
	 */
	public function getSlaveDB() {
		$conn = $this->lb->getConnection( DB_SLAVE, array(), $this->wiki );
		if ( $this->rdbStore->hasTransaction() && !$conn->trxLevel() ) {
			$conn->begin( __METHOD__ );
		}
		return $conn;
	}

	/**
	 * @see RDBStoreTablePartition
	 * @return DatabaseBase
	 */
	public function getMasterDB() {
		$conn = $this->lb->getConnection( DB_MASTER, array(), $this->wiki );
		if ( $this->rdbStore->hasTransaction() && !$conn->trxLevel() ) {
			$conn->begin( __METHOD__ );
		}
		return $conn;
	}
}
