#
# Example BASH script for Raspberry Pi (Assumes you have the PHP script in /home/pi)
#
# If you want to create a subdirectory called Solax uncomment the line below and 
# make sure you change the lines below to include the subdirectory and the cron job
# entry.

# cd Solax

php /home/pi/Solax-pvoutput.php >> "/home/pi/SolaX-"$(date '+%Y-%m-%d')"-log.solaxapi"
