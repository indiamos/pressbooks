<?php
/**
 * Contains functions for creating and managing a user's PressBooks Catalog.
 *
 * @author  PressBooks <code@pressbooks.org>
 * @license GPLv2 (or any later version)
 */

namespace PressBooks;


class Catalog {


	/**
	 * The value for option: pb_catalog_db_version
	 *
	 * @see install()
	 * @var int
	 */
	static $currentVersion = 3;


	/**
	 * Maximum number allowed in tags_group column
	 *
	 * @var int
	 */
	static $maxTagsGroup = 2;


	/**
	 * Catalog tables, set in constructor
	 *
	 * @var string
	 */
	protected $dbTable, $dbTagsTable, $dbLinkTable;


	/**
	 * User ID to construct this object
	 *
	 * @var int
	 */
	protected $userId;


	/**
	 * Column structure of catalog_table
	 *
	 * @var array
	 */
	protected $dbColumns = array(
		'users_id' => '%d',
		'blogs_id' => '%d',
		'deleted' => '%d',
		'featured' => '%d',
	);


	/**
	 * Profile keys, stored in user_meta table
	 *
	 * @var array
	 */
	protected $profileMetaKeys = array(
		'pressbooks_catalog_about' => '%s',
		'pressbooks_catalog_logo' => '%s',
		// Tags added in constructor
	);


	/**
	 * @param int $user_id (optional)
	 */
	function __construct( $user_id = 0 ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		// Tables
		$this->dbTable = $wpdb->base_prefix . 'pressbooks_catalog';
		$this->dbTagsTable = $wpdb->base_prefix . 'pressbooks_tags';
		$this->dbLinkTable = $wpdb->base_prefix . 'pressbooks__catalog__tags';

		// Tags
		for ( $i = 1; $i <= static::$maxTagsGroup; ++$i ) {
			$this->profileMetaKeys["pressbooks_catalog_tag_{$i}_name"] = '%s';
		}

		// User
		if ( $user_id ) {
			$this->userId = $user_id;
		} elseif ( isset( $_REQUEST['user_id'] ) && current_user_can( 'edit_user', (int) $_REQUEST['user_id'] ) ) {
			$this->userId = (int) $_REQUEST['user_id'];
		} else {
			$this->userId = get_current_user_id();
		}
	}


	/**
	 * Get User ID
	 *
	 * @return int
	 */
	function getUserId() {

		return $this->userId;
	}


	/**
	 * Get an entire catalog.
	 *
	 * @return mixed
	 */
	function get() {

		/** @var $wpdb \wpdb */
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT * FROM {$this->dbTable} WHERE users_id = %d AND deleted = 0 ", $this->userId );

		return $wpdb->get_results( $sql, ARRAY_A );
	}


	/**
	 * Save an entire catalog.
	 *
	 * @param array $items
	 */
	function save( array $items ) {

		foreach ( $items as $item ) {
			if ( isset( $item['blogs_id'] ) ) {
				$this->saveBook( $this->userId, $item['blogs_id'], $item );
			}
		}
	}


	/**
	 * Delete an entire catalog.
	 *
	 * @param bool $for_real (optional)
	 *
	 * @return mixed
	 */
	function delete( $for_real = false ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		if ( $for_real ) {
			return $wpdb->delete( $this->dbTable, array( 'users_id' => $this->userId ), array( '%d' ) );
		} else {
			return $wpdb->update( $this->dbTable, array( 'deleted' => 1 ), array( 'users_id' => $this->userId ), array( '%d' ), array( '%d' ) );
		}
	}


	/**
	 * Get a book from a user catalog.
	 *
	 * @param int $blog_id
	 *
	 * @return mixed
	 */
	function getBook( $blog_id ) {

		/** @var $wpdb \wpdb */
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT * FROM {$this->dbTable} WHERE users_id = %d AND blogs_id = %d AND deleted = 0 ", $this->userId, $blog_id );

		return $wpdb->get_row( $sql, ARRAY_A );
	}


