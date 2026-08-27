<?php

namespace Hestiacp\Adapter;

/**
 * Command Registry — the single source of truth mapping a resource-oriented
 * operation name to an existing bin/v-* script invocation, per
 * ARCHITECTURE_ADAPTER_DESIGN.md section 2.
 *
 * Deliberately minimal for this vertical slice: two read-only operations
 * ("domain.get", "domain.list") and three mutating operations
 * ("domain.create", "domain.delete", "backup.schedule"), each
 * hand-verified against the actual bin/v-list-web-domain /
 * bin/v-list-web-domains / bin/v-add-web-domain / bin/v-delete-web-domain /
 * bin/v-schedule-user-backup source (not guessed, and not derived from
 * any script's header comment — ARCHITECTURE_ADAPTER_DESIGN.md section 2
 * explicitly distrusts header comments after finding two that were
 * stale/mismatched with actual script behavior). See
 * DOMAIN_CREATE_IMPLEMENTATION.md, DOMAIN_DELETE_IMPLEMENTATION.md, and
 * BACKUP_SCHEDULE_IMPLEMENTATION.md "Command Contract"/"Source Contract"
 * sections for the full source traces behind each mutating operation.
 * "backup.schedule" is deliberately NOT named "backup.create" — see
 * BACKUP_CREATE_DESIGN.md and BACKUP_SCHEDULE_IMPLEMENTATION.md
 * "Mutation Semantics": its underlying script only ever queues a backup
 * job for a later, cron-driven, fully detached run of a different
 * script (bin/v-backup-user) — it never creates a backup archive
 * itself, so its mutation_state=confirmed means "queued," not
 * "backup exists."
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
 *
 * "mutation.known_post_mutation_exit_codes" was added in the
 * MUTATION_AND_AUTHORIZATION_DESIGN.md pass — an optional list of
 * symbolic Hestia error names (e.g. "E_RESTART") that a registry author
 * has positively verified, from the underlying script's own source, can
 * ONLY occur after that operation's core mutation is durably complete.
 * Drives CommandAdapter's mutation_state = "confirmed_degraded"
 * classification generically, with no per-operation code in
 * CommandAdapter itself. Validated against
 * CommandAdapter::HESTIA_EXIT_CODES at construction time (see
 * validateMutationMetadata() below) so a typo'd symbolic name fails
 * loudly rather than silently never matching.
 *
 * A per-parameter "sensitive" (bool) and "delivery" (string) pair were
 * added in the sensitive-parameter pass — see
 * SENSITIVE_PARAMETER_DESIGN.md for the full rationale, security
 * analysis, and execution lifecycle. In short: "sensitive" => true
 * excludes that parameter's value from AdapterResult::$target and from
 * the array passed to AuthorizerInterface::authorize(), while the value
 * remains available for argv construction; "delivery" => "temp_file"
 * (currently the only entry in CommandAdapter::SUPPORTED_DELIVERY_MODES)
 * routes that parameter's value through a securely created, restrictively
 * permissioned temp file instead of placing it literally in argv, passing
 * the file's path as the argv entry instead. Both fields are purely
 * generic at the CommandRegistry/CommandAdapter level — no operation,
 * script, or parameter name is referenced by either mechanism's
 * implementation. Enforced invariant (validateParameterMetadata() below):
 * "sensitive" => true requires a "delivery" value from the supported-modes
 * table; "delivery" without "sensitive" is allowed; an unrecognized
 * "delivery" value fails construction.
 */
final class CommandRegistry {
	/** @var array<string, array<string, mixed>> */
	private array $operations;

