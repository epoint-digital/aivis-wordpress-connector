<?php
/**
 * The Pages screen's list table — WordPress's own WP_List_Table, so the
 * pagination, sorting, views, search and bulk actions behave like every
 * other list an administrator already knows.
 *
 * Thin by design: the query lives in PagesQuery and Repository::search().
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Plugin;
use AivisOS\Rest\StatusDocument;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PagesTable extends \WP_List_Table {

	private int $total = 0;
	/** @var array<string,int> */
	private array $view_counts = [];
	/** @var array<string,string> */
	private array $moved = [];
	/** @var array<string,array<string,mixed>> */
	private array $conflicts = [];
	/** @var array<string,array<string,mixed>> */
	private array $chains = [];

	/**
	 * @param array{state:string,language:string,chain:string,search:string,page:int,per_page:int,orderby:string,order:string} $filters
	 */
	public function __construct( private readonly Plugin $plugin, private readonly array $filters ) {
		parent::__construct( [ 'singular' => 'page', 'plural' => 'pages', 'ajax' => false ] );
	}

	public function prepare_items(): void {
		$o     = $this->plugin->options();
		$state = $o->sync_state();
		$moved = (array) ( $state['moved'] ?? [] );
		foreach ( $moved as $k => $m ) {
			$this->moved[ (string) $k ] = (string) ( $m['to'] ?? '' );
		}
		$this->conflicts = (array) $o->conflicts()['items'];
		$this->chains    = (array) ( $state['chain_summaries'] ?? [] );

		$where  = PagesQuery::where( $this->filters, array_keys( $this->moved ), array_keys( $this->conflicts ) );
		$result = $this->plugin->repository()->search( $where['sql'], $where['args'], $this->filters['per_page'], $this->filters['page'], $this->filters['orderby'], $this->filters['order'] );
		$this->items = $result['rows'];
		$this->total = $result['total'];
		$this->set_pagination_args( [ 'total_items' => $this->total, 'per_page' => $this->filters['per_page'] ] );

		$counts            = $this->plugin->repository()->counts();
		$attention         = PagesQuery::where( [ 'state' => 'attention', 'language' => '', 'chain' => '', 'search' => '' ], array_keys( $this->moved ), array_keys( $this->conflicts ) );
		$this->view_counts = [
			'all'       => (int) $counts['total'],
			'attention' => $this->plugin->repository()->count_where( $attention['sql'], $attention['args'] ),
			'active'    => (int) $counts['active'],
			'stale'     => (int) $counts['stale'],
			'holding'   => (int) $counts['hold'],
			'suspended' => (int) $counts['suspended'],
			'retired'   => (int) $counts['retired'],
			'moved'     => count( $this->moved ),
			'conflict'  => count( $this->conflicts ),
		];
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'page' ];
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return [
			'cb'        => '<input type="checkbox">',
			'page'      => __( 'Page', 'aivis-os' ),
			'state'     => __( 'State', 'aivis-os' ),
			'language'  => __( 'Language', 'aivis-os' ),
			'chain'     => __( 'Chain', 'aivis-os' ),
			'generated' => __( 'Generated', 'aivis-os' ),
			'published' => __( 'Published', 'aivis-os' ),
			'verified'  => __( 'Seen on page', 'aivis-os' ),
		];
	}

	/** @return array<string,array{0:string,1:bool}> */
	public function get_sortable_columns(): array {
		return [
			'page'      => [ 'source_url', true ],
			'language'  => [ 'language_code', false ],
			'chain'     => [ 'chain_id', false ],
			'generated' => [ 'source_generated_at', false ],
			'published' => [ 'published_at', false ],
			'verified'  => [ 'verified_at', false ],
		];
	}

	/** @return array<string,string> */
	public function get_views(): array {
		$base  = admin_url( 'admin.php?page=' . Menu::SLUG_PAGES );
		$cur   = $this->filters['state'];
		$views = [];
		foreach ( [ 'all', 'attention', 'active', 'stale', 'holding', 'suspended', 'retired', 'moved', 'conflict' ] as $v ) {
			$n = $this->view_counts[ $v ] ?? 0;
			if ( 0 === $n && ! in_array( $v, [ 'all', 'attention' ], true ) ) {
				continue;
			}
			$url   = 'all' === $v ? $base : add_query_arg( 'state', $v, $base );
			$class = ( 'all' === $v && '' === $cur ) || $v === $cur ? ' class="current"' : '';
			$label = PagesQuery::label( 'all' === $v ? '' : $v );
			$views[ $v ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $n );
		}
		return $views;
	}

	/**
	 * The bulk select is named `aivis_bulk`, not `action`: the surrounding
	 * form is a GET to admin.php and `action` would collide with WordPress's
	 * own dispatch. The load-hook on the Pages screen processes it.
	 *
	 * @param string $which
	 */
	protected function bulk_actions( $which = '' ): void {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<label for="aivis-bulk" class="screen-reader-text">' . esc_html__( 'Bulk action', 'aivis-os' ) . '</label>';
		echo '<select name="aivis_bulk" id="aivis-bulk"><option value="">' . esc_html__( 'Bulk actions', 'aivis-os' ) . '</option>';
		echo '<option value="refresh">' . esc_html__( 'Refresh from AIVIS now', 'aivis-os' ) . '</option>';
		echo '<option value="disable">' . esc_html__( 'Disable here', 'aivis-os' ) . '</option>';
		echo '<option value="restore">' . esc_html__( 'Restore', 'aivis-os' ) . '</option></select> ';
		submit_button( __( 'Apply', 'aivis-os' ), 'action', '', false, [ 'id' => 'aivis-bulk-apply' ] );
	}

	/** @param string $which */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$langs = $this->plugin->repository()->distinct( 'language_code' );
		echo '<div class="alignleft actions">';
		echo '<select name="language"><option value="">' . esc_html__( 'All languages', 'aivis-os' ) . '</option>';
		foreach ( $langs as $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $l ), selected( $this->filters['language'], $l, false ), esc_html( $l ) );
		}
		echo '</select> <select name="chain"><option value="">' . esc_html__( 'All chains', 'aivis-os' ) . '</option>';
		foreach ( $this->plugin->repository()->distinct( 'chain_id' ) as $c ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $c ), selected( $this->filters['chain'], $c, false ), esc_html( (string) ( $this->chains[ $c ]['name'] ?? $c ) ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'aivis-os' ), '', 'filter_action', false );
		echo '</div>';
	}

	/** @param array<string,mixed> $item */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="url_key[]" value="%s">', esc_attr( (string) $item['url_key'] ) );
	}

	/** @param array<string,mixed> $item */
	public function column_page( array $item ): string {
		$url   = (string) $item['source_url'];
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		$state = StatusDocument::state_of( $item );
		$acts  = [
			'refresh' => sprintf( '<a href="%s">%s</a>', esc_url( Menu::action_url( 'refresh_url', [ 'url' => $url ] ) ), esc_html__( 'Refresh now', 'aivis-os' ) ),
			'toggle'  => 'retired' === $state
				? sprintf( '<a href="%s">%s</a>', esc_url( Menu::action_url( 'restore_url', [ 'url_key' => $item['url_key'] ] ) ), esc_html__( 'Restore', 'aivis-os' ) )
				: sprintf( '<a href="%s">%s</a>', esc_url( Menu::action_url( 'disable_url', [ 'url_key' => $item['url_key'] ] ) ), esc_html__( 'Disable here', 'aivis-os' ) ),
			'open'    => sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), esc_html__( 'Open page', 'aivis-os' ) ),
		];
		$h = '<span class="aivis-url">' . esc_html( $path ) . '</span>';
		if ( isset( $this->moved[ (string) $item['url_key'] ] ) ) {
			$h .= '<br><span class="description">' . esc_html__( 'now at', 'aivis-os' ) . ' <span class="aivis-url">' . esc_html( (string) wp_parse_url( $this->moved[ (string) $item['url_key'] ], PHP_URL_PATH ) ?: '/' ) . '</span></span>';
		}
		if ( ! empty( $item['last_error_code'] ) ) {
			$h .= '<br><span class="description">' . esc_html( StatusPage::explain_code( (string) $item['last_error_code'], $state ) ) . '</span>';
		}
		return $h . $this->row_actions( $acts );
	}

	/** @param array<string,mixed> $item */
	public function column_state( array $item ): string {
		$state = StatusDocument::state_of( $item );
		$h     = StatusPage::chip_for( $state );
		if ( isset( $this->conflicts[ (string) $item['source_url'] ] ) ) {
			$cf = $this->conflicts[ (string) $item['source_url'] ];
			$h .= '<br><span class="aivis-chip aivis-chip--bad" style="margin-top:4px">' . esc_html( sprintf( /* translators: %s: sources */ __( 'Other JSON-LD: %s', 'aivis-os' ), implode( ', ', array_map( [ \AivisOS\Delivery\Conflicts::class, 'label' ], (array) ( $cf['sources'] ?? [] ) ) ) ) ) . '</span>';
		}
		return $h;
	}

	/**
	 * @param array<string,mixed> $item
	 * @param string              $column_name
	 */
	public function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'language'  => '<code>' . esc_html( (string) ( $item['language_code'] ?: '—' ) ) . '</code>',
			'chain'     => esc_html( (string) ( $this->chains[ $item['chain_id'] ]['name'] ?? $item['chain_id'] ) ),
			'generated' => esc_html( self::when( $item['source_generated_at'] ?? null ) ),
			'published' => esc_html( self::when( $item['published_at'] ?? null ) ),
			'verified'  => esc_html( self::when( $item['verified_at'] ?? null ) ),
			default     => '',
		};
	}

	public function no_items(): void {
		if ( 'attention' === $this->filters['state'] ) {
			esc_html_e( 'Nothing needs you. Every page is either injected as AIVIS generated it, or retired on purpose.', 'aivis-os' );
			return;
		}
		esc_html_e( 'No pages match.', 'aivis-os' );
	}

	public function total(): int {
		return $this->total;
	}

	private static function when( mixed $mysql ): string {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return '—';
		}
		$t = strtotime( $mysql . ' UTC' );
		return false === $t ? $mysql : human_time_diff( $t ) . ' ' . __( 'ago', 'aivis-os' );
	}
}
