<?php

class OpenVpnConnection
{
    const VPN_CONNECT_TIMEOUT = 30;

    private $connectionStartedAt,
            $openVpnConfigFile,
            $openVpnConfigFileDir,
            $vpnName,
            $vpnProcess,
            $tunDeviceIndex,
            $applicationObject,
            $vpnProcessPGid,
            $pipes,
            $log,
            $instantLog,
            $vpnClientIp,
            $vpnNetmask,
            $vpnNetwork,
            $vpnGatewayIp,
            $vpnDnsServers,
            $vpnPublicIp,
            $netnsName,
            $netInterface,
            $resolveFileDir,
            $resolveFilePath,
            $wasConnected = false,
            $connectionFailed = false,
            $credentialsFileTrimmed,

                                                                  $test;

    private static $openVpnConfigFiles = [],
                   $openVpnConfigFilesCount = 0,
                   $openVpnConfigFilesSortedByDir = [],
                   $openVpnConfigFilesInUse = [],
                   $UP_SCRIPT = __DIR__ . '/open-vpn-up-cli.php';

    public static $openVpnConfigFilesUsed = [],
                  $openVpnConfigFilesWithError = [],
                  $vpnPublicIPsUsed = [];

    public function __construct($tunDeviceIndex)
    {
        $this->connectionStartedAt = time();
        $this->pickOpenVpnConfigFile();
        $this->tunDeviceIndex = $tunDeviceIndex;
        $this->netInterface = 'tun' . $this->tunDeviceIndex;

        $this->log("Connecting VPN {$this->vpnName}");
        $credentialsArgs = $this->getCredentialsArgs();
        $vpnCommand = "/usr/sbin/openvpn  --config \"{$this->openVpnConfigFile}\"  --ifconfig-noexec  --route-noexec  --script-security 2  --route-up " . static::$UP_SCRIPT . "  --dev-type tun --dev {$this->netInterface} $credentialsArgs  2>&1";
        $this->log($vpnCommand);
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a") // stderr
        );