	/**
	 * Get only blog IDs.
	 *
	 * @return array
	 */
	function getBookIds() {

		/** @var $wpdb \wpdb */
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT blogs_id FROM {$this->dbTable} WHERE users_id = %d AND deleted = 0 ", $this->userId );

		return $wpdb->get_col( $sql );
	}


	/**
	 * Save a book to a user catalog.
	 *
	 * @param $blog_id
	 * @param array $item
	 *
	 * @return mixed
	 */
	function saveBook( $blog_id, array $item ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		unset( $item['users_id'], $item['blogs_id'], $item['deleted'] ); // Don't allow spoofing

		$data = array( 'users_id' => $this->userId, 'blogs_id' => $blog_id, 'deleted' => 0 );
		$format = array( 'users_id' => $this->dbColumns['users_id'], 'blogs_id' => $this->dbColumns['blogs_id'], 'deleted' => $this->dbColumns['deleted'] );

		foreach ( $item as $key => $val ) {
			if ( isset( $this->dbColumns[$key] ) ) {
				$data[$key] = $val;
				$format[$key] = $this->dbColumns[$key];
			}
		}

		// INSERT ... ON DUPLICATE KEY UPDATE
		// @see http://dev.mysql.com/doc/refman/5.0/en/insert-on-duplicate.html

		$args = array();
		$sql = "INSERT INTO {$this->dbTable} ( ";
		foreach ( $data as $key => $val ) {
			$sql .= "`$key`, ";
		}
		$sql = rtrim( $sql, ', ' ) . ' ) VALUES ( ';

		foreach ( $format as $key => $val ) {
			$sql .= $val . ', ';
			$args[] = $data[$key];
		}
		$sql = rtrim( $sql, ', ' ) . ' ) ON DUPLICATE KEY UPDATE ';

		$i = 0;
		foreach ( $data as $key => $val ) {
			if ( 'users_id' == $key || 'blogs_id' == $key ) continue;
			$sql .= "`$key` = {$format[$key]}, ";
			$args[] = $val;
			++$i;
		}
		$sql = rtrim( $sql, ', ' );
		if ( ! $i ) $sql .= ' users_id = users_id '; // Do nothing

		$sql = $wpdb->prepare( $sql, $args );

		return $wpdb->query( $sql );
	}


	/**
	 * Delete a book from a user catalog.
	 *
	 * @param int $blog_id
	 * @param bool $for_real (optional)
	 *
	 * @return mixed
	 */
	function deleteBook( $blog_id, $for_real = false ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		if ( $for_real ) {
			return $wpdb->delete( $this->dbTable, array( 'users_id' => $this->userId, 'blogs_id' => $blog_id ), array( '%d', '%d' ) );
		} else {
			return $wpdb->update( $this->dbTable, array( 'deleted' => 1 ), array( 'users_id' => $this->userId, 'blogs_id' => $blog_id ), array( '%d' ), array( '%d', '%d' ) );
		}
	}


	/**
	 * Get tags
	 *
	 * @param int $tag_group
	 *
	 * @return mixed
	 */
	function getTags( $tag_group ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		$sql = "SELECT {$this->dbTagsTable}.id, {$this->dbTagsTable}.tag FROM {$this->dbTagsTable}
 				INNER JOIN {$this->dbLinkTable} ON {$this->dbLinkTable}.tags_id = {$this->dbTagsTable}.id
 				INNER JOIN {$this->dbTable} ON {$this->dbTable}.users_id = {$this->dbLinkTable}.users_id
 				WHERE {$this->dbLinkTable}.tags_group = %d AND {$this->dbLinkTable}.users_id = %d ";

		$sql = $wpdb->prepare( $sql, $tag_group, $this->userId );

		return $wpdb->get_results( $sql, ARRAY_A );
	}


