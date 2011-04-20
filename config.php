<?php

// setup your zootool api key. 
// get it from http://zootool.com/api/keys
c::set('zootool.key', 'xxx'); 

// setup your zootool username and password
c::set('zootool.username', 'yourusername');
c::set('zootool.password', 'yourpassword');

// setup your dropbox api key and api secret. 
// get it from https://www.dropbox.com/developers/apps
c::set('dropbox.key', 'xxx');
c::set('dropbox.secret', 'xxx');

// setup your dropbox email address and password
c::set('dropbox.email', 'your@email.com');
c::set('dropbox.password', 'yourpassword');

// setup the folder where the app should store all the images
// public/zootool is recommended, but you can also use a private folder
c::set('dropbox.folder', 'public/zootool');

// setup your database connection. 
// make sure to add the 'zync' database before you run the 
// app for the first time. only mysql is supported 
c::set('db.host', '127.0.0.1');
c::set('db.user', 'root');
c::set('db.password', '');
c::set('db.name', 'zync');

// set this to true if you want to keep the downloaded
// files on your server as well or set it to false if they
// should be deleted once they are uploaded to dropbox. 
c::set('zync.keepfiles', true);

// set the dir, where the files should be saved on your
// server. just leave this as it is, if you are not sure. 
// make sure this dir is writable. 
c::set('zync.dir', c::get('root') . '/images');

?>