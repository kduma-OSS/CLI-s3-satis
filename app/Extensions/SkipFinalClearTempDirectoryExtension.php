<?php

namespace App\Extensions;

use App\Extensions\Internals\BuildExtension;
use App\Extensions\Internals\BuildHook;
use App\Extensions\Internals\BuildHooks;
use App\Extensions\Internals\BuildStateInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[BuildExtension(name: 'Skip Final Cleanup', key: 'skip-final-cleanup')]
class SkipFinalClearTempDirectoryExtension
{
    #[BuildHook(BuildHooks::BEFORE_FINAL_CLEAR_TEMP_DIRECTORY)]
    public function hook(): bool
    {
        return false;
    }
}
