<?php
/**
 * Read-only box on post and term edit screens (§11): what AIVIS delivers
 * for this object, and nothing to click. Facts only — state, hash, times,
 * whether the page has moved — with a link to the Status screen.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Delivery\ObjectResolver;
use AivisOS\Plugin;
use AivisOS\Rest\StatusDocument;

final class ObjectBox {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
		foreach ( (array) get_taxonomies( [ 'public' => true ] ) as $tax ) {
			add_action( (string) $tax . '_edit_form_fields', [ $this, 'term_field' ] );
		}
	}

	public function add_boxes(): void {
		add_meta_box( 'aivis-os', 'AIVIS OS', [ $this, 'post_box' ], array_values( (array) get_post_types( [ 'public' => true ] ) ), 'side', 'default' );
	}

	/** @param mixed $post */
	public function post_box( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		echo $this->render( ObjectResolver::POST, (string) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
	}

	/** @param mixed $term */
	public function term_field( $term ): void {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		echo '<tr><th scope="row">AIVIS OS</th><td>' . $this->render( ObjectResolver::TERM, (string) $term->term_id ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
	}

	public function render( string $type, string $id ): string {
		$row = $this->plugin->repository()->find_by_object( $type, $id );
		if ( null === $row ) {
			return '<p class="description">' . esc_html( sprintf( 'Not managed by AIVIS: no structured data has been synced for this %s, so nothing is injected here.', ObjectResolver::label( $type ) ) ) . '</p>';
		}
		$state  = StatusDocument::state_of( $row );
		$moved  = (array) ( $this->plugin->options()->sync_state()['moved'] ?? [] );
		$status = admin_url( 'admin.php?page=' . Menu::SLUG_STATUS );
		$label  = match ( $state ) {
			'published' => 'Injected',
			'stale'     => 'Injected (source changed; regeneration pending in AIVIS)',
			'holding'   => 'Holding last good',
			'suspended' => 'Suspended (withdrawn in AIVIS)',
			'retired'   => 'Retired',
			default     => 'Not injected',
		};
		$h  = '<p><strong>' . esc_html( $label ) . '</strong><br>';
		$h .= '<code>' . esc_html( substr( (string) $row['content_hash'], 0, 12 ) ) . '…</code></p>';
		$h .= '<p class="description">' . esc_html( sprintf( 'Generated %s · published %s · last seen on the page %s', self::when( $row['source_generated_at'] ?? null ), self::when( $row['published_at'] ?? null ), self::when( $row['verified_at'] ?? null ) ) ) . '</p>';
		if ( isset( $moved[ (string) $row['url_key'] ] ) ) {
			$h .= '<p class="description"><strong>' . esc_html( 'This page moved since AIVIS crawled it.' ) . '</strong> ' . esc_html( 'AIVIS still has the old address; the structured data stays with the old URL until AIVIS re-crawls.' ) . '</p>';
		}
		$h .= '<p><a href="' . esc_url( $status ) . '">' . esc_html( 'Open AIVIS OS status' ) . '</a></p>';
		return $h;
	}

	private static function when( mixed $mysql ): string {
		return is_string( $mysql ) && '' !== $mysql ? $mysql . ' UTC' : 'never';
	}
}
