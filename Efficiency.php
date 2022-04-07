<?php

class Efficiency {
    private static $counter = null,
                   $efficiency = 0;

    public static function clear()
    {
        static::$counter = 0;
        static::$efficiency = null;
    }

    public static function addValue($value)
    {
        //echo static::$counter . "add $value\n";
        static::$counter += $value;
    }

    public static function newIteration()
    {
        //echo 'new iteration ef' . static::$efficiency . ' c'. static::$counter . "\n";
        if (static::$counter) {
            static::$efficiency = static::$counter;
        }

        static::$counter = 0;
    }

    public static function getMessage()
    {
        if (static::$efficiency) {
            return "Total response rate " . round(static::$efficiency) . "%  (in compare with single VPN connection)";
        }
    }

}