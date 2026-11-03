<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI: wp greenlight-seo status | check | links | scan | llms | set
 */
class CLI {

	/**
	 * What's on, what steps aside for the SEO plugin, and the site's issues.
	 *
	 * [--format=<format>]
	 * : table (default) or json. The JSON is a contract (see README).
	 */
	public function status( $args, $assoc ) {
		$r = Report::build();
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( wp_json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::line( 'Greenlight SEO ' . $r['greenlight_seo_version'] . ' · SEO plugin: ' . ( $r['seo_plugin'] ?: 'none' ) );
		foreach ( $r['features'] as $k => $f ) {
			\WP_CLI::line( sprintf( '  %-18s %s', $k, $f['on'] ? 'on' : 'off (' . $f['why'] . ')' ) );
		}
		\WP_CLI::line( 'Business: ' . $r['business']['name'] . ' (' . $r['business']['type'] . ')' . ( $r['business']['has_address'] ? '' : ', no address' ) . ', ' . $r['business']['locations'] . ' other location(s)' );
		foreach ( $r['problems'] as $p ) {
			\WP_CLI::line( '  ' . strtoupper( $p['severity'] ) . '  ' . $p['message'] );
		}
		\WP_CLI::line( 'Pages with indexing or on-page issues: ' . $r['checks']['flagged_pages'] . ( $r['checks']['queued'] ? ' (' . $r['checks']['queued'] . ' still to check)' : '' ) );
		\WP_CLI::line( 'Duplicate titles: ' . count( $r['duplicate_titles'] ) );
		\WP_CLI::line( 'Links: ' . count( $r['links']['orphans'] ) . ' orphan(s), ' . count( $r['links']['dead_ends'] ) . ' dead end(s), ' . count( $r['links']['broken'] ) . ' broken, ' . count( $r['links']['redirected'] ) . ' redirected (' . $r['links']['indexed'] . '/' . $r['links']['pages'] . ' pages indexed)' );
		\WP_CLI::line( 'llms.txt: ' . ( $r['llms_txt']['on'] ? $r['llms_txt']['url'] : 'off' ) );
	}

	/**
	 * Check one page, or every published page, the way search engines see it.
	 *
	 * [<id>...]
	 * : Post IDs.
	 *
	 * [--all]
	 * : Check every published page now (one HTTP request each).
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 */
	public function check( $args, $assoc ) {
		$ids = ! empty( $assoc['all'] ) ? get_posts( array( 'post_type' => Checks::post_types(), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'has_password' => false ) ) : array_map( 'intval', $args );
		if ( ! $ids ) {
			\WP_CLI::error( 'Pass a post ID or --all.' );
		}
		$all = array();
		foreach ( $ids as $id ) {
			$r     = Checks::run( $id );
			$all[] = array( 'id' => $id ) + $r;
			if ( 'json' !== ( $assoc['format'] ?? '' ) ) {
				\WP_CLI::line( $id . ' ' . $r['url'] . ( $r['issues'] ? '' : '  ✓' ) );
				foreach ( $r['issues'] as $i ) {
					\WP_CLI::line( '    ' . str_pad( strtoupper( $i['severity'] ), 7 ) . $i['text'] );
				}
			}
		}
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( wp_json_encode( $all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Rebuild the internal link index now, then list orphans, dead ends, broken and redirected links.
	 *
	 * [<id>]
	 * : Instead: link suggestions for this post.
	 */
	public function links( $args ) {
		if ( $args ) {
			foreach ( Links::suggestions( (int) $args[0] ) as $s ) {
				\WP_CLI::line( sprintf( '%5s  %s  %s  %s', $s['score'], $s['title'], $s['url'], $s['reason'] ) );
			}
			return;
		}
		$ids = get_posts( array( 'post_type' => Checks::post_types(), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			Links::index( $id, true );
			Checks::index_title( $id );
		}
		update_option( Links::QUEUE, array(), false );
		Links::rebuild_inbound();
		$r = Links::report();
		\WP_CLI::line( 'Indexed ' . $r['indexed'] . ' of ' . $r['pages'] . ' pages.' );
		foreach ( $r['orphans'] as $o ) {
			\WP_CLI::line( '  orphan    ' . get_permalink( $o['id'] ) . ( $o['in_menu'] ? '  (in the menu)' : '' ) );
		}
		foreach ( $r['dead_ends'] as $id ) {
			\WP_CLI::line( '  dead end  ' . get_permalink( $id ) );
		}
		foreach ( $r['broken'] as $b ) {
			\WP_CLI::line( '  broken    ' . $b['url'] . '  on ' . get_permalink( $b['id'] ) );
		}
		foreach ( $r['redirected'] as $x ) {
			\WP_CLI::line( '  redirect  ' . $x['url'] . ' → ' . $x['to'] . '  on ' . get_permalink( $x['id'] ) );
		}
	}

	/**
	 * Queue a background check of every page and a rebuild of the link index (WP-Cron, a few pages at a time).
	 */
	public function scan() {
		$n = Checks::start_all();
		Links::start_index();
		\WP_CLI::success( "Queued {$n} page(s) for checks and the link index." );
	}

	/**
	 * Print the llms.txt Greenlight SEO would serve.
	 */
	public function llms() {
		$f = Detect::features()['llms_txt'];
		if ( ! $f['on'] ) {
			\WP_CLI::warning( 'llms.txt is not served: ' . $f['why'] . '. Preview:' );
		}
		\WP_CLI::line( Llms::build() );
	}

	/**
	 * Change one setting.
	 *
	 * <key>
	 * : Setting name: local_schema, service_schema, faq_block, checks_on_save, check_title_suffix,
	 * link_suggestions, llms_txt (1/0), or a business field (name, type, phone, street, city, region, postal, …).
	 *
	 * <value>
	 * : New value.
	 */
	public function set( $args ) {
		list( $key, $value ) = $args;
		$all = Settings::all();
		if ( ! array_key_exists( $key, $all ) || 'locations' === $key ) {
			\WP_CLI::error( "Unknown setting {$key}." );
		}
		Settings::update( array( $key => $value ) );
		$now = Settings::get( $key );
		\WP_CLI::success( "{$key} = " . ( is_array( $now ) ? wp_json_encode( $now ) : $now ) );
	}
}
