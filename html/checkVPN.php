<?php
$ip=$_SERVER['REMOTE_ADDR'];
$message=shell_exec("/var/www/scripts/checkVPN.sh $ip 2>&1");
echo $message;
?>
