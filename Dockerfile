FROM ubuntu:14.04
#RUN echo "Acquire::http::Proxy \"http://10.0.0.1:3142\";" > /etc/apt/apt.conf
RUN apt-get update && apt-get upgrade -y
RUN apt-get -y install php5-cli php5-mongo php5-curl
ADD . /pybot
RUN chmod a+x /pybot/pybotd
CMD ["/pybot/pybotd"]
