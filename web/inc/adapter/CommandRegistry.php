<?php

namespace Hestiacp\Adapter;

/**
 * Command Registry — the single source of truth mapping a resource-oriented
 * operation name to an existing bin/v-* script invocation, per
 * ARCHITECTURE_ADAPTER_DESIGN.md section 2.
 *
 * Deliberately minimal for this vertical slice: two read-only operations
 * ("domain.get", "domain.list") and one mutating operation
 * ("domain.create"), each hand-verified against the actual
 * bin/v-list-web-domain / bin/v-list-web-domains / bin/v-add-web-domain
 * source (not guessed, and not derived from any script's header comment —
 * ARCHITECTURE_ADAPTER_DESIGN.md section 2 explicitly distrusts header
 * comments after finding two that were stale/mismatched with actual
 * script behavior). See DOMAIN_CREATE_IMPLEMENTATION.md "Command Contract"
 * for the full bin/v-add-web-domain source trace behind "domain.create".
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
			"domain.create" => [
				// bin/v-add-web-domain, positional contract confirmed by direct
				// source read: user=$1; domain=$2; ip=$3; restart=$4; aliases=$5;
				// proxy_ext=$6 (bin/v-add-web-domain lines 19-25) — six
				// positional slots, only the first two required
				// (check_args '2' "$#" ..., line 52).
				"script" => "v-add-web-domain",
				"argument_order" => ["user", "domain", "ip", "restart", "aliases", "proxy_ext"],
				// Minimal public parameter model, deliberately NOT a 1:1
				// mirror of every CLI slot — see
				// DOMAIN_CREATE_IMPLEMENTATION.md "Parameter Model" for the
				// full reasoning. Only the two parameters an operation to
				// "create this domain for this user" cannot function
				// without are caller-supplied; both already have validators
				// (ParameterValidator::isValidUsername/isValidDomain,
				// unchanged, reused as-is — the same two types domain.get
				// and domain.list already use).
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
				// ip="": not caller-controlled in this slice. Empty is not
				// "no value was chosen" here — it is the specific value
				// that makes bin/v-add-web-domain take its own
				// already-existing "else get_user_ip" branch (line 81-85),
				// i.e. the script picks the same IP it would pick for any
				// other domain this user owns, exactly as if a human
				// operator ran `v-add-web-domain user domain` with no IP
				// argument at all.
				// restart="yes": fixed so the new vhost config is applied
				// immediately, matching the one existing production
				// caller's own hardcoded choice
				// (web/add/web/index.php:81, "... . ' \'yes\''").
				// aliases="": bin/v-add-web-domain's own default
				// www-alias behavior applies (lines 156-181) — not "none",
				// which would suppress the alias entirely; "" is what the
				// existing UI caller leaves this argument as (it never
				// supplies a 5th positional value at all).
				// proxy_ext="": bin/v-add-web-domain's own default
				// extension list applies (lines 195-224) — again matching
				// the existing UI caller, which never supplies a 6th
				// positional value.
				"fixed_parameters" => [
					"ip" => "",
					"restart" => "yes",
					"aliases" => "",
					"proxy_ext" => "",
				],
				// bin/v-add-web-domain has no JSON output mode at all (no
				// "format" argument, no case-on-format branch anywhere in
				// its source) — confirmed by reading the full script, not
				// assumed from domain.get/domain.list's unrelated "format"
				// argument. No "output_format" key is declared here, so
				// CommandAdapter's existing, unmodified output-parsing step
				// (`if (($entry["output_format"] ?? null) === "json" ...)`)
				// leaves parsed_output null for every domain.create result,
				// exactly as designed for a script that was never asked to
				// produce structured output in the first place — this is
				// not a gap, it is the correct behavior for this script.
				// No "result_shape" key either, for the same reason: that
				// field only has meaning for a JSON-producing operation.
				"mutation" => ["kind" => "create"],
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
