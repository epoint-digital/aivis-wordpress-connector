<?php
/**
 * Plugin container and hook registration.
 *
 * Every service is built lazily and exactly once. Nothing here does work at
 * load time beyond registering hooks — WP-I2 (no blocking work on the public
 * path) starts with not doing anything expensive in `plugins_loaded`.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS;

use AivisOS\Admin\AdminBar;
use AivisOS\Admin\Menu;
use AivisOS\Admin\Notices;
use AivisOS\Admin\SiteHealth;
use AivisOS\Api\Client;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Cli\Commands;
use AivisOS\Delivery\Injector;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Storage\Schema;
use AivisOS\Sync\Gc;
use AivisOS\Sync\Notifier;
use AivisOS\Sync\Scheduler;
use AivisOS\Sync\Synchronizer;
use AivisOS\Sync\Verifier;
use AivisOS\Update\GitHubReleases;

final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string, object> */
	private array $services = [];

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		self::instance()->register();
	}

	/**
	 * Activation: create the table, register schedules. Never fetch anything —
	 * activation must succeed with no network and no token.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			// Q-06: per-site only in 1.0. Network activation is refused rather
			// than half-supported.
			deactivate_plugins( AIVIS_OS_BASENAME, true, true );
			wp_die(
				esc_html__( 'AIVIS OS does not support network activation in this version. Activate it per site.', 'aivis-os' ),
				'',
				[ 'back_link' => true ]
			);
		}
		( new Schema() )->install();
		Scheduler::register_events();
	}

	/**
	 * Deactivation: stop the clocks. Data is kept (WP-I8) — uninstall.php
	 * handles removal, honouring the retention preference.
	 */
	public static function deactivate(): void {
		Scheduler::clear_events();
		( new Sync\Lock( self::instance()->options() ) )->release();
	}

	private function register(): void {
		load_plugin_textdomain( 'aivis-os', false, dirname( AIVIS_OS_BASENAME ) . '/languages' );

		// Keep the schema current on upgrade without a re-activation.
		add_action( 'init', [ $this, 'maybe_upgrade' ], 1 );


		// Delivery — the only thing that runs on a public request (WP-I2).
		add_action( 'wp_head', [ $this->injector(), 'render' ], 100 );

		// Background clocks.
		add_filter( 'cron_schedules', [ Scheduler::class, 'add_schedules' ] );
		add_action( 'aivis_os_sync', [ $this->synchronizer(), 'run' ] );
		add_action( 'aivis_os_gc', [ $this->gc(), 'run' ] );
		add_action( 'aivis_os_verify', [ $this->verifier(), 'daily' ] );
		add_action( 'aivis_os_scan_conflicts', [ $this->verifier(), 'scan_conflicts' ] );
		add_action( 'aivis_connector_sync_completed', [ $this->notifier(), 'sync_completed' ] );
		// On-demand miss lookups (opt-in, §05): scheduled by the injector, run here.
		add_action( 'aivis_os_lookup', [ $this->synchronizer(), 'refresh_url' ] );

		// Update URI answers — never wordpress.org.
		add_filter( 'update_plugins_github.com', [ $this->updater(), 'check' ], 10, 4 );

		// Warnings reach a logged-in admin on the front end too (§09a).
		( new AdminBar( $this ) )->register();

		if ( is_admin() ) {
			( new Menu( $this ) )->register();
			( new Notices( $this ) )->register();
			( new SiteHealth( $this ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'aivis', new Commands( $this ) );
		}
	}

	public function maybe_upgrade(): void {
		( new Schema() )->maybe_upgrade();
	}

	/* ── services ─────────────────────────────────────────────────────── */

	public function options(): Options {
		return $this->services['options'] ??= new Options();
	}

	public function repository(): Repository {
		return $this->services['repository'] ??= new Repository();
	}

	public function client(): Client {
		return $this->services['client'] ??= new Client( $this->options() );
	}

	public function cache(): AdapterFactory {
		return $this->services['cache'] ??= new AdapterFactory( $this->options() );
	}

	public function synchronizer(): Synchronizer {
		return $this->services['synchronizer'] ??= new Synchronizer(
			$this->client(),
			$this->repository(),
			$this->options(),
			$this->cache(),
			$this->assignment()
		);
	}

	/** §07a — chain → language assignment, catalogue and hints. */
	public function assignment(): \AivisOS\Sync\ChainAssignment {
		return $this->services['assignment'] ??= new \AivisOS\Sync\ChainAssignment( $this->client(), $this->options() );
	}

	public function injector(): Injector {
		return $this->services['injector'] ??= new Injector( $this->repository(), $this->options() );
	}

	public function gc(): Gc {
		return $this->services['gc'] ??= new Gc( $this->repository() );
	}

	public function verifier(): Verifier {
		return $this->services['verifier'] ??= new Verifier( $this->repository(), $this->options(), $this->notifier() );
	}

	public function notifier(): Notifier {
		return $this->services['notifier'] ??= new Notifier( $this->options(), $this->client(), $this->repository(), $this->cache() );
	}

	public function updater(): GitHubReleases {
		return $this->services['updater'] ??= new GitHubReleases();
	}
}
