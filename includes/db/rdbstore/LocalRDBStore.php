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
 * Class representing a simple, non-external, DB storage system.
 * Tables are not sharded, and only the wiki cluster is used.
 *
 * @ingroup RDBStore
 * @since 1.20
 */
class LocalRDBStore extends RDBStore {
	protected $wiki; // string

	/**
	 * @param $options array
	 */
	public function __construct( array $options ) {
		$this->wiki = ( $options['wiki'] === false ) ? wfWikiID() : $options['wiki'];
	}

	/**
	 * @see RDBStore::beginTransaction()
	 * @return bool
	 */
	public function beginTransaction() {
		return true; // use main transaction
	}

	/**
	 * @see RDBStore::commitTransaction()
	 * @see DatabaseBase::commit()
	 * @return bool
	 */
	public function commitTransaction() {
		return true; // use main transaction
	}

	/**
	 * @see RDBStore::rollbackTransaction()
	 * @see DatabaseBase::rollback()
	 * @return bool
	 */
	public function rollbackTransaction() {
		return true; // use main transaction
	}

	/**
	 * @see RDBStore::doGetTablePartition()
	 * @return LocalRDBStoreTablePartition
	 */
	public function doGetTablePartition( $table, $column, $value, $wiki = false ) {
		$wiki = ( $wiki === false ) ? wfWikiID() : $wiki;
		if ( $wiki !== $this->wiki ) {
			throw new DBUnexpectedError( "Wiki ID '$wiki' does not match '{$this->wiki}'." );
		}
		return new LocalRDBStoreTablePartition( $table, $column, $value, $wiki );
	}
}

/**
 * Class representing a single partition of a virtual DB table.
 * This is just a regular table on the non-external main DB.
 *
 * @ingroup RDBStore
 * @since 1.20
 */
class LocalRDBStoreTablePartition extends RDBStoreTablePartition {
	/**
	 * @param $sTable string Partition table name
	 * @param $key string Shard key column name
	 * @param $value Array Shard key column value
	 * @param $wiki string Wiki ID
	 */
	public function __construct( $sTable, $key, $value, $wiki ) {
		$this->sTable = $sTable;
		$this->key    = $key;
		$this->value  = $value;
		$this->wiki   = $wiki;
	}

	/**
	 * @see RDBStoreTablePartition
	 * @return DatabaseBase
	 */
	public function getSlaveDB() {
		$lb = wfGetLBFactory()->getMainLB( $this->wiki );
		return $lb->getConnection( DB_SLAVE, array(), $this->wiki );
	}

	/**
	 * @see RDBStoreTablePartition
	 * @return DatabaseBase
	 */
	public function getMasterDB() {
		$lb = wfGetLBFactory()->getMainLB( $this->wiki );
		return $lb->getConnection( DB_MASTER, array(), $this->wiki );
	}
}