        $this->vpnProcess = proc_open($vpnCommand, $descriptorSpec, $this->pipes);
        if (!is_resource($this->vpnProcess)) {
            _die('Command failed: ' . $vpnCommand);
        }
        $this->changePGid();
        $this->log(debugPosix($this->vpnProcess));
        stream_set_blocking($this->pipes[1], false);

    }

    public function processConnection()
    {
        global $OPEN_VPN_UP_ENV_FILE;

        if ($this->connectionFailed) {
            return -1;
        }

        if ($this->wasConnected) {
            return true;
        }

        $stdOutLines = streamReadLines($this->pipes[1], 0.1);
        if ($stdOutLines) {
            $this->log($stdOutLines, true);
        }

        if ($this->isAlive() !== true) {
            $this->connectionFailed = true;
            $this->terminate(true);
            return -1;
        }

        if (strpos($stdOutLines,'SIGTERM') !== false) {
            $this->connectionFailed = true;
            $this->terminate(true);
            return -1;
        }

        if (strpos($this->log, 'Initialization Sequence Completed') !== false) {

            $envJson = @file_get_contents(static::getEnvFilePath($this->netInterface));
            //echo "$envJson\n\n";
            $env = json_decode($envJson, true);
            @unlink($OPEN_VPN_UP_ENV_FILE);

            $this->vpnClientIp = $env['ifconfig_local'] ?? '';
            $this->vpnGatewayIp = $env['route_vpn_gateway'] ?? '';
            $this->vpnNetmask = $env['ifconfig_netmask'] ?? '255.255.255.255';
            $this->vpnNetwork = long2ip(ip2long($this->vpnGatewayIp) & ip2long($this->vpnNetmask));
            $this->netnsName = 'netc' . $this->tunDeviceIndex;

            $this->vpnDnsServers = [];
            $dnsRegExp = <<<PhpRegExp
                             #dhcp-option\s+DNS\s+([\d\.]+)#  
                             PhpRegExp;
            $i = 1;
            while ($foreignOption = $env['foreign_option_' . $i] ?? false) {
                if (preg_match(trim($dnsRegExp), $foreignOption, $matches) === 1) {
                    $this->vpnDnsServers[] = trim($matches[1]);
                }
                $i++;
            }

            $this->log("\nnetInterface " . $this->netInterface);
            $this->log('vpnClientIp ' . $this->vpnClientIp);
            $this->log('vpnGatewayIp ' . $this->vpnGatewayIp);
            $this->log('vpnNetmask /' . $this->vpnNetmask);
            $this->log('vpnNetwork ' . $this->vpnNetwork);
            $this->log('vpnDnsServers ' . implode(', ', $this->vpnDnsServers));
            $this->log("netnsName " . $this->netnsName . "\n");

            if (!(
                $this->netInterface
                &&  $this->vpnClientIp
                &&  $this->vpnNetmask
                &&  $this->vpnGatewayIp
                &&  $this->vpnDnsServers
                &&  $this->vpnNetwork
            )) {
                $this->log("Failed to get VPN config");
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            }

            shell_exec("ip netns delete {$this->netnsName}  2>&1");
            $commands = [
                "ip netns add {$this->netnsName}",
                "ip link set dev {$this->netInterface} up netns {$this->netnsName}",
                "ip netns exec {$this->netnsName}  ip addr add {$this->vpnClientIp}/32 dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add {$this->vpnNetwork}/{$this->vpnNetmask} dev {$this->netInterface}",
                "ip netns exec {$this->netnsName}  ip route add default dev {$this->netInterface} via {$this->vpnGatewayIp}",
                "ip netns exec {$this->netnsName}  ip addr show",
                "ip netns exec {$this->netnsName}  ip route show"
            ];

            foreach ($commands as $command) {
                $r = shell_exec("$command 2>&1");
                $this->log($r, !strlen($r));
            }

            //------------

            $this->resolveFileDir = "/etc/netns/{$this->netnsName}";
            $this->resolveFilePath = $this->resolveFileDir . "/resolv.conf";
            if (! is_dir($this->resolveFileDir)) {
                mkdir($this->resolveFileDir, 0775, true);
            }

            $this->vpnDnsServers[] = '8.8.8.8';
            $this->vpnDnsServers = array_unique($this->vpnDnsServers);
            $nameServersList  = array_map(
                function ($ip) {
                    return "nameserver $ip";
                },
                $this->vpnDnsServers
            );
            $nameServersListStr = implode("\n", $nameServersList);
            file_put_contents($this->resolveFilePath, $nameServersListStr);

            $this->log(shell_exec("ip netns exec {$this->netnsName}  cat /etc/resolv.conf   2>&1") . "\n");

            //------------


            if (! $this->checkPing()) {
                $this->log("VPN tunnel Ping failed!");
                $this->connectionFailed = true;
                $this->terminate(true);
                return -1;
            } else {
                $this->log("VPN tunnel Ping OK");
                $this->vpnPublicIp = trim(shell_exec("ip netns exec {$this->netnsName}   wget -qO- http://ipecho.net/plain   2>&1"));
                if (preg_match('#[^\d\.]#', $this->vpnPublicIp, $matches) > 0) {
                    $this->log("\"http://ipecho.net/plain\" returned non IP address.\n"
                        . "Possibly your VPN is returning it's own HTML in any HTTP request\n"
                        . "Which sometimes happens, if something is wrong with your subscription/credentials");
                    $this->connectionFailed = true;
                    $this->terminate(true);
                    return -1;
                }
                $this->log("Detected VPN public IP " . $this->vpnPublicIp);
            }

            $this->wasConnected = true;
            return true;
        }

        // Check timeout
        $timeElapsed = time() - $this->connectionStartedAt;
        if ($timeElapsed > static::VPN_CONNECT_TIMEOUT) {
            $this->log("VPN Timeout");
            $this->terminate(true);
            return -1;
        }

        return false;
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

    public function getLog()
    {
        return $this->log;
    }

    public function getVpnName()
    {
        return $this->vpnName;
    }

    public function getNetnsName()
    {
        return $this->netnsName;
    }

    public function getVpnPublicIp()
    {
        return $this->vpnPublicIp;
    }

    public function setApplicationObject($applicationObject)
    {
        $this->applicationObject = $applicationObject;
    }

    public function getApplicationObject()
    {
        return $this->applicationObject;
    }

    public function terminate($hasError = false)
    {
        global $OPEN_VPN_UP_ENV_FILE,  $LOG_BADGE_WIDTH;
        $this->log(str_repeat(' ', $LOG_BADGE_WIDTH + 3) . "OpenVpnConnection SIGTERM PGID -{$this->vpnProcessPGid}");

        posix_kill(0 - $this->vpnProcessPGid, SIGTERM);
        unset(static::$openVpnConfigFilesInUse[$this->openVpnConfigFile]);
        shell_exec("ip netns delete {$this->netnsName}  2>&1");
        @unlink($this->resolveFilePath);
        @rmdir($this->resolveFileDir);
        @unlink($this->credentialsFileTrimmed);
        @unlink($OPEN_VPN_UP_ENV_FILE);
        
        if ($hasError) {
            $count = static::$openVpnConfigFilesWithError[$this->openVpnConfigFile] ?? 0;
            $count++;
            static::$openVpnConfigFilesWithError[$this->openVpnConfigFile] = $count;
        } else {
            $count = static::$openVpnConfigFilesUsed[$this->openVpnConfigFile] ?? 0;
            $count++;
            static::$openVpnConfigFilesUsed[$this->openVpnConfigFile] = $count;
            
            if ($this->vpnPublicIp) {
                $count = static::$vpnPublicIPsUsed[$this->vpnPublicIp] ?? 0;
                $count++;
                static::$vpnPublicIPsUsed[$this->vpnPublicIp] = $count;
            }
        }
    }

    public function checkPing()
    {
        $r = shell_exec("ip netns exec {$this->netnsName} ping  -c 1  -w 5  8.8.8.8   2>&1");
        return mb_strpos($r, 'bytes from 8.8.8.8') !== false;
    }

    public function isAlive()
    {
        if (! is_resource($this->vpnProcess)) {
            return false;
        }

        $processStatus = proc_get_status($this->vpnProcess);
        return $processStatus['running'];
    }

    private function getCredentialsArgs()
    {
        global $TEMP_DIR;

        $ret = '';
        $credentialsFile = $this->openVpnConfigFileDir . '/credentials.txt';
        $this->credentialsFileTrimmed = $TEMP_DIR . '/credentials-trimmed-' . $this->netInterface . '.txt';

        if (file_exists($credentialsFile)) {
            $credentialsFileContent = mbTrim(file_get_contents($credentialsFile));

            $regexp = <<<PhpRegExp
                      [\n\r]+
                      PhpRegExp;
            $credentialsFileLines = mb_split(trim($regexp), $credentialsFileContent);

            $login = mbTrim($credentialsFileLines[0] ?? '');
            $password = mbTrim($credentialsFileLines[1] ?? '');
            if (!($login  &&  $password)) {
                _die("Credential file \"$credentialsFile\" has wrong content. It should contain two lines.\n"
                   . "First line - login, second line - password");
            }

            $trimmedContent = $login . "\n" . $password;
            file_put_contents_secure($this->credentialsFileTrimmed, $trimmedContent);
            $ret = "--auth-user-pass \"{$this->credentialsFileTrimmed}\"";
        }

        return $ret;
    }

    private function pickOpenVpnConfigFile()
    {
        while (true) {
            $randomDirPath = randomArrayItem(array_keys(static::$openVpnConfigFilesSortedByDir));
            $randomOpenVpnConfigFile = randomArrayItem(static::$openVpnConfigFilesSortedByDir[$randomDirPath]);

            if (! in_array($randomOpenVpnConfigFile, static::$openVpnConfigFilesInUse)) {
                static::$openVpnConfigFilesInUse[] = $randomOpenVpnConfigFile;
                break;
            }
        }

        $this->openVpnConfigFile = $randomOpenVpnConfigFile;
        $this->openVpnConfigFileDir = dirname($randomOpenVpnConfigFile);
        $this->vpnName = mbFilename($randomOpenVpnConfigFile);
    }

    private function changePGid()
    {
        $processStatus = proc_get_status($this->vpnProcess);
        $pid = $processStatus['pid'];
        posix_setpgid($pid, $pid);
        $this->vpnProcessPGid = posix_getpgid($pid);
    }

    // ----------------------  Static part of the class ----------------------

    public static function init()
    {
        global $PARALLEL_VPN_CONNECTIONS_COUNT, $TEMP_DIR;

        static::$openVpnConfigFilesInUse = [];
        static::$openVpnConfigFiles = getDirectoryFilesListRecursive('/media', 'ovpn');
        static::$openVpnConfigFilesCount = count(static::$openVpnConfigFiles);
        if (! static::$openVpnConfigFilesCount) {
            _die("NO *.ovpn files found in Shared Folders\n"
               . "Add a share folder with ovpn files and reboot this virtual machine");
        } else if (static::$openVpnConfigFilesCount <= $PARALLEL_VPN_CONNECTIONS_COUNT) {
            _die("To start $PARALLEL_VPN_CONNECTIONS_COUNT parallel VPN connections you need to have more then $PARALLEL_VPN_CONNECTIONS_COUNT .ovpn files\n"
               . "Currently only " . static::$openVpnConfigFilesCount  . " .ovpn files were found");
        }

        foreach (static::$openVpnConfigFiles as $openVpnConfigFile) {
            $dirPath = mbDirname($openVpnConfigFile);
            static::$openVpnConfigFilesSortedByDir[$dirPath][] = $openVpnConfigFile;
        }

    }

    public static function getEnvFilePath($netInterface)
    {
        global $TEMP_DIR;
        return $TEMP_DIR . "/open-vpn-env-{$netInterface}.txt";
    }

    /*private static function getNewNetnsName($prefix = 'netc')
    {
        $list = shell_exec("ip netns show   2>&1");
        $regex = "#" . preg_quote($prefix) . "(\d+)#u";
        $count = preg_match_all($regex, $list, $matches);
        $maxId = -1;
        for ($i = 0; $i < $count; $i++) {
            $id = $matches[1][$i];
            $maxId = max($maxId, $id);
        }
        return $prefix . ($maxId + 1);
    }*/

    public static function getNextTunDeviceIndex($curDeviceIndex)
    {
        $ipLinks = shell_exec('ip link show   2>&1');
        $i = $curDeviceIndex;
        do {
            $i++;
        } while (strpos($ipLinks, 'tun' . $i . ':') !== false);

        return $i;
    }

}