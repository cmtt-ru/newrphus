<?php
require 'vendor/autoload.php';

$newrphus = new TJ\Newrphus;
$newrphus->setSlackSettings(['endpoint' => 'https://hooks.slack.com/services/ABCDE/12345', 'channel' => '#misprints']);

// Uncomment if you want to track errors with Monolog
/*
$monolog = new Monolog\Logger('Misprints');
$monolog->pushHandler(new Monolog\Handler\StreamHandler(sys_get_temp_dir() . '/misprints.log', Monolog\Logger::DEBUG));

$newrphus->setLogger($monolog);
*/

// Uncomment if you want to use Memcached for antiflood protection
/*
$memcached = new Memcached();
$memcached->addServer('localhost', '11211');

$newrphus->setMemcached($memcached);
*/

// You can add multiple additional fields to message (optional)
$userId = intval($_POST['misprintUserId']);
$url = $_POST['misprintUrl'];

$newrphus->addField('User ID', $userId, true)
         ->addField('Page url', $url, true);

// You can also set custom text for Slack notifications (optional)
$newrphus->setNotificationText("Hey, @user, new misprint: {$_POST['misprintText']}");

// And customize Slack message text (optional)
$newrphus->setMessageText("New misprint: {$_POST['misprintText']}");

$result = $newrphus->report($_POST['misprintText'], $_POST['misprintUrl']);