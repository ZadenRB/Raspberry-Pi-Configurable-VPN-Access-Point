# Raspberry Pi as a Wireless Access Point with a VPN, Configurable with a Web Interface
In this guide, we'll be setting up a Raspberry Pi 3B as a wireless access point with a VPN, which each user can enable/disable for themselves via a web interface.

### Installing Packages
First, we ensure that everything is up to date:
```
sudo apt-get update
sudo apt-get dist-upgrade
```
And install the necessary packages(The last 3 are only needed if you intend to set up the web interface):

    sudo apt-get install openvpn dnsmasq hostapd unzip apache2 php libapache2-mod-php

### Set up the VPN
In this example, we will be using [Private Internet Access](https://www.privateinternetaccess.com/) as my VPN provider, but as long as your VPN provider supports OpenVPN these steps should be essentially the same. We start by moving into the OpenVPN directory:

    cd /etc/openvpn
And getting the VPN's OpenVPN configuration files:

    sudo wget https://privateinternetaccess.com/openvpn/openvpn.zip
Now we use unzip to extract the files:

    sudo unzip openvpn.zip
The next step is for you to pick the configuration file you will be using for your location. In this example, we will use US West as the region. So, open up the configuration file:

    sudo nano "US West.ovpn"
and add the following lines to the bottom:
```
script-security 2
up /etc/openvpn/up.sh
```
We will now create `up.sh`:

    sudo nano up.sh
and paste the following:
```
#!/bin/bash
gateway=$(ip route show | grep -i 'default via' | awk '{print $3}')
mask=$(/sbin/ifconfig eth0 | grep 'inet ' | awk '{print $2}')
addr=$(/sbin/ifconfig eth0 | grep 'inet ' | awk '{print $4}')
ip rule add from $addr table 128
ip route add table 128 to $mask dev eth0
ip route add table 128 default via $gateway
ip route add default via $gateway dev eth0 table 3
```
Then, we set the script to be executable:

    sudo chmod +x up.sh
This sets up packet routing around the VPN when desired, and allows us to SSH into the Pi through the VPN. Before moving on, confirm that the VPN is working:

    sudo openvpn --config "/etc/openvpn/US West.ovpn"
You will be prompted to enter the username and password of your account. In order to automatically pass this information in so that the VPN can be run on startup, we will create a new file:

    sudo nano auth-pass.txt
and paste our account information in this format:
```
username
password
```
The following command will start the VPN:

    sudo openvpn --config "/etc/openvpn/US West.ovpn" --auth-user-pass /etc/openvpn/auth-pass.txt
To actually run the command on startup, we will edit the `rc.local` file:

    sudo nano /etc/rc.local
and add the above command just above `exit 0`.
### Setting up the Wireless Access Point
First we will configure `wlan0` using `dhcpcd`. Open it's configuration file:

    sudo nano /etc/dhcpcd.conf
We configure `wlan0` to have a static IP address by adding the following at the bottom of the file:
```
interface wlan0
    static ip_address=172.24.1.1/24
    nohook wpa_supplicant
```
And restart `dhcpcd`:
    
    sudo systemctl restart dhcpcd
With our static ip address set up, we can configure the DHCP server with `dnsmasq`. But first, move its original configuration file in case you need to restore it later:

    sudo mv /etc/dnsmasq.conf /etc/dnsmasq.conf.orig
Now fill the actual configuration file:

    sudo nano /etc/dnsmasq.conf
With the following(Go [here](http://www.thekelleys.org.uk/dnsmasq/doc.html) for documentation on the `dnsmasq` configuration file):
```
interface=wlan0
    bind-interfaces
    server=1.1.1.1
    bogus-priv
    domain-needed
    listen-address=172.24.1.1
    dhcp-range=172.24.1.2,172.24.1.50,255.255.255.0,12h
```
The next step is to configure our access point using `hostapd`. Create the configuration file:

    sudo nano /etc/hostapd/hostapd.conf
Then, paste the following(Go [here](https://w1.fi/cgit/hostap/plain/hostapd/hostapd.conf) for documentation on the `hostapd` configuration file):
```
interface=wlan0
driver=nl80211
ssid=Pi3-AP
hw_mode=g
country_code=US
channel=7
wpa=2
wpa_passphrase=raspberry
wpa_key_mgmt=WPA-PSK
wpa_pairwise=TKIP
rsn_pairwise=CCMP
```
We need to make sure that `hostapd` uses this configuration on startup, so edit its original configuration:

    sudo nano /etc/default/hostapd
and change the line `#DAEMON_CONF=""` to `DAEMON_CONF="/etc/hostapd/hostapd.conf"`. Our access point is almost ready, but we need to enable forwarding of packets before our access point can work:


    sudo nano /etc/sysctl.conf
and uncomment the line `#net.ipv4.ip_forward=1`, so it is `net.ipv4.ip_forward=1`. The final step to set up our access point is to share the connection from the VPN `tun0` to the WiFi `wlan0`.
```
sudo iptables -t nat -A POSTROUTING -o tun0 -j MASQUERADE  
sudo iptables -A FORWARD -i tun0 -o wlan0 -m state --state RELATED,ESTABLISHED -j ACCEPT  
sudo iptables -A FORWARD -i wlan0 -o tun0 -j ACCEPT
```
In order to allow users to connect directly through `eth0` to avoid the VPN, we also need to set up these rules for `eth0`.
```
sudo iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE  
sudo iptables -A FORWARD -i eth0 -o wlan0 -m state --state RELATED,ESTABLISHED -j ACCEPT  
sudo iptables -A FORWARD -i wlan0 -o eth0 -j ACCEPT
```
Now save these rules:

    sudo sh -c "iptables-save > /etc/iptables.ipv4.nat"
To have the rules be restored on reboot, once again edit `rc.local`

    sudo nano /etc/rc.local
and add the following just above `openvpn` line:

    iptables-restore < /etc/iptables.ipv4.nat
Now, run `sudo reboot` and once the Pi has restarted, try to connect to your access point to confirm that it is working(See the troubleshooting section if you have difficulties, or open and issue if it remains unresolved).
### Allowing Routing Around the VPN
We will be creating a web interface in order for users to toggle the VPN on and off. The basic setup, however, simply involves marking packets from a particular source(user) and creating a routing rule for marked packets in order to route them via `eth0` instead of `tun0`. First, we will add the rule for marked packets, and make sure to run the command when dhcpcd starts. Edit

    sudo nano /etc/dhcpcd.enter-hook
And write:

    ip rule add fwmark 3 table 3
To route traffic around the VPN, we must mark packets from a specific user. The command for a device with the IP of `172.24.1.126` would be:

    sudo iptables -t mangle -A PREROUTING -s 172.24.1.126 -j MARK --set-mark 3
This adds a rule to the `mangle` table in the `PREROUTING` chain(before any routing decisions have been made) to mark anything from the source `172.24.1.126` and marks it with 3. If you would like this rule to persist through reboots, run:

    sudo sh -c "iptables-save > /etc/iptables.ipv4.nat"
again to save the rule. If you don't mind manually running this command for each user who wants to disable the VPN, you can stop here. To re-enable the VPN for a user, run the same command, but replace `-A` with `-D` to delete the rule. If you want each user to be able to enable or disable the VPN for themselves, continue on.
### Setting up the Web Interface
We will use Apache for the web server, which we have already installed. check to confirm by visiting `172.24.1.1` from a device connected to your access point. It should display the default Apache web page. Before we edit this, we will create the directories necessary for our page:
```
sudo mkdir /var/www/scripts
sudo mkdir /var/www/html/img
```
Use the files provided above and place them into their respective folders. The last step is to allow apache to execute the bash scripts:

    sudo visudo
and add the line:

    www-data ALL=(ALL) NOPASSWD: ALL
Make sure to save to the actual `/etc/sudoers` file, not `/etc/sudoers.tmp`.
Now if you visit `172.24.1.1` the interface should be fully functioning. To check and ensure that the VPN is being enabled/disabled, use:

    curl ifconfig.me
and see if the IP is changing.
###Troubleshooting
#####Can connect to the access point, but quickly lose the connection
This issue may be due to `dnsmasq` attempting to start before `dhcpcd` has properly configured `wlan0`. To fix this, run `sudo systemctl disable dnsmasq` to prevent it from starting on boot, and instead run it manually from the dhcpcd start file:

    sudo nano /etc/dhcpcd.enter-hook
And add `dnsmasq -C /etc/dnsmasq.conf` to the top. Reboot for changes to take effect.
