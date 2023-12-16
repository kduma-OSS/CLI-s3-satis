<?php

namespace App\Extensions\Internals;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class BuildExtension
{
    public function __construct(
        public string  $name,
        public ?string $key = null,
    ) { }
}
