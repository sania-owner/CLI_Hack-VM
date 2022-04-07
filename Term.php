<?php

// https://en.wikipedia.org/wiki/ANSI_escape_code
// https://stackoverflow.com/questions/4842424/list-of-ansi-color-escape-sequences

class Term {
    public const clear  = "\033[0m",
                 red    = "\033[31m",
                 green  = "\033[32m";

    public const bold        = "\033[2m",
                 noBold      = "\033[22m",
                 underline   = "\033[4m",
                 noUnderline =  "\033[24m";

    public const toBeginningOfTheLine = "\033[F",
                 oneLineUp            = "\033[A";

    public static function removeMessage($message)
    {
        $message = preg_replace('#\033\[[\d;]+m#', '', $message);
        $messageLines = explode("\n", $message);
        $messageLinesReverse = array_reverse($messageLines);
        foreach ($messageLinesReverse as $key => $line) {
            $lineLength = strlen($line);
            echo str_repeat(chr(8), $lineLength);     // Move left
            echo str_repeat(' ',       $lineLength);     // Print emptiness
            echo str_repeat(chr(8), $lineLength);     // Move left
            if ($key !== array_key_last($messageLinesReverse)) {
                echo static::oneLineUp;                       // Move up
            }
        }
    }
}