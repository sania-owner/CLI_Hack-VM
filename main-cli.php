#!/usr/bin/env php
<?php

require_once __DIR__ . '/init.php';
global $PARALLEL_VPN_CONNECTIONS_COUNT,
       $MAX_FAILED_VPN_CONNECTIONS_COUNT,
       $PING_INTERVAL,
       $ONE_VPN_SESSION_DURATION,
       $CONNECT_SIMULTANEOUSLY,
       $TERM,
       $LONG_LINE,
       $TOTAL_EFFICIENCY_LEVEL;

while (true) {

    initSession();

    // ------------------- Checking for openvpv and db1000n processes, which may stall in memory since last session -------------------

    $checkProcessesCommands = [
        'ps -aux | grep db1000n',
        'ps -aux | grep openvpn',
    ];
    foreach ($checkProcessesCommands as $checkProcessCommand) {
        $r = shell_exec($checkProcessCommand . ' 2>&1');
        $lines = mbSplitLines((string) $r);
        foreach ($lines as $line) {
            if (strpos($line, 'grep') === false) {
                echo "$line\n";
            }
        }
    }
    shell_exec('killall openvpn   2>&1');

    // ------------------- Start VPN connections and Hack applications -------------------

    $connectingStartedAt = time();
    $vpnConnections = [];
    $vpnConnectionsPortion = [];
    $failedVpnConnectionsCount = 0;
    $tunDeviceIndex = OpenVpnConnection::getNextTunDeviceIndex(9);

    do {
        // ------------------- Start a portion of VPN connections simultaneously -------------------

        $debugPortion = false;
        if ($debugPortion) {
            echo "\nportion-start";
        }

        $vpnConnectionsPortion = [];
        for ($connectionIndex = 0; $connectionIndex < $CONNECT_SIMULTANEOUSLY; $connectionIndex++) {
            $vpnConnectionsPortion[$connectionIndex] = new OpenVpnConnection($tunDeviceIndex);
            $tunDeviceIndex = OpenVpnConnection::getNextTunDeviceIndex($tunDeviceIndex);

            if (count($vpnConnections) + count($vpnConnectionsPortion) >= $PARALLEL_VPN_CONNECTIONS_COUNT) {
                break;
            }

            if (count($vpnConnections) + $failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_COUNT) {
                break;
            }
        }

        while (true) {
            // ------------------- Watch portion connection state -------------------

            foreach ($vpnConnectionsPortion as $k => $vpnConnection) {
                sayAndWait(7);
                $vpnState = $vpnConnection->processConnection();

                if ($debugPortion) {
                    echo "\nportion [" . implode(', ', array_keys($vpnConnectionsPortion)) . "] key{$k}";
                }
                switch (true) {

                    case ($vpnState === -1):
                        // this vpnConnection failed to connect
                        unset($vpnConnectionsPortion[$k]);
                        $failedVpnConnectionsCount++;

                        if ($debugPortion) {
                            echo " failed";
                        } else {
                            echo "$LONG_LINE\n\n";
                            echo Term::red;
                            echo $vpnConnection->getLog();
                            echo "\n\n";
                            echo Term::clear;
                        }
                        break;


                    case ($vpnState === true):
                        // this vpnConnection was connected
                        unset($vpnConnectionsPortion[$k]);

                        if ($debugPortion) {
                            echo " connected";
                        } else {
                            echo "$LONG_LINE\n\n";
                            echo $vpnConnection->getLog();
                            echo "\n\n";
                        }

                        // Launch Hack Application
                        $hackApplication = new HackApplication();
                        $appState = $hackApplication->processLaunch($vpnConnection->getNetnsName());
                        if ($appState === -1) {
                            // App launch failed
                            $vpnConnection->terminate();

                            if ($debugPortion) {
                                echo " launch-failed";
                            } else {
                                echo Term::red;
                                echo $hackApplication->getLog();
                                echo "\n\n";
                                echo Term::clear;
                            }
                        } else if ($appState === true) {
                            // App launch successful
                            $vpnConnection->setApplicationObject($hackApplication);
                            $vpnConnections[] = $vpnConnection;

                            if ($debugPortion) {
                                echo " launched";
                            } else {
                                echo $hackApplication->getLog();
                                echo "\n\n";
                                $hackApplication->clearLog();
                                $hackApplication->setReadHackApplicationOutput(true);
                            }
                        }
                        break;
                }
            }

            if ($debugPortion) {
                echo "\nend portion iteration";
            }

            if (count($vpnConnectionsPortion) === 0) {
                break;
            }

        }

        if ($debugPortion) {
            echo "\nvpnConnectionsSize " . count($vpnConnections);
        }

        if ($failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_COUNT) {
            if (count($vpnConnections)) {
                echo Term::red;
                echo "\nReached " . count($vpnConnections) . " of $PARALLEL_VPN_CONNECTIONS_COUNT VPN connections, because $failedVpnConnectionsCount attempts failed\n\n";
                echo Term::clear;
                break;
            } else {
                echo Term::red;
                echo "\nNo VPN connections were established. $failedVpnConnectionsCount attempts failed\n\n";
                echo Term::clear;
                goto finish;
            }
        }

    } while (count($vpnConnections) < $PARALLEL_VPN_CONNECTIONS_COUNT);

    $connectingDuration = time() - $connectingStartedAt;
    $connectingDurationMinutes = floor($connectingDuration / 60);
    $connectingDurationSeconds = $connectingDuration - ($connectingDurationMinutes * 60);
    echo "\n" . count($vpnConnections) . " connections established during {$connectingDurationMinutes}min {$connectingDurationSeconds}sec\n\n";



    // ------------------- Watch VPN connections and Hack applications -------------------
    $vpnSessionStartedAt = time();
    $lastPing = time();
    while (true) {

        Efficiency::newIteration();
        foreach ($vpnConnections as $connectionIndex => $vpnConnection) {

            // ------------------- Echo the Hack applications output -------------------
            $vpnName = $vpnConnection->getVpnName();
            $hackApplication = $vpnConnection->getApplicationObject();
            $hackApplicationOutput = trim($hackApplication->getLog());
            $country = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
            $label = $country ?? "\n";
            $connectionEfficiencyLevel = $hackApplication->getEfficiencyLevel();
            Efficiency::addValue($connectionEfficiencyLevel);
            if ($hackApplicationOutput) {
                if (count(mbSplitLines($hackApplicationOutput)) > 3) {
                   $label .= "\n$vpnName";
                   if ($connectionEfficiencyLevel !== null) {
                       $label .="\nResponse rate   $connectionEfficiencyLevel%";
                   }
                }
                _echo($connectionIndex, $label, $hackApplicationOutput);
                $hackApplication->clearLog();
                sayAndWait(10);
            } else {
                //echo "empty\n";
                sayAndWait(1);
            }

            // ------------------- Check the Hack applications alive state -------------------
            if (! $hackApplication->isAlive()) {
                $exitCode = $hackApplication->getExitCode();
                _echo($connectionIndex, $country, "\n\nApplication " . ($exitCode === 0 ? 'was terminated' : 'died with exit code ' . $exitCode));
                $hackApplication->terminate(true);
                sayAndWait(1);
                $vpnConnection->terminate(true);
                unset($vpnConnections[$connectionIndex]);
                if (count($vpnConnections) === 0) {
                    goto finish;
                }
            }
        }

        // ------------------- Check VPN pings -------------------
        if ($lastPing + $PING_INTERVAL < time()) {

            foreach ($vpnConnections as $connectionIndex => $vpnConnection) {
                $hackApplication = $vpnConnection->getApplicationObject();
                $country = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
                $vpnName = $vpnConnection->getVpnName();
                $vpnNamePadded = str_pad($vpnName, 50);

                _echo($connectionIndex, $country, "$vpnNamePadded ping?", true, true);
                $ping = $vpnConnection->checkPing();

                echo str_repeat(chr(8), 5);
                if ($ping) {
                    echo "[Ping OK]\n";
                } else {
                    echo "[Ping timeout]\n";
                }
            }

            $lastPing = time();
        }

        // ------------------- Check session duration -------------------
        $vpnSessionTimeElapsed = time() - $vpnSessionStartedAt;
        if ($vpnSessionTimeElapsed > $ONE_VPN_SESSION_DURATION) {
            goto finish;
        }
    }


    finish:
    terminateSession();
    echo "\n\n\n───────────────────────────────────────────── SESSION FINISHED ─────────────────────────────────────────────\n\n\n\n\n\n\n";
    sayAndWait(30);

}
    



    /*$i = 0;
    do {
        while (true) {
            // ------------------- Start a VPN connection -------------------

            $vpnConnection = new OpenVpnConnection(true);
            if ($vpnConnection->isConnected()) {
                $failedVpnConnectionsCount = 0;
                break;
            } else {
                $failedVpnConnectionsCount++;
                // This pause is to let broken TCP connections be closed
                sayAndWait(30);

                if ($failedVpnConnectionsCount >= $MAX_FAILED_VPN_CONNECTIONS_COUNT) {
                    // To many failed VPN connections
                    if (count($vpnConnections)) {
                        // But some connections were established. Let's continue with what we have
                        break 2;
                    } else {
                        // No VPN connections were established
                        _die("Unfortunately, no VPN connections were established.\n"
                           . "Please, check your credentials.txt. Fix your VPN and reboot the virtual machine");
                    }
                }
            }
        }
        $vpnConnections[$i] = $vpnConnection;
        echo "\n";

        // ------------------- Launch a Hack application -------------------

        $hackApplication = new HackApplication($vpnConnection->getNetnsName());
        $hackApplications[$i] = $hackApplication;
        echo "\n";
        sayAndWait(15); // This should reduce db1000n memory burst, which happens after launch

        $i++;
    } while ($i < $PARALLEL_VPN_CONNECTIONS_COUNT);*


    // ------------------- Watch VPN connections and Hack applications -------------------
    $vpnSessionStartedAt = time();
    $lastPing = time();
    while (true) {

        foreach ($hackApplications as $i => $hackApplication) {

            // ------------------- Echo the Hack applications output -------------------
            $vpnConnection = $vpnConnections[$i];
            $country = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
            $hackApplicationOutput = trim($hackApplication->readOutputLines());
            if ($hackApplicationOutput) {
                _echo($i, $country, $hackApplicationOutput);
                sayAndWait(10);
            }

            // ------------------- Check the Hack applications alive state -------------------
            if (! $hackApplication->isAlive()) {
                $exitCode = $hackApplication->getExitCode();
                _echo($i, $country, "\n\nApplication " . ($exitCode === 0 ? 'was terminated' : 'died with exit code ' . $exitCode));
                $hackApplication->terminate(true);
                sayAndWait(1);
                $vpnConnection->terminate(true);
                unset($vpnConnections[$i]);
                unset($hackApplications[$i]);
                if (count($vpnConnections) === 0) {
                    goto finish;
                }
            }
        }

        // ------------------- Check VPN pings -------------------
        if ($lastPing + $PING_INTERVAL < time()) {

            foreach ($vpnConnections as $i => $vpnConnection) {
                $hackApplication = $hackApplications[$i];
                $country = $hackApplication->getCurrentCountry()  ??  $vpnConnection->getVpnPublicIp();
                $vpnName = $vpnConnection->getVpnName();
                $vpnNamePadded = str_pad($vpnName, 50);

                _echo($i, $country, "$vpnNamePadded ping?", true, true);
                $ping = $vpnConnection->checkPing();

                echo str_repeat(chr(8), 5);
                if ($ping) {
                    echo "[Ping OK]\n";
                } else {
                    echo "[Ping timeout]\n";
                }
            }

            $lastPing = time();
        }

        // ------------------- Check session duration -------------------
        $vpnSessionTimeElapsed = time() - $vpnSessionStartedAt;
        if ($vpnSessionTimeElapsed > $ONE_VPN_SESSION_DURATION) {
            goto finish;
        }
    }

    finish:
    terminateSession();
    echo "\n\n\n───────────────────────────────────────────── SESSION FINISHED ─────────────────────────────────────────────\n\n\n\n\n\n\n";
    sayAndWait(30);
}*/

