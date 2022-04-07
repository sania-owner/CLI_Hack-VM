<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions-mb-string.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Efficiency.php';
require_once __DIR__ . '/open-vpn/OpenVpnConnection.php';
require_once __DIR__ . '/HackApplication.php';

$IS_DOCKER = file_exists(__DIR__ . '/docker.flag');

//------------------------------------------------------------------------------------------------

$ONE_VPN_SESSION_DURATION = 15 * 60;
$PING_INTERVAL = 5 * 60;
$LOG_WIDTH = 113;
$LOG_BADGE_WIDTH = 23;
$REDUCE_DB1000N_OUTPUT = true;
$LONG_LINE = str_repeat('─', $LOG_WIDTH + $LOG_BADGE_WIDTH);

$NEW_DIR_ACCESS_MODE = 0770;
$NEW_FILE_ACCESS_MODE = 0660;
$TEMP_DIR = '/tmp/hack-linux';
@mkdir($TEMP_DIR, $NEW_DIR_ACCESS_MODE, true);

// https://en.wikipedia.org/wiki/ANSI_escape_code
// https://stackoverflow.com/questions/4842424/list-of-ansi-color-escape-sequences

//-------------------------------------------------------

function initSession()
{
    global $IS_IN_DOCKER,
           $PARALLEL_VPN_CONNECTIONS_COUNT,
           $CPU_QUANTITY,
           $OS_RAM_CAPACITY,
           $CONNECT_SIMULTANEOUSLY,
           $MAX_FAILED_VPN_CONNECTIONS_COUNT,
           $IS_IN_DOCKER;

    passthru('reset');  // Clear console
    echo "Staring new session ...\n";


    if (($config = getDockerConfig())) {
        $IS_IN_DOCKER = true;
        echo "Docker container detected ...\n";
        $OS_RAM_CAPACITY = $config['memory'];
        $CPU_QUANTITY = $config['cpus'];
    } else {
        $IS_IN_DOCKER = false;
        $OS_RAM_CAPACITY = getRAMCapacity();
        $CPU_QUANTITY = getCPUQuantity();
    }

    if ($CPU_QUANTITY < 1) {
        _die("Script detected 0 CPU cores, something went wrong");
    }

    if ($OS_RAM_CAPACITY < 1.2) {
        _die("Virtual machine has not enough RAM memory. Minimum 1.2GiB is required, {$OS_RAM_CAPACITY}GiB found");
    }

    $PARALLEL_VPN_CONNECTIONS_COUNT = round(($OS_RAM_CAPACITY - 1) * 4);
    $CONNECT_SIMULTANEOUSLY = $CPU_QUANTITY * 5;
    $MAX_FAILED_VPN_CONNECTIONS_COUNT = max(10, $CONNECT_SIMULTANEOUSLY);

    if ($OS_RAM_CAPACITY < 3.7) {
        echo "Your virtual machine has reduced amount of RAM memory: {$OS_RAM_CAPACITY}GiB\n";
        echo "If it is possible, increase this value to 4GiB\n";
        echo "It will increase the quantity of simultaneous VPN connections, and overall attack efficiency\n";
        echo "Now only {$PARALLEL_VPN_CONNECTIONS_COUNT} simultaneous VPN connection(s) are possible\n\n";
        sleep(15);
    } else {
        echo "Virtual machine has {$OS_RAM_CAPACITY}GiB of RAM memory and $CPU_QUANTITY virtual CPU core(s).\n"
            . "{$PARALLEL_VPN_CONNECTIONS_COUNT} simultaneous VPN connection(s) will be established\n\n";

        $requireCpusQuantity = ceil($PARALLEL_VPN_CONNECTIONS_COUNT / 16);
        if ($requireCpusQuantity > $CPU_QUANTITY) {
            _die("To run {$PARALLEL_VPN_CONNECTIONS_COUNT} simultaneous VPN connection(s) your virtual machine require\n"
                . "$requireCpusQuantity virtual CPU cors, but only $CPU_QUANTITY were found");
        }
    }

    Efficiency::clear();
    OpenVpnConnection::init();
}