<?php
/**
 * This file contains the methods for interacting with the IDX API
 * to import listing data
 */

if ( ! defined( 'ABSPATH' ) ) exit;
class WPL_Idx_Listing {

	public $_idx;

	public function __construct() {
	}

	/**
	 * Function to get the array key (listingID+mlsID)
	 * @param  [type] $array  [description]
	 * @param  [type] $key    [description]
	 * @param  [type] $needle [description]
	 * @return [type]         [description]
	 */
	public static function get_key($array, $key, $needle) {
		if(!$array) return false;
		foreach($array as $index => $value) {
			if($value[$key] == $needle) return $index;
		}
		return false;
	}

	/**
	 * Function to find the key in the array
	 * @param  [type]  $needle   [description]
	 * @param  [type]  $haystack [description]
	 * @param  boolean $strict   [description]
	 * @return [type]            [description]
	 */
	public static function in_array($needle, $haystack, $strict = false) {
		if(!$haystack) return false;
		foreach ($haystack as $item) {
			if (($strict ? $item === $needle : $item == $needle) || (is_array($item) && self::in_array($needle, $item, $strict))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates a post of listing type using post data from options page
	 * @param  array $listings listingID of the property
	 * @return [type] $featured [description]
	 */
	public static function wp_listings_idx_create_post($listings) {
		if(class_exists( 'IDX_Broker_Plugin')) {
			require_once(ABSPATH . 'wp-content/plugins/idx-broker-platinum/idx/idx-api.php');

			// Load Equity API if it exists
			if(class_exists( 'Equity_Idx_Api' )) {
				require_once(ABSPATH . 'wp-content/themes/equity/lib/idx/class.Equity_Idx_Api.inc.php');
				$_equity_idx = new Equity_Idx_Api;
			}

			// Load IDX Broker API Class and retrieve featured properties
			$_idx_api = new \IDX\Idx_Api();
			$properties = $_idx_api->client_properties('featured');

			// Load WP options
			$idx_featured_listing_wp_options = get_option('wp_listings_idx_featured_listing_wp_options');
			$wpl_options = get_option('plugin_wp_listings_settings');

			// Loop through featured properties
			foreach($properties as $prop) {

				// Get the listing ID
				$key = self::get_key($properties, 'listingID', $prop['listingID']);

				// Add options
				if(!in_array($prop['listingID'], $listings)) {
					$idx_featured_listing_wp_options[$prop['listingID']]['listingID'] = $prop['listingID'];
					$idx_featured_listing_wp_options[$prop['listingID']]['status'] = '';
				}

				// Unset options if they don't exist
				if(isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) && !get_post($idx_featured_listing_wp_options[$prop['listingID']]['post_id'])) {
					unset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']);
					unset($idx_featured_listing_wp_options[$prop['listingID']]['status']);
			 	}

			 	// Add post and update post meta
				if(in_array($prop['listingID'], $listings) && !isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id'])) {

					// Get Equity listing API data if available
					if(class_exists( 'Equity_Idx_Api' )) {
						$equity_properties = $_equity_idx->equity_listing_ID($prop['idxID'], $prop['listingID']);

						if($equity_properties == false) {
							add_settings_error('wp_listings_idx_listing_settings_group', 'idx_listing_empty', 'The Equity API returned no data for property ' . $prop['listingID'] . '. This is usually caused by your WordPress domain not matching the approved domain in your IDX account. Only some data has been imported.', 'error');
							$equity_properties = $properties[$key];
							delete_transient('equity_listing_' . $prop['listingID']);
						}
					}

					// Post creation options
					$opts = array(
						'post_content' => $properties[$key]['remarksConcat'],
						'post_title' => $properties[$key]['address'],
						'post_status' => 'publish',
						'post_type' => 'listing'
					);

					// Add the post
					$add_post = wp_insert_post($opts, true);

					// Show error if wp_insert_post fails
					// add post meta and update options if success
					if (is_wp_error($add_post)) {
						$error_string = $add_post->get_error_message();
						add_settings_error('wp_listings_idx_listing_settings_group', 'insert_post_failed', 'WordPress failed to insert the post. Error ' . $error_string, 'error');
						return;
					} elseif($add_post) {
						$idx_featured_listing_wp_options[$prop['listingID']]['post_id'] = $add_post;
						$idx_featured_listing_wp_options[$prop['listingID']]['status'] = 'publish';
						if(class_exists( 'Equity_Idx_Api' )) {
							self::wp_listings_idx_insert_post_meta($add_post, $equity_properties);
						} else {
							self::wp_listings_idx_insert_post_meta($add_post, $properties[$key]);
						}
					}
				}
				// Change status to publish if it's not already
				elseif( in_array($prop['listingID'], $listings) && $idx_featured_listing_wp_options[$prop['listingID']]['status'] != 'publish' ) {
					self::wp_listings_idx_change_post_status($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], 'publish');
					$idx_featured_listing_wp_options[$prop['listingID']]['status'] = 'publish';
				}
				// Change post status or delete post based on options
				elseif( !in_array($prop['listingID'], $listings) && $idx_featured_listing_wp_options[$prop['listingID']]['status'] == 'publish' ) {

					// Change to draft or delete listing if the post exists but is not in the listing array based on settings
					if(isset($wpl_options['wp_listings_idx_sold']) && $wpl_options['wp_listings_idx_sold'] == 'sold-draft') {

						// Change to draft
						self::wp_listings_idx_change_post_status($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], 'draft');
						$idx_featured_listing_wp_options[$prop['listingID']]['status'] = 'draft';
					} elseif(isset($wpl_options['wp_listings_idx_sold']) && $wpl_options['wp_listings_idx_sold'] == 'sold-delete') {

						$idx_featured_listing_wp_options[$prop['listingID']]['status'] = 'deleted';

						// Delete featured image
						$post_featured_image_id = get_post_thumbnail_id( $idx_featured_listing_wp_options[$prop['listingID']]['post_id'] );
						wp_delete_attachment( $post_featured_image_id );

						//Delete post
						wp_delete_post( $idx_featured_listing_wp_options[$prop['listingID']]['post_id'] );
					}
				}
			}

			// Lastly update our options
			update_option('wp_listings_idx_featured_listing_wp_options', $idx_featured_listing_wp_options);
			return $idx_featured_listing_wp_options;
		}
	}

