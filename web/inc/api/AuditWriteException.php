<?php

namespace Hestiacp\Api;

/**
 * Thrown by an AuditLogger implementation when an event genuinely could
 * not be recorded (directory missing/unwritable, disk full, etc.) — not
 * a signal about the event's own content. Never allowed to escape
 * ExecuteRequestHandler uncaught: the fail-open policy documented in
 * dev-docs/api-v2/API_V2_AUDIT_LOGGING_IMPLEMENTATION.md §13 always
 * catches this and lets the already-built API response proceed
 * unchanged.
 */
final class AuditWriteException extends \RuntimeException {
}
