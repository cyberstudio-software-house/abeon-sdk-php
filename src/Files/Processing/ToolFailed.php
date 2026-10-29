<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Processing;

use RuntimeException;

/**
 * A parser refused the file or ran out of a limit. The same bytes fail the same way on a retry,
 * so the job marks the file failed instead of trying again.
 */
final class ToolFailed extends RuntimeException
{
}
