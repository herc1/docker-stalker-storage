#!/bin/bash

cp -f /opt/conf/nginx/*.conf /etc/nginx/conf.d/
cp -f /opt/conf/apache/*.conf /etc/apache2/sites-available/
cp -f /opt/conf/apache/conf-available/*.conf /etc/apache2/conf-available/
cp -f /opt/conf/stalker/config.php /var/www/stalker_portal/storage/

mkdir -p -m 0777 /media/raid0/storage /media/raid0/karaoke /media/raid0/records /media/raid0/mac
mkdir -m 0777 /media/raid0/records/archive
ln -s /media/raid0/records/archive/ /var/www/
ln -s /media/raid0/mac/ /var/www/media/${storage_name}



services=(cron apache2 nginx)
while true; do
 for service in ${services[@]}; do
  if [ $(pgrep $service | wc -l) -eq 0 ]
  then
   echo "Service $service is not running. Starting"
   if [ "$service" != "cron" ]
   then
    service $service start
   else
    cron
   fi
  fi
 done
 sleep 5
done