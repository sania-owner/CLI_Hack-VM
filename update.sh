#!/bin/bash

/root/DDOS/stop.sh

rm -rf /root/DDOS
cd /root; wget https://raw.githubusercontent.com/sania-owner/CLI_Hack-VM/main/install.sh; sudo chmod ugo+x install.sh; sh ./install.sh
rm /root/update.sh
