<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The "Greenlight SEO" box on the edit screen (block editor, classic editor and pages built with page builders):
 * mark the page as a service page (Service schema), see its SEO checks (re-run after each save), how many pages
 * link to it, and pages worth linking from it.
 */
class Editor {

	const NONCE = 'greenlight_seo_editor';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function box() {
		foreach ( Checks::post_types() as $type ) {
			add_meta_box( 'greenlight-seo', 'Greenlight SEO', array( __CLASS__, 'render' ), $type, 'normal', 'default' );
		}
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! in_array( get_current_screen()->post_type ?? '', Checks::post_types(), true ) ) {
			return;
		}
		wp_enqueue_style( 'greenlight-seo-editor', GREENLIGHT_SEO_URL . 'assets/editor.css', array(), GREENLIGHT_SEO_VERSION );
		wp_enqueue_script( 'greenlight-seo-editor', GREENLIGHT_SEO_URL . 'assets/editor.js', array( 'wp-api-fetch' ), GREENLIGHT_SEO_VERSION, true );
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$m        = get_post_meta( $post->ID, Schema::SERVICE_META, true );
		$m        = is_array( $m ) ? $m : array();
		$features = Detect::features();
		$state    = array(
			'id'          => (int) $post->ID,
			'published'   => 'publish' === $post->post_status,
			'checksOn'    => $features['checks_on_save']['on'],
			'check'       => Checks::last( $post->ID ),
			'stale'       => Checks::stale( $post->ID ),
			'linksOn'     => $features['link_suggestions']['on'],
			'linksWhyOff' => $features['link_suggestions']['why'],
		);
		$place    = Business::place_for_page( $post->ID );
		?>
		<div class="glseo-box" data-state="<?php echo esc_attr( wp_json_encode( $state ) ); ?>">
			<section class="glseo-sec">
				<h4>Schema</h4>
				<?php if ( $place ) : ?>
					<p class="glseo-note">LocalBusiness schema for <strong><?php echo esc_html( $place['name'] ); ?></strong> is added to this page<?php echo $features['local_schema']['on'] ? '' : ' (off: ' . esc_html( $features['local_schema']['why'] ) . ')'; ?>.</p>
				<?php endif; ?>
				<label class="glseo-check"><input type="checkbox" name="greenlight_seo_service[on]" value="1" <?php checked( ! empty( $m['on'] ) ); ?>> This is a service page: add Service schema<?php echo $features['service_schema']['on'] ? '' : ' <em>(Service schema is switched off in Greenlight SEO)</em>'; ?></label>
				<div class="glseo-service">
					<label>Service name <small>(default: the page title)</small><input type="text" name="greenlight_seo_service[name]" value="<?php echo esc_attr( $m['name'] ?? '' ); ?>" placeholder="<?php echo esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ); ?>"></label>
					<label>Short description <small>(default: the SEO meta description)</small><textarea name="greenlight_seo_service[description]" rows="2"><?php echo esc_textarea( $m['description'] ?? '' ); ?></textarea></label>
					<label>Areas served <small>(one city per line; default: the business's areas)</small><textarea name="greenlight_seo_service[area]" rows="2"><?php echo esc_textarea( $m['area'] ?? '' ); ?></textarea></label>
				</div>
			</section>
			<section class="glseo-sec">
				<h4>Checks <button type="button" class="button button-small glseo-run">Check now</button></h4>
				<div class="glseo-checks"><p class="glseo-note">Loading…</p></div>
			</section>
			<section class="glseo-sec">
				<h4>Internal links <button type="button" class="button button-small glseo-links-run">Refresh</button></h4>
				<div class="glseo-links"><p class="glseo-note">Loading…</p></div>
			</section>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, Checks::post_types(), true ) ) {
			return;
		}
		$in = isset( $_POST['greenlight_seo_service'] ) && is_array( $_POST['greenlight_seo_service'] ) ? wp_unslash( $_POST['greenlight_seo_service'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$m  = array(
			'on'          => empty( $in['on'] ) ? 0 : 1,
			'name'        => mb_substr( sanitize_text_field( $in['name'] ?? '' ), 0, 200 ),
			'description' => mb_substr( sanitize_textarea_field( $in['description'] ?? '' ), 0, 1000 ),
			'area'        => mb_substr( sanitize_textarea_field( $in['area'] ?? '' ), 0, 2000 ),
		);
		if ( $m['on'] || '' !== $m['name'] || '' !== $m['description'] || '' !== $m['area'] ) {
			update_post_meta( $post_id, Schema::SERVICE_META, $m );
		} else {
			delete_post_meta( $post_id, Schema::SERVICE_META );
		}
	}
}