	/**
	 * Update existing post
	 * @return true if success
	 */
	public static function wp_listings_update_post() {

		require_once(ABSPATH . 'wp-content/plugins/idx-broker-platinum/idx/idx-api.php');

		// Load IDX Broker API Class and retrieve featured properties
		$_idx_api = new \IDX\Idx_Api();
		$properties = $_idx_api->client_properties('featured');

		// Load WP options
		$idx_featured_listing_wp_options = get_option('wp_listings_idx_featured_listing_wp_options');
		$wpl_options = get_option('plugin_wp_listings_settings');

		foreach ( $properties as $prop ) {

			$key = self::get_key($properties, 'listingID', $prop['listingID']);

			if( isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) && $idx_featured_listing_wp_options[$prop['listingID']]['listingID'] != $prop['listingID'] ) {
				self::wp_listings_idx_change_post_status($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], 'draft');
			} elseif( isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) ) {
				// Update property data
				if(class_exists( 'Equity_Idx_Api' )) {
					require_once(ABSPATH . 'wp-content/themes/equity/lib/idx/class.Equity_Idx_Api.inc.php');
					$_equity_idx = new Equity_Idx_Api;
					$equity_properties = $_equity_idx->equity_listing_ID($prop['idxID'], $prop['listingID']);
					if($equity_properties == false) {
						$equity_properties = $properties[$key];
						delete_transient('equity_listing_' . $prop['listingID']);
					}
					if(!isset($wpl_options['wp_listings_idx_update']) || isset($wpl_options['wp_listings_idx_update']) && $wpl_options['wp_listings_idx_update'] != 'update-none')
						self::wp_listings_idx_insert_post_meta($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], $equity_properties, true, ($wpl_options['wp_listings_idx_update'] == 'update-noimage') ? false : true );
					$idx_featured_listing_wp_options[$prop['listingID']]['updated'] = date("m/d/Y h:i:sa");
				} else {
					if(!isset($wpl_options['wp_listings_idx_update']) || isset($wpl_options['wp_listings_idx_update']) && $wpl_options['wp_listings_idx_update'] != 'update-none')
						self::wp_listings_idx_insert_post_meta($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], $properties[$key], true, ($wpl_options['wp_listings_idx_update'] == 'update-noimage') ? false : true );
					$idx_featured_listing_wp_options[$prop['listingID']]['updated'] = date("m/d/Y h:i:sa");
				}
			}

		}

		// Load and loop through Sold properties
		$sold_properties = $_idx_api->client_properties('soldpending');
		foreach ( $sold_properties as $prop ) {

			$key = self::get_key($sold_properties, 'listingID', $prop['listingID']);

			if( isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) ) {

				// Update property data
				self::wp_listings_idx_insert_post_meta($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], $sold_properties[$key], true, ($wpl_options['wp_listings_idx_update'] == 'update-noimage') ? false : true );

				if(isset($wpl_options['wp_listings_idx_sold']) && $wpl_options['wp_listings_idx_sold'] == 'sold-draft') {

					// Change to draft
					self::wp_listings_idx_change_post_status($idx_featured_listing_wp_options[$prop['listingID']]['post_id'], 'draft');
				} elseif(isset($wpl_options['wp_listings_idx_sold']) && $wpl_options['wp_listings_idx_sold'] == 'sold-delete') {

					// Delete featured image
					$post_featured_image_id = get_post_thumbnail_id( $idx_featured_listing_wp_options[$prop['listingID']]['post_id'] );
					wp_delete_attachment( $post_featured_image_id );

					//Delete post
					wp_delete_post( $idx_featured_listing_wp_options[$prop['listingID']]['post_id'] );
				}
			}

		}

		update_option('wp_listings_idx_featured_listing_wp_options', $idx_featured_listing_wp_options);

	}

	/**
	 * Change post status
	 * @param  [type] $post_id [description]
	 * @param  [type] $status  [description]
	 * @return [type]          [description]
	 */
	public static function wp_listings_idx_change_post_status($post_id, $status){
	    $current_post = get_post( $post_id, 'ARRAY_A' );
	    $current_post['post_status'] = $status;
	    wp_update_post($current_post);
	}

	/**
	 * Inserts post meta based on property data
	 * API fields are mapped to post meta fields
	 * prefixed with _listing_ and lowercased
	 * @param  [type] $id  [description]
	 * @param  [type] $key [description]
	 * @return [type]      [description]
	 */
	public static function wp_listings_idx_insert_post_meta($id, $idx_featured_listing_data, $update = false, $update_image = true) {

		if (class_exists( 'Equity_Idx_Api' ) && $update == false && $update_image == true) {
			$imgs = '';
			$featured_image = $idx_featured_listing_data['images']['1']['url'];

			foreach ($idx_featured_listing_data['images'] as $image_data => $img) {
				if($image_data == "totalCount") continue;
				$img_markup = sprintf('<img src="%s" alt="%s" />', $img['url'], $idx_featured_listing_data['address']);
				$imgs .= apply_filters( 'wp_listings_imported_image_markup', $img_markup, $img, $idx_featured_listing_data );
			}
		} else {
			$featured_image = $idx_featured_listing_data['image']['0']['url'];
		}

		if ($idx_featured_listing_data['propStatus'] == 'A'){
			$propstatus = 'Active';
		} elseif($idx_featured_listing_data['propStatus'] == 'S') {
			$propstatus = 'Sold';
		} else {
			$propstatus = $idx_featured_listing_data['propStatus'];
		}

		// Add or reset taxonomies for property-types, locations, and status
		wp_set_object_terms($id, $idx_featured_listing_data['idxPropType'], 'property-types');
		wp_set_object_terms($id, $idx_featured_listing_data['cityName'], 'locations');
		wp_set_object_terms($id, $propstatus, 'status');

		// Add post meta for existing WPL fields
		update_post_meta($id, '_listing_lot_sqft', $idx_featured_listing_data['acres'].' acres');
		update_post_meta($id, '_listing_price', $idx_featured_listing_data['listingPrice']);
		update_post_meta($id, '_listing_address', $idx_featured_listing_data['address']);
		update_post_meta($id, '_listing_city', $idx_featured_listing_data['cityName']);
		update_post_meta($id, '_listing_state', $idx_featured_listing_data['state']);
		update_post_meta($id, '_listing_zip', $idx_featured_listing_data['zipcode']);
		update_post_meta($id, '_listing_mls', $idx_featured_listing_data['listingID']);
		update_post_meta($id, '_listing_sqft', $idx_featured_listing_data['sqFt']);
		update_post_meta($id, '_listing_year_built', (isset($idx_featured_listing_data['yearBuilt'])) ? $idx_featured_listing_data['yearBuilt'] : '');
		update_post_meta($id, '_listing_bedrooms', $idx_featured_listing_data['bedrooms']);
		update_post_meta($id, '_listing_bathrooms', $idx_featured_listing_data['totalBaths']);
		update_post_meta($id, '_listing_half_bath', $idx_featured_listing_data['partialBaths']);
		if ($update == false || $update_image == true) {
			update_post_meta($id, '_listing_gallery', apply_filters('wp_listings_imported_gallery', $gallery = '<img src="' . $featured_image . '" alt="' . $idx_featured_listing_data['address'] . '" />'));
		}

		// Add post meta for Equity API fields
		if (class_exists( 'Equity_Idx_Api' )) {
			if ($update == false || $update_image == true) {
				update_post_meta($id, '_listing_gallery', apply_filters('wp_listings_equity_imported_gallery', $imgs));
			}
			foreach ($idx_featured_listing_data as $metakey => $metavalue) {
				if ($update == true) {
					delete_post_meta($id, '_listing_' . strtolower($metakey));
				}
				if(isset($metavalue) && !is_array($metavalue) && $metavalue != '') {
					update_post_meta($id, '_listing_' . strtolower($metakey), $metavalue);
				} elseif(isset( $metavalue ) && is_array( $metavalue )) {
					foreach ($metavalue as $key => $value) {
						if(get_post_meta($id, '_listing_' . strtolower($metakey)) && $metakey != 'images') {
							$oldvalue = get_post_meta($id, '_listing_' . strtolower($metakey), true);
							$newvalue = $value . ', ' . $oldvalue;
							update_post_meta($id, '_listing_' . strtolower($metakey), $newvalue);
						} elseif($metakey != 'images') {
							update_post_meta($id, '_listing_' . strtolower($metakey), $value);
						}
					}
				}
			}
		}

		/**
		 * Pull featured image if it's not an update or update image is set to true
		 */
		if($update == false || $update_image == true) {
			// Delete previously attached image
			if($update_image == true) {
				$post_featured_image_id = get_post_thumbnail_id( $id );
				wp_delete_attachment( $post_featured_image_id );
			}

			// Add Featured Image to Post
			$image_url  = $featured_image; // Define the image URL here
			$upload_dir = wp_upload_dir(); // Set upload folder
			$image_data = file_get_contents($image_url); // Get image data
			$filename   = basename($image_url.'/' . $idx_featured_listing_data['listingID'] . '.jpg'); // Create image file name

			// Check folder permission and define file location
			if( wp_mkdir_p( $upload_dir['path'] ) ) {
				$file = $upload_dir['path'] . '/' . $filename;
			} else {
				$file = $upload_dir['basedir'] . '/' . $filename;
			}

			// Create the image file on the server
			if(!file_exists($file))
				file_put_contents( $file, $image_data );

			// Check image file type
			$wp_filetype = wp_check_filetype( $filename, null );

			// Set attachment data
			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				'post_title'     => $idx_featured_listing_data['listingID'] . ' - ' . $idx_featured_listing_data['address'],
				'post_content'   => '',
				'post_status'    => 'inherit'
			);

			// Create the attachment
			$attach_id = wp_insert_attachment( $attachment, $file, $id );

			// Include image.php
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			// Define attachment metadata
			$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

			// Assign metadata to attachment
			wp_update_attachment_metadata( $attach_id, $attach_data );

			// Assign featured image to post
			set_post_thumbnail( $id, $attach_id );
		}

		return true;

	}

}


