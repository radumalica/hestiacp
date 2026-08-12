<?php

namespace Hestiacp\Adapter;

/**
 * Bash CLI Adapter — the ONLY supported way to invoke a bin/v-* script
 * through this new layer. Implements the vertical slice described in
 * ADAPTER_VERTICAL_SLICE.md, itself scoped down from the full design in
 * ARCHITECTURE_ADAPTER_DESIGN.md.
 *
 * Deliberately has no generic exec()/runRaw() method. The only public
 * entry point is invoke(operation, params, actor) — a caller can never
 * supply a command name or a raw argument list; both are always derived
 * from a CommandRegistry entry. This is the adapter's primary security
 * property (see ARCHITECTURE_ADAPTER_DESIGN.md section 5 and this slice's
 * "how command injection is prevented").
 *
 * Per-user locking for mutating operations (WRITE_OPERATION_DESIGN.md,
 * LOCK_PERMISSION_REVIEW.md, LOCK_IMPLEMENTATION.md) IS implemented — see
 * $lockManager and the mutation-kind check in invoke(). NOT implemented
 * in this slice (see ADAPTER_VERTICAL_SLICE.md "known limitations" for
 * the full list and reasoning): audit persistence, timeouts/cancellation
 * of the underlying process itself (only lock ACQUISITION has a
 * timeout), sensitive-argument redaction beyond what is inherent in the
 * minimal parameter set used so far.
 */
final class CommandAdapter {
	private CommandRegistry $registry;
	private ProcessRunnerInterface $runner;
	private string $binDir;
	private string $sudoBinary;
	private LockManagerInterface $lockManager;
	/** @var callable(): float */
	private $clock;
	/** @var callable(): string */
	private $idGenerator;

	/**
	 * Shape-only validators per declared parameter type. See
	 * ParameterValidator for what each one checks and which func/main.sh
	 * function it approximates.
	 *
	 * @var array<string, callable(mixed): bool>
	 */
	private array $typeValidators;

	/**
	 * Mirrors func/main.sh's E_* exit code constants (func/main.sh lines
	 * 109-129) by name, so a caller sees "E_NOTEXIST" rather than a bare
	 * "3" that means nothing without cross-referencing the shell source.
	 * This adapter does not invent a new error-code space; it adopts
	 * Hestia's existing one, per ARCHITECTURE_ADAPTER_DESIGN.md section 4.
	 *
	 * @var array<int, string>
	 */
	private const HESTIA_EXIT_CODES = [
		0 => "OK",
		1 => "E_ARGS",
		2 => "E_INVALID",
		3 => "E_NOTEXIST",
		4 => "E_EXISTS",
		5 => "E_SUSPENDED",
		6 => "E_UNSUSPENDED",
		7 => "E_INUSE",
		8 => "E_LIMIT",
		9 => "E_PASSWORD",
		10 => "E_FORBIDEN",
		11 => "E_DISABLED",
		12 => "E_PARSING",
		13 => "E_DISK",
		14 => "E_LA",
		15 => "E_CONNECT",
		16 => "E_FTP",
		17 => "E_DB",
		18 => "E_RRD",
		19 => "E_UPDATE",
		20 => "E_RESTART",
	];

	public function __construct(
		CommandRegistry $registry,
		ProcessRunnerInterface $runner,
		string $binDir = "/usr/local/hestia/bin/",
		string $sudoBinary = "/usr/bin/sudo",
		?callable $clock = null,
		?callable $idGenerator = null,
		?LockManagerInterface $lockManager = null
	) {
		$this->registry = $registry;
		$this->runner = $runner;
		$this->binDir = rtrim($binDir, "/") . "/";
		$this->sudoBinary = $sudoBinary;
		$this->lockManager = $lockManager ?? new LockManager();
		$this->clock = $clock ?? static function (): float {
			return microtime(true);
		};
		$this->idGenerator = $idGenerator ?? static function (): string {
			return bin2hex(random_bytes(16));
		};

		$this->typeValidators = [
			"username" => [ParameterValidator::class, "isValidUsername"],
			"domain" => [ParameterValidator::class, "isValidDomain"],
		];
	}

