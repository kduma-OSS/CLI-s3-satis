<?php

namespace App\Extensions\Internals;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class BuildHook
{
    public function __construct(
        public BuildHooks $hook,
    ) {
    }
}