	/**
	 * Get all tags for a book
	 *
	 * @param int $blog_id
	 * @param int $tag_group
	 *
	 * @return mixed
	 */
	function getTagsByBook( $blog_id, $tag_group ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		$sql = "SELECT {$this->dbTagsTable}.id, {$this->dbTagsTable}.tag FROM {$this->dbTagsTable}
 				INNER JOIN {$this->dbLinkTable} ON {$this->dbLinkTable}.tags_id = {$this->dbTagsTable}.id
 				INNER JOIN {$this->dbTable} ON {$this->dbTable}.users_id = {$this->dbLinkTable}.users_id AND {$this->dbTable}.blogs_id = {$this->dbLinkTable}.blogs_id
 				WHERE {$this->dbLinkTable}.tags_group = %d AND {$this->dbLinkTable}.users_id = %d AND {$this->dbLinkTable}.blogs_id = %d ";

		$sql = $wpdb->prepare( $sql, $tag_group, $this->userId, $blog_id );

		return $wpdb->get_results( $sql, ARRAY_A );
	}


	/**
	 * Save tag
	 *
	 * @param string $tag
	 * @param int $blog_id
	 * @param int $tag_group
	 *
	 * @return \false|int
	 */
	function saveTag( $tag, $blog_id, $tag_group ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		$tag = strip_tags( $tag );
		$tag = trim( $tag );

		// INSERT ... ON DUPLICATE KEY UPDATE
		// @see http://dev.mysql.com/doc/refman/5.0/en/insert-on-duplicate.html

		$sql = "INSERT INTO {$this->dbTagsTable} ( users_id, tag ) VALUES ( %d, %s ) ON DUPLICATE KEY UPDATE id = id ";
		$sql = $wpdb->prepare( $sql, $this->userId, $tag );
		$_ = $wpdb->query( $sql );

		// Get ID

		$sql = "SELECT id FROM {$this->dbTagsTable} WHERE tag = %s ";
		$sql = $wpdb->prepare( $sql, $tag );
		$tag_id = $wpdb->get_var( $sql );

		// Create JOIN

		$sql = "INSERT INTO {$this->dbLinkTable} ( users_id, blogs_id, tags_id, tags_group ) VALUES ( %d, %d, %d, %d ) ON DUPLICATE KEY UPDATE users_id = users_id ";
		$sql = $wpdb->prepare( $sql, $this->userId, $blog_id, $tag_id, $tag_group );
		$result = $wpdb->query( $sql );

		return $result;
	}


	/**
	 * Delete a tag.
	 *
	 * IMPORTANT: The 'for_real' option is extremely destructive. Do not use unless you know what you are doing.
	 *
	 * @param string $tag
	 * @param int $blog_id
	 * @param int $tag_group
	 * @param bool $for_real (optional)
	 *
	 * @return mixed
	 */
	function deleteTag( $tag, $blog_id, $tag_group, $for_real = false ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		// Get ID

		$sql = "SELECT id FROM {$this->dbTagsTable} WHERE tag = %s ";
		$sql = $wpdb->prepare( $sql, $tag );
		$tag_id = $wpdb->get_var( $sql );

		if ( ! $tag_id )
			return false;

		if ( $for_real && is_super_admin() ) {

			$wpdb->delete( $this->dbLinkTable, array( 'tags_id' => $tag_id ), array( '%d' ) );
			$wpdb->delete( $this->dbTagsTable, array( 'id' => $tag_id ), array( '%d' ) );
			$result = 1;

		} else {

			$result = $wpdb->delete( $this->dbLinkTable, array( 'users_id' => $this->userId, 'blogs_id' => $blog_id, 'tags_id' => $tag_id, 'tags_group' => $tag_group ), array( '%d', '%d', '%d', '%d' ) );
		}

		// TODO:
		// $wpdb->query( "OPTIMIZE TABLE {$this->dbLinkTable} " );
		// $wpdb->query( "OPTIMIZE TABLE {$this->dbTagsTable} " );

		return $result;
	}


