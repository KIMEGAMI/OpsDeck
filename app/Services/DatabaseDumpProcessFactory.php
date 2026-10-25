<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class DatabaseDumpProcessFactory
{
    /** @param list<string> $command */
    public function create(array $command): Process
    {
        return new Process(
            $command,
            timeout: (float) config('opsdeck.backup.process_timeout_seconds'),
        );
    }
}
