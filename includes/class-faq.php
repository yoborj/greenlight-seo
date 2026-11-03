<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * An FAQ block with FAQPage schema, for sites without Yoast SEO or Rank Math (both have their own FAQ block).
 * The block always renders, so FAQs written earlier don't vanish when Yoast or Rank Math is installed later; it
 * just leaves the inserter and stops adding schema. (Google shows FAQ rich results only for government and
 * health sites since 2023; the markup still tells search and AI answers what the questions are.)
 */
class Faq {

	const BLOCK = 'greenlight-seo/faq';

	/** Questions rendered on this page, for the FAQPage schema. */
	private static $items = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_schema' ) );
	}

	public static function register() {
		wp_register_script( 'greenlight-seo-faq', GREENLIGHT_SEO_URL . 'assets/faq-block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), GREENLIGHT_SEO_VERSION, true );
		register_block_type( self::BLOCK, array(
			'api_version'     => 2,
			'title'           => 'FAQ (Greenlight SEO)',
			'description'     => 'Questions and answers, with FAQPage schema.',
			'category'        => 'text',
			'icon'            => 'editor-help',
			'attributes'      => array(
				'items' => array(
					'type'    => 'array',
					'default' => array( array( 'q' => '', 'a' => '' ) ),
				),
			),
			'supports'        => array( 'html' => false, 'inserter' => Detect::on( 'faq_block' ) ),
			'editor_script'   => 'greenlight-seo-faq',
			'render_callback' => array( __CLASS__, 'render' ),
		) );
	}

	public static function render( $attributes ) {
		$html = '';
		foreach ( (array) ( $attributes['items'] ?? array() ) as $item ) {
			$q = trim( wp_strip_all_tags( (string) ( $item['q'] ?? '' ) ) );
			$a = trim( wp_kses( (string) ( $item['a'] ?? '' ), self::allowed() ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			self::$items[] = array( 'q' => $q, 'a' => $a );
			$html         .= '<details class="greenlight-faq-item"><summary class="greenlight-faq-question">' . esc_html( $q ) . '</summary><div class="greenlight-faq-answer">' . wpautop( $a ) . '</div></details>';
		}
		return $html ? '<div class="wp-block-greenlight-seo-faq greenlight-faq">' . $html . '</div>' : '';
	}

	private static function allowed() {
		return array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'br' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array() );
	}

	public static function print_schema() {
		if ( ! self::$items || ! Detect::on( 'faq_block' ) ) {
			return;
		}
		$questions = array();
		foreach ( self::$items as $it ) {
			$questions[] = array(
				'@type'          => 'Question',
				'name'           => $it['q'],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $it['a'] ),
			);
		}
		$data = array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions );
		if ( is_singular() ) {
			$data['@id'] = get_permalink() . '#/schema/faq';
		}
		echo "\n<script type=\"application/ld+json\" class=\"greenlight-seo-faq\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
