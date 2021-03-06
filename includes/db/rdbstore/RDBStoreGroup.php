<?php
/**
 * This file deals with sharded database stores.
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
 * Factory class for getting RDBStore objects
 *
 * @ingroup RDBStore
 * @since 1.20
 */
class RDBStoreGroup {
	/** @var RDBStoreGroup */
	protected static $instance = null;

	/** @var Array */
	protected $rdbStoreConf = array(); // (store name => config array)
	/** @var Array */
	protected $rdbStoredTables = array(); // (table name => store name)

	/** @var Array */
	protected $lclStoreInstances = array();  // (wiki ID => RDBStore)
	/** @var Array */
	protected $extStoreInstances = array();  // (store name => RDBStore)

	protected function __construct() {}

	/**
	 * @return RDBStoreGroup
	 */
	public static function singleton() {
		if ( self::$instance == null ) {
			self::$instance = new self();
			self::$instance->initFromGlobals();
		}
		return self::$instance;
	}

	/**
	 * Destroy the singleton instance
	 *
	 * @return void
	 */
	public static function destroySingleton() {
		self::$instance = null;
	}

	/**
	 * Register db stores from the global variables
	 *
	 * @return void
	 */
	protected function initFromGlobals() {
		global $wgRDBStores, $wgRDBStoredTables;

		$this->rdbStoreConf = $wgRDBStores;
		$this->rdbStoredTables = $wgRDBStoredTables;
	}

	/**
	 * Get a DB store on the main cluster of a wiki.
	 * This uses a multi-singleton pattern to improve transactions.
	 *
	 * @param $wiki string Wiki ID
	 * @return LocalRDBStore
	 */
	public function getInternal( $wiki = false ) {
		$wiki = ( $wiki === false ) ? wfWikiID() : $wiki;
		if ( !isset( $this->lclStoreInstances[$wiki] ) ) {
			$this->lclStoreInstances[$wiki] = new LocalRDBStore( array( 'wiki' => $wiki ) );
		}
		return $this->lclStoreInstances[$wiki];
	}

	/**
	 * Get an external DB store by name.
	 * This uses a multi-singleton pattern to improve transactions.
	 *
	 * @param $name string Storage group ID
	 * @return ExternalRDBStore
	 */
	public function getExternal( $name ) {
		if ( !isset( $this->extStoreInstances[$name] ) ) {
			if ( !isset( $this->rdbStoreConf[$name] ) ) {
				throw new MWException( "No DB store defined with the name '$name'." );
			}
			$this->extStoreInstances[$name] = new ExternalRDBStore( $this->rdbStoreConf[$name] );
		}
		return $this->extStoreInstances[$name];
	}

	/**
	 * Get the DB store designated for a certain DB table.
	 * A LocalRDBStore will be returned if one is not configured.
	 *
	 * @param $table string
	 * @param $wiki string Wiki ID
	 * @return RDBStore
	 */
	public function getForTable( $table, $wiki = false ) {
		if ( isset( $this->rdbStoredTables[$table] ) ) {
			return $this->getExternal( $this->rdbStoredTables[$table] );
		} else {
			return $this->getInternal( $wiki );
		}
	}
}
