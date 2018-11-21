#!/bin/bash
IP="$1"
sudo iptables -t mangle -D PREROUTING -s $IP -j MARK --set-mark 3
sudo sh -c "iptables-save > /etc/iptables.ipv4.nat"