	/**
	 * @param array<string, mixed> $params Named, typed parameters as declared by the operation's registry entry.
	 * @param array{user?: string, acting_as?: string} $actor Who is requesting the operation.
	 */
	public function invoke(string $operation, array $params, array $actor = []): AdapterResult {
		$commandId = ($this->idGenerator)();
		$startedAtSeconds = ($this->clock)();
		$startedAt = $this->formatTimestamp($startedAtSeconds);
		$normalizedActor = [
			"user" => $actor["user"] ?? null,
			"acting_as" => $actor["acting_as"] ?? null,
		];

		$entry = $this->registry->get($operation);
		if ($entry === null) {
			return $this->rejected(
				$operation,
				"",
				$commandId,
				$startedAt,
				$startedAtSeconds,
				"UNKNOWN_OPERATION",
				"Unknown operation: " . $operation,
				$normalizedActor,
				[]
			);
		}

		// Determined once the entry is resolved, used both to decide
		// whether to acquire a lock later and to pick the mutation_state
		// for every rejection from this point on (WRITE_OPERATION_DESIGN.md
		// Part 4: any rejection of a mutating operation, for any reason,
		// before the underlying process is spawned, is "not_attempted" —
		// the adapter KNOWS nothing ran). Read-only operations never carry
		// a mutation_state at all (null).
		$mutationKind = $entry["mutation"]["kind"] ?? "read";
		$isMutating = $mutationKind !== "read";
		$rejectedMutationState = $isMutating ? "not_attempted" : null;

		$parameterSchema = $entry["parameters"] ?? [];
		$target = [];

		// Reject unexpected parameters before validating anything else —
		// a caller passing a key the registry does not declare is a
		// programming/integration error we want surfaced immediately,
		// not silently ignored.
		foreach (array_keys($params) as $suppliedKey) {
			if (!array_key_exists($suppliedKey, $parameterSchema)) {
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"UNEXPECTED_PARAMETER",
					"Unexpected parameter: " . $suppliedKey,
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}
		}

		// Required-parameter presence + shape validation.
		foreach ($parameterSchema as $name => $definition) {
			$required = $definition["required"] ?? false;
			$hasValue = array_key_exists($name, $params);

			if (!$hasValue) {
				if ($required) {
					return $this->rejected(
						$operation,
						$entry["script"],
						$commandId,
						$startedAt,
						$startedAtSeconds,
						"MISSING_PARAMETER",
						"Missing required parameter: " . $name,
						$normalizedActor,
						$target,
						$entry["result_shape"] ?? null,
						$rejectedMutationState
					);
				}
				continue;
			}

			$value = $params[$name];
			$type = $definition["type"];
			$validator = $this->typeValidators[$type] ?? null;

			if ($validator === null) {
				// A registry entry declared a type this adapter build
				// does not know how to validate. Fail closed rather than
				// forward an unvalidated value to a subprocess.
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"UNKNOWN_PARAMETER_TYPE",
					"No validator registered for parameter type: " . $type,
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}

			if (!call_user_func($validator, $value)) {
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"VALIDATION_FAILED",
					sprintf("Parameter '%s' failed shape validation for type '%s'", $name, $type),
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}

			$target[$name] = $value;
		}

		// Build argv strictly from the registry's declared argument_order.
		// Values come only from (a) validated caller params or (b) fixed
		// registry values (e.g. format=json) — never from any other
		// source, and never assembled into a shell string. See
		// ProcOpenProcessRunner for why array-form argv, not string
		// concatenation, is what actually prevents injection here.
		$fixedParameters = $entry["fixed_parameters"] ?? [];
		$argv = [];
		foreach ($entry["argument_order"] as $argName) {
			if (array_key_exists($argName, $fixedParameters)) {
				$argv[] = (string) $fixedParameters[$argName];
			} elseif (array_key_exists($argName, $params)) {
				$argv[] = (string) $params[$argName];
			} else {
				// argument_order referenced a name with neither a
				// supplied value nor a fixed value — a registry
				// authoring bug, not a caller error. Fail closed.
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"REGISTRY_ERROR",
					"Registry entry for '" . $operation . "' references undefined argument: " . $argName,
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}
		}

		// Lock acquisition happens here: after every validation step above
		// (so contention is never incurred for a request that was going to
		// be rejected anyway — WRITE_OPERATION_DESIGN.md Part 3's ordering
		// requirement), and strictly before the underlying process is
		// spawned (so a lock timeout guarantees the v-* command never
		// runs — Part 3's "timeout must not execute the command").
		//
		// Locked on $target["user"], not on any raw $params value: by this
		// point "user" has already passed ParameterValidator::isValidUsername()
		// for every registered operation that declares a "user" parameter
		// (both existing operations, and any mutating operation a future
		// registry entry adds), so LockManager's own independent
		// revalidation (LockManager::lockFilePath()) is defense-in-depth,
		// not the only check.
		$lockAcquired = false;
		if ($isMutating) {
			$lockUser = $target["user"] ?? null;
			if ($lockUser === null) {
				// A mutating registry entry without a "user" parameter is a
				// registry authoring bug — there is nothing to lock on.
				// Fail closed rather than run a mutating command with no
				// serialization at all.
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"REGISTRY_ERROR",
					"Mutating operation '" . $operation . "' has no 'user' parameter to lock on",
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}

			try {
				$lockAcquired = $this->lockManager->acquire($lockUser);
			} catch (LockUnavailableException $exception) {
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"LOCK_UNAVAILABLE",
					"Locking mechanism unavailable: " . $exception->getMessage(),
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}

			if (!$lockAcquired) {
				return $this->rejected(
					$operation,
					$entry["script"],
					$commandId,
					$startedAt,
					$startedAtSeconds,
					"LOCK_TIMEOUT",
					"Timed out waiting for the per-user lock for: " . $lockUser,
					$normalizedActor,
					$target,
					$entry["result_shape"] ?? null,
					$rejectedMutationState
				);
			}
		}

		try {
			$scriptPath = $this->binDir . $entry["script"];
			$processResult = $this->runner->run($this->sudoBinary, array_merge([$scriptPath], $argv));
		} finally {
			// Always released, including when $this->runner->run() throws
			// (CommandAdapter does not swallow that exception — it
			// propagates to the caller unchanged, per this class's
			// existing "every EXPECTED failure is an AdapterResult, not an
			// exception" contract; only the lock itself is guaranteed not
			// to leak). Idempotent / safe to call when nothing was
			// acquired (LockManager::release()).
			if ($lockAcquired) {
				$this->lockManager->release();
			}
		}

		$finishedAtSeconds = ($this->clock)();
		$finishedAt = $this->formatTimestamp($finishedAtSeconds);
		$durationMs = (int) round(($finishedAtSeconds - $startedAtSeconds) * 1000);

		$parsedOutput = null;
		if (($entry["output_format"] ?? null) === "json" && trim($processResult->stdout) !== "") {
			$decoded = json_decode($processResult->stdout, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$parsedOutput = $decoded;
			}
		}

		if ($processResult->exitCode === 0) {
			$status = "ok";
			$hestiaErrorCode = null;
			$errorMessage = null;
		} else {
			$status = "hestia_error";
			$hestiaErrorCode = self::HESTIA_EXIT_CODES[$processResult->exitCode] ?? null;
			$errorMessage = trim($processResult->stderr) !== ""
				? trim($processResult->stderr)
				: trim($processResult->stdout);
			if ($errorMessage === "") {
				$errorMessage = sprintf("Command exited with code %d", $processResult->exitCode);
			}
		}

		// mutation_state (WRITE_OPERATION_DESIGN.md Part 4): only ever set
		// for mutating operations. "confirmed" trusts the same exit-0
		// signal every existing direct exec() caller already trusts.
		// "unknown" — never a more specific guess like "partial_failure" —
		// is the deliberately non-committal answer for a non-zero exit;
		// see AdapterResult::$mutationState's docblock and
		// WRITE_OPERATION_DESIGN.md Part 5 for why.
		$mutationState = null;
		if ($isMutating) {
			$mutationState = $processResult->exitCode === 0 ? "confirmed" : "unknown";
		}

		return new AdapterResult(
			$operation,
			$entry["script"],
			$commandId,
			$status,
			$processResult->exitCode,
			$hestiaErrorCode,
			null,
			$errorMessage,
			$processResult->stdout,
			$processResult->stderr,
			$parsedOutput,
			$startedAt,
			$finishedAt,
			$durationMs,
			$normalizedActor,
			$target,
			$entry["result_shape"] ?? null,
			$mutationState
		);
	}

	private function rejected(
		string $operation,
		string $resolvedCommand,
		string $commandId,
		string $startedAt,
		float $startedAtSeconds,
		string $adapterErrorCode,
		string $errorMessage,
		array $actor,
		array $target,
		?string $resultShape = null,
		?string $mutationState = null
	): AdapterResult {
		$finishedAtSeconds = ($this->clock)();
		$finishedAt = $this->formatTimestamp($finishedAtSeconds);
		$durationMs = (int) round(($finishedAtSeconds - $startedAtSeconds) * 1000);

		return new AdapterResult(
			$operation,
			$resolvedCommand,
			$commandId,
			"adapter_error",
			null,
			null,
			$adapterErrorCode,
			$errorMessage,
			null,
			null,
			null,
			$startedAt,
			$finishedAt,
			$durationMs,
			$actor,
			$target,
			$resultShape,
			$mutationState
		);
	}

	private function formatTimestamp(float $seconds): string {
		$wholeSeconds = (int) floor($seconds);
		return gmdate("Y-m-d\TH:i:s\Z", $wholeSeconds);
	}
}
