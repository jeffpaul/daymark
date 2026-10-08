<?php
/**
 * E2E fixture: small upload parts.
 *
 * The browser suite tests that a chunked upload resumes partway through a
 * file (issue #483). With the default 4 MB parts that would need a large
 * fixture, so this fixture sets the smallest part size the server accepts,
 * and a generated file of under 1 MB still splits into several parts.
 *
 * Copied into mu-plugins by the Playwright CI job's Seed step — never part
 * of the distributed plugin.
 */

add_filter(
	'daymark_upload_chunk_bytes',
	static function () {
		return Daymark_Uploads::MIN_CHUNK_BYTES;
	}
);
