#!/bin/bash
sudo apt-get update
sudo git clone https://github.com/sania-owner/CLI_Hack-VM.git /root/DDOS
sudo apt -y install  procps kmod iputils-ping wget php-cli php-mbstring openvpn
ln -s /media /root/DDOS
sudo chmod -R 0777 /media; sudo chmod -R 0777 /root"/DDOS
rm /root/install.sh