	/**
	 * @param array<string, array<string, mixed>> $additionalOperations
	 *        Test-only extension point: lets tests register a synthetic
	 *        operation (e.g. one with a made-up mutation kind) without this
	 *        class needing a matching built-in entry for it. No production
	 *        caller passes this argument; `new CommandRegistry()` with no
	 *        arguments is unaffected. Entries here take precedence if a key
	 *        collides with a built-in operation name (tests only; not a
	 *        supported override mechanism for production use).
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
				//
				// known_post_mutation_exit_codes: E_RESTART (20) is the
				// ONLY exit code bin/v-add-web-domain can produce AFTER its
				// core mutation (the $USER_DATA/web.conf append, line 245)
				// is already durably complete — confirmed by source read:
				// $BIN/v-restart-web/v-restart-proxy (lines 250-254) run
				// strictly after that append, and every OTHER non-zero exit
				// this script can produce (E_ARGS, E_INVALID, E_NOTEXIST,
				// E_EXISTS, E_SUSPENDED, E_LIMIT, E_DISABLED) fires during
				// its earlier "Verifications" section. See
				// DOMAIN_CREATE_IMPLEMENTATION.md "Service Reload / Failure
				// Semantics" and MUTATION_AND_AUTHORIZATION_DESIGN.md Part
				// 1/3 for the full reasoning behind why ONLY this specific,
				// source-verified code is declared here — never a broader
				// or guessed set.
				"mutation" => ["kind" => "create", "known_post_mutation_exit_codes" => ["E_RESTART"]],
			],
			"domain.delete" => [
				// bin/v-delete-web-domain, positional contract confirmed by
				// direct source read: user=$1; domain=$2; restart=$3
				// (bin/v-delete-web-domain lines 17-20) — only 3 positional
				// slots (far simpler than v-add-web-domain's 6), only the
				// first two required (check_args '2' "$#" ..., line 43). See
				// DOMAIN_DELETE_IMPLEMENTATION.md "Command Contract" for the
				// full trace.
				"script" => "v-delete-web-domain",
				"argument_order" => ["user", "domain", "restart"],
				// Same minimal public parameter model as "domain.create":
				// only the two values an operation to "delete this domain
				// for this user" cannot function without are caller-supplied.
				// Both types (username, domain) already exist and are
				// reused as-is — no new validator was needed.
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
				// restart="yes": fixed so the deletion's effects (removed
				// vhost/proxy config) are applied immediately — the same
				// choice already made for "domain.create", for the same
				// reason (matches the one real production UI caller's own
				// hardcoded 'yes' for the sibling create operation; no
				// production caller for delete currently passes a
				// caller-chosen restart value either).
				"fixed_parameters" => [
					"restart" => "yes",
				],
				// bin/v-delete-web-domain has no JSON output mode, no
				// "format" argument, and no structured output at all —
				// confirmed by reading the full script. Same as
				// "domain.create": no "output_format"/"result_shape" key is
				// declared, so parsed_output stays null, correctly.
				//
				// known_post_mutation_exit_codes: E_RESTART (20) is, by the
				// same source-verified reasoning as "domain.create" above,
				// the ONLY exit code bin/v-delete-web-domain can produce
				// after its core mutation (the domain's directories and
				// $USER_DATA/web.conf line, both already removed by line
				// 116) is complete — its three restart calls (lines
				// 148-156) run strictly after. Every other reachable exit
				// code (E_ARGS, E_INVALID, E_NOTEXIST, E_DISABLED) fires
				// during "Verifications." See
				// DOMAIN_DELETE_IMPLEMENTATION.md "Mutation Semantics" and
				// MUTATION_AND_AUTHORIZATION_DESIGN.md Part 1/3.
				"mutation" => ["kind" => "delete", "known_post_mutation_exit_codes" => ["E_RESTART"]],
			],
			"backup.schedule" => [
				// bin/v-schedule-user-backup, positional contract confirmed by
				// direct source read: user=$1 (bin/v-schedule-user-backup
				// line 14) — a single positional slot, required
				// (check_args '1' "$#" 'USER', line 28). Named "schedule", not
				// "create": this script's entire mutation is appending one
				// line to $HESTIA/data/queue/backup.pipe (line 44); it never
				// creates a backup archive itself. The archive is produced,
				// up to five minutes later, by a cron-driven, fully detached
				// run of bin/v-backup-user against that queue — a separate
				// script this operation never invokes and this adapter does
				// not represent. See BACKUP_SCHEDULE_IMPLEMENTATION.md and
				// BACKUP_CREATE_DESIGN.md Part 0/Part 2 for the full source
				// trace behind this naming and mapping choice.
				"script" => "v-schedule-user-backup",
				"argument_order" => ["user"],
				"parameters" => [
					"user" => [
						"type" => "username",
						"required" => true,
					],
				],
				// No fixed parameters: the script takes no argument besides
				// USER (confirmed by source read — there is no NOTIFY, no
				// FORMAT, nothing else on its positional contract).
				"fixed_parameters" => [],
				// bin/v-schedule-user-backup has no JSON output mode, no
				// "format" argument, and no structured output at all —
				// confirmed by reading the full script. Same as
				// "domain.create"/"domain.delete": no "output_format"/
				// "result_shape" key is declared, so parsed_output stays
				// null, correctly.
				//
				// No known_post_mutation_exit_codes: every non-zero exit
				// this script can produce (E_ARGS, E_NOTEXIST, E_DISABLED,
				// E_EXISTS via is_backup_scheduled) fires during its
				// "Verifications" section (lines 28-34), strictly before the
				// one mutating line (line 44). There is no exit code that
				// occurs after that line to declare — confirmed by source
				// read, not assumed from the sibling domain operations'
				// shape. See BACKUP_CREATE_DESIGN.md Part 5.
				"mutation" => ["kind" => "create"],
			],
			"database.create" => [
				// bin/v-add-database, positional contract confirmed by direct
				// source read: "# options: USER DATABASE DBUSER DBPASS [TYPE]
				// [HOST] [CHARSET]" and user=$1; database="$user"_"$2";
				// dbuser="$user"_"$3"; password=$4; type=${5-mysql}; host=$6;
				// charset=${7-UTF8MB4} (bin/v-add-database lines 20-27) —
				// seven positional slots, only the first four required
				// (check_args '4' "$#" ..., line 49). See
				// DATABASE_CREATE_IMPLEMENTATION.md "Source Contract" for the
				// full trace.
				//
				// "database"/"dbuser" here are the RAW, un-prefixed suffix a
				// caller supplies (e.g. "wordpress_db") — the script itself
				// concatenates "$user"_ onto both internally
				// (bin/v-add-database:21-22), exactly as this registry's own
				// argument_order below sends them. This registry never sends
				// the already-prefixed form.
				"script" => "v-add-database",
				"argument_order" => ["user", "database", "dbuser", "password", "type", "host", "charset"],
				// Minimal public parameter model, deliberately NOT a 1:1
				// mirror of every CLI slot — same posture as domain.create's
				// own parameter model (DOMAIN_CREATE_IMPLEMENTATION.md
				// "Parameter Model"), even though the one real production UI
				// caller (web/add/db/index.php) DOES let a human choose
				// type/host/charset. Only the four values an operation to
				// "create this database for this user" cannot function
				// without are caller-supplied here; type/host/charset are
				// fixed below to the script's own built-in defaults for an
				// omitted argument (see DATABASE_CREATE_IMPLEMENTATION.md
				// "Registry Entry" for the full reasoning) — a deliberately
				// narrower vertical slice than the existing UI supports,
				// documented as a follow-up, not an oversight.
				"parameters" => [
					"user" => [
						"type" => "username",
						"required" => true,
					],
					"database" => [
						"type" => "database_name",
						"required" => true,
					],
					"dbuser" => [
						"type" => "db_username",
						"required" => true,
					],
					// "sensitive"=>true + "delivery"=>"temp_file": confirmed
					// by source (func/main.sh:625-633, is_password_valid())
					// that bin/v-add-database dereferences a value matching
					// "^/tmp/" back into the real password before use — the
					// SAME generic mechanism backup.schedule's sibling design
					// review (DATABASE_CREATE_DESIGN.md) identified this need
					// for, and SENSITIVE_PARAMETER_DESIGN.md/
					// SENSITIVE_PARAMETER_REVIEW.md already hostile-reviewed
					// and remediated generically, with zero
					// database.create-specific code in CommandAdapter itself.
					"password" => [
						"type" => "secret",
						"required" => true,
						"sensitive" => true,
						"delivery" => "temp_file",
					],
				],
				// type="mysql": the script's own default when this
				// positional argument is omitted (bin/v-add-database:25,
				// `type=${5-mysql}`) — not a caller choice in this slice.
				// PostgreSQL support (type="pgsql") is a real, source-verified
				// second path in the same script (add_pgsql_database(),
				// func/db.sh:374-397) but was deliberately excluded from this
				// vertical slice: it introduces a different exclude-character
				// check (bin/v-add-database:64-72), a lowercase-casing step
				// applied to database/dbuser (bin/v-add-database:40-43), and
				// its own, source-verified-weaker mutation-checking behavior
				// (see DATABASE_CREATE_IMPLEMENTATION.md "Mutation-State
				// Semantics" — add_pgsql_database() checks NO query result at
				// all, unlike add_mysql_database()'s one checked
				// CREATE DATABASE call). Adding it is a mechanical, source-
				// grounded follow-up, not a redesign, when a concrete need
				// exists — same posture as every other "NOT implemented in
				// this slice" note elsewhere in this file.
				// host="": not caller-controlled in this slice. Empty is not
				// "no value was chosen" — it is the specific value that makes
				// bin/v-add-database's own get_next_dbhost() (func/db.sh:218-247)
				// take its auto-selection branch (`[ -z "$host" ]`), exactly
				// as if a human operator ran `v-add-database user db dbuser
				// pass` with no HOST argument at all.
				// charset="UTF8MB4": the script's own default when this
				// positional argument is omitted (bin/v-add-database:27,
				// `charset=${7-UTF8MB4}`) — matches the script's built-in
				// choice, not an invented one. (bin/v-add-database:60 also
				// shows its own charset-validity check, is_charset_valid, is
				// commented out entirely — charset is not currently validated
				// by the script at all, confirmed by source read.)
				"fixed_parameters" => [
					"type" => "mysql",
					"host" => "",
					"charset" => "UTF8MB4",
				],
				// bin/v-add-database has no JSON output mode, no "format"
				// argument, and no structured output at all — confirmed by
				// reading the full script. Same as domain.create/domain.delete/
				// backup.schedule: no "output_format"/"result_shape" key is
				// declared, so parsed_output stays null, correctly.
				//
				// No known_post_mutation_exit_codes: for the mysql path this
				// registry entry actually exercises (type is fixed to
				// "mysql" above), the ONLY mutating SQL statement whose
				// result is checked is "CREATE DATABASE" itself
				// (func/db.sh:312-314, `mysql_query "$query"; check_result $?
				// ...`) — every subsequent statement in add_mysql_database()
				// (CREATE USER x2, GRANT x2, the md5-retrieval SHOW query) has
				// its output redirected to /dev/null with NO check_result
				// call at all (func/db.sh:316-370), and neither does anything
				// after add_mysql_database() returns (db.conf append,
				// increase_dbhost_values, increase_user_value, v-log-action —
				// none of these can fail the script). Once "CREATE DATABASE"
				// succeeds, bin/v-add-database cannot produce a non-zero exit
				// at all — there is no post-mutation exit code to declare,
				// confirmed by source read, not assumed from the sibling
				// domain operations' shape (which DO have one, E_RESTART).
				// Same "no known_post_mutation_exit_codes" posture as
				// backup.schedule, for the same reason: no code exists after
				// this operation's one checked mutation point.
				"mutation" => ["kind" => "create"],
			],
			"database.delete" => [
				// bin/v-delete-database, positional contract confirmed by
				// direct source read: "# options: USER DATABASE" and
				// user=$1; database=$2 (bin/v-delete-database lines 15-16)
				// — only TWO positional slots, both required
				// (check_args '2' "$#" 'USER DATABASE', line 32). No
				// "dbuser" argument exists at all: the script recovers
				// DBUSER (and TYPE/HOST) internally via
				// get_database_values() -> parse_object_kv_list(), which
				// greps "DB='$database'" out of $USER_DATA/db.conf
				// (func/db.sh:442-444) — the adapter never supplies or
				// even validates a dbuser for this operation. See
				// DATABASE_DELETE_IMPLEMENTATION.md "Source Contract".
				//
				// CRITICAL ASYMMETRY WITH database.create, confirmed by
				// source read, not assumed: bin/v-add-database prefixes
				// the caller's raw suffix internally
				// (database="$user"_"$2"), so database.create's
				// "database" parameter is the RAW suffix. bin/v-delete-
				// database performs NO such prefixing (database=$2,
				// verbatim) — its Verifications section calls
				// is_object_valid('db', 'DB', "$database")
				// (func/main.sh:377-397), which greps
				// "DB='$database'" directly against db.conf, and db.conf's
				// DB field was written by v-add-database using the
				// ALREADY-PREFIXED value. Therefore database.delete's
				// "database" parameter must be the FULL, PREFIXED name
				// (e.g. "admin_wordpress_db"), not the raw suffix
				// database.create accepts for the same-looking parameter
				// name. This is a real, source-verified API inconsistency
				// between the two operations, not an adapter defect — it
				// is documented here and in
				// DATABASE_DELETE_IMPLEMENTATION.md rather than papered
				// over with any adapter-side renaming/translation, which
				// would be operation-specific logic this task prohibits.
				"script" => "v-delete-database",
				"argument_order" => ["user", "database"],
				// Both types already exist and are reused as-is — no new
				// ParameterValidator method was needed, unlike
				// database.create (which needed three). "database_name"'s
				// shape check (exclude-char class + length) is the SAME
				// regex Hestia's own is_database_format_valid() applies,
				// and here — unlike database.create, where the caller-
				// facing value is a raw suffix shorter than what the
				// script itself checks — the caller-facing value IS the
				// exact string the script validates, so this reuse is
				// not an approximation, it is an exact match.
				"parameters" => [
					"user" => [
						"type" => "username",
						"required" => true,
					],
					"database" => [
						"type" => "database_name",
						"required" => true,
					],
				],
				// No fixed_parameters: the script takes no argument
				// besides USER/DATABASE (confirmed by source read — there
				// is no TYPE/HOST/CHARSET/dbuser slot to default).
				"fixed_parameters" => [],
				// bin/v-delete-database has no JSON output mode, no
				// "format" argument, and no structured output at all —
				// confirmed by reading the full script. No
				// "output_format"/"result_shape" key is declared, so
				// parsed_output stays null, correctly.
				//
				// No known_post_mutation_exit_codes: confirmed by source
				// read. delete_mysql_database() (func/db.sh:529-548) and
				// delete_pgsql_database() (func/db.sh:551-566) contain
				// ZERO check_result calls anywhere — not even on their own
				// "DROP DATABASE"/"DROP ROLE" statements, which unlike
				// database.create's checked "CREATE DATABASE" are never
				// gated at all. Every reachable non-zero exit this script
				// can produce (E_ARGS from check_args, E_INVALID from
				// is_format_valid/is_database_format_valid,
				// E_DISABLED, E_NOTEXIST for a nonexistent user/database,
				// E_ARGS from check_hestia_demo_mode's literal `exit 1` —
				// all in "Verifications"; plus E_PARSING/E_CONNECT from
				// mysql_connect(), func/db.sh:38-92, called at the very
				// start of delete_mysql_database() itself, func/db.sh:530)
				// fires strictly BEFORE the first mutating statement
				// ("DROP DATABASE", func/db.sh:532) — there is no
				// post-mutation exit code to declare.
				//
				// IMPORTANT CAVEAT, not represented by any exit-code
				// metadata because the existing model has no way to
				// represent it without inventing a new state (prohibited
				// by this task): since "DROP DATABASE" itself is
				// UNCHECKED, a real failure there (the query itself
				// erroring after a successful mysql_connect) does NOT
				// stop the script — it proceeds to REVOKE, conditionally
				// DROP USER, remove the db.conf line, decrement counters,
				// log success, and exit 0. mutation_state="confirmed" on
				// exit 0 is therefore only as reliable as "the script
				// believed it succeeded," not proof the DROP actually
				// took effect — see DATABASE_DELETE_IMPLEMENTATION.md
				// "Mutation Semantics" and "Architectural Issues
				// Discovered" for the full accounting. This is a
				// documented gap in what "confirmed" is worth for this
				// specific script, not an adapter defect, and not
				// something this task's registry-metadata mechanism can
				// close (there is no non-zero exit code to declare for
				// it — the script exits 0 in this scenario).
				"mutation" => ["kind" => "delete"],
			],
		];

		foreach ($additionalOperations as $name => $entry) {
			$this->operations[$name] = $entry;
		}

		self::validateMutationMetadata($this->operations);
		self::validateParameterMetadata($this->operations);
	}

	/**
	 * Fails fast, loudly, at construction time, rather than letting a
	 * typo'd symbolic Hestia error name (e.g. "E_RESTAR") silently
	 * compile into a registry entry that can never actually reach
	 * CommandAdapter's confirmed_degraded classification — see
	 * MUTATION_AND_AUTHORIZATION_DESIGN.md Part 3.
	 *
	 * Validates against CommandAdapter::HESTIA_EXIT_CODES — the SAME,
	 * single, authoritative int-to-symbolic-name table CommandAdapter
	 * already uses to populate AdapterResult::$hestiaErrorCode. No second
	 * exit-code vocabulary is introduced by this check.
	 *
	 * @param array<string, array<string, mixed>> $operations
	 */
	private static function validateMutationMetadata(array $operations): void {
		$validNames = array_flip(CommandAdapter::HESTIA_EXIT_CODES);

		foreach ($operations as $operationName => $entry) {
			$declared = $entry["mutation"]["known_post_mutation_exit_codes"] ?? [];
			if (!is_array($declared)) {
				throw new \InvalidArgumentException(sprintf(
					"Registry entry '%s' declares mutation.known_post_mutation_exit_codes as %s, but it must be an array of symbolic Hestia error code strings.",
					$operationName,
					gettype($declared)
				));
			}
			foreach ($declared as $code) {
				if (!isset($validNames[$code])) {
					throw new \InvalidArgumentException(sprintf(
						"Registry entry '%s' declares an unknown symbolic Hestia error code '%s' in " .
							"mutation.known_post_mutation_exit_codes. Valid codes are: %s",
						$operationName,
						is_string($code) ? $code : var_export($code, true),
						implode(", ", array_keys($validNames))
					));
				}
			}
		}
	}

