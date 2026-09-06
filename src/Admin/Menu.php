<?php
/**
 * Admin menu, asset loading, and POST action dispatch.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Plugin;
use AivisOS\Sync\Scheduler;

final class Menu {

	public const SLUG_STATUS   = 'aivis-os';
	public const SLUG_SETTINGS = 'aivis-os-settings';

	public function __construct( private readonly Plugin $plugin ) {}

	public static function capability(): string {
		/**
		 * Filter the capability required to manage the plugin.
		 *
		 * @param string $cap Default manage_options.
		 */
		return (string) apply_filters( 'aivis_connector_manage_capability', 'manage_options' );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_post_aivis_os_action', [ $this, 'handle' ] );
	}

	public function menu(): void {
		$cap = self::capability();
		add_menu_page( 'AIVIS OS', 'AIVIS OS', $cap, self::SLUG_STATUS, [ new StatusPage( $this->plugin ), 'render' ], 'dashicons-networking', 81 );
		add_submenu_page( self::SLUG_STATUS, __( 'Status', 'aivis-os' ), __( 'Status', 'aivis-os' ), $cap, self::SLUG_STATUS, [ new StatusPage( $this->plugin ), 'render' ] );
		add_submenu_page( self::SLUG_STATUS, __( 'Settings', 'aivis-os' ), __( 'Settings', 'aivis-os' ), $cap, self::SLUG_SETTINGS, [ new SettingsPage( $this->plugin ), 'render' ] );
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'aivis-os' ) ) {
			return;
		}
		wp_enqueue_style( 'aivis-os-admin', AIVIS_OS_URL . 'assets/admin.css', [], AIVIS_OS_VERSION );
	}

	/** One nonce-checked entry point for every admin action. */
	public function handle(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aivis-os' ) );
		}
		check_admin_referer( 'aivis_os_action' );
		$do   = sanitize_key( (string) ( $_POST['do'] ?? '' ) );
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG_STATUS );
		$msg  = '';

		switch ( $do ) {
			case 'save_settings':
				$msg = ( new SettingsPage( $this->plugin ) )->save( $_POST );
				break;
			case 'test_connection':
				$r = $this->plugin->client()->me();
				if ( $r->ok() ) {
					$this->plugin->options()->set_token_status( true, (string) ( $r->body['tokenName'] ?? '' ), (string) ( $r->body['email'] ?? '' ) );
					$msg = 'connected';
				} else {
					$this->plugin->options()->set_token_status( false );
					$msg = 'auth_failed';
				}
				break;
			case 'sync_now':
				Scheduler::request_sync_now();
				$msg = 'sync_requested';
				break;
			case 'sync_run':
				$this->plugin->synchronizer()->run();
				$msg = 'synced';
				break;
			case 'refresh_url':
				$url = esc_url_raw( (string) ( $_POST['url'] ?? '' ) );
				if ( '' !== $url ) {
					$this->plugin->synchronizer()->refresh_url( $url );
				}
				$msg = 'refreshed';
				break;
			case 'disable_url':
			case 'restore_url':
				$key = sanitize_text_field( (string) ( $_POST['url_key'] ?? '' ) );
				if ( 'disable_url' === $do ) {
					$this->plugin->repository()->retire( $key, 'AIVIS_ADMIN_DISABLED' );
				} else {
					$this->plugin->repository()->restore( $key );
				}
				$msg = 'updated';
				break;
			case 'disconnect':
				$this->plugin->options()->set_token( '' );
				$this->plugin->options()->clear_business();
				$this->plugin->options()->set_token_status( null );
				$this->plugin->repository()->delete_all();
				$this->plugin->cache()->adapter()->purge_all();
				$msg = 'disconnected';
				break;
			case 'clear_diagnostics':
				$this->plugin->options()->clear_diagnostics();
				$msg = 'cleared';
				break;
			case 'acknowledge_conflicts':
				$this->plugin->options()->acknowledge_conflicts();
				$msg = 'overridden';
				break;
			case 'scan_conflicts':
				$r   = $this->plugin->verifier()->scan_conflicts();
				$msg = $r['conflicts'] > 0 ? 'scan_conflicts' : 'scan_clean';
				break;
		}
		wp_safe_redirect( add_query_arg( 'aivis_msg', $msg, $back ) );
		exit;
	}

	/** Small helper used by both pages. */
	public static function action_form( string $do, string $label, array $hidden = [], string $class = 'button', bool $confirm = false ): string {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"'
			. ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( __( 'Are you sure?', 'aivis-os' ) ) . '\')"' : '' ) . '>';
		$h .= wp_nonce_field( 'aivis_os_action', '_wpnonce', true, false );
		$h .= '<input type="hidden" name="action" value="aivis_os_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		foreach ( $hidden as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$h .= '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		return $h;
	}
}
