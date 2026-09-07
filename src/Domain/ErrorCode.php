<?php
/**
 * Stable error codes (§08). These are contract for the status screen, the
 * diagnostics buffer and the runbook; messages may change, codes may not.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Domain;

final class ErrorCode {
	public const AUTH_401          = 'AIVIS_AUTH_401';
	public const ACCOUNT_403       = 'AIVIS_ACCOUNT_403';
	public const HTTP_TIMEOUT      = 'AIVIS_HTTP_TIMEOUT';
	public const HTTP_ERROR        = 'AIVIS_HTTP_ERROR';
	public const SCHEMA_INVALID    = 'AIVIS_SCHEMA_INVALID';
	public const SCOPE_MISMATCH    = 'AIVIS_SCOPE_MISMATCH';
	public const RETRACTED         = 'AIVIS_RETRACTED';
	public const NOT_GENERATED     = 'AIVIS_NOT_GENERATED';
	public const DB_WRITE          = 'AIVIS_DB_WRITE';
	public const PURGE_UNSUPPORTED = 'AIVIS_PURGE_UNSUPPORTED';
	public const PURGE_FAILED      = 'AIVIS_PURGE_FAILED';
	public const LOCKED            = 'AIVIS_LOCKED';
	/** §07a: a site language has no chain, or a chain is not assigned to any language. */
	public const LANGUAGE_UNASSIGNED = 'AIVIS_LANGUAGE_UNASSIGNED';
	/** §07a: AIVIS's languageCode for a chain's pages disagrees with the assignment. */
	public const LANGUAGE_MISMATCH   = 'AIVIS_LANGUAGE_MISMATCH';
	/** §11: a page's address changed since AIVIS crawled it; reported, never acted on. */
	public const URL_MOVED           = 'AIVIS_URL_MOVED';
}
