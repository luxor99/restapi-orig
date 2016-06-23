RESTful implementation with Silex PHP microframework
==================

This project aims to provide a demo on implementing a RESTful API with Silex.  It provides simple APIs to select, update and insert records into a database, as well as save PDF and image files to a server.  

### Instructions

You can download Silex package or intall via composer:

curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

or

curl -sS https://getcomposer.org/installer | php - 

this will generate a composer.phar file in your current directory, which can be executed with 

php composer.phar


1. Change DB credentials  in config.php

2. Import database.sql to you DB

3. "upload/" folder must be writable by the web server (run "chmod 755 upload")

You can test API:

http://you-host/test.html
http://you-host/test_password.html
http://you-host/test_joints.html
http://you-host/test_upload.html