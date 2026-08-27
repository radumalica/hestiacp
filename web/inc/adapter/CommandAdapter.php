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
 * $lockManager and the mutation-kind check in invoke().
 *
 * An authorization seam (MUTATION_AND_AUTHORIZATION_DESIGN.md Part 4-9)
 * IS implemented — see $authorizer. CommandAdapter structurally
 * guarantees $authorizer->authorize() is consulted for EVERY resolved,
 * validated request (read or mutating), after parameter validation and
 * before lock acquisition/process execution — but CommandAdapter itself
 * contains, and must always contain, zero authorization POLICY (no
 * roles, no scopes, no delegation rules). The default
 * AllowAllAuthorizer preserves today's fully-trusted-internal-caller
 * behavior exactly; a real policy-checking implementation is expected to
 * be injected by a future service layer, never written inside this
 * class.
 *
 * NOT implemented in this slice (see ADAPTER_VERTICAL_SLICE.md "known
 * limitations" for the full list and reasoning): audit persistence,
 * timeouts/cancellation of the underlying process itself (only lock
 * ACQUISITION has a timeout), sensitive-argument redaction beyond what
 * is inherent in the minimal parameter set used so far.
 */
final class CommandAdapter {
	private CommandRegistry $registry;
	private ProcessRunnerInterface $runner;
	private string $binDir;
	private string $sudoBinary;
	private LockManagerInterface $lockManager;
	private AuthorizerInterface $authorizer;
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
	 * The only supported value for a parameter's "delivery" metadata
	 * (SENSITIVE_PARAMETER_DESIGN.md). A parameter marked
	 * "delivery" => "temp_file" has its value written to a securely
	 * created, 0600, unpredictably-named file instead of being placed
	 * directly into argv; the FILE PATH is what actually reaches the
	 * child process. Mirrors a temp-file delivery convention already used
	 * elsewhere in this codebase for a sensitive CLI argument
	 * (SENSITIVE_PARAMETER_DESIGN.md "why temp-file delivery is used") —
	 * this adapter does not invent a new convention, it generalizes an
	 * existing one. This class deliberately names no specific operation,
	 * script, or parameter anywhere in its own source — see
	 * SENSITIVE_PARAMETER_DESIGN.md "why this is generic".
	 *
	 * Public (not private), same reasoning as HESTIA_EXIT_CODES below:
	 * CommandRegistry::validateParameterMetadata() validates every
	 * declared "delivery" value against this SAME table at construction
	 * time, so a typo'd delivery mode fails loudly rather than silently
	 * falling back to placing a value in argv unprotected.
	 *
	 * @var string[]
	 */
	public const SUPPORTED_DELIVERY_MODES = ["temp_file"];

	private const DELIVERY_TEMP_FILE = "temp_file";

	/**
	 * Prefix for every secure temp file this class creates
	 * (SENSITIVE_PARAMETER_DESIGN.md "execution lifecycle"). Kept short
	 * and adapter-generic — never operation-specific — since the same
	 * mechanism serves any future sensitive, temp-file-delivered
	 * parameter, whatever operation eventually declares one.
	 */
	private const TEMP_FILE_PREFIX = "hstadapter";

	/**
	 * Fixed, literal directory every secure temp file is created under —
	 * deliberately NOT sys_get_temp_dir() (SENSITIVE_PARAMETER_REVIEW.md
	 * finding F-2: sys_get_temp_dir() resolves dynamically, honoring a
	 * TMPDIR environment override, which could silently diverge from the
	 * literal "/tmp/" prefix an existing, already-established convention
	 * for this exact problem elsewhere in this codebase requires — see
	 * SENSITIVE_PARAMETER_DESIGN.md "security requirements" for the full
	 * source-verified trace of that requirement). Deliberately not
	 * configurable — this generic mechanism has exactly one concrete
	 * contract it was built to satisfy, and that contract is a literal
	 * path prefix, not "wherever this host's temp directory happens to
	 * be."
	 */
	private const TEMP_FILE_DIRECTORY = "/tmp";

	/**
	 * Mirrors func/main.sh's E_* exit code constants (func/main.sh lines
	 * 109-129) by name, so a caller sees "E_NOTEXIST" rather than a bare
	 * "3" that means nothing without cross-referencing the shell source.
	 * This adapter does not invent a new error-code space; it adopts
	 * Hestia's existing one, per ARCHITECTURE_ADAPTER_DESIGN.md section 4.
	 *
	 * Public (not private): CommandRegistry::validateMutationMetadata()
	 * validates each registry entry's declared
	 * "mutation.known_post_mutation_exit_codes" symbolic names against
	 * this SAME table (MUTATION_AND_AUTHORIZATION_DESIGN.md Part 3) — the
	 * smallest possible change to make the one existing, authoritative
	 * exit-code vocabulary reachable from the registry layer, deliberately
	 * NOT a second table and NOT a relocation of this one.
	 *
	 * @var array<int, string>
	 */
	public const HESTIA_EXIT_CODES = [
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
		?LockManagerInterface $lockManager = null,
		?AuthorizerInterface $authorizer = null
	) {
		$this->registry = $registry;
		$this->runner = $runner;
		$this->binDir = rtrim($binDir, "/") . "/";
		$this->sudoBinary = $sudoBinary;
		$this->lockManager = $lockManager ?? new LockManager();
		// Never conditional on "was an authorizer supplied" — the seam is
		// ALWAYS consulted (see invoke()); only the default POLICY behind
		// it matters. The default is SameUserAuthorizer, a real (not
		// permissive) policy — see AUTHORIZATION_POLICY_IMPLEMENTATION.md.
		// No production code constructs CommandAdapter today (this
		// parameter default is therefore the only "default wiring" that
		// concretely exists); callers that genuinely need permissive
		// behavior (tests exercising unrelated concerns) must inject
		// AllowAllAuthorizer explicitly rather than rely on omission.
		$this->authorizer = $authorizer ?? new SameUserAuthorizer();
		$this->clock = $clock ?? static function (): float {
			return microtime(true);
		};
		$this->idGenerator = $idGenerator ?? static function (): string {
			return bin2hex(random_bytes(16));
		};

		$this->typeValidators = [
			"username" => [ParameterValidator::class, "isValidUsername"],
			"domain" => [ParameterValidator::class, "isValidDomain"],
			"database_name" => [ParameterValidator::class, "isValidDatabaseName"],
			"db_username" => [ParameterValidator::class, "isValidDatabaseUsername"],
			"secret" => [ParameterValidator::class, "isValidSecret"],
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

			// SENSITIVE_PARAMETER_DESIGN.md: a parameter declared
			// "sensitive" => true is validated exactly like any other
			// parameter above, and remains available below for argv
			// construction via $params — but it is deliberately never
			// copied into $target. $target is what reaches
			// AuthorizerInterface::authorize() (below) and every
			// AdapterResult this method returns (including every
			// rejection from this point on), so this is the one
			// generic choke point that keeps a sensitive value out of
			// both. No other place in this method reads $params
			// directly for anything other than argv construction.
			//
			// Strict identity comparison (=== true), not loose
			// truthiness — deliberately, per SENSITIVE_PARAMETER_REVIEW.md
			// finding F-1: this field gates a security property, and
			// CommandRegistry::validateParameterMetadata() now rejects
			// any "sensitive" value that is not an actual PHP boolean (or
			// absent/null, treated as false) at construction time, so by
			// the time this line runs, $definition["sensitive"] can only
			// ever be true, false, null, or absent — matching this
			// comparison's own strictness exactly, on both sides, so the
			// two checks can no longer disagree about a malformed value.
			if (($definition["sensitive"] ?? false) !== true) {
				$target[$name] = $value;
			}
		}

		// Authorization (MUTATION_AND_AUTHORIZATION_DESIGN.md Part 8):
		// after operation resolution, parameter validation, and target
		// normalization (all above); strictly before argv construction,
		// lock acquisition, and process execution (all below). Applies to
		// EVERY resolved, validated operation — read or mutating — not
		// only mutating ones, since a future authorizer may legitimately
		// deny read access too. CommandAdapter supplies only the already-
		// validated $operation/$target/$normalizedActor to the seam; it
		// evaluates no policy itself and asks no question beyond the one
		// this call answers.
		if (!$this->authorizer->authorize($operation, $target, $normalizedActor)) {
			return $this->rejected(
				$operation,
				$entry["script"],
				$commandId,
				$startedAt,
				$startedAtSeconds,
				"AUTHORIZATION_DENIED",
				sprintf("Actor is not authorized to perform '%s'", $operation),
				$normalizedActor,
				$target,
				$entry["result_shape"] ?? null,
				$rejectedMutationState
			);
		}

		// Build argv strictly from the registry's declared argument_order.
		// Values come only from (a) validated caller params or (b) fixed
		// registry values (e.g. format=json) — never from any other
		// source, and never assembled into a shell string. See
		// ProcOpenProcessRunner for why array-form argv, not string
		// concatenation, is what actually prevents injection here.
		//
		// A parameter declared "delivery" => "temp_file"
		// (SENSITIVE_PARAMETER_DESIGN.md) does NOT have its value placed
		// into argv directly: it is written to a securely created temp
		// file, and the FILE PATH becomes the argv entry instead. This is
		// deliberately positioned here — after authorization (above) has
		// already succeeded, and only once we know we are actually about
		// to attempt a lock/execution — so no temp file is ever created
		// for a request authorization would go on to deny.
		//
		// $temporaryFiles collects every path created below so the
		// outer try/finally can guarantee cleanup on every exit from this
		// point on: successful execution, a non-zero exit, a lock
		// failure, a registry-authoring error, or an exception escaping
		// $this->runner->run() — see SENSITIVE_PARAMETER_DESIGN.md
		// "cleanup guarantees".
		$fixedParameters = $entry["fixed_parameters"] ?? [];
		$argv = [];
		$temporaryFiles = [];

		try {
			foreach ($entry["argument_order"] as $argName) {
				if (array_key_exists($argName, $fixedParameters)) {
					$rawValue = (string) $fixedParameters[$argName];
				} elseif (array_key_exists($argName, $params)) {
					$rawValue = (string) $params[$argName];
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

				$delivery = $parameterSchema[$argName]["delivery"] ?? null;
				if ($delivery === self::DELIVERY_TEMP_FILE) {
					try {
						$tempPath = $this->writeSecureTempFile($rawValue);
					} catch (\RuntimeException $exception) {
						return $this->rejected(
							$operation,
							$entry["script"],
							$commandId,
							$startedAt,
							$startedAtSeconds,
							"TEMP_FILE_UNAVAILABLE",
							"Unable to prepare secure parameter delivery for '" . $argName . "': " . $exception->getMessage(),
							$normalizedActor,
							$target,
							$entry["result_shape"] ?? null,
							$rejectedMutationState
						);
					}
					$temporaryFiles[] = $tempPath;
					$argv[] = $tempPath;
				} else {
					$argv[] = $rawValue;
				}
			}

			// Lock acquisition happens here: after every validation step
			// above (so contention is never incurred for a request that
			// was going to be rejected anyway — WRITE_OPERATION_DESIGN.md
			// Part 3's ordering requirement), and strictly before the
			// underlying process is spawned (so a lock timeout guarantees
			// the v-* command never runs — Part 3's "timeout must not
			// execute the command").
			//
			// Locked on $target["user"], not on any raw $params value: by
			// this point "user" has already passed
			// ParameterValidator::isValidUsername() for every registered
			// operation that declares a "user" parameter (both existing
			// operations, and any mutating operation a future registry
			// entry adds), so LockManager's own independent revalidation
			// (LockManager::lockFilePath()) is defense-in-depth, not the
			// only check.
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

			// mutation_state (WRITE_OPERATION_DESIGN.md Part 4,
			// MUTATION_AND_AUTHORIZATION_DESIGN.md Part 1): only ever set for
			// mutating operations. "confirmed" trusts the same exit-0 signal
			// every existing direct exec() caller already trusts.
			//
			// For a non-zero exit, "confirmed_degraded" is used ONLY when the
			// resolved registry entry explicitly declares $hestiaErrorCode as
			// one it has independently, source-verified as occurring strictly
			// after this operation's core mutation is durably complete
			// (mutation.known_post_mutation_exit_codes — see
			// CommandRegistry::validateMutationMetadata()). This is a purely
			// data-driven lookup against the resolved $entry — CommandAdapter
			// itself contains no operation-specific exit code or error name
			// (no "if ($hestiaErrorCode === 'E_RESTART')" anywhere in this
			// file); a different registry entry declaring a different code,
			// or none at all, changes this outcome without any code change
			// here. Every other non-zero exit remains "unknown" — never a
			// more specific guess like "partial_failure", and never a
			// "definitely nothing changed" claim either (MUTATION_AND_AUTHORIZATION_DESIGN.md
			// Part 1's asymmetric-risk reasoning for why no such state exists).
			$mutationState = null;
			if ($isMutating) {
				if ($processResult->exitCode === 0) {
					$mutationState = "confirmed";
				} else {
					$knownPostMutationCodes = $entry["mutation"]["known_post_mutation_exit_codes"] ?? [];
					$mutationState = ($hestiaErrorCode !== null && in_array($hestiaErrorCode, $knownPostMutationCodes, true))
						? "confirmed_degraded"
						: "unknown";
				}
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
		} finally {
			// Runs on every exit from the try block above: the normal
			// return, every early "return $this->rejected(...)" (registry
			// error, lock unavailable, lock timeout), and any exception
			// propagating out of $this->runner->run(). See
			// SENSITIVE_PARAMETER_DESIGN.md "cleanup guarantees".
			foreach ($temporaryFiles as $tempPath) {
				$this->removeSecureTempFile($tempPath);
			}
		}
	}

	/**
	 * Writes $value to a newly created, securely permissioned,
	 * unpredictably named temp file and returns its path.
	 * SENSITIVE_PARAMETER_DESIGN.md "execution lifecycle" /
	 * "security requirements".
	 *
	 * Uses the fixed, literal self::TEMP_FILE_DIRECTORY ("/tmp") rather
	 * than a dedicated adapter directory or the dynamically-resolved
	 * system temp directory — deliberately mirroring an existing,
	 * already-established production convention for this exact problem
	 * elsewhere in this codebase, not inventing a second one, and
	 * deliberately deterministic rather than environment-dependent (see
	 * self::TEMP_FILE_DIRECTORY's own docblock). This also keeps the
	 * mechanism compatible with any future script whose own
	 * value-from-file support is scoped to a "/tmp/" prefix, without
	 * CommandAdapter needing to know that fact about any specific script
	 * — it always uses this same directory, generically. See
	 * SENSITIVE_PARAMETER_DESIGN.md "security requirements" for the full
	 * source-verified rationale.
	 *
	 * tempnam() creates the file atomically with mode 0600 already; the
	 * explicit chmod() below is defense-in-depth, not the only guarantee.
	 * A trailing newline is appended to match the existing convention
	 * documented in SENSITIVE_PARAMETER_DESIGN.md "security requirements"
	 * (verified against the actual reader, not assumed).
	 *
	 * @throws \RuntimeException if a secure temp file could not be created or written.
	 */
	private function writeSecureTempFile(string $value): string {
		error_clear_last();
		$path = @tempnam(self::TEMP_FILE_DIRECTORY, self::TEMP_FILE_PREFIX);
		if ($path === false) {
			$lastError = error_get_last();
			throw new \RuntimeException(
				"Unable to create a secure temporary file: " . ($lastError["message"] ?? "unknown error")
			);
		}

		@chmod($path, 0600);

		$written = @file_put_contents($path, $value . "\n");
		if ($written === false) {
			@unlink($path);
			throw new \RuntimeException("Unable to write to secure temporary file: " . $path);
		}

		return $path;
	}

	/**
	 * Best-effort, idempotent removal of a temp file created by
	 * writeSecureTempFile(). Never throws — cleanup must not itself
	 * become a new failure mode on an already-completed or already-failed
	 * invocation. SENSITIVE_PARAMETER_DESIGN.md "cleanup guarantees".
	 */
	private function removeSecureTempFile(string $path): void {
		if (is_file($path)) {
			@unlink($path);
		}
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
