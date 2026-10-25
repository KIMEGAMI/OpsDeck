<?php

namespace App\Services;

use Aws\Command;

class R2UploadCommandOptions
{
    public static function removeUnsupportedAcl(Command $command): void
    {
        unset($command['ACL']);
    }
}
