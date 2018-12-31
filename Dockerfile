FROM ubuntu:14.04

RUN echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections

RUN apt-get update

RUN apt-get -y upgrade

RUN apt-get install -y -u apache2 php5 php-soap python2.7 nginx nginx-extras build-essential git

RUN git clone https://bitbucket.org/cesbo/astra.git

RUN cd astra && ./configure.sh && make && make install

RUN mkdir /var/www/stalker_portal/

COPY stalker_portal/server /var/www/stalker_portal/

RUN sleep 600

RUN cd /var/www/stalker_portal/storage && chmod a+x install.sh && ./install.sh