	/**
	 * Fails fast, loudly, at construction time — same posture as
	 * validateMutationMetadata() above — rather than letting a registry
	 * entry silently place a secret value directly into argv.
	 * SENSITIVE_PARAMETER_DESIGN.md "registry validation" documents the
	 * invariant enforced here in full; summarized:
	 *
	 *   1. "sensitive", if declared, must be an actual PHP boolean (true
	 *      or false) — or entirely absent, or explicitly null, both of
	 *      which are treated identically to "not declared" (matching the
	 *      pre-existing `?? false` semantics this and every other
	 *      optional registry field already use elsewhere in this class).
	 *      Any other value — 1, "true", "1", 0, an array, anything not a
	 *      strict PHP bool — fails construction. This was added after a
	 *      source-first review (SENSITIVE_PARAMETER_REVIEW.md finding
	 *      F-1) found that CommandAdapter's enforcement compared this
	 *      field with loose truthiness while this method compared it
	 *      with strict equality, so a value like `1` could slip past
	 *      this check (satisfying neither "declared sensitive" per this
	 *      method) while CommandAdapter still excluded it from $target
	 *      (since `1` is truthy) — silently defeating the very
	 *      protection "sensitive" exists to guarantee, because a
	 *      malformed value read as "excluded from target" gave every
	 *      appearance of correctness while argv was left unprotected. A
	 *      security-sensitive metadata field must never be interpreted
	 *      via loose truthiness; requiring an actual boolean here closes
	 *      that gap at its source, and CommandAdapter's own enforcement
	 *      (see invoke()) was changed to the same strict comparison, so
	 *      the two can no longer disagree.
	 *   2. "delivery", if declared, must be one of
	 *      CommandAdapter::SUPPORTED_DELIVERY_MODES (currently just
	 *      "temp_file") — the same "validate against the one
	 *      authoritative table CommandAdapter itself uses" pattern
	 *      validateMutationMetadata() already established for exit codes.
	 *   3. "sensitive" === true REQUIRES a "delivery" value from that same
	 *      table. Today that means "sensitive" === true effectively
	 *      requires "delivery" => "temp_file", because temp_file is the
	 *      only supported delivery mode — but the check itself does not
	 *      hardcode that mode's name; it only asks "is a supported
	 *      delivery mode declared at all," so a second safe delivery
	 *      mode, if one is ever added to SUPPORTED_DELIVERY_MODES, is
	 *      accepted without this method changing.
	 *   4. The reverse is NOT required: "delivery" => "temp_file" without
	 *      "sensitive" === true is allowed. The two flags answer different
	 *      questions — "sensitive" controls $target/authorizer exposure,
	 *      "delivery" controls how the value reaches argv — and nothing
	 *      about routing a value through a temp file requires also
	 *      hiding it from $target. No known caller needs this
	 *      combination today; it is left unforbidden rather than
	 *      speculatively restricted.
	 *
	 * @param array<string, array<string, mixed>> $operations
	 */
	private static function validateParameterMetadata(array $operations): void {
		$supportedDeliveryModes = array_flip(CommandAdapter::SUPPORTED_DELIVERY_MODES);

		foreach ($operations as $operationName => $entry) {
			$parameters = $entry["parameters"] ?? [];
			foreach ($parameters as $parameterName => $definition) {
				$rawSensitive = $definition["sensitive"] ?? null;
				if ($rawSensitive !== null && !is_bool($rawSensitive)) {
					throw new \InvalidArgumentException(sprintf(
						"Registry entry '%s' parameter '%s' declares 'sensitive' as %s, but it must be " .
							"an actual boolean (true or false), or omitted/null (treated as false). " .
							"Loose-truthy values (e.g. 1, \"true\", \"1\") are rejected because this is a " .
							"security-sensitive field: silently accepting a value CommandAdapter would " .
							"treat as truthy while this method's own invariant check does not could leave " .
							"a parameter excluded from target but NOT protected in argv.",
						$operationName,
						$parameterName,
						var_export($rawSensitive, true)
					));
				}

				$delivery = $definition["delivery"] ?? null;
				$sensitive = $rawSensitive === true;

				// F-4 (SENSITIVE_PARAMETER_REVIEW.md, LOW): a non-string,
				// non-null "delivery" (e.g. an array) used directly as an
				// array key below would raise an uncaught TypeError
				// ("Illegal offset type") instead of this method's own,
				// clearer InvalidArgumentException. Trivial and adjacent
				// to the "sensitive" type guard above, so fixed here
				// rather than left for a separate task.
				if ($delivery !== null && !is_string($delivery)) {
					throw new \InvalidArgumentException(sprintf(
						"Registry entry '%s' parameter '%s' declares 'delivery' as %s, but it must be a string " .
							"naming one of the supported delivery modes, or omitted/null.",
						$operationName,
						$parameterName,
						var_export($delivery, true)
					));
				}

				if ($delivery !== null && !isset($supportedDeliveryModes[$delivery])) {
					throw new \InvalidArgumentException(sprintf(
						"Registry entry '%s' parameter '%s' declares unknown delivery mode '%s'. Supported delivery modes are: %s",
						$operationName,
						$parameterName,
						is_string($delivery) ? $delivery : var_export($delivery, true),
						implode(", ", CommandAdapter::SUPPORTED_DELIVERY_MODES)
					));
				}

				if ($sensitive === true && $delivery === null) {
					throw new \InvalidArgumentException(sprintf(
						"Registry entry '%s' parameter '%s' is declared sensitive but declares no safe " .
							"delivery mode. A sensitive parameter's plaintext value must never be placed " .
							"directly into argv; declare \"delivery\" => \"%s\" or remove \"sensitive\" => true.",
						$operationName,
						$parameterName,
						CommandAdapter::SUPPORTED_DELIVERY_MODES[0] ?? "temp_file"
					));
				}
			}
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
