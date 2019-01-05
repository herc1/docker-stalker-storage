FROM ubuntu:14.04

ENV stalker_version 520

ENV stalker_zip stalker_portal-5.2.0.zip

RUN echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections

RUN apt-get update

RUN apt-get -y upgrade

RUN apt-get install -y -u apache2 php5 php-soap python2.7 nginx nginx-extras build-essential git unzip

RUN git clone https://bitbucket.org/cesbo/astra.git

RUN cd astra && ./configure.sh && make && make install

RUN rm -rf astra

RUN mkdir /var/www/stalker_portal/

COPY ${stalker_zip} /

RUN unzip ${stalker_zip} -d stalker_portal

RUN cd stalker_portal/* && cp -r storage/ /var/www/stalker_portal/

RUN rm -rf stalker_portal

RUN rm -rf ${stalker_zip}

RUN echo ${stalker_version} > stalker_version

RUN cd /var/www/stalker_portal/storage && chmod a+x install.sh && ./install.sh

COPY dumpstream.lua /var/www/stalker_portal/storage/dumpstream.lua

RUN mkdir /var/www/media

RUN mkdir -p -m 0777 /media/raid0

RUN mkdir /opt/conf

RUN sed -i 's/Listen 80/Listen 88/' /etc/apache2/ports.conf

RUN rm -f /etc/apache2/sites-enabled/000-default.conf

RUN rm -f /etc/nginx/sites-enabled/default

COPY conf/apache2/default.conf /etc/apache2/sites-available/

COPY conf/nginx/default.conf /etc/nginx/conf.d/

COPY entrypoint.sh entrypoint.sh

EXPOSE 88

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]