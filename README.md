# Raspberry Pi as a Wireless Access Point with a VPN, Configurable with a Web Interface
In this guide, I'll be setting up a Raspberry Pi 3 as a wireless access point with a VPN, which each user can disable/enable for themselves via a web interface.

### Installing Packages
First, ensure that everything is up to date:
```
sudo apt-get update
sudo apt-get dist-upgrade
```
Next, install the necessary packages:

    sudo apt-get install OpenVPN dnsmasq hostapd unzip apache2 libapache2-mod-php5 php5 php-pear php5-xcache php5-mysql php5-curl php5-gd

### Set up the VPN
In this example, we will be using [Private Internet Access](https://www.privateinternetaccess.com/) as my VPN provider, but as long as your VPN provider supports OpenVPN these steps should be essentially the same. Start by moving into the OpenVPN directory:

    cd /etc/openvpn

Then download your VPN's OpenVPN configuration files:

    wget https://privateinternetaccess.com/openvpn/openvpn.zip

Now use unzip to extract the files:

    unzip openvpn.zip

The next step is for you to pick the configuration file you will be using for your location. In this guide, we will use US West as the region. So, open up the configuration file:

    sudo nano "US West.ovpn"

and add the following line to the bottom:

    up /etc/openvpn/up.sh

We will now create that file

    sudo nano up.sh

and paste the following:
```
#!/bin/sh
gateway=$(ip route show | grep -i 'default via'| awk '{print $3 }')
mask=$(/sbin/ifconfig eth0 | grep 'Mask:' | cut -d: -f4)
addr=$(/sbin/ifconfig eth0 | grep 'inet addr:' | cut -d: -f2 | awk '{ print $1}')
ip rule add from $addr table 128
ip route add table 128 to $mask dev eth0
ip route add table 128 default via $gateway
```
In order to allow remote SSH into your Raspberry Pi through the VPN. This file will be used later as well. Lastly, confirm that the VPN is working:

    sudo openvpn --config "/etc/openvpn/US West.ovpn"

You will be prompted to enter the username and password of your account. In order to automatically pass this information in so that the VPN can be run on startup, create a new file:

    sudo nano auth-pass.txt

and paste your account information like so:
```
username
password
```
Now run a command to confirm it works:

    sudo openvpn --config "/etc/openvpn/US West.ovpn" --auth-user-pass /etc/openvpn/auth.txt

To actually run the command on startup, we will edit the `rc.local` file:

    sudo nano /etc/rc.local

and add the above command just above `exit 0`.
### Setting up the Wireless Access Point
Open up the `dhcpcd.conf` file in order to ignore the `wlan0` interface, since it will be configured elsewhere:

    sudo nano /etc/dhcpcd.conf

and add `denyinterfaces wlan0` at the bottom of the file, but above any other interface lines you may have added. Now we will configure `wlan0`.  Open the interfaces file:

    sudo nano /etc/network/interfaces

and edit the `wlan0` configuration so that it looks like the following:
```
allow-hotplug wlan0
iface wlan0 inet static
    address 172.24.1.1
    netmask 255.255.255.0
    network 172.24.1.0
    broadcast 172.24.1.255
#   wpa-conf /etc/wpa_supplicant/wpa_supplicant.conf
```
and restart `dhcpcd` and reload the `wlan0` configuration:
```
sudo service dhcpcd restart
sudo ifdown wlan0
sudo ifup wlan0
```
The next step is to configure our access point using `hostapd`. Create the configuration file:

    sudo nano /etc/hostapd/hostapd.conf

and paste the following:
```
interface=wlan0
# Set the driver
driver=nl80211
# Set the name of the network.
ssid=Pi3-AP
# Use the 2.4GHz band
hw_mode=g
# Use channel 6
channel=6
# Enable 802.11n
ieee80211n=1
# Enable WMM
wmm_enabled=1
# Enable 40MHz channels with 20ns guard interval
ht_capab=[HT40][SHORT-GI-20][DSSS_CCK-40]
# Accept all MAC addresses
macaddr_acl=0
# Use WPA authentication
auth_algs=1
# Require clients to know the network name
ignore_broadcast_ssid=0
# Use WPA2
wpa=2
# Use a pre-shared key
wpa_key_mgmt=WPA-PSK
# The network passphrase(I recommend using something more secure than just 'raspberry')
wpa_passphrase=raspberry
# Use AES, instead of TKIP
rsn_pairwise=CCMP
```
We need to make sure that `hostapd` uses this configuration on startup, so edit it's original configuration:

    sudo nano /etc/default/hostapd

and change the line `#DAEMON_CONF=""` to `DAEMON_CONF="/etc/hostapd/hostapd.conf"`. Our access point is almost ready, but we need to set up `dnsmasq`. Move the original configuration file and create a new one:
```
sudo mv /etc/dnsmasq.conf /etc/dnsmasq.conf.orig  
sudo nano /etc/dnsmasq.conf
```
Fill the new configuration with:
```
interface=wlan0
listen-address=172.24.1.1 # This will be the ip address of our web interface
bind-interfaces      # Bind to the interface to make sure we aren't sending things elsewhere  
server=8.8.8.8       # DNS server. This is google's DNS server, if you have a preferred server place it here.
domain-needed        # Don't forward short names  
bogus-priv           # Never forward addresses in the non-routed address spaces.  
dhcp-range=172.24.1.50,172.24.1.150,12h # Assign IP addresses in the range 172.24.1.50 to 172.24.1.150 with a 12 hour lease time
```
We need to enable forwarding of packets before our access point can work:

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

and add the following just above `cd /etc/openvpn` line:
```
iptables-restore < /etc/iptables.ipv4.nat
sleep 5
```
Now, run `sudo reboot` and once the Pi has restarted, try to connect to your access point to confirm that it is working.
### Allowing Routing Around the VPN
We will be creating a web interface in order for users to toggle the VPN on and off. The basic setup, however, simply involves marking packets from a particular source(user) and creating a routing rule for marked packets in order to route them via `eth0` instead of `tun0`. First, we will add the rule for marked packets, and make sure to run the command when the `wlan0` interface is configured.

    sudo nano /etc/network/interfaces

At the bottom of the `wlan0` section, just above the commented out `wpa_supplicant` line, add:

    post-up ip rule add fwmark 3 table 3

This rule will route packets that are marked with 3 through table 3. In order to create the route through `eth0` for table 3, open up the `up.sh` file we created earlier:

    sudo nano /etc/openvpn/up.sh

and at the very bottom add:

    sudo ip route add default via $gateway dev eth0 table 3

This will make the default route for everything through the `eth0` interface, avoiding the VPN. The last step to route around the VPN is to actually mark packets from a specific user. The command for a device with the IP of `172.24.1.126` is:

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