	/**
	 * Delete all tags from a user catalog
	 *
	 * Note: Doesn't actually delete a tag, just removes the association in dbLinkTable
	 *
	 * @param $blog_id
	 * @param $tag_group
	 *
	 * @return mixed
	 */
	function deleteTags( $blog_id, $tag_group ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		$result = $wpdb->delete( $this->dbLinkTable, array( 'users_id' => $this->userId, 'blogs_id' => $blog_id, 'tags_group' => $tag_group ), array( '%d', '%d', '%d' ) );

		// TODO:
		// $wpdb->query( "OPTIMIZE TABLE {$this->dbLinkTable} " );

		return $result;

	}


	/**
	 * Find all IDs in dbTagsTable that have no matching ID in dbLinkTable and delete them.
	 */
	function purgeOrphanTags() {

		// TODO
	}


	/**
	 * Get catalog profile.
	 *
	 * @return array
	 */
	function getProfile() {

		$profile = array();
		foreach ( $this->profileMetaKeys as $key => $type ) {
			$profile[$key] = get_user_meta( $this->userId, $key, true );
		}

		return $profile;
	}


	/**
	 * Save catalog profile
	 *
	 * @param array $item
	 */
	function saveProfile( array $item ) {

		/** @var $wpdb \wpdb */
		global $wpdb;

		// Sanitize
		$item = array_intersect_key( $item, $this->profileMetaKeys );

		foreach ( $item as $key => $val ) {

			if ( '%d' == $this->profileMetaKeys[$key] ) {
				$val = (int) $val;
			} elseif ( '%f' == $this->profileMetaKeys[$key] ) {
				$val = (float) $val;
			} else {
				$val = (string) $val;
			}

			update_user_meta( $this->userId, $key, $val );
		}
	}


	// ----------------------------------------------------------------------------------------------------------------
	// Upgrades
	// ----------------------------------------------------------------------------------------------------------------


	/**
	 * Upgrade catalog.
	 *
	 * @param int $version
	 */
	function upgrade( $version ) {

		if ( $version < self::$currentVersion ) {
			$this->createTables();
		}
	}