/**
 * Admin settings page
 * Outputs clients/featured properties to import
 * Enqueues scripts for display
 * Deletes post and post thumbnail via ajax
 */
add_action( 'admin_menu', 'wp_listings_idx_listing_register_menu_page');
function wp_listings_idx_listing_register_menu_page() {
	add_submenu_page( 'edit.php?post_type=listing', __( 'Import IDX Listings', 'wp_listings' ), __( 'Import IDX Listings', 'wp_listings' ), 'manage_options', 'wplistings-idx-listing', 'wp_listings_idx_listing_setting_page' );
	add_action( 'admin_init', 'wp_listings_idx_listing_register_settings' );
}

function wp_listings_idx_listing_register_settings() {
	register_setting('wp_listings_idx_listing_settings_group', 'wp_listings_idx_featured_listing_options', array('WPL_Idx_Listing', 'wp_listings_idx_create_post'));
}

add_action( 'admin_enqueue_scripts', 'wp_listings_idx_listing_scripts' );
function wp_listings_idx_listing_scripts() {
	wp_enqueue_script( 'wp_listings_idx_listing_delete_script', WP_LISTINGS_URL . 'includes/js/admin-listing-import.js', array( 'jquery' ), true );
	wp_enqueue_script( 'jquery-masonry' );
	wp_localize_script( 'wp_listings_idx_listing_delete_script', 'DeleteListingAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	wp_enqueue_style( 'wp_listings_idx_listing_style', WP_LISTINGS_URL . 'includes/css/wp-listings-import.css' );
}
add_action( 'wp_ajax_wp_listings_idx_listing_delete', 'wp_listings_idx_listing_delete' );
function wp_listings_idx_listing_delete(){

	$permission = check_ajax_referer( 'wp_listings_idx_listing_delete_nonce', 'nonce', false );
	if( $permission == false ) {
		echo 'error';
	}
	else {
		// Delete featured image
		$post_featured_image_id = get_post_thumbnail_id( $_REQUEST['id'] );
		wp_delete_attachment( $post_featured_image_id );

		//Delete post
		wp_delete_post( $_REQUEST['id'] );
		echo 'success';
	}
	die();
}

function wp_listings_idx_listing_setting_page() {

	?>
			<h1>Import IDX Listings</h1>
			<p>Select the listings to import.</p>
			<form id="wplistings-idx-listing-import" method="post" action="options.php">
				<label for="selectall"><input type="checkbox" id="selectall"/>Select/Deselect All<br/><em>If importing all listings, it may take some time. <strong class="error">Please be patient.</strong></em></label>
				<?php submit_button('Import Listings'); ?>

			<?php
			// Show popup if IDX Broker plugin not active or installed
			if( !class_exists( 'IDX_Broker_Plugin') ) {
				// thickbox like content
				echo '
					<img class="idx-import bkg" src="' . WP_LISTINGS_URL . 'images/import-bg.jpg' . '" /></a>
					<div class="idx-import thickbox">
					     <a href="http://www.idxbroker.com/features/idx-wordpress-plugin" target="_blank"><img src="' . WP_LISTINGS_URL . 'images/idx-ad.png' . '" alt="Sign up for IDX now!"/></a>
					</div>';

				return;
			}

			settings_errors('wp_listings_idx_listing_settings_group');
			?>

			<ol id="selectable" class="grid">
			<div class="grid-sizer"></div>

			<?php
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugin_data = get_plugins();

			// Get properties from IDX Broker plugin
			if (class_exists( 'IDX_Broker_Plugin' )) {
				// bail if IDX plugin version is not at least 2.0
				if($plugin_data['idx-broker-platinum/idx-broker-platinum.php']['Version'] < 2.0 ) {
					add_settings_error('wp_listings_idx_listing_settings_group', 'idx_listing_update', 'You must update to <a href="' . admin_url( 'update-core.php' ) . '">IMPress for IDX Broker</a> version 2.0.0 or higher to import listings.', 'error');
					settings_errors('wp_listings_idx_listing_settings_group');
					return;
				}

				$_idx_api = new \IDX\Idx_Api();
				$properties = $_idx_api->client_properties('featured');
			} else {
				return;
			}

			$idx_featured_listing_wp_options = get_option('wp_listings_idx_featured_listing_options');

			settings_fields( 'wp_listings_idx_listing_settings_group' );
			do_settings_sections( 'wp_listings_idx_listing_settings_group' );

			// No featured properties found
			if(!$properties) {
				echo 'No featured properties found.';
				return;
			}

			// Loop through properties
			foreach ($properties as $prop) {

				if(!isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) || !get_post($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) ) {
					$idx_featured_listing_wp_options[$prop['listingID']] = array(
						'listingID' => $prop['listingID']
						);
				}

				if(isset($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) && get_post($idx_featured_listing_wp_options[$prop['listingID']]['post_id']) ) {
					$pid = $idx_featured_listing_wp_options[$prop['listingID']]['post_id'];
					$nonce = wp_create_nonce('wp_listings_idx_listing_delete_nonce');
					$delete_listing = sprintf('<a href="%s" data-id="%s" data-nonce="%s" class="delete-post">Delete</a>',
						admin_url( 'admin-ajax.php?action=wp_listings_idx_listing_delete&id=' . $pid . '&nonce=' . $nonce),
							$pid,
							$nonce
					 );
				}

				printf('<div class="grid-item post"><label for="%s" class="idx-listing"><li class="%s"><img class="listing" src="%s"><input type="checkbox" id="%s" class="checkbox" name="wp_listings_idx_featured_listing_options[]" value="%s" %s />%s<p><span class="price">%s</span><br/><span class="address">%s</span><br/><span class="mls">MLS#: </span>%s</p>%s</li></label></div>',
					$prop['listingID'],
					isset($idx_featured_listing_wp_options[$prop['listingID']]['status']) ? ($idx_featured_listing_wp_options[$prop['listingID']]['status'] == 'publish' ? "imported" : '') : '',
					isset($prop['image']['0']['url']) ? $prop['image']['0']['url'] : '//mlsphotos.idxbroker.com/defaultNoPhoto/noPhotoFull.png',
					$prop['listingID'],
					$prop['listingID'],
					isset($idx_featured_listing_wp_options[$prop['listingID']]['status']) ? ($idx_featured_listing_wp_options[$prop['listingID']]['status'] == 'publish' ? "checked" : '') : '',
					isset($idx_featured_listing_wp_options[$prop['listingID']]['status']) ? ($idx_featured_listing_wp_options[$prop['listingID']]['status'] == 'publish' ? "<span class='imported'><i class='dashicons dashicons-yes'></i>Imported</span>" : '') : '',
					$prop['listingPrice'],
					$prop['address'],
					$prop['listingID'],
					isset($idx_featured_listing_wp_options[$prop['listingID']]['status']) ? ($idx_featured_listing_wp_options[$prop['listingID']]['status'] == 'publish' ? $delete_listing : '') : ''
					);

			}
			echo '</ol>';
			submit_button('Import Listings');
			?>
			</form>
	<?php
}

/**
 * Check if update is scheduled - if not, schedule it to run twice daily.
 * Only add if IDX plugin is installed
 * @since 2.0
 */
if( class_exists( 'IDX_Broker_Plugin') ) {
	add_action( 'admin_init', 'wp_listings_idx_update_schedule' );
}
function wp_listings_idx_update_schedule() {
	if ( ! wp_next_scheduled( 'wp_listings_idx_update' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'wp_listings_idx_update');
	}
}
/**
 * On the scheduled update event, run wp_listings_update_post with activation status
 *
 * @since 2.0
 */
add_action( 'wp_listings_idx_update', array('WPL_Idx_Listing', 'wp_listings_update_post') );
