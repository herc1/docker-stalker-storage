FROM ubuntu:14.04

RUN echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections

RUN apt-get update

RUN apt-get -y upgrade

RUN apt-get install -y -u apache2 php5 php-soap python2.7 nginx nginx-extras build-essential git

RUN git clone https://bitbucket.org/cesbo/astra.git

RUN cd astra && ./configure.sh && make && make install

RUN mkdir /var/www/stalker_portal/

COPY stalker_portal/storage /var/www/stalker_portal/storage

RUN cd /var/www/stalker_portal/storage && chmod a+x install.sh && ./install.sh

RUN mkdir /var/www/media

RUN mkdir -p -m 0777 /media/raid0

RUN mkdir /opt/conf

RUN sed -i 's/Listen 80/Listen 88/' /etc/apache2/ports.conf

RUN rm -f /etc/apache2/sites-enabled/000-default.conf

RUN rm -f /etc/nginx/sites-enabled/default

COPY entrypoint.sh entrypoint.sh

EXPOSE 88

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]



