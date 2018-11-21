#!/bin/bash
IP="$1"
sudo iptables -t mangle -C PREROUTING -s $IP -j MARK --set-mark 3 >/dev/null 2>&1
echo $?
