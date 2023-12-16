<?php

namespace App\Extensions\Internals;

use Illuminate\Support\Collection;

class ExtensionDescriptor
{
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public readonly string $class_name,
        public readonly Collection $hooks,
    ) {
    }
}
