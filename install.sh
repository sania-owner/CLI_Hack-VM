#!/bin/bash
sudo apt-get update
sudo git clone https://github.com/sania-owner/CLI_Hack-VM.git "$HOME"/DDOS
sudo apt -y install  procps kmod iputils-ping wget php-cli php-mbstring openvpn
ln -s /media "$HOME"/DDOS
sudo chmod -R 0777 /media; sudo chmod -R 0777 "$HOME"/DDOS
rm /root/install.sh
