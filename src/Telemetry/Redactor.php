<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Telemetry;

/**
 * Strips the things an exception message should not carry across the fleet.
 *
 * FLEET.md's line is that a call report carries the envelope and never the
 * payload, and an error report is the same kind of document — except that an
 * exception message is far leakier than a request envelope, because it is
 * assembled at the point of failure out of whatever was to hand. Real examples
 * of what turns up in one: the email address a lookup failed on, a full SQL
 * statement with its bindings, an API key in a rejected request, the body of
 * the record that failed validation.
 *
 * So messages are redacted here, before they are stored — not before they are
 * sent. Storing the raw text and cleaning it on the way out leaves the raw text
 * on disk, and the app's own database is exactly where a "we don't hold that"
 * claim goes to die.
 *
 * This is a filter, not a guarantee. It catches the shapes that recur; it
 * cannot catch a name. The other half of the defence is the length cap: a
 * truncated message is enough to recognise a known failure and not enough to
 * be a copy of the record that caused it. When you need the full text, it is
 * in that app's own log, on that app's own disk, which is where it should be.
 */
final class Redactor
{
    /**
     * Long enough to identify the failure, short enough that nothing
     * substantial rides along inside it.
     */
    public const MAX_LENGTH = 300;

    /**
     * Ordered: each pattern and what replaces it.
     *
     * Emails go first because an address caught later by the token rule would
     * be reported as a token and read as a leaked credential.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // Email addresses.
        '/[\w.+-]+@[\w-]+\.[\w.-]+/i' => '[email]',

        // Bearer tokens, API keys and the long random strings that are always
        // one of the two. Twenty-plus chars of base64-ish alphabet with at
        // least one digit — long enough not to catch ordinary words.
        '/\b(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{20,}\b/' => '[token]',

        // Anything after a key that names a secret, up to the next quote or
        // comma. Catches `"password":"hunter2"` and `api_key=abc`.
        '/\b(password|passwd|secret|token|api[_-]?key|authorization|signature)\b\s*[:=]\s*\S+/i' => '$1=[redacted]',

        // Card-length and account-length digit runs.
        '/\b\d[\d\s-]{11,}\d\b/' => '[number]',

        // Anything that looks like a bare SQL statement dragged into a
        // message. The query shape is useful; its bindings are not, and a
        // PDOException carries both.
        '/\((?:SQL|Connection):.*$/is' => '(SQL: [redacted])',
    ];

    public static function message(?string $message): string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return '(no message)';
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        // Collapse whitespace so a multi-line message stays one line in a
        // table, and so the length cap measures content rather than layout.
        $message = (string) preg_replace('/\s+/', ' ', $message);
        $message = trim($message);

        if (mb_strlen($message) > self::MAX_LENGTH) {
            return mb_substr($message, 0, self::MAX_LENGTH - 1).'…';
        }

        return $message;
    }

    /**
     * A file path relative to the application root.
     *
     * An absolute path names the deploy directory, the user account and
     * sometimes the release hash — none of which helps anyone reading the
     * report, and all of which describes the host to whoever gets hold of it.
     */
    public static function path(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $base = base_path();

        return str_starts_with($path, $base)
            ? ltrim(substr($path, strlen($base)), '/')
            : basename($path);
    }
}
