<?php

function getDirectoryFilesListRecursive($dir, $ext = '')
{
    $ret = [];
    $ext = mb_strtolower($ext);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $iterator->rewind();
    while($iterator->valid()) {
        if($iterator->isDot()) {
            goto nextFile;
        }

        if ($ext  &&  $ext !== mb_strtolower($iterator->getExtension())) {
            goto nextFile;
        }

        $ret[] = $iterator->getPathname();

        nextFile:
        $iterator->next();
    }
    return $ret;
}

function streamReadLines($stream, $wait = 0.5)
{
    stream_set_blocking($stream, false);
    waitForOsSignals($wait);

    global $streamReadLinesIncompleteLine;
    $streamId = (int) $stream;
    $line = $streamReadLinesIncompleteLine[$streamId] ?? '';

    $ret = '';
    $readsCount = 0;
    while (true) {
        $readsCount++;
        $c = @fgetc($stream);
        $line .= $c;
        if ($c == ''  ||  feof($stream)  ||  $readsCount > 150000) {
            // Exit time!
            if (strlen($line)) {
                // Saving incomplete line for next function call
                $streamReadLinesIncompleteLine[$streamId] = $line;
            } else {
                unset($streamReadLinesIncompleteLine[$streamId]);
            }
            // Return collected lines
            return $ret;
        }
        if (mb_substr($line, -1) === PHP_EOL) {
            $ret .= $line;
            $line = '';
        }
    }
}

function buildFirstLineLabel($vpnI, $label)
{
    global $LOG_BADGE_WIDTH;
    $vpnId = ($vpnI < 10  ?  'VPN' : 'VP') . $vpnI;
    $labelCut = substr($label, 0, $LOG_BADGE_WIDTH - 8);
    $labelPadded = str_pad($labelCut, $LOG_BADGE_WIDTH - strlen($vpnId) - 3);
    return $labelPadded . $vpnId;
}

function _echo($vpnI, $label, $message, $noNewLineInTheEnd = false, $forceBadge = false)
{
    global $LOG_WIDTH, $LOG_BADGE_WIDTH, $_echoPreviousLabel;
    $emptyLabel = str_repeat(' ', $LOG_BADGE_WIDTH);
    $separator = $emptyLabel . "│\n"
               . str_repeat('─', $LOG_BADGE_WIDTH) . '┼'
               . str_repeat('─', $LOG_WIDTH + 3) . "\n"
               . $emptyLabel . "│\n";

    $label = mbTrim($label);
    if ($label === $_echoPreviousLabel) {
        $labelLines = [];
    } else {
        $labelLines = mbSplitLines($label);
    }
    if (isset($labelLines[0])) {
        $labelLines[0] = buildFirstLineLabel($vpnI, $labelLines[0]);
        $label =  implode("\n", $labelLines);
    }
    $_echoPreviousLabel = $label;

    // Split long lines
    $messageLines = [];
    $pos = 0;
    $line = '';
    while ($pos < mb_strlen($message)) {
        $c = mb_substr($message, $pos, 1);

        if ($c === PHP_EOL) {
            $messageLines[] = $line;
            $line = '';
        } else if (mb_strlen($line) > $LOG_WIDTH) {
            $messageLines[] = $line;
            $line = '>   ' . $c;
        } else {
            $line .= $c;
        }

        $pos++;
    }
    $messageLines[] = $line;

    // ---------- Output ----------
    if (count($labelLines)  ||  $forceBadge) {
        echo $separator;
    }

    foreach ($messageLines as $i => $line) {
        $label = $labelLines[$i] ?? '';
        if ($label) {
            $label = ' ' . $label;
            $label = substr($label, 0, $LOG_BADGE_WIDTH - 2);
            $label = str_pad($label, $LOG_BADGE_WIDTH);
        } else {
            $label = $emptyLabel;
        }

        echo $label . '│  ' . $line;

        if (
                 $i !== array_key_last($messageLines)
            ||  ($i === array_key_last($messageLines)  &&  !$noNewLineInTheEnd)
        ) {
            echo "\n";
        }
    }
}

function _die($message)
{
    echo Term::red;
    echo Term::bold;
    echo "\n\n\nCRITICAL ERROR: $message\n\n\n";
    echo Term::clear;
    waitForOsSignals(3600);
    die();
}

function randomArrayItem(array $array, int $quantity = 1)
{
    $randomKeys = array_rand($array, $quantity);
    if (is_array($randomKeys)) {
        $ret = [];
        foreach ($randomKeys as $randomKey) {
            $ret[$randomKey] = $array[$randomKey];
        }
        return $ret;
    } else if (isset($array[$randomKeys])) {
        return $array[$randomKeys];
    } else {
        return $randomKeys;
    }
}

function debugPosix($phpProcess)
{
    $label = str_pad("Script process PID/PGID", 30);
    $ret = $label . posix_getpid() . '/'
        . posix_getpgid(posix_getpid()) . "\n";

    $processStatus = proc_get_status($phpProcess);
    $pid = $processStatus['pid'];
    $pGid = posix_getpgid($pid);

    $label = str_pad("Subprocess PID/PGID", 30);
    $ret .= $label . "$pid/$pGid";
    return $ret;
}

