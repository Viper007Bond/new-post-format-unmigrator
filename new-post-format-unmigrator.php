<?php /*

**************************************************************************

Plugin Name:  New Post Format Unmigrator
Plugin URI:   https://github.com/Viper007Bond/new-post-format-unmigrator
Description:  If you were running WordPress trunk during the development of version 3.6, you may have used the new post format UI and it's extra media fields. Since those fields were yanked from core, this plugin migrates the data back into your post content so that it isn't lost in your post meta.
Author:       Alex Mills (Viper007Bond)
Author URI:   http://www.viper007bond.com/

**************************************************************************

TODO:

* WP-CLI wrapper as an alternative to the admin UI

**************************************************************************/

/**
 * Does the actual work of fetching posts needing unmigrating
 * as well as processing individual posts.
 */
class New_Post_Format_Unmigrator {

	/**
	 * Stores the shared instance of this class.
	 */
	private static $instance;

	/**
	 * Returns this shared instance of this class, making a new one if need be.
	 *
	 * @return object New_Post_Format_Unmigrator
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new New_Post_Format_Unmigrator;
		}
		return self::$instance;
	}

	private function __construct() { /* Do nothing here */ }

	/**
	 * Fetches some posts that need unmigrating.
	 *
	 * @param array $args Function arguments. Currently only supports "count".
	 * @return object WP_Query
	 */
	public function get_posts( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'count' => 25,
		) );

		add_filter( 'posts_clauses', array( $this, 'filter_sql' ), 10, 2 );

		$query = new WP_Query( array(
			'new_post_format_unmigrator' => 1, // This tells the SQL filter to modify this query
			'tax_query'        => array(
				array(
					'taxonomy' => 'post_format',
					'field'    => 'slug',
					'terms'    => array( 'post-format-image', 'post-format-link', 'post-format-video', 'post-format-audio', 'post-format-quote' ),
				),
			),
			'posts_per_page'   => $args['count'],
			'orderby'          => 'ID',
			'order'            => 'ASC',
		) );

		remove_filter( 'posts_clauses', array( $this, 'filter_sql' ), 10, 2 );

		return $query;
	}

	/**
	 * Modifies the database query used to fetch posts needing unmigrating.
	 * This results in a conditional like this pseudo-query:
	 *
	 * ( EXISTS: key = '_format_url' OR key = '_wp_format_url' ) AND key = '_format_unmigrated' NOT EXISTS
	 *
	 * @param array $clauses The parts of the database query.
	 * @param object $query The WP_Query object.
	 * @return array The modified query parts.
	 */
	public function filter_sql( $clauses, $query ) {
		global $wpdb;

		if ( $query->get( 'new_post_format_unmigrator' ) ) {
			$clauses['join'] .= "
				INNER JOIN {$wpdb->postmeta} AS mt_format_url        ON ( {$wpdb->posts}.ID = mt_format_url.post_id )
				INNER JOIN {$wpdb->postmeta} AS mt_wp_format_url     ON ( {$wpdb->posts}.ID = mt_wp_format_url.post_id )
				LEFT  JOIN {$wpdb->postmeta} AS mt_format_unmigrated ON ( {$wpdb->posts}.ID = mt_format_unmigrated.post_id AND mt_format_unmigrated.meta_key = '_format_unmigrated' )
			";

			$clauses['where'] .= " AND ( ( mt_format_url.meta_key = '_format_url' OR mt_wp_format_url.meta_key = '_wp_format_url' ) AND mt_format_unmigrated.post_id IS NULL )";
		}

		return $clauses;
	}

	/**
	 * Unmigrates an individual post by adding the data from the post meta to the post_content
	 * and then saves the changes to the post.
	 *
	 * @param object|int $post A post object or post ID.
	 * @return boolean True if post updated, false if not.
	 */
	public function unmigrate_post( $post ) {
		$post = get_post( $post );

		if ( ! $post )
			return false;

		$post_format = get_post_format( $post );

		if ( ! $post_format )
			return false;

		$post_content = $post->post_content;

		switch ( $post_format ) {
			case 'image':

				// The later storage version which stores HTML
				$image = get_post_meta( $post->ID, '_format_image', true );
				if ( $image ) {
					// Is it just a URL?
					if ( false === strpos( $image, '<' ) ) {
						$image = '<img src="' . esc_url( $image ) . '" alt="" />';
					}

					// Wrap the image in a link if the user supplied a URL
					$url = get_post_meta( $post->ID, '_format_url', true );
					if ( $url ) {
						$image = $this->wrap_img_tag_in_link( $image, $url );
					}

					$post_content = $image . "\n\n" . $post_content;
				}

				// Otherwise try the old version which (can?) stores an ID
				else {
					$image = get_post_meta( $post->ID, '_wp_format_image', true );

					// Convert ID into HTML
					if ( $image && is_numeric( $image ) ) {
						$image = wp_get_attachment_image( $image, 'large' );
					}

					if ( $image ) {
						// Wrap the image in a link if the user supplied a URL
						$url = get_post_meta( $post->ID, '_wp_format_url', true );
						if ( $url ) {
							$image = $this->wrap_img_tag_in_link( $image, $url );
						}

						$post_content = $image . "\n\n" . $post_content;
					}
				}

				break;

			case 'link':
				// The later storage version
				$url = get_post_meta( $post->ID, '_format_link_url', true );

				// The earlier storage version
				if ( ! $url ) {
					$url = get_post_meta( $post->ID, '_wp_format_url', true );
				}

				if ( $url ) {
					$url = '<a href="' . esc_url( $url ) . '">' . $post->post_title . '</a>';

					$post_content = $url . "\n\n" . $post_content;
				}

				break;

			case 'video':
			case 'audio':

				// The later storage version
				$media = get_post_meta( $post->ID, '_format_'. $post_format . '_embed', true );

				// The earlier storage version
				if ( ! $media ) {
					$media = get_post_meta( $post->ID, '_wp_format_media', true );
				}

				if ( $media ) {
					$post_content = $media . "\n\n" . $post_content;
				}

				break;

			case 'quote':
				$quote_source_name = get_post_meta( $post->ID, '_format_quote_source_name', true );
				$quote_source_url  = get_post_meta( $post->ID, '_format_quote_source_url',  true );

				// The earlier storage version
				if ( ! $quote_source_name ) {
					$quote_source_name = get_post_meta( $post->ID, '_wp_format_quote_source', true );
				}
				if ( ! $quote_source_url ) {
					$quote_source_url  = get_post_meta( $post->ID, '_wp_format_url', true );
				}

				// No quote source name but there is a URL, then use the URL as the name
				if ( ! $quote_source_name && $quote_source_url ) {
					$quote_source_name = $quote_source_url;
				}

				// Make the quote source name clickable if a URL was provided
				if ( $quote_source_name && $quote_source_url ) {
					$quote_source_name = '<a href="' . esc_url( $quote_source_url ) . '">' . $quote_source_name . '</a>';
				}

				// If there's a quote source, create the whole quote block
				if ( $quote_source_name ) {
					$post_content = "<figure>\n<blockquote>{$post_content}</blockquote>\n<figcaption>&mdash; {$quote_source_name}</figcaption>\n</figure>";
				}
				// Otherwise just blockquote the whole content area so it doesn't appear as a standard blog post
				else {
					$post_content = '<blockquote>' . $post_content . '</blockquote>';
				}

				break;

			default:
				return false;
		} // end switch()

		// Flag post as unmigrated
		update_post_meta( $post->ID, '_format_unmigrated', 1 );

		if ( $post->post_content === $post_content )
			return false;

		$new_post = array(
			'ID'           => $post->ID,
			'post_content' => $post_content,
		);

		$result = wp_update_post( $new_post );

		if ( ! $result )
			return false;

		return true;
	}

	/**
	 * Wraps a link tag around the img tag in the provided HTML.
	 *
	 * @param string $html The HTML to search in and process.
	 * @param string $url The URL that should be added.
	 * @return string The modified HTML.
	 */
	public function wrap_img_tag_in_link( $html, $url ) {
		return preg_replace( '#(.*)(<img [^>]+>)(.*)#i', '\1<a href="' . esc_url( $url ) . '">\2</a>\3', $html );
	}
}

