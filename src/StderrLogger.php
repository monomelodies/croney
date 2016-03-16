<?php

namespace Croney;

class StderrLogger
{
    public function __call($name, array $args)
    {
        if (preg_match('@^add[A-Z]@', $name)
            && isset($args[0])
            && is_string($args[0])
        ) {
            fwrite(STDERR, $args[0]."\n");
        }
    }
}

