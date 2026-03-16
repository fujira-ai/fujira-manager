<?php
declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    */

    'app' => [
        'name'       => 'Fujira Manager',
        'env'        => 'production',
        'base_url'   => 'https://fujira.tokyo/fujira-manager',
        'timezone'   => 'Asia/Tokyo',
    ],


    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'db' => [
        'host'    => 'mysql2103.db.sakura.ne.jp',
        'port'    => 3306,
        'dbname'  => 'officefujira_fujiramanager',
        'user'    => 'officefujira_fujiramanager',
        'pass'    => 'fujiramanager123',
        'charset' => 'utf8mb4',
    ],


    /*
    |--------------------------------------------------------------------------
    | LINE Messaging API
    |--------------------------------------------------------------------------
    */

    'line' => [
        'channel_secret'       => '206085fa368a21011d757698f3a50a34',
        'channel_access_token' => '5XFcgw4fWRC5Bcv+yrMGvP5DV88MUJtI+fSZAqkSaFNIaGLbU5LZb+yse1rS4q0rgkh8IzlbxAW7NlgvB/yoSfGV0V8Y4SkfgFfdzevPpPCuo28PXTJybv3WDRLoUHKu3cRJH733y/zmDInJXhvC7gdB04t89/1O/w1cDnyilFU=',
    ],


    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'log_dir' => __DIR__ . '/../logs',
    ],

];