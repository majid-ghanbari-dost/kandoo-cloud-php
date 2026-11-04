<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

/**
 * A qualifying projection trigger whose data violates the §7.1/§7.2 rules of
 * CLOUD-CONTRACT.md. Frozen behaviour: the WHOLE ingest transaction fails — no entries,
 * no projection, no watermark movement (fail closed). The frozen Cloud surfaces an
 * unhandled 500; the PHP Cloud answers the INTERNAL_ERROR envelope (U11). The frozen
 * internal code rides in the exception message for diagnostics only and is NEVER sent to
 * clients or logs with request context.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class ProjectionViolationException extends \RuntimeException
{
    public function __construct(string $frozenCode)
    {
        parent::__construct('Public invoice projection violation: ' . $frozenCode);
    }
}
