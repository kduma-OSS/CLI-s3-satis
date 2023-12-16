<?php

namespace App\Extensions\Internals;

class HookDescriptor
{
    public function __construct(
        public readonly BuildHooks $hook,
        public readonly string $method_name,
    ) {
    }
}
