FROM ubuntu:16.04

# set mysql password
RUN echo "mysql-server mysql-server/root_password password toor" | debconf-set-selections
RUN echo "mysql-server mysql-server/root_password_again password toor" | debconf-set-selections

# install
RUN apt-get -y update && apt-get -y install mysql-client mysql-server apache2 php libapache2-mod-php php-mcrypt php-mysql

# configure mysql
COPY database/create.sql /tmp/create.sql
RUN sed -i 's/^bind/#bind/' /etc/mysql/mysql.conf.d/mysqld.cnf
RUN service mysql start && mysql -u root -p'toor' << "update mysql.user set Host='%';flush privileges;" && mysql -u root -p'toor' < /tmp/create.sql

# configure apache
RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# expose ports
EXPOSE 3306
EXPOSE 80

# start it...
CMD service apache2 start && service mysql start && chown 33:33 /var/www/secure/lib/keys && tail -f /var/log/apache2/error.log