function terminateSession()
{
   /* global $hackApplications, $vpnConnections, $LOG_BADGE_WIDTH;
    if (is_array($hackApplications)  &&  count($hackApplications)) {
        foreach ($hackApplications as $i => $hackApplication) {
            $hackApplication->terminate();
            unset($hackApplications[$i]);
        }
        echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Waiting 10 seconds\n"; sleep(10);
    }

    if (is_array($vpnConnections)  &&  count($vpnConnections)) {
        foreach ($vpnConnections as $i => $vpnConnection) {
            $vpnConnection->terminate();
            unset($vpnConnections[$i]);
        }
    }*/

    global $LOG_BADGE_WIDTH, $vpnConnectionsPortion, $vpnConnections;

    //echo count($vpnConnectionsPortion);
    //echo count($vpnConnections);

    if (is_array($vpnConnectionsPortion)  &&  count($vpnConnectionsPortion)) {
        foreach ($vpnConnectionsPortion as $connectionIndex => $vpnConnection) {
            $vpnConnection->terminate();
            unset($vpnConnectionsPortion[$connectionIndex]);
        }
    }

    if (is_array($vpnConnections)  &&  count($vpnConnections)) {
        foreach ($vpnConnections as $vpnConnection) {
            $hackApplication = $vpnConnection->getApplicationObject();
            $hackApplication->setReadHackApplicationOutput(false);
            $hackApplication->clearLog();
            $hackApplication->terminate();
            echo $hackApplication->getLog();
        }
    }

    echo str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "Waiting 10 seconds\n"; sleep(10);

    if (is_array($vpnConnections)  &&  count($vpnConnections)) {
        foreach ($vpnConnections as $connectionIndex => $vpnConnection) {
            $vpnConnection->clearLog();
            $vpnConnection->terminate();
            echo $vpnConnection->getLog();
            unset($vpnConnections[$connectionIndex]);
        }
    }

    writeStatistics();
}