<?php

namespace App\Support;

class ShellEnv
{
    /**
     * Render one POSIX-safe export line for shell eval.
     */
    public static function export(string $name, string $value): string
    {
        $escaped = str_replace("'", "'\\''", $value);

        return "export {$name}='{$escaped}'";
    }
}
