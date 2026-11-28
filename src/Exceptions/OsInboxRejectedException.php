<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Exceptions;

use RuntimeException;

/**
 * The OS inbox answered with a 4xx: the receiver refused the report and a retry will not change that.
 */
final class OsInboxRejectedException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $excerpt,
    ) {
        parent::__construct(sprintf('UteqOS inbox rejected the report (HTTP %d): %s', $status, $excerpt));
    }
}
