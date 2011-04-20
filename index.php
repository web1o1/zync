<?php

// include the app
require_once(dirname(__FILE__) . '/app/zync.php');
require_once(dirname(__FILE__) . '/config.php');

// start syncing
zync::sync();

?>