/**
 * Returns the single instance of New_Post_Format_Unmigrator.
 * Call this function to reference the class, i.e. New_Post_Format_Unmigrator()->get_posts().
 *
 * @return object New_Post_Format_Unmigrator
 */
function New_Post_Format_Unmigrator() {
	return New_Post_Format_Unmigrator::instance();
}



/**
 * Implements a UI in the WordPress admin area for unmigrating posts.
 */
class New_Post_Format_Unmigrator_UI {

	/**
	 * Stores the shared instance of this class.
	 */
	private static $instance;

	/**
	 * Returns this shared instance of this class, making a new one if need be.
	 *
	 * @return object New_Post_Format_Unmigrator_UI
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new New_Post_Format_Unmigrator_UI;
			self::$instance->setup();
		}
		return self::$instance;
	}

	private function __construct() { /* Do nothing here */ }

	/**
	 * Registers the class's action.
	 */
	public function setup() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
	}

	/**
	 * Registers the class's menu entry.
	 */
	public function register_admin_menu() {
		add_management_page( 'New Post Format Unmigrator', 'New Post Formats', 'manage_options', 'new-post-format-unmigrator', array( $this, 'admin_page' ) );
	}

	/**
	 * Outputs the class's admin page and uses New_Post_Format_Unmigrator to unmigrate the posts.
	 */
	public function admin_page() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>New Post Format Unmigrator</h2>';

		// Intro text
		if ( empty( $_GET['processposts'] ) || ! isset( $_GET['processed'] ) || empty( $_GET['_wpnonce'] ) ) {
			$query = New_Post_Format_Unmigrator()->get_posts( array( 'count' => 1 ) );

			if ( ! $query->found_posts ) {
				echo "<p>No posts needing migration could be found. Looks like you're all good to go!</p>";
			} else {
				echo '<p>' . sprintf( '%s posts were found that need to be unmigrated.', number_format_i18n( $query->found_posts ) ) . '</p>';
				echo '<p>Click the button below to begin processing the posts in chunks. The page will automatically refresh after each chunk of posts has been processed.</p>';

				$button_url = add_query_arg( array(
					'processposts' => 1,
					'processed'    => 0,
				) );

				$button_url = wp_nonce_url( $button_url, 'new-post-format-unmigrator' );

				echo '<p><a class="button-primary" href="' . esc_url( $button_url ) . '">Start Processing Posts</a></p>';
			}
		}
		// Process posts
		else {
			if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'new-post-format-unmigrator' ) ) {
				echo '<p>Bad nonce. Please go back and try again.</p>';
			}
			else {
				$processed = absint( $_GET['processed'] );

				$query = New_Post_Format_Unmigrator()->get_posts();

				// No posts found, they must have been all processed
				if ( ! $query->found_posts ) {
					echo '<p>' . sprintf( 'Finished processing %s posts', number_format_i18n( $processed ) ) . '</p>';
				}
				// Process some posts!
				else {
					echo '<p>Processing posts...</p>';

					echo '<ol start="' . esc_attr( $processed + 1 ) . '">';
					$this->flush();
					foreach ( $query->posts as $post ) {
						$result = New_Post_Format_Unmigrator()->unmigrate_post( $post );
						$processed++;

						echo '<li><em>' . $post->post_title . '</em> ';

						echo ( $result ) ? 'Processed' : '<strong style="color:red">No action taken</strong>';

						$this->flush();
					}
					echo '</ol>';

					echo '<p>One moment, refreshing the page to continue processing more posts...</p>';
					echo '<script type="text/javascript">window.location.href = "' . esc_url_raw( add_query_arg( 'processed', $processed ) ) . '";</script>';
				}
			}
		}

		echo '</div>';
	}

	/**
	 * Flushes the current buffer to the page.
	 */
	public function flush() {
		@ob_flush();
		@flush();
	}
}

/**
 * Returns the single instance of New_Post_Format_Unmigrator_UI.
 *
 * @return object New_Post_Format_Unmigrator_UI
 */
function New_Post_Format_Unmigrator_UI() {
	return New_Post_Format_Unmigrator_UI::instance();
}

add_action( 'plugins_loaded', 'New_Post_Format_Unmigrator_UI' );

?>