	/**
	 * DB Delta the initial Catalog tables.
	 *
	 * If you change this, then don't forget to also change $this->dbColumns
	 *
	 * @see dbColumns
	 * @see http://codex.wordpress.org/Creating_Tables_with_Plugins#Creating_or_Updating_the_Table
	 */
	protected function createTables() {

		/* TODO: Before launch
		 DROP TABLE  `wp_pressbooks_catalog` ,
		`wp_pressbooks_tags` ,
		`wp_pressbooks__catalog__tags` ;
		 */

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$sql = "CREATE TABLE {$this->dbTable} (
				users_id INT(11) NOT null,
  				blogs_id INT(11) NOT null,
  				deleted TINYINT(1) NOT null,
  				featured INT(11) DEFAULT 0 NOT null ,
  				PRIMARY KEY  (users_id, blogs_id),
  				KEY featured (featured)
				); ";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$this->dbLinkTable} (
				users_id INT(11) NOT null,
  				blogs_id INT(11) NOT null,
  				tags_id INT(11) NOT null,
  				tags_group INT(3) NOT null,
  				PRIMARY KEY  (users_id, blogs_id, tags_id, tags_group)
				); ";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$this->dbTagsTable} (
				id INT(11) NOT null AUTO_INCREMENT,
  				users_id INT(11) NOT null,
  				tag VARCHAR(255) NOT null,
  				PRIMARY KEY  (id),
  				UNIQUE KEY (tag)
				); ";
		dbDelta( $sql );
	}


	// ----------------------------------------------------------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------------------------------------------------------


	/**
	 * Return an array of tags from a comma delimited string
	 *
	 * @param string $tags
	 *
	 * @return array
	 */
	static function stringToTags( $tags ) {

		$tags = mb_split( ',', $tags );

		foreach ( $tags as $key => &$val ) {
			$val = strip_tags( $val );
			$val = mb_convert_case( $val, MB_CASE_TITLE, 'UTF-8' );
			$val = mb_split( '\W', $val ); // Split on negated \w
			$val = implode( ' ', $val ); // Put back together with spaces
			$val = trim( $val );
			if ( ! $val ) unset( $tags[$key] );
		}

		return $tags;
	}


	/**
	 * Return a comma delimited string from an SQL array of tags, in alphabetical order.
	 *
	 * @param array $tags
	 *
	 * @return string
	 */
	static function tagsToString( array $tags ) {

		$tags = \PressBooks\Utility\multi_sort( $tags, 'tag:asc' );

		$str = '';
		foreach ( $tags as $tag ) {
			$str .= $tag['tag'] . ', ';
		}

		return rtrim( $str, ', ' );
	}


	/**
	 * WP Hook, Instantiate UI
	 */
	static function addMenu() {

		switch ( @$_REQUEST['action'] ) {

			case 'edit_profile':
			case 'edit_tags':
				require( PB_PLUGIN_DIR . 'admin/templates/catalog.php' );
				break;

			case 'add':
			case 'remove':
				// This should not happen, formSubmit() is supposed to catch this
				break;

			default:
				Catalog_List_Table::addMenu();
				break;
		}
	}


	// ----------------------------------------------------------------------------------------------------------------
	// Catch form submissions
	// ----------------------------------------------------------------------------------------------------------------


	/**
	 * Catch me
	 */
	static function formSubmit() {

		if ( false == static::isFormSubmission() || false == current_user_can( 'read' ) ) {
			// Don't do anything in this function, bail.
			return;
		}

		if ( static::isCurrentAction( 'add' ) ) {
			static::formBulk( 'add' );
		} elseif ( static::isCurrentAction( 'remove' ) ) {
			static::formBulk( 'remove' );
		} elseif ( static::isCurrentAction( 'edit_tags' ) ) {
			static::formTags();
		} elseif ( static::isCurrentAction( 'edit_profile' ) ) {
			static::formProfile();
		}


	}


	/**
	 * Check if a user submitted something to index.php?page=pb_catalog
	 *
	 * @return bool
	 */
	static function isFormSubmission() {

		if ( 'pb_catalog' != @$_REQUEST['page'] ) {
			return false;
		}

		if ( ! empty( $_POST ) ) {
			return true;
		}

		if ( static::isCurrentAction( 'add' ) || static::isCurrentAction( 'remove' ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Two actions are possible in a generic WP_List_Table form. The first takes precedence.
	 *
	 * @param $action
	 *
	 * @see \WP_List_Table::current_action
	 *
	 * @return bool
	 */
	static function isCurrentAction( $action ) {

		if ( isset( $_REQUEST['action'] ) && - 1 != $_REQUEST['action'] )
			$compare = $_REQUEST['action'];
		else if ( isset( $_REQUEST['action2'] ) && - 1 != $_REQUEST['action2'] )
			$compare = $_REQUEST['action2'];
		else
			return false;

		return ( $action == $compare );
	}


	/**
	 * @param $action
	 */
	protected static function formBulk( $action ) {

		$redirect_url = get_bloginfo( 'url' ) . '/wp-admin/index.php?page=pb_catalog';
		$redirect_url = Catalog_List_Table::addSearchParamsToUrl( $redirect_url );

		/* Sanity check */

		if ( ! empty( $_REQUEST['book'] ) ) {
			// Bulk
			check_admin_referer( 'bulk-books' );
			$books = $_REQUEST['book'];

		} elseif ( ! empty( $_REQUEST['ID'] ) ) {
			// Single item
			check_admin_referer( $_REQUEST['ID'] );
			$books = array( $_REQUEST['ID'] );

		} else {
			// Handle empty bulk submission
			if ( ! empty( $_REQUEST['user_id'] ) ) {
				$redirect_url .= '&user_id=' . $_REQUEST['user_id'];
			}
			\PressBooks\Redirect\location( $redirect_url );
		}

		// Make an educated guess as to who's catalog we are editing
		list( $user_id, $_ ) = explode( ':', $books[0] );

		if (  ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( __( 'You do not have permission to do that.', 'pressbooks' ) );
		}

		// Fix redirect URL
		if ( get_current_user_id() != $user_id ) {
			$redirect_url .= '&user_id=' . $user_id;
		}

		/* Go! */

		$catalog = new static( $user_id );

		foreach ( $books as $book ) {
			list( $_, $book_id ) = explode( ':', $book );
			if ( 'add' == $action ) {
				$catalog->saveBook( $book_id, array() );
			} elseif ( 'remove' == $action ) {
				$catalog->deleteBook( $book_id );
			} else {
				// TODO: Throw Error
				$_SESSION['pb_errors'][] = "Invalid action: $action";
			}
		}

		// Ok!
		$_SESSION['pb_notices'][] = __( 'Settings saved.' );

		// Redirect back to form
		\PressBooks\Redirect\location( $redirect_url );
	}


	/**
	 *
	 */
	protected static function formTags() {

		check_admin_referer( 'pb-user-catalog' );

		list( $user_id, $blog_id ) = explode( ':', @$_REQUEST['ID'] );
		if ( ! empty( $_REQUEST['user_id'] ) ) $user_id = $_REQUEST['user_id'];
		if ( ! empty( $_REQUEST['blog_id'] ) ) $blog_id = $_REQUEST['blog_id'];
		$user_id = absint( $user_id );
		$blog_id = absint( $blog_id );

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( __( 'You do not have permission to do that.', 'pressbooks' ) );
		}

		// Set Redirect URL
		if ( get_current_user_id() != $user_id ) {
			$redirect_url = get_bloginfo( 'url' ) . '/wp-admin/index.php?page=pb_catalog&user_id=' . $user_id;
		} else {
			$redirect_url = get_bloginfo( 'url' ) . '/wp-admin/index.php?page=pb_catalog';
		}

		/* Go! */

		$catalog = new static( $user_id );
		$catalog->saveBook( $blog_id, array( 'featured' => absint( @$_REQUEST['featured'] ) ) );

		// Tags
		for ( $i = 1; $i <= static::$maxTagsGroup; ++$i ) {
			$catalog->deleteTags( $blog_id, $i );
			$tags = $catalog::stringToTags( $_REQUEST["tags_$i"] );
			foreach ( $tags as $tag ) {
				$catalog->saveTag( $tag, $blog_id, $i );
			}
		}

		// Ok!
		$_SESSION['pb_notices'][] = __( 'Settings saved.' );

		// Redirect back to form
		\PressBooks\Redirect\location( $redirect_url );
	}


	/**
	 *
	 */
	protected static function formProfile() {

		check_admin_referer( 'pb-user-catalog' );

		$user_id = @$_REQUEST['user_id'];
		$user_id = absint( $user_id );

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( __( 'You do not have permission to do that.', 'pressbooks' ) );
		}

		// Set Redirect URL
		if ( get_current_user_id() != $user_id ) {
			$redirect_url = get_bloginfo( 'url' ) . '/wp-admin/index.php?page=pb_catalog&user_id=' . $user_id;
		} else {
			$redirect_url = get_bloginfo( 'url' ) . '/wp-admin/index.php?page=pb_catalog';
		}

		/* Go! */

		$catalog = new static( $user_id );
		$catalog->saveProfile( $_POST );

		// Ok!
		$_SESSION['pb_notices'][] = __( 'Settings saved.' );

		// Redirect back to form
		\PressBooks\Redirect\location( $redirect_url );
	}


}
