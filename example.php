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

// If you want to process user IDs, specify the callback function
$newrphus->setUserIdAnalysis(function($userId) {
    // Here you might want to get username by user ID
    $username = "Username {$userId}";

    // Callback function must return Slack attachment
    return [
        'title' => 'Username',
        'value' => $username,
        'short' => true
    ];
});

// If you want to process page URL, specify the callback function
$newrphus->setURLAnalysis(function($url) {
    return [
        'title' => 'Page',
        'value' => $url,
        'short' => true
    ];
});

$result = $newrphus->report([
    'misprintText' => $_POST['misprintText'],
    'misprintUrl' => $_POST['misprintUrl'],
    'misprintUserId' => $_POST['misprintUserId']
]);