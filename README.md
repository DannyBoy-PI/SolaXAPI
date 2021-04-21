# SolaXAPI
SolaX API to PVOutput

Connect to Solax RealTime API and Push to PVOutput

It will pull the latest RealTime information about your inverter and store it in a datafile (No database needed).

It will then push the defined range up to PVOutput from that datafile. If the SolaX API service is unavailable, your inverter is
in an abnormal state or there is no internet connectivity, you will get data gaps. If and when SolaX release a full
historical API, this code will need to be replaced/modified to pull a range from SolaX rather than just the RealTime data.

This code assumes the data, log and executable files are all in the same directory.

This code assumes you have the date/time/region set correctly.

This code should be called from a BASH script in CRON and run once a minute (This is important as upload rate is set as below).

It will attempt to upload data to PVOuput ONLY when the minute is 00, 15, 30 or 45.

Written for PHP 7.x with PHP CURL Extension installed.

This software is free and no warranty is given for the saftey and fitness of it for any given purpose.
