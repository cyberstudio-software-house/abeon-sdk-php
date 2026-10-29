<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Processing;

use Symfony\Component\Process\Process;

/**
 * Runs the parsers (libvips, poppler) on bytes a stranger sent (Files PRD §11a).
 *
 * They run as separate processes with nothing of the application's environment — no database
 * password, no service key — under an address-space limit and a timeout. libvips also refuses
 * its untrusted loaders (ImageMagick, Matlab and the like) with `VIPS_BLOCK_UNTRUSTED`.
 */
final class Toolbox
{
    /** A single file a parser may write: far above any variant, far below a full disk. */
    private const MAX_OUTPUT_BYTES = 512 * 1024 * 1024;

    public function __construct(
        private readonly int $timeoutSeconds,
        private readonly int $memoryBytes,
    ) {
    }

    public function run(string $workDir, string ...$command): string
    {
        $process = new Process(
            [
                'prlimit',
                "--as={$this->memoryBytes}",
                '--fsize='.self::MAX_OUTPUT_BYTES,
                "--cpu={$this->timeoutSeconds}",
                '--nofile=256',
                '--',
                ...$command,
            ],
            $workDir,
            $this->environment($workDir),
            null,
            $this->timeoutSeconds,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ToolFailed(sprintf(
                '%s exited with %s: %s',
                $command[0],
                (string) $process->getExitCode(),
                mb_substr(trim($process->getErrorOutput()), 0, 500),
            ));
        }

        return $process->getOutput();
    }

    /**
     * Symfony's `Process` inherits `getenv()`, `$_ENV` and `$_SERVER`; every key from them is
     * set to `false`, which is how it is told to leave one out.
     *
     * @return array<string, string|false>
     */
    private function environment(string $home): array
    {
        $inherited = array_keys(getenv() + $_ENV + array_filter($_SERVER, 'is_string'));

        return [
            'PATH'                 => '/usr/local/bin:/usr/bin:/bin',
            'HOME'                 => $home,
            'TMPDIR'               => $home,
            'VIPS_CONCURRENCY'     => '1',
            'VIPS_BLOCK_UNTRUSTED' => '1',
        ] + array_fill_keys(array_map('strval', $inherited), false);
    }
}
