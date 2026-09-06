<?php
/**
 * §06 — inventory walk, authoritative runs, artifact fetch, retirement.
 *
 * Runs from cron (and WP-CLI / the admin "Sync now"). Never on a public
 * request. Resumable: cursors and the in-progress inventory persist in
 * aivis_os_sync_state so a tick that hits its time budget picks up where it
 * left off instead of starting over.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Api\Client;
use AivisOS\Api\Response;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Delivery\Language;
use AivisOS\Domain\Action;
use AivisOS\Domain\ErrorCode;
use AivisOS\Domain\UrlKey;
use AivisOS\Security\Binding;
use AivisOS\Security\Envelope;
use AivisOS\Security\Serializer;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;

final class Synchronizer {

	/** Self-throttle (§04): the real rate limit, chosen by us. */
	public const MAX_ARTIFACTS_PER_JOB = 20;
	/** Wall-clock budget per tick before persisting cursors and yielding. */
	public const TIME_BUDGET_S = 20;
	/** Negative cache for unknown URLs (§05). */
	public const MISS_TTL = 6 * HOUR_IN_SECONDS;

	private float $started = 0.0;
	private bool $api_reachable = false;
	/** @var list<string> */
	private array $purge = [];
	private readonly ChainAssignment $assignment;

	public function __construct(
		private readonly Client $client,
		private readonly Repository $repository,
		private readonly Options $options,
		private readonly AdapterFactory $cache,
		?ChainAssignment $assignment = null
	) {
		$this->assignment = $assignment ?? new ChainAssignment( $client, $options );
	}

	/**
	 * One tick. Returns a summary for CLI/admin.
	 *
	 * @return array<string,mixed>
	 */
	public function run(): array {
		$this->started = microtime( true );
		$summary       = [ 'ok' => false ];

		if ( '' === $this->options->token() ) {
			return $summary + [ 'skipped' => 'no token' ];
		}
		$biz = $this->options->business();
		if ( '' === $biz['business_id'] ) {
			return $summary + [ 'skipped' => 'no business bound' ];
		}
		// Domain binding is re-checked on every run (§07): a business edited in
		// AIVIS to point elsewhere unbinds instead of quietly serving another
		// site's data.
		$biz_host = strtolower( (string) wp_parse_url( $biz['base_url'], PHP_URL_HOST ) );
		if ( '' !== $biz_host && ! $this->host_matches( $biz_host, $this->options->site_host() ) ) {
			$this->options->record( ErrorCode::SCOPE_MISMATCH, "bound business domain {$biz_host} no longer matches this site" );
			return $summary + [ 'skipped' => 'domain mismatch' ];
		}

		// §07a — only chains assigned to a WordPress language sync. Assign
		// automatically where there is nothing to decide (one language, or a
		// chain whose language AIVIS reports matches exactly one); otherwise
		// the admin must, and nothing runs until then.
		if ( ! $this->options->assigned_chain_ids() ) {
			$auto = $this->assignment->auto_assign();
			if ( ! $auto['map'] ) {
				if ( $auto['catalog_ok'] ) {
					$this->options->record( ErrorCode::LANGUAGE_UNASSIGNED, 'no chain is assigned to a language yet — assign chains under Settings → Languages & chains' );
				}
				return $summary + [ 'skipped' => 'no chain assigned to a language' ];
			}
		}

		$lock = new Lock( $this->options );
		if ( ! $lock->acquire( 'sync' ) ) {
			return $summary + [ 'skipped' => 'locked' ];
		}

		try {
			$state   = $this->options->sync_state();
			$sync_id = (string) ( $state['in_progress'] ?? '' );
			if ( '' === $sync_id ) {
				$sync_id = wp_generate_uuid4();
				$state   = [
					'in_progress' => $sync_id,
					'chains'      => null,   // list of chain ids once listed
					'chain_pos'   => 0,
					'cursor'      => null,
					'inventory'   => [],     // url_key => compact row
					'totals'      => [],     // chain_id => [seen, total]
					'mismatch'    => [],     // chain_id => AIVIS languageCode vs assignment (§07a)
					'phase'       => 'inventory',
					'started_at'  => time(),
				];
				$this->options->patch_sync_state( $state );
			}

			if ( 'inventory' === $state['phase'] ) {
				$state = $this->walk_inventory( $state, $biz['business_id'] );
				$this->options->patch_sync_state( $state );
				if ( 'inventory' === $state['phase'] ) {
					return $summary + [
						'ok'      => true,
						'partial' => true,
						'phase'   => 'inventory',
						'sync_id' => $sync_id,
					];
				}
			}
			if ( 'aborted' === $state['phase'] ) {
				// Token rejected, account problem, or no assigned chain exists (§07a):
				// nothing to fetch, nothing to retire, and not a "partial" run either.
				$this->options->patch_sync_state( [ 'in_progress' => '', 'inventory' => [], 'chains' => null, 'pending' => [] ] );
				do_action( 'aivis_connector_sync_failed', 'AIVIS_SYNC_ABORTED' );
				return $summary + [ 'skipped' => 'aborted', 'sync_id' => $sync_id ];
			}

			$authoritative = $this->reconciles( $state );
			$fetched       = $this->fetch_artifacts( $state, $biz, $sync_id );
			$this->options->patch_sync_state( $state );

			if ( empty( $state['pending'] ) ) {
				$this->note_language_mismatch( (array) ( $state['mismatch'] ?? [] ) );
			}

			$retired = 0;
			if ( $authoritative && empty( $state['pending'] ) ) {
				$retired = $this->retirement_pass( $state, $biz['business_id'], $sync_id );
				$this->options->patch_sync_state(
					[
						'in_progress'             => '',
						'last_complete_at'        => time(),
						'last_complete_sync_id'   => $sync_id,
						'last_authoritative'      => true,
						'inventory'               => [],
						'chains'                  => null,
						'pending'                 => [],
					]
				);
			} elseif ( empty( $state['pending'] ) ) {
				// Walk done but not reconciled: a partial traversal never retires.
				$this->options->patch_sync_state(
					[
						'in_progress'        => '',
						'last_complete_at'   => time(),
						'last_authoritative' => false,
						'inventory'          => [],
						'chains'             => null,
					]
				);
				$this->options->record( 'AIVIS_SYNC_PARTIAL', 'inventory did not reconcile with totals; nothing retired' );
			}

			$this->flush_purges();
			do_action( 'aivis_connector_sync_completed', [ 'sync_id' => $sync_id, 'authoritative' => $authoritative, 'fetched' => $fetched, 'retired' => $retired ] );

			return [
				'ok'            => true,
				'sync_id'       => $sync_id,
				'authoritative' => $authoritative,
				'fetched'       => $fetched,
				'retired'       => $retired,
				'pending'       => count( (array) ( $this->options->sync_state()['pending'] ?? [] ) ),
			];
		} catch ( \Throwable $e ) {
			$this->options->record( 'AIVIS_SYNC_FATAL', $e->getMessage() );
			do_action( 'aivis_connector_sync_failed', 'AIVIS_SYNC_FATAL' );
			return $summary + [ 'error' => $this->options->redact( $e->getMessage() ) ];
		} finally {
			$lock->release();
		}
	}

	/** Force one URL's re-check outside the schedule ("Refresh this URL now"). */
	public function refresh_url( string $absolute_url ): array {
		$biz = $this->options->business();
		if ( '' === $biz['business_id'] ) {
			return [ 'ok' => false, 'error' => 'no business bound' ];
		}
		if ( ! $this->options->assigned_chain_ids() ) {
			return [ 'ok' => false, 'error' => 'no chain assigned to a language' ];
		}
		$sync_id = 'manual-' . wp_generate_uuid4();
		$r       = $this->client->jsonld_by_url( $absolute_url );
		$this->api_reachable = $this->api_reachable || 200 === $r->status;
		[ $chains, $context ] = $this->chains_for_url( $absolute_url );
		$out = $this->apply_lookup( $absolute_url, $r, $biz, $sync_id, $chains, $context );
		$this->flush_purges();
		return $out;
	}

	/** Purge a set of URLs now (used when an assignment change deactivates rows). */
	public function purge( array $urls ): void {
		foreach ( $urls as $u ) {
			$this->purge[] = (string) $u;
		}
		$this->flush_purges();
	}

	/**
	 * §07a — which chains may serve this URL: the ones assigned to the page's
	 * language. Falls back to every assigned chain only when the site has a
	 * single language (then they are the same set) or nothing narrower exists.
	 *
	 * @return array{0:list<string>,1:string}
	 */
	private function chains_for_url( string $url ): array {
		$lang   = Language::of_url( $url );
		$chains = $this->options->chains_for_language( $lang );
		if ( ! $chains ) {
			return [ $this->options->assigned_chain_ids(), "language {$lang} (no chain assigned; any assigned chain)" ];
		}
		return [ $chains, "language {$lang}" ];
	}

	/* ── phase 1: inventory ──────────────────────────────────────────── */

	/** @param array<string,mixed> $state */
	private function walk_inventory( array $state, string $business_id ): array {
		if ( null === $state['chains'] ) {
			$all = $this->list_all_chains( $business_id );
			if ( null === $all ) {
				return $state; // transport problem; try again next tick
			}
			// §07a: an assignment to a chain that no longer exists is dropped; the
			// walk covers assigned chains only, so unassigned ones never sync.
			$map   = $this->options->chain_languages();
			$stale = array_diff( array_keys( $map ), $all );
			if ( $stale ) {
				$this->options->set_chain_languages( array_diff_key( $map, array_flip( $stale ) ) );
				$this->options->record( ErrorCode::LANGUAGE_UNASSIGNED, 'chain(s) no longer exist in AIVIS, assignment dropped: ' . implode( ', ', $stale ) );
			}
			$chains = array_values( array_intersect( $all, $this->options->assigned_chain_ids() ) );
			if ( ! $chains ) {
				$this->options->record( ErrorCode::LANGUAGE_UNASSIGNED, 'none of this business\'s chains is assigned to a language; nothing synced' );
				$state['phase'] = 'aborted';
				return $state;
			}
			$state['chains']    = $chains;
			$state['chain_pos'] = 0;
			$state['cursor']    = null;
		}
		$chains = (array) $state['chains'];
		while ( $state['chain_pos'] < count( $chains ) ) {
			if ( $this->over_budget() ) {
				return $state;
			}
			$chain_id   = (string) $chains[ $state['chain_pos'] ];
			$chain_lang = (string) ( $this->options->language_for_chain( $chain_id ) ?? '' );
			$r          = $this->client->urls( $chain_id, $state['cursor'] );
			if ( ! $r->ok() ) {
				$this->note_failure( $r, "inventory {$chain_id}" );
				if ( in_array( $r->kind(), [ 'auth', 'account' ], true ) ) {
					$state['phase'] = 'aborted';
				}
				return $state;
			}
			$this->api_reachable = true;
			foreach ( (array) ( $r->body['items'] ?? [] ) as $item ) {
				// Count every row the API returned BEFORE filtering: `seen` must
				// reconcile with `total`, or the run can never be authoritative.
				$state['totals'][ $chain_id ]['seen'] = ( $state['totals'][ $chain_id ]['seen'] ?? 0 ) + 1;
				$url = (string) ( $item['url'] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				try {
					$key = UrlKey::of( $url );
				} catch ( \Throwable ) {
					continue;
				}
				// AIVIS's own language for the row vs the admin's assignment: a
				// disagreement is reported, not acted on — the assignment is the
				// admin's decision, and the API-10 chain language would settle it.
				$aivis_lang = Language::normalize( (string) ( $item['languageCode'] ?? '' ) );
				if ( '' !== $aivis_lang && '' !== $chain_lang && ! Language::same( $aivis_lang, $chain_lang ) ) {
					$m                              = (array) ( $state['mismatch'][ $chain_id ] ?? [ 'aivis' => $aivis_lang, 'assigned' => $chain_lang, 'count' => 0, 'url' => $url ] );
					$m['count']                     = (int) $m['count'] + 1;
					$state['mismatch'][ $chain_id ] = $m;
				}
				$compact = [
					'url'           => $url,
					'urlId'         => (string) ( $item['id'] ?? '' ),
					'chainId'       => $chain_id,
					'languageCode'  => (string) ( $item['languageCode'] ?? '' ),
					'layer'         => (string) ( $item['layer'] ?? '' ),
					'captureStatus' => $item['captureStatus'] ?? null,
					'jsonLd'        => [
						'ready'       => ! empty( $item['jsonLd']['ready'] ),
						'stale'       => ! empty( $item['jsonLd']['stale'] ),
						'generatedAt' => $item['jsonLd']['generatedAt'] ?? null,
					],
				];
				// Same URL in two chains: keep the one that would win the API's
				// tie-break (non-stale first, then newest), so target selection
				// agrees with what /jsonld will return.
				$prev = $state['inventory'][ $key ] ?? null;
				if ( null === $prev || self::wins( $compact, $prev ) ) {
					$state['inventory'][ $key ] = $compact;
				}
			}
			$state['totals'][ $chain_id ]['total'] = (int) ( $r->body['total'] ?? 0 );
			$next = $r->body['nextCursor'] ?? null;
			if ( is_string( $next ) && '' !== $next && ! empty( $r->body['hasMore'] ) ) {
				$state['cursor'] = $next;
			} else {
				$state['totals'][ $chain_id ]['complete'] = true;
				$state['chain_pos']++;
				$state['cursor'] = null;
			}
		}
		$state['phase']   = 'artifacts';
		$state['pending'] = $this->select_targets( $state );
		return $state;
	}

	/** @return list<string>|null */
	private function list_all_chains( string $business_id ): ?array {
		$ids       = [];
		$summaries = [];
		$cursor    = null;
		do {
			$r = $this->client->chains( $business_id, $cursor );
			if ( ! $r->ok() ) {
				$this->note_failure( $r, 'chains' );
				return null;
			}
			$this->api_reachable = true;
			foreach ( (array) ( $r->body['items'] ?? [] ) as $c ) {
				if ( ! empty( $c['id'] ) ) {
					$ids[]                            = (string) $c['id'];
					$summaries[ (string) $c['id'] ] = [
						'name'     => (string) ( $c['name'] ?? '' ),
						'state'    => (string) ( $c['state'] ?? '' ),
						'kg'       => ! empty( $c['knowledgeGraphReady'] ),
						'step'     => (int) ( $c['currentStep'] ?? 0 ),
						'urlCount' => (int) ( $c['urlCount'] ?? 0 ),
					];
				}
			}
			$cursor = ( ! empty( $r->body['hasMore'] ) && is_string( $r->body['nextCursor'] ?? null ) ) ? $r->body['nextCursor'] : null;
		} while ( null !== $cursor );
		$this->options->patch_sync_state( [ 'chain_ids' => $ids, 'chain_summaries' => $summaries ] );
		return $ids;
	}

	/** @param array<string,mixed> $a @param array<string,mixed> $b */
	private static function wins( array $a, array $b ): bool {
		$as = $a['jsonLd']['stale'] ? 1 : 0;
		$bs = $b['jsonLd']['stale'] ? 1 : 0;
		if ( $as !== $bs ) {
			return $as < $bs;
		}
		return strtotime( (string) $a['jsonLd']['generatedAt'] ) > strtotime( (string) $b['jsonLd']['generatedAt'] );
	}

	/**
	 * Which inventory rows need an artifact fetch this run: ready rows that are
	 * new locally, or whose generatedAt / stale flag moved.
	 *
	 * @param array<string,mixed> $state
	 * @return list<string> url_keys
	 */
	private function select_targets( array $state ): array {
		$targets = [];
		foreach ( (array) $state['inventory'] as $key => $row ) {
			if ( empty( $row['jsonLd']['ready'] ) ) {
				continue;
			}
			$local = $this->repository->find_by_key( (string) $key );
			if ( null === $local || ! empty( $local['retired_at'] ) ) {
				$targets[] = (string) $key;
				continue;
			}
			$remote_gen = $row['jsonLd']['generatedAt'] ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $row['jsonLd']['generatedAt'] ) ) : null;
			$stale      = ! empty( $row['jsonLd']['stale'] ) ? 1 : 0;
			if ( $local['source_generated_at'] !== $remote_gen || (int) $local['source_stale'] !== $stale || ! empty( $local['suspended_at'] ) || null !== $local['last_error_code'] ) {
				$targets[] = (string) $key;
			}
		}
		return $targets;
	}

	/* ── phase 2: artifacts ──────────────────────────────────────────── */

	/** @param array<string,mixed> $state @param array<string,mixed> $biz */
	private function fetch_artifacts( array &$state, array $biz, string $sync_id ): int {
		$pending = (array) ( $state['pending'] ?? [] );
		$done    = 0;
		/**
		 * Filter how many artifacts one sync tick may fetch. No real rate limit
		 * exists upstream (API-7); raise this for an initial import.
		 *
		 * @param int $n Default 20.
		 */
		$cap = max( 1, (int) apply_filters( 'aivis_connector_artifacts_per_job', self::MAX_ARTIFACTS_PER_JOB ) );
		while ( $pending && $done < $cap && ! $this->over_budget() ) {
			$key = (string) array_shift( $pending );
			$row = $state['inventory'][ $key ] ?? null;
			if ( null === $row ) {
				continue;
			}
			// Fetch by urlId: it pins the chain. /jsonld?url= would return the
			// freshest artifact across every chain (and business) on the account,
			// which with one chain per language can be another language's page.
			$url_id = (string) ( $row['urlId'] ?? '' );
			$r      = '' !== $url_id ? $this->client->jsonld_by_id( $url_id ) : $this->client->jsonld_by_url( (string) $row['url'] );
			if ( 200 === $r->status ) {
				$this->api_reachable = true;
			}
			// ZT-03 chain context: the chains assigned to this page's language.
			[ $chains, $context ] = $this->chains_for_url( (string) $row['url'] );
			$this->apply_lookup( (string) $row['url'], $r, $biz, $sync_id, $chains, $context );
			$done++;
			if ( 'throttled' === $r->kind() ) {
				// Honour Retry-After by yielding; never sleep in-process.
				array_unshift( $pending, $key );
				break;
			}
			if ( in_array( $r->kind(), [ 'auth', 'account' ], true ) ) {
				array_unshift( $pending, $key );
				break;
			}
		}
		$state['pending'] = array_values( $pending );
		return $done;
	}

	/**
	 * Apply one lookup outcome to storage (R-01 / R-01a / serve).
	 *
	 * @param array<string,mixed> $biz
	 * @param list<string>|null   $known_chain_ids
	 * @return array<string,mixed>
	 */
	private function apply_lookup( string $url, Response $r, array $biz, string $sync_id, ?array $known_chain_ids, ?string $chain_context = null ): array {
		$key = UrlKey::of( $url );
		// R-01 needs confirmed reachability in the same run. If this 404 is the
		// first thing we heard from the API, ask /me once rather than assume.
		if ( 'url_gone' === $r->kind() && ! $this->api_reachable ) {
			$this->api_reachable = $this->client->reachable();
		}
		$decision = Decision::from_lookup( $r, $this->api_reachable );
		$local    = $this->repository->find_by_key( $key );

		switch ( $decision['action'] ) {
			case Action::SERVE:
				$v = Envelope::validate( $r->body );
				if ( ! $v['ok'] ) {
					$this->options->record( ErrorCode::SCHEMA_INVALID, implode( '; ', $v['errors'] ), $url );
					if ( $local ) {
						$this->repository->mark_hold( $key, ErrorCode::SCHEMA_INVALID, $sync_id );
					}
					return [ 'ok' => false, 'action' => 'hold', 'code' => ErrorCode::SCHEMA_INVALID ];
				}
				$b = Binding::check( $r->body, (string) $biz['business_id'], $this->options->allowed_hosts(), $known_chain_ids ?: null, $url, $chain_context );
				if ( ! $b['ok'] ) {
					$this->options->record( ErrorCode::SCOPE_MISMATCH, implode( '; ', $b['errors'] ), $url );
					return [ 'ok' => false, 'action' => 'reject', 'code' => ErrorCode::SCOPE_MISMATCH ];
				}
				try {
					$serialized = Serializer::serialize( (array) $r->body['jsonLd'] );
				} catch ( \Throwable $e ) {
					$this->options->record( ErrorCode::SCHEMA_INVALID, $e->getMessage(), $url );
					return [ 'ok' => false, 'action' => 'hold', 'code' => ErrorCode::SCHEMA_INVALID ];
				}
				$res = $this->repository->upsert_artifact(
					[
						'url_key'             => $key,
						'source_url'          => (string) $r->body['url'],
						'url_id'              => (string) $r->body['urlId'],
						'chain_id'            => (string) $r->body['chainId'],
						'business_id'         => (string) $r->body['businessId'],
						'language_code'       => (string) $r->body['languageCode'],
						'source_generated_at' => gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $r->body['generatedAt'] ) ),
						'source_stale'        => (bool) $r->body['stale'],
						'local_post_id'       => $this->post_id_for( $url ),
						'sync_id'             => $sync_id,
					],
					$serialized
				);
				if ( null === $res ) {
					$this->options->record( ErrorCode::DB_WRITE, 'upsert failed', $url );
					return [ 'ok' => false, 'action' => 'hold', 'code' => ErrorCode::DB_WRITE ];
				}
				if ( $res['changed'] || $res['activated'] ) {
					$this->purge[] = $url;
					do_action( 'aivis_connector_artifact_changed', $url, $local['content_hash'] ?? '', $res['hash'] );
				}
				return [ 'ok' => true, 'action' => 'serve', 'changed' => $res['changed'] ];

			case Action::SUSPEND:
				if ( $local && empty( $local['suspended_at'] ) ) {
					$this->repository->suspend( $key, $sync_id );
					$this->purge[] = $url;
					$this->options->record( ErrorCode::RETRACTED, 'withdrawn upstream; injection suspended pending inventory confirmation', $url );
				}
				return [ 'ok' => true, 'action' => 'suspend', 'rule' => 'R-01' ];

			case Action::HOLD:
			default:
				if ( $local ) {
					$code = match ( $decision['kind'] ) {
						'not_generated' => ErrorCode::NOT_GENERATED,
						'auth'          => ErrorCode::AUTH_401,
						'account'       => ErrorCode::ACCOUNT_403,
						'transport'     => ErrorCode::HTTP_TIMEOUT,
						default         => ErrorCode::HTTP_ERROR,
					};
					$this->repository->mark_hold( $key, $code, $sync_id );
				} elseif ( 'url_gone' === $decision['kind'] ) {
					set_transient( 'aivis_os_miss_' . $key, 1, self::MISS_TTL );
				}
				$this->note_failure( $r, $url );
				return [ 'ok' => true, 'action' => 'hold', 'rule' => $decision['rule'], 'kind' => $decision['kind'] ];
		}
	}

	/* ── phase 3: retirement (authoritative only) ────────────────────── */

	/** @param array<string,mixed> $state */
	private function retirement_pass( array $state, string $business_id, string $sync_id ): int {
		$retired   = 0;
		$inventory = (array) $state['inventory'];

		// Rows the inventory knows about: apply R-02a and clear stale suspicions.
		foreach ( $inventory as $key => $row ) {
			$local = $this->repository->find_by_key( (string) $key );
			if ( null === $local ) {
				continue;
			}
			$d = Decision::from_inventory( true, $row, (int) $local['missing_complete_runs'] );
			if ( Action::DEACTIVATE === $d['action'] && (int) $local['active'] === 1 ) {
				$this->repository->deactivate( (string) $key, ErrorCode::RETRACTED );
				$this->purge[] = (string) $row['url'];
			} elseif ( Action::SERVE === $d['action'] ) {
				if ( ! empty( $local['suspended_at'] ) ) {
					// Suspicion not confirmed: the URL is still in inventory and ready.
					$this->repository->unsuspend( (string) $key );
				}
				$this->repository->mark_seen( (string) $key, $sync_id );
			} else {
				$this->repository->mark_seen( (string) $key, $sync_id );
			}
		}

		// Rows the inventory did NOT see: two authoritative absences retire (R-02).
		// A row whose chain is no longer assigned to a language is absent by the
		// admin's decision, not AIVIS's — it retires under its own code (§07a).
		$assigned = $this->options->assigned_chain_ids();
		foreach ( $this->repository->unseen_in_sync( $business_id, $sync_id ) as $local ) {
			$runs = $this->repository->increment_missing( (string) $local['url_key'] );
			$d    = Decision::from_inventory( true, null, $runs - 1 );
			if ( Action::RETIRE === $d['action'] ) {
				$code = in_array( (string) $local['chain_id'], $assigned, true ) ? ErrorCode::RETRACTED : ErrorCode::LANGUAGE_UNASSIGNED;
				$this->repository->retire( (string) $local['url_key'], $code );
				$this->purge[] = (string) $local['source_url'];
				$retired++;
			}
		}
		return $retired;
	}

	/* ── helpers ─────────────────────────────────────────────────────── */

	/** @param array<string,mixed> $state */
	private function reconciles( array $state ): bool {
		return self::is_reconciled( (array) ( $state['totals'] ?? [] ), (array) ( $state['chains'] ?? [] ) );
	}

	/**
	 * Authoritative = every chain walked to completion and seen == total.
	 *
	 * @param array<string,array{seen?:int,total?:int,complete?:bool}> $totals
	 * @param list<string> $chains
	 */
	public static function is_reconciled( array $totals, array $chains ): bool {
		if ( ! $chains ) {
			return false; // no chains at all is not vacuously authoritative
		}
		foreach ( $chains as $id ) {
			$t = $totals[ $id ] ?? null;
			if ( ! $t || empty( $t['complete'] ) || (int) ( $t['seen'] ?? -1 ) !== (int) ( $t['total'] ?? -2 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * §07a — persist the per-chain language disagreement for the Status screen
	 * and Site Health, and record one diagnostic per chain per run.
	 *
	 * @param array<string,array{aivis:string,assigned:string,count:int,url:string}> $mismatch
	 */
	private function note_language_mismatch( array $mismatch ): void {
		$this->options->patch_sync_state( [ 'language_mismatch' => $mismatch ] );
		foreach ( $mismatch as $chain_id => $m ) {
			$this->options->record(
				ErrorCode::LANGUAGE_MISMATCH,
				sprintf( 'chain %s is assigned to %s but AIVIS reports %d of its pages as %s', $chain_id, $m['assigned'], (int) $m['count'], $m['aivis'] ),
				(string) $m['url']
			);
		}
	}

	private function flush_purges(): void {
		$urls = array_values( array_unique( $this->purge ) );
		$this->purge = [];
		if ( ! $urls ) {
			return;
		}
		$result = $this->cache->adapter()->purge_urls( $urls );
		$this->options->patch_sync_state( [ 'last_purge' => [ 'state' => $result->state, 'count' => count( $urls ), 'at' => time() ] ] );
		foreach ( $urls as $u ) {
			$pid = $this->post_id_for( $u );
			if ( $pid ) {
				clean_post_cache( $pid );
			}
		}
		do_action( 'aivis_connector_purge_urls', $urls, [ 'state' => $result->state ] );
	}

	private function post_id_for( string $url ): ?int {
		$id = (int) url_to_postid( $url );
		return $id > 0 ? $id : null;
	}

	private function note_failure( Response $r, string $ctx ): void {
		$code = match ( $r->kind() ) {
			'auth'          => ErrorCode::AUTH_401,
			'account'       => ErrorCode::ACCOUNT_403,
			'transport'     => '' !== $r->transport_error ? ErrorCode::HTTP_TIMEOUT : ErrorCode::HTTP_ERROR,
			'not_generated' => ErrorCode::NOT_GENERATED,
			'url_gone'      => ErrorCode::RETRACTED,
			default         => ErrorCode::HTTP_ERROR,
		};
		if ( 'ok' !== $r->kind() ) {
			$this->options->record( $code, $r->message() ?: $r->transport_error ?: ( 'HTTP ' . $r->status ), $ctx );
		}
	}

	private function host_matches( string $a, string $b ): bool {
		$strip = static fn( string $h ): string => preg_replace( '/^www\./', '', strtolower( $h ) ) ?? $h;
		return $strip( $a ) === $strip( $b );
	}

	private function over_budget(): bool {
		return ( microtime( true ) - $this->started ) > self::TIME_BUDGET_S;
	}
}
