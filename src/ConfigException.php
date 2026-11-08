<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

/**
 * Thrown when configuration is missing or invalid. The application FAILS CLOSED:
 * the web front controller answers with a plain error and no details; CLI tools
 * print the message to STDERR and exit non-zero.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class ConfigException extends \RuntimeException
{
}
