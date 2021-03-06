<?php
/**
 * @author  PressBooks <code@pressbooks.org>
 * @license GPLv2 (or any later version)
 */
namespace PressBooks;


use \PressBooks\Book;
use \PressBooks\Catalog;


if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * @see http://codex.wordpress.org/Class_Reference/WP_List_Table
 */
class Catalog_List_Table extends \WP_List_Table {


	// ----------------------------------------------------------------------------------------------------------------
	// WordPress Overrides
	// ----------------------------------------------------------------------------------------------------------------


	/**
	 * Constructor, must call parent
	 */
	function __construct() {

		global $status, $page;

		$args = array(
			'singular' => 'book',
			'plural' => 'books', // Parent will create bulk nonce: "bulk-{$plural}"
			'ajax' => true,
		);
		parent::__construct( $args );
	}


	/**
	 * This method is called when the parent class can't find a method
	 * for a given column. For example, if the class needs to process a column
	 * named 'title', it would first see if a method named $this->column_title()
	 * exists. If it doesn't this one will be used.
	 *
	 * @see WP_List_Table::single_row_columns()
	 *
	 * @param array $item A singular item (one full row's worth of data)
	 * @param array $column_name The name/slug of the column to be processed
	 *
	 * @return string Text or HTML to be placed inside the column <td>
	 */
	function column_default( $item, $column_name ) {

		if ( preg_match( '/^tag_\d+$/', $column_name ) ) {
			return $this->renderTagColumn( $item, $column_name );
		}

		return esc_html( $item[$column_name] );
	}


	/**
	 * @param array $item A singular item (one full row's worth of data)
	 *
	 * @return string Text to be placed inside the column <td>
	 */
	function column_title( $item ) {

		list( $user_id, $blog_id ) = explode( ':', $item['ID'] );

		// Build row actions
		$actions = array(
			'visit' => sprintf( '<a href="%s">%s</a>', get_site_url( $blog_id ), __( 'Visit' ) ),
			'dashboard' => sprintf( '<a href="%s">%s</a>', get_admin_url( $blog_id ), __( 'Edit Book', 'pressbooks' ) ),
		);

		// Return the title contents
		return sprintf( '<span class="title">%1$s</span> %2$s',
			/*$1%s*/
			$item['title'],
			/*$2%s*/
			$this->row_actions( $actions )
		);
	}


	/**
	 * @param array $item A singular item (one full row's worth of data)
	 *
	 * @return string Text to be placed inside the column <td>
	 */
	function column_status( $item ) {

		// TODO, Better HTML?
		if ( $item['status'] ) $status = '<img src="' . esc_url( admin_url( 'images/yes.png' ) ) . '" alt="' . __( 'Yes' ) . '" />';
		else $status = '<img src="' . esc_url( admin_url( 'images/no.png' ) ) . '" alt="' . __( 'No' ) . '" />';

		$add_url = sprintf( ' ?page=%s&action=%s&ID=%s', $_REQUEST['page'], 'add', $item['ID'] );
		$add_url = add_query_arg( '_wpnonce', wp_create_nonce( $item['ID'] ), $add_url );
		$add_url = static::addSearchParamsToUrl( $add_url );

		$remove_url = sprintf( ' ?page=%s&action=%s&ID=%s', $_REQUEST['page'], 'remove', $item['ID'] );
		$remove_url = add_query_arg( '_wpnonce', wp_create_nonce( $item['ID'] ), $remove_url );
		$remove_url = static::addSearchParamsToUrl( $remove_url );

		// Build row actions
		$actions = array(
			'add' => sprintf( '<a href="%s">%s</a>', $add_url, __( 'Add', 'pressbooks' ) ),
			'remove' => sprintf( '<a href="%s">%s</a>', $remove_url, __( 'Remove', 'pressbooks' ) ),
		);

		// Return the title contents
		return sprintf( '<span class="status">%1$s</span> %2$s',
			/*$1%s*/
			$status,
			/*$2%s*/
			$this->row_actions( $actions )
		);
	}


	/**
	 * @param array $item A singular item (one full row's worth of data)
	 *
	 * @return string Text to be placed inside the column <td>
	 */
	function column_cover( $item ) {

		$img = esc_url( $item['cover'] );
		$alt = esc_attr( $item['title'] );

		$html = "<img src='$img' alt='$alt' />";

		return $html;
	}