function waitForOsSignals($floatSeconds)
{
    global $LONG_LINE, $LOG_BADGE_WIDTH;
    $intSeconds = floor($floatSeconds);
    $nanoSeconds = ($floatSeconds - $intSeconds) * pow(10, 9);
    //echo "$intSeconds $nanoSeconds\n";
    $r = pcntl_sigtimedwait([SIGTERM, SIGINT], $info,  $intSeconds,  $nanoSeconds);
    if (gettype($r) === 'integer'  &&  $r > 0) {
        echo chr(7);
        echo "\n\n$LONG_LINE\n\n";
        echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "OS signal #$r received\n";
        echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Termination process started\n";
        terminateSession();
        echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "The script exited\n\n";
        exit(0);
    }
}

function sayAndWait($seconds)
{
    if ($seconds > 3) {
        $clearSecond = 3;
        $message  = Term::green . "\nThe author of this virtual machine keeps working to improve it\n"
                  . "Please, visit the Google Drive share at least once daily, to download new versions\n"
                  . Term::underline . "https://drive.google.com/drive/folders/1273-asI_FZKYVceXgTytxiHID9CtgIa7" . Term::noUnderline
                  . "\n\nWaiting $seconds seconds. Press Ctrl+C " . Term::bold . "now" . Term::noBold . ", if you want to terminate this script (correctly)" . Term::clear;

        $efficiencyMessage = Efficiency::getMessage();
        if ($efficiencyMessage) {
            $message = "\n{$efficiencyMessage}$message";
        }
    } else {
        $clearSecond = 0;
        $message = "";
    }

    echo $message;
    waitForOsSignals($seconds - $clearSecond);
    Term::removeMessage($message);
    waitForOsSignals($clearSecond);
}

function writeStatistics()
{
    global $TEMP_DIR;
    $statisticsLog = $TEMP_DIR . '/statistics-log.txt';
    ksort(OpenVpnConnection::$openVpnConfigFilesUsed);
    ksort(OpenVpnConnection::$openVpnConfigFilesWithError);
    ksort(OpenVpnConnection::$vpnPublicIPsUsed);
    $statistics  = print_r(OpenVpnConnection::$openVpnConfigFilesUsed, true) . "\n\n";
    $statistics .= print_r(OpenVpnConnection::$openVpnConfigFilesWithError, true) . "\n\n";
    $statistics .= print_r(OpenVpnConnection::$vpnPublicIPsUsed, true) . "\n\n";
    file_put_contents($statisticsLog, $statistics);
}

function getDockerConfig()
{
    $cpus = 0;
    $memory = 0;
    $config = @file_get_contents(__DIR__ . '/docker.config');
    if (! $config) {
        return false;
    }

    $cpusRegExp = <<<PhpRegExp
        #cpus=(\d+)#
        PhpRegExp;
    if (preg_match(trim($cpusRegExp), $config, $matches) === 1) {
        $cpus = (int) $matches[1];
    }

    $memoryRegExp = <<<PhpRegExp
        #memory=(\d+)#
        PhpRegExp;
    if (preg_match(trim($memoryRegExp), $config, $matches) === 1) {
        $memory = (int) $matches[1];
    }

    if (!$cpus  ||  !$memory) {
        return false;
    }

    return [
        'cpus'   => $cpus,
        'memory' => $memory
    ];
}

function getRAMCapacity()
{
    $regExp = <<<PhpRegExp
              #MemTotal:\s+(\d+)\s+kB#  
              PhpRegExp;

    $r = file_get_contents('/proc/meminfo');
    if (preg_match(trim($regExp), $r, $matches) === 1) {
        $sizeBytes = (int) $matches[1] * 1024;
        $sizeGB = round($sizeBytes / (1024 * 1024 * 1024), 1);
        return $sizeGB;
    }
}

function getCPUQuantity()
{
    $regExp = <<<PhpRegExp
              #CPU\(s\):\s+(\d+)#  
              PhpRegExp;

    $r = shell_exec('lscpu');
    if (preg_match(trim($regExp), $r, $matches) === 1) {
        return (int) $matches[1];
    }
    return $r;
}

function clearTotalEfficiencyLevel($keepPrevious = false)
{
    global $TOTAL_EFFICIENCY_LEVEL, $TOTAL_EFFICIENCY_LEVEL_PREVIOUS;
    if ($keepPrevious) {
        $TOTAL_EFFICIENCY_LEVEL_PREVIOUS = $TOTAL_EFFICIENCY_LEVEL;
    } else {
        $TOTAL_EFFICIENCY_LEVEL_PREVIOUS = null;
    }

    $TOTAL_EFFICIENCY_LEVEL = null;
}

function file_put_contents_secure(string $filename, $data, int $flags = 0, $context = null)
{
    global $NEW_DIR_ACCESS_MODE, $NEW_FILE_ACCESS_MODE;
    $dir = mbDirname($filename);
    @mkdir($dir, $NEW_DIR_ACCESS_MODE, true);
    file_put_contents($filename, 'nothing');
    chmod($filename, $NEW_FILE_ACCESS_MODE);
    file_put_contents($filename, $data, $flags, $context);
}

// ps -p 792 -o args                         Command line by pid
// ps -o pid --no-heading --ppid 792         Children pid by parent
// ps -o ppid= -p 1167                       Parent pid by current pid
// pgrep command                             Find pid by command
// ps -e -o pid,pri,cmd | grep command       Check process priority
// ps -efj | grep 2428