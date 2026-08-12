<?php

namespace Hestiacp\Adapter;

/**
 * Command Registry — the single source of truth mapping a resource-oriented
 * operation name to an existing bin/v-* script invocation, per
 * ARCHITECTURE_ADAPTER_DESIGN.md section 2.
 *
 * Deliberately minimal for this vertical slice: two read-only operations
 * ("domain.get", "domain.list"), each hand-verified against the actual
 * bin/v-list-web-domain / bin/v-list-web-domains source (not guessed, and
 * not derived from either script's header comment —
 * ARCHITECTURE_ADAPTER_DESIGN.md section 2 explicitly distrusts header
 * comments after finding two that were stale/mismatched with actual
 * script behavior).
 *
 * Representation: a plain PHP associative array (trivially
 * json_encode()-able later) rather than an external JSON/YAML file. The
 * full design recommends a language-neutral file format so a future Go
 * component can share the registry without re-deriving it; this slice
 * has exactly one consumer (this PHP adapter) and no second consumer yet,
 * so introducing a file-loading mechanism now would be building ahead of
 * demonstrated need (see ARCHITECTURE_ADAPTER_DESIGN.md section 12,
 * "what not to build"). Extracting this array into a JSON file is a
 * mechanical follow-up, not a redesign, whenever a second consumer
 * actually exists.
 *
 * Fields intentionally NOT present on entries in this slice, versus the
 * full design (section 2): lock_scope, timeout_seconds, role_required,
 * idempotent. Timeouts remain explicitly out of scope (see
 * ADAPTER_VERTICAL_SLICE.md "known limitations"); adding those fields
 * back is a mechanical registry-schema change, not an adapter redesign.
 *
 * "mutation" WAS added in the locking pass (WRITE_OPERATION_DESIGN.md
 * Part 1), but deliberately only as {"kind": "read"|...} — the fuller
 * 4-field proposal in that document (kind, config_write, service_reload,
 * destructive) is NOT implemented here because nothing in this codebase
 * consumes the other three fields yet; CommandAdapter's locking decision
 * only ever needs to ask "is kind === 'read'?". Adding them back is the
 * same kind of mechanical follow-up as the fields above, if a concrete
 * consumer appears.
 */
final class CommandRegistry {
	/** @var array<string, array<string, mixed>> */
	private array $operations;

	/**
	 * @param array<string, array<string, mixed>> $additionalOperations
	 *        Test-only extension point: lets tests register a synthetic
	 *        operation (e.g. a fake mutating one) without this class ever
	 *        defining a real write operation itself — domain.create is
	 *        explicitly not implemented yet. No production caller passes
	 *        this argument; `new CommandRegistry()` with no arguments is
	 *        unaffected. Entries here take precedence if a key collides
	 *        with a built-in operation name (tests only; not a supported
	 *        override mechanism for production use).
	 */
	public function __construct(array $additionalOperations = []) {
		$this->operations = [
			"domain.get" => [
				// bin/v-list-web-domain, positional contract confirmed by direct
				// source read: "# options: USER DOMAIN [FORMAT]" and
				// user=$1; domain=$2; format=${3-shell} (bin/v-list-web-domain lines 12-14).
				"script" => "v-list-web-domain",
				"argument_order" => ["user", "domain", "format"],
				"parameters" => [
					"user" => [
						"type" => "username",
						"required" => true,
					],
					"domain" => [
						"type" => "domain",
						"required" => true,
					],
				],
				// Not caller-supplied: the adapter always requests JSON, the
				// same way today's PHP call sites append the literal "json"
				// argument (e.g. web/inc/main.php:246). Fixed here instead
				// of left to each caller to avoid the "forgot to pass json"
				// failure mode named in ARCHITECTURE_ADAPTER_DESIGN.md section 2.
				"fixed_parameters" => [
					"format" => "json",
				],
				"output_format" => "json",
				// One JSON object keyed by the single requested domain
				// (bin/v-list-web-domain's json_list(), one echo'd object).
				// See AdapterResult::$resultShape for what this is used for.
				"result_shape" => "single",
				// Read-only: no lock is acquired for this operation. See
				// CommandAdapter's mutation-kind check.
				"mutation" => ["kind" => "read"],
			],
			"domain.list" => [
				// bin/v-list-web-domains, positional contract confirmed by direct
				// source read: "# options: USER [FORMAT]" and
				// user=$1; format=${2-shell} (bin/v-list-web-domains lines 12-13).
				// Note this script takes NO domain argument at all — it lists
				// every web domain the given user owns, reading $USER_DATA/web.conf
				// directly (bin/v-list-web-domains json_list(), which loops
				// `cat $USER_DATA/web.conf` line by line). Confirmed by source
				// read, not inferred from the "domain.get" entry above or from
				// the script's naming convention.
				"script" => "v-list-web-domains",
				"argument_order" => ["user", "format"],
				"parameters" => [
					"user" => [
						"type" => "username",
						"required" => true,
					],
				],
				"fixed_parameters" => [
					"format" => "json",
				],
				"output_format" => "json",
				// One JSON object with one key PER domain the user owns
				// (bin/v-list-web-domains json_list() loops $USER_DATA/web.conf
				// and echoes one key per line read, comma-joined into a single
				// top-level object — confirmed by source read: the loop's
				// `if [ "$i" -lt "$objects" ]; then echo ','` is exactly a
				// multi-key single-object join, not a JSON array). Still a
				// JSON *object* at the top level (not an array) — same as
				// domain.get's shape, just with N keys instead of 1. See
				// AdapterResult::$resultShape for what this is used for.
				"result_shape" => "collection",
				// Read-only: no lock is acquired for this operation.
				"mutation" => ["kind" => "read"],
			],
		];

		foreach ($additionalOperations as $name => $entry) {
			$this->operations[$name] = $entry;
		}
	}

	public function has(string $operation): bool {
		return array_key_exists($operation, $this->operations);
	}

	/** @return array<string, mixed>|null */
	public function get(string $operation): ?array {
		return $this->operations[$operation] ?? null;
	}
}
