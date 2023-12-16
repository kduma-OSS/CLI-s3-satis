<?php

namespace App\Extensions\Internals;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class BuildHook
{
    public function __construct(
        public BuildHooks $hook,
    ) { }
}
