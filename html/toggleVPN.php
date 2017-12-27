<?php
$ip=$_SERVER['REMOTE_ADDR'];
$message=shell_exec("/var/www/scripts/checkVPN.sh $ip 2>&1");
if ($message > 0) {
    shell_exec("/var/www/scripts/disableVPN.sh $ip 2>&1");
} else {
    shell_exec("/var/www/scripts/enableVPN.sh $ip 2>&1");
}
?>
