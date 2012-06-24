<?php
/**
 * @defgroup RDBStore RDBStore
 *
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
 * Class representing a relational DB storage system.
 * Callers access data as if it was horizontally partitioned,
 * which may actually be the case for the external RDB stores.
 * Partitioning is based on a single non-NULL table column.
 *
 * @ingroup RDBStore
 * @since 1.20
 */
abstract class RDBStore {
	/**
	 * @param $options array
	 */
	public function __construct( array $options ) {}

	/**
	 * Begin a transaction
	 *
	 * @see DatabaseBase::begin()
	 * @return bool
	 */
	abstract public function beginTransaction();

	/**
	 * Commit any transaction
	 *
	 * @see DatabaseBase::commit()
	 * @return bool
	 */
	abstract public function commitTransaction();

	/**
	 * Rollback any transaction
	 *
	 * @see DatabaseBase::rollback()
	 * @return bool
	 */
	abstract public function rollbackTransaction();

	/**
	 * Get an object representing a shard of a virtual DB table.
	 * Each table is sharded on at least one column key, and possibly
	 * denormalized and sharded on muliple column keys (e.g. thread ID, user ID).
	 *
	 * @param $table string Virtual table name
	 * @param $column string Shard key column name
	 * @param $value Array Shard key column value
	 * @param $wiki string Wiki ID; defaults to the current wiki
	 * @return RDBStoreTablePartition
	 */
	final public function getTablePartition( $table, $column, $value, $wiki = false ) {
		if ( !isset( $column ) || !isset( $value ) ) {
			throw new DBUnexpectedError( "Missing table shard column or value." );
		}
		return $this->doGetTablePartition( $table, $column, $value, $wiki );
	}

	/**
	 * @see RDBStore::getTablePartition()
	 * @return RDBStoreTablePartition
	 */
	abstract protected function doGetTablePartition( $table, $column, $value, $wiki = false );
}

/**
 * Class representing a single partition of a virtual DB table.
 * If a shard column value is provided, queries are restricted
 * to those that apply to that value; otherwise, queries can be
 * made on the entire table partition.
 *
 * @ingroup RDBStore
 * @since 1.20
 */
abstract class RDBStoreTablePartition {
	protected $wiki; // string; wiki ID
	protected $sTable; // string; partition table name
	protected $key; // string; column name
	protected $value; // string; column value

	/**
	 * @return string Wiki ID
	 */
	final public function getWiki() {
		return $this->wiki;
	}

	/**
	 * @return string Table name (e.g. "flaggedtemplates__0030__ft_rev_id")
	 */
	final public function getPartitionTable() {
		return $this->sTable;
	}

	/**
	 * @return string Name of the column used to shard on (e.g. "ft_rev_id")
	 */
	final public function getPartitionKey() {
		return $this->key;
	}

	/**
	 * @return string|null Value of the shard column or NULL
	 */
	final public function getPartitionKeyValue() {
		return $this->value;
	}

	/**
	 * @see DatabaseBase::select()
	 * @return ResultWrapper
	 */
	final public function selectFromSlave( $vars, array $conds, $fname, $options = array() ) {
		$this->assertKeyCond( $conds ); // sanity
		return $this->getSlaveDB()->select( $this->sTable, $vars, $conds, $fname, $options );
	}

	/**
	 * @see DatabaseBase::select()
	 * @return ResultWrapper
	 */
	final public function selectFromMaster( $vars, array $conds, $fname, $options = array() ) {
		$this->assertKeyCond( $conds ); // sanity
		return $this->getMasterDB()->select( $this->sTable, $vars, $conds, $fname, $options );
	}

	/**
	 * @see DatabaseBase::selectRow()
	 * @return ResultWrapper
	 */
	final public function selectRowFromSlave( $vars, array $conds, $fname, $options = array() ) {
		$this->assertKeyCond( $conds ); // sanity
		return $this->getSlaveDB()->selectRow( $this->sTable, $vars, $conds, $fname, $options );
	}

	/**
	 * @see DatabaseBase::selectRow()
	 * @return ResultWrapper
	 */
	final public function selectRowFromMaster( $vars, array $conds, $fname, $options = array() ) {
		$this->assertKeyCond( $conds ); // sanity
		return $this->getMasterDB()->selectRow( $this->sTable, $vars, $conds, $fname, $options );
	}

	/**
	 * @see DatabaseBase::insert()
	 * @return bool
	 */
	final public function insert( array $rows, $fname, $options = array() ) {
		$rows = ( isset( $rows[0] ) && is_array( $rows[0] ) ) ? $rows : array( $rows );
		array_map( array( $this, 'assertKeyCond' ), $rows ); // sanity
		return $this->getMasterDB()->insert( $this->sTable, $rows, $fname, $options );
	}

	/**
	 * @see DatabaseBase::replace()
	 * @return bool
	 */
	final public function replace( $uniqueIndexes, array $rows, $fname ) {
		$rows = ( isset( $rows[0] ) && is_array( $rows[0] ) ) ? $rows : array( $rows );
		array_map( array( $this, 'assertKeyCond' ), $rows ); // sanity
		return $this->getMasterDB()->replace( $this->sTable, $uniqueIndexes, $rows, $fname );
	}

	/**
	 * @see DatabaseBase::update()
	 * @return bool
	 */
	final public function update( $values, array $conds, $fname, $options = array() ) {
		$this->assertKeyCond( $conds ); // sanity
		return $this->getMasterDB()->update( $this->sTable, $values, $conds, $fname, $options );
	}

	/**
	 * @see DatabaseBase::delete()
	 * @return bool
	 */
	final public function delete( $conds, $fname ) {
		$this->assertKeyCond( $conds ); // sanity
		return $this->getMasterDB()->delete( $this->sTable, $conds, $fname );
	}

	/**
	 * Get a direct slave DB connection.
	 * Queries should always be done use the provided wrappers.
	 * This can be used to call functions like timestamp() or affectedRows().
	 *
	 * @return DatabaseBase
	 */
	abstract public function getSlaveDB();

	/**
	 * Get a direct master DB connection.
	 * Queries should always be done use the provided wrappers.
	 * This can be used to call functions like timestamp() or affectedRows().
	 *
	 * @return DatabaseBase
	 */
	abstract public function getMasterDB();

	/**
	 * Do a (partition key => value) assertion on a WHERE or insertion row array.
	 * This sanity checks that the column actually exists and protects against callers
	 * forgetting to add the condition or saving rows to the wrong table shard.
	 *
	 * @param $conds array
	 */
	final protected function assertKeyCond( array $conds ) {
		if ( !isset( $conds[$this->key] ) ) {
			throw new DBUnexpectedError( "Shard column '{$this->key}' value not provided." );
		} elseif ( $this->value !== null && $conds[$this->key] !== $this->value ) {
			throw new DBUnexpectedError( "Shard column '{$this->key}' value is mismatched." );
		}
	}
}