	/**
	 * REQUIRED if displaying checkboxes or using bulk actions! The 'cb' column
	 * is given special treatment when columns are processed. It ALWAYS needs to
	 * have it's own method.
	 *
	 * @param array $item A singular item (one full row's worth of data)
	 *
	 * @return string Text to be placed inside the column <td>
	 */
	function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			/*$1%s*/
			$this->_args['singular'], // Let's simply repurpose the table's singular label ("book")
			/*$2%s*/
			$item['ID'] // The value of the checkbox should be the record's id
		);
	}


	/**
	 * This method dictates the table's columns and titles.
	 *
	 * @return array An associative array containing column information: 'slugs'=>'Visible Titles'
	 */
	function get_columns() {

		$profile = ( new Catalog() )->getProfile(); // PHP 5.4+

		$columns = array(
			'cb' => '<input type="checkbox" />', // Render a checkbox instead of text
			'status' => __( 'Status', 'pressbooks' ),
			'cover' => __( 'Cover', 'pressbooks' ),
			'title' => __( 'Title', 'pressbooks' ),
			'author' => __( 'Author', 'pressbooks' ),
		);

		for ( $i = 1; $i <= Catalog::$maxTagsGroup; ++$i ) {
			$columns["tag_{$i}"] = ! empty( $profile["pressbooks_catalog_tag_{$i}_name"] ) ? $profile["pressbooks_catalog_tag_{$i}_name"] : __( 'Tag', 'pressbooks' ) . " $i";
		}

		$columns['featured'] = __( 'Featured', 'pressbooks' );
		$columns['pub_date'] = __( 'Pub Date', 'pressbooks' );

		return $columns;
	}


	/**
	 * This method merely defines which columns should be sortable and makes them
	 * clickable - it does not handle the actual sorting.
	 *
	 * @return array An associative array containing all the columns that should be sortable: 'slugs'=>array('data_values',bool)
	 */
	function get_sortable_columns() {

		$sortable_columns = array(
			'status' => array( 'status', false ),
			'title' => array( 'title', false ),
			'author' => array( 'author', false ),
			'pub_date' => array( 'pub_date', false ),
		);

		return $sortable_columns;
	}



	/**
	 * @return array An associative array containing all the bulk actions: 'slugs'=>'Visible Titles'
	 */
	function get_bulk_actions() {

		$actions = array(
			'add' => __( 'Add', 'pressbooks' ),
			'remove' => __( 'Remove', 'pressbooks' ),
		);

		return $actions;
	}


	/**
	 * REQUIRED! This is where you prepare your data for display. This method will
	 * usually be used to query the database, sort and filter the data, and generally
	 * get it ready to be displayed. At a minimum, we should set $this->items and
	 * $this->set_pagination_args()
	 */
	function prepare_items() {

		// Define Columns
		$columns = $this->get_columns();
		$hidden = $this->getHiddenColumns();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Get data, sort
		$data = $this->getItemsData();
		$valid_cols = $this->get_sortable_columns();

		$order = ( ! empty( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'asc'; // If no order, default to asc
		if ( isset( $_REQUEST['orderby'] ) && isset( $valid_cols[$_REQUEST['orderby']] ) ) {
			$data = \PressBooks\Utility\multi_sort( $data, "{$_REQUEST['orderby']}:$order" );
		} else {
			$data = \PressBooks\Utility\multi_sort( $data, 'status:desc', 'title:asc' ); // Default
		}

		// Pagination
		$per_page = 1000;
		$current_page = $this->get_pagenum();
		$total_items = count( $data );

		/* The WP_List_Table class does not handle pagination for us, so we need
		 * to ensure that the data is trimmed to only the current page. We can use
		 * array_slice() to
		 */
		$data = array_slice( $data, ( ( $current_page - 1 ) * $per_page ), $per_page );


		/* REQUIRED. Now we can add our *sorted* data to the items property, where
		 * it can be used by the rest of the class.
		 */
		$this->items = $data;

		/* REQUIRED. We also have to register our pagination options & calculations.
		 */
		$args = array(
			'total_items' => $total_items, // WE have to calculate the total number of items
			'per_page' => $per_page, // WE have to determine how many items to show on a page
			'total_pages' => ceil( $total_items / $per_page ) // WE have to calculate the total number of pages
		);
		$this->set_pagination_args( $args );

	}


	/**
	 * Form is POST not GET. Override parent method to compensate.
	 *
	 * @param bool $with_id
	 */
	function print_column_headers( $with_id = true ) {

		if ( empty( $_GET['s'] ) && ! empty( $_POST['s'] ) )
			$_SERVER['REQUEST_URI'] = add_query_arg( 's', $_POST['s'] );

		if ( empty( $_GET['orderby'] ) && ! empty( $_POST['orderby'] ) )
			$_GET['orderby'] = $_POST['orderby'];

		if ( empty( $_GET['order'] ) && ! empty( $_POST['order'] ) )
			$_GET['order'] = $_POST['order'];

		parent::print_column_headers( $with_id );
	}


	function _js_vars() {

		parent::_js_vars();
	}


	function ajax_response() {

		parent::ajax_response();
	}


	// ----------------------------------------------------------------------------------------------------------------
	// PressBooks Stuff
	// ----------------------------------------------------------------------------------------------------------------


	/**
	 * @param array $item A singular item (one full row's worth of data)
	 * @param array $column_name The name/slug of the column to be processed
	 *
	 * @return string Text to be placed inside the column <td>
	 */
	protected function renderTagColumn( $item, $column_name ) {

		$html = Catalog::tagsToString( $item[$column_name] );

		if ( ! $html ) $html = '<span style="color:silver">n/a</span>';

		// Build row actions
		$actions = array(
			'edit_tags' => sprintf( '<a href="?page=%s&action=%s&ID=%s">%s</a>', $_REQUEST['page'], 'edit_tags', $item['ID'], __( 'Edit Tags', 'pressbooks' ) ),
		);

		// Return the title contents
		return sprintf( '%1$s %2$s',
			/*$1%s*/
			$html,
			/*$2%s*/
			$this->row_actions( $actions )
		);

	}

	/**
	 * TODO: This isn't documented, not sure i'm doing it right...
	 *
	 * @return array
	 */
	protected function getHiddenColumns() {

		$hidden_columns = array(
			'featured',
		);

		return $hidden_columns;
	}


	/**
	 * @return array
	 */
	protected function getItemsData() {

		// TODO: Caching
		// TODO: Improve search filter for big data
		// TODO: Don't use switch_to_blog()

		$catalog_obj = new Catalog();
		$catalog = $catalog_obj->get();
		$userblogs = get_blogs_of_user( $catalog_obj->getUserId() );
		$data = array();
		$already_loaded = array();
		$i = 0;

		foreach ( $catalog as $val ) {
			switch_to_blog( $val['blogs_id'] );

			$metadata = Book::getBookInformation();
			$meta_version = get_option( 'pressbooks_metadata_version', 0 );

			$data[$i]['ID'] = "{$val['users_id']}:{$val['blogs_id']}";
			$data[$i]['status'] = 1;
			$data[$i]['title'] = ! empty( $metadata['pb_title'] ) ? $metadata['pb_title'] : get_bloginfo( 'name' );
			$data[$i]['author'] = @$metadata['pb_author'];
			$data[$i]['featured'] = $val['featured'];
			$data[$i]['pub_date'] = ! empty( $metadata['pb_publication_date'] ) ? date( 'Y-m-d', (int) $metadata['pb_publication_date'] ) : '';

			// Cover
			if ( $meta_version < 7 || preg_match( '~assets/images/default-book-cover\.jpg$~', $metadata['pb_cover_image'] ) ) {
				$data[$i]['cover'] = PB_PLUGIN_URL . 'assets/images/default-book-cover-65x0.jpg';
			} else {
				$data[$i]['cover'] = \PressBooks\Image\get_thumbnail_from_url( $metadata['pb_cover_image'], 'pb_cover_small' );
			}

			// Tags
			for ( $j = 1; $j <= $catalog_obj::$maxTagsGroup; ++$j ) {
				$data[$i]["tag_{$j}"] = $catalog_obj->getTagsByBook( $val['blogs_id'], $j );
			}

			++$i;
			$already_loaded[$val['blogs_id']] = true;
		}

		foreach ( $userblogs as $book ) {
			// Skip
			if ( is_main_site( $book->userblog_id ) ) continue;
			if ( isset( $already_loaded[$book->userblog_id] ) ) continue;

			switch_to_blog( $book->userblog_id );

			$metadata = Book::getBookInformation();
			$meta_version = get_option( 'pressbooks_metadata_version', 0 );

			$data[$i]['ID'] = "{$catalog_obj->getUserId()}:{$book->userblog_id}";
			$data[$i]['status'] = 0;
			$data[$i]['title'] = ! empty( $metadata['pb_title'] ) ? $metadata['pb_title'] : get_bloginfo( 'name' );
			$data[$i]['author'] = @$metadata['pb_author'];
			$data[$i]['featured'] = 0;
			$data[$i]['pub_date'] = ! empty( $metadata['pb_publication_date'] ) ? date( 'Y-m-d', (int) $metadata['pb_publication_date'] ) : '';

			// Cover
			if ( $meta_version < 7 || preg_match( '~assets/images/default-book-cover\.jpg$~', $metadata['pb_cover_image'] ) ) {
				$data[$i]['cover'] = PB_PLUGIN_URL . 'assets/images/default-book-cover-65x0.jpg';
			} else {
				$data[$i]['cover'] = \PressBooks\Image\get_thumbnail_from_url( $metadata['pb_cover_image'], 'pb_cover_small' );
			}

			// Tags
			for ( $j = 1; $j <= $catalog_obj::$maxTagsGroup; ++$j ) {
				$data[$i]["tag_{$j}"] = $catalog_obj->getTagsByBook( $book->userblog_id, $j );
			}

			++$i;
		}

		restore_current_blog();

		return $this->searchFilter( $data );
	}


	/**
	 * @param array $data
	 *
	 * @return array
	 */
	protected function searchFilter( array $data ) {

		$keyword = (string) trim( @$_REQUEST['s'] );

		if ( ! $keyword ) {
			// No keyword
			return $data;
		}

		$filtered_data = array();
		foreach ( $data as $_ => $val ) {
			if ( $this->atLeastOneKeyword( $keyword, $val ) ) {
				$filtered_data[] = $val;
			}
		}

		return $filtered_data;
	}


	/**
	 * @param $keyword
	 * @param array $data
	 *
	 * @return bool
	 */
	protected function atLeastOneKeyword( $keyword, array $data ) {

		// TODO: Does this work with multi-byte characters?

		foreach ( $data as $key => $val ) {
			if ( is_array( $val ) ) {
				$found = $this->atLeastOneKeyword( $keyword, $val );
				if ( $found ) return true;
				else continue;
			} elseif ( false !== stripos( $val, $keyword ) ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * WP Hook, Instantiate UI
	 */
	static function addMenu() {

		$url = get_bloginfo( 'url' ) . '/wp-admin/index.php?page=pb_catalog';

		$list_table = new self();
		$list_table->prepare_items();

		?>
		<div class="wrap">
			<div id="icon-edit" class="icon32"><br /></div>
			<h2>My Catalog <a href="<?php echo $url . '&action=edit_profile'; ?>" class="button add-new-h2"><?php _e( 'Edit My Catalog Profile', 'pressbooks' ); ?></a></h2>

			<form id="books-filter" method="post" action="<?php echo $url; ?>" >
				<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
				<?php $list_table->search_box( 'search', 'search_id' ); ?>
				<?php if ( @$_REQUEST['user_id'] ) : ?><input type="hidden" name="user_id" value="<?php echo $_REQUEST['user_id'] ?>" /><?php endif; ?>
				<?php $list_table->display() ?>
			</form>

		</div>
	<?php

	}


	/**
	 * Rebuild a URL with known search parameters
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	static function addSearchParamsToUrl( $url ) {

		if ( ! empty( $_REQUEST['s'] ) )
			$url = add_query_arg( 's', $_REQUEST['s'], $url );

		if ( ! empty( $_REQUEST['orderby'] ) )
			$url = add_query_arg( 'orderby', $_REQUEST['orderby'], $url );

		if ( ! empty( $_REQUEST['order'] ) )
			$url = add_query_arg( 'order', $_REQUEST['order'], $url );

		if ( ! empty( $_REQUEST['paged'] ) )
			$url = add_query_arg( 'paged', $_REQUEST['paged'], $url );

		return $url;
	}


}
