FROM ubuntu:14.04

RUN echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections

RUN apt-get update

RUN apt-get -y upgrade

RUN apt-get install -y -u apache2 php5 php-soap python2.7 nginx nginx-extras build-essentials git

RUN git clone https://bitbucket.org/cesbo/astra.git

RUN cd astra

RUN ./configure.sh

RUN make

RUN make install





