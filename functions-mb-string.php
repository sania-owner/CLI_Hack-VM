<?php

function mbDirname($path) {
    $parts = mb_split("[/\\\\]", $path);
    $partsCount = count($parts);
    if ($partsCount > 1) {
        unset($parts[$partsCount - 1]);
    }
    return implode('/', $parts);
}

function mbFilename($path) {
    $baseName = mbBasename($path);
    if (($pos = mb_strrpos($baseName, '.')) === false) {
        return $baseName;
    } else {
        return mb_substr($baseName, 0, $pos);
    }
}

function mbBasename($path) {
    $parts = mb_split("[/\\\\]", $path);
    $partsCount = count($parts);
    if ($partsCount > 1) {
        return $parts[$partsCount - 1];
    } else {
        return $path;
    }
}

function mbTrim($str, $charactersMask = null)
{
    $str = mbLTrim($str, $charactersMask);
    $str = mbRTrim($str, $charactersMask);
    return $str;
}

function mbLTrim($str, $charactersMask = null)
{
    if ($charactersMask) {
        $pattern = '/^[';
        $pattern .= mbPregQuote($charactersMask, '/');
        $pattern .= ']+/u';
    } else {
        $pattern = '/^\s+/u';
    }

    return preg_replace($pattern, '', $str);
}

function mbRTrim($str, $charactersMask = null)
{
    if ($charactersMask) {
        $pattern = '/[';
        $pattern .= mbPregQuote($charactersMask, '/');
        $pattern .= ']+$/u';
    } else {
        $pattern = '/\s+$/u';
    }

    return preg_replace($pattern, '', $str);
}

function mbPregQuote($str, $delimiter = null)
{
    $ret = '';

    $specialChars = '\.+*?[^]$(){}=!<>|:-';
    if ($delimiter) {
        $specialChars .= $delimiter;
    }

    for ($ci = 0; $ci < mb_strlen($str); $ci++) {
        $c = mb_substr($str, $ci, 1);
        if (mb_strpos($specialChars, $c) !== false) {
            $c = "\\" . $c;
        }
        $ret .= $c;
    }

    return $ret;
}

function mbExplode(string $separator, string $string)
{
    $separator = mbPregQuote($separator);
    return mb_split($separator, $string);
}

function mbSplitLines(string $string) : array
{
    $newLineRegExp = <<<PhpRegExp
        (\r\n|\r|\n)
        PhpRegExp;
    return mb_split(trim($newLineRegExp), $string);
}

function mbRemoveEmptyLinesFromArray(array $array, bool $reIndex = true) : array
{
    $array = array_map('mbTrim', $array);
    $array = array_filter($array);
    if ($reIndex) {
        $array = array_values($array);
    }
    return $array;
}

function mbPathWithoutExt($path)
{
    $baseName = mbBasename($path);
    if (mb_strpos($baseName, '.') === false) {
        return $path;
    } else {
        $pos = mb_strrpos($path, '.');
        return mb_substr($path, 0, $pos);
    }
}

function mbExt(string $path) : string
{
    $baseName = mbBasename($path);
    if (($pos = mb_strrpos($baseName, '.')) === false) {
        return '';
    } else {
        return mb_substr($baseName, $pos + 1);
    }
}

