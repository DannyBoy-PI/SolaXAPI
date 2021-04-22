#
# Example BASH script for Raspberry Pi (Assumes you have the PHP script in /home/pi)
#
php /home/pi/Solax-pvoutput.php >> "/home/pi/SolaX-"$(date '+%Y-%m-%d')"-log.solaxapi"