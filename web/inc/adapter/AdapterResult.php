<?php

namespace Hestiacp\Adapter;

/**
 * Structured result returned by CommandAdapter::invoke().
 *
 * Field set is the "minimal but compatible" subset of the result model
 * defined in ARCHITECTURE_ADAPTER_DESIGN.md section 7. Per-user locking
 * (WRITE_OPERATION_DESIGN.md, LOCK_PERMISSION_REVIEW.md) is now
 * implemented for mutating operations — see $lockWaitMs and
 * $mutationState. Audit persistence remains intentionally
 * unimplemented (LOCK_IMPLEMENTATION.md "Known Limitations").
 */
final class AdapterResult {
	/** @var string Registry operation name, e.g. "domain.get". */
	public string $operation;

	/** @var string Underlying script that actually ran, e.g. "v-list-web-domain". */
	public string $resolvedCommand;

	/** @var string Unique id for this invocation, for correlation. */
	public string $commandId;

	/** @var string One of: ok, adapter_error, hestia_error, timeout, cancelled. */
	public string $status;

	/** @var int|null Raw process exit code. Null if the process never ran. */
	public ?int $exitCode;

	/** @var string|null Hestia E_* error name (e.g. "E_NOTEXIST"), set only when status === hestia_error. */
	public ?string $hestiaErrorCode;

	/** @var string|null Adapter-native error code, set only when status !== ok|hestia_error. */
	public ?string $adapterErrorCode;

	/** @var string|null Human-readable error message, non-null whenever status !== ok. */
	public ?string $errorMessage;

	/** @var string|null Raw stdout. Null if the process never ran. */
	public ?string $stdout;

	/** @var string|null Raw stderr, captured separately from stdout. Null if the process never ran. */
	public ?string $stderr;

	/** @var mixed Decoded JSON body of stdout, when the operation requested JSON output and it parsed. Null otherwise. */
	public $parsedOutput;

	/** @var string ISO-8601 UTC timestamp. */
	public string $startedAt;

	/** @var string ISO-8601 UTC timestamp. */
	public string $finishedAt;

	/** @var int Wall-clock duration of the whole invoke() call, in milliseconds. */
	public int $durationMs;

	/**
	 * @var int|null Reserved for a future lock-wait-duration metric
	 * (ARCHITECTURE_ADAPTER_DESIGN.md section 6/12). Locking itself is now
	 * implemented (LockManager, WRITE_OPERATION_DESIGN.md Part 2), but
	 * this specific timing metric is not populated by this pass — see
	 * LOCK_IMPLEMENTATION.md "Known Limitations". Always null for now.
	 */
	public ?int $lockWaitMs;

	/** @var array{user: ?string, acting_as: ?string} Who requested the operation. */
	public array $actor;

	/** @var array<string, mixed> Structured description of the resource(s) targeted. */
	public array $target;

	/**
	 * @var string|null Declared by the registry entry ("single" | "collection"),
	 * describing the shape parsedOutput takes when status === ok and
	 * output_format === json. Null when the operation was rejected before
	 * a registry entry was resolved (e.g. UNKNOWN_OPERATION), or when the
	 * registry entry does not declare one.
	 *
	 * Added when implementing domain.list: without this, a caller (or a
	 * future API v2 mapping layer) had no operation-agnostic way to know
	 * whether parsedOutput is one object keyed by a single domain
	 * (domain.get) or one object keyed by every domain the user owns
	 * (domain.list) — both are valid JSON objects at the top level, so
	 * json_decode() alone cannot distinguish them. This is registry
	 * metadata surfaced on the result for convenience; the adapter does
	 * not change how it parses stdout based on this value — see
	 * CommandAdapter's output-parsing step, which stays a single, generic
	 * json_decode() regardless of shape.
	 */
	public ?string $resultShape;

	/**
	 * @var string|null One of "not_attempted" | "confirmed" | "unknown",
	 * populated only when the operation's registry entry declares
	 * mutation.kind !== "read" (WRITE_OPERATION_DESIGN.md Part 4). Null
	 * for read-only operations, where the question "did this mutate
	 * anything" does not apply.
	 *
	 *   not_attempted — rejected before the underlying process was ever
	 *                   spawned (unknown operation, validation failure,
	 *                   LOCK_TIMEOUT, LOCK_UNAVAILABLE, ...). The adapter
	 *                   KNOWS no mutation occurred, because nothing ran.
	 *   confirmed     — the underlying process exited 0. Trusts the CLI's
	 *                   own success signal, same as every existing direct
	 *                   exec() caller already does.
	 *   unknown       — the underlying process was spawned and exited
	 *                   non-zero. This is the deliberately non-committal
	 *                   answer: the adapter does NOT claim to know
	 *                   whether zero, some, or all of the operation's
	 *                   intended writes happened. See
	 *                   WRITE_OPERATION_DESIGN.md Part 5's full trace of
	 *                   bin/v-add-web-domain for why "unknown" — not a
	 *                   more specific guess like "partial_failure" — is
	 *                   the only honest answer for most write failures.
	 */
	public ?string $mutationState;

	public function __construct(
		string $operation,
		string $resolvedCommand,
		string $commandId,
		string $status,
		?int $exitCode,
		?string $hestiaErrorCode,
		?string $adapterErrorCode,
		?string $errorMessage,
		?string $stdout,
		?string $stderr,
		$parsedOutput,
		string $startedAt,
		string $finishedAt,
		int $durationMs,
		array $actor,
		array $target,
		?string $resultShape = null,
		?string $mutationState = null
	) {
		$this->operation = $operation;
		$this->resolvedCommand = $resolvedCommand;
		$this->commandId = $commandId;
		$this->status = $status;
		$this->exitCode = $exitCode;
		$this->hestiaErrorCode = $hestiaErrorCode;
		$this->adapterErrorCode = $adapterErrorCode;
		$this->errorMessage = $errorMessage;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
		$this->parsedOutput = $parsedOutput;
		$this->startedAt = $startedAt;
		$this->finishedAt = $finishedAt;
		$this->durationMs = $durationMs;
		$this->lockWaitMs = null;
		$this->actor = $actor;
		$this->target = $target;
		$this->resultShape = $resultShape;
		$this->mutationState = $mutationState;
	}

	public function isSuccess(): bool {
		return $this->status === "ok";
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			"operation" => $this->operation,
			"resolved_command" => $this->resolvedCommand,
			"command_id" => $this->commandId,
			"status" => $this->status,
			"exit_code" => $this->exitCode,
			"hestia_error_code" => $this->hestiaErrorCode,
			"adapter_error_code" => $this->adapterErrorCode,
			"error_message" => $this->errorMessage,
			"stdout" => $this->stdout,
			"stderr" => $this->stderr,
			"parsed_output" => $this->parsedOutput,
			"started_at" => $this->startedAt,
			"finished_at" => $this->finishedAt,
			"duration_ms" => $this->durationMs,
			"lock_wait_ms" => $this->lockWaitMs,
			"actor" => $this->actor,
			"target" => $this->target,
			"result_shape" => $this->resultShape,
			"mutation_state" => $this->mutationState,
		];
	}
}
