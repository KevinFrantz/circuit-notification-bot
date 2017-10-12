#!/usr/bin/env php
<?php
if(count($argv) < 2)
{
    fwrite(STDERR, "ERROR: This script takes one or two argument(s). For more execute \"{$argv[0]} help\".\n");
    exit(2);
}

if($argv[1] == "help")
{
    fwrite(STDERR,
        "Circuit Bot runner.\nUsage: {$argv[0]} bot-dir .test_plugin].\n" .
        "bot-dir: bot's directory name\ntest_plugin: \"test_plugin\" if the directory is a plugin, not a bot, and you want to test the hooks. (Your plugin must reside in index.php.)"
    );
    exit(0);
}

$bot_dir=$argv[1];
$test_plugin=isset($argv[2]) && $argv[2] == "test_plugin";
$get_token=isset($argv[2]) && $argv[2] == "token";

if(is_dir($bot_dir))
{

    chdir($bot_dir);

    require_once('./vendor/autoload.php');

    $config_file = './config.php';
    $config;

    if(is_file($config_file))
    {
        require_once('./config.php'); // . is PWD not __DIR__ !
    }
    elseif(!is_array($config)) // this file meight be included and config already set.
    {
        $config = [];
    }

    if($test_plugin)
    {
        $config['hooks_only'] = true;
        include('./index.php');
    }
    elseif($get_token)
    {
        $host = isset($argv[3]) ? $argv[3] : "https://eu.yourcircuit.com";

        if($host == "sandbox")
        {
            $host = "https://circuitsandbox.net";
        }

        system("curl -d client_id={$config['client']['id']} -d client_secret={$config['client']['secret']} -d grant_type=client_credentials -d scope=ALL ${host}/oauth/token");

        exit(0);
    }


    circuit_bot($config);
}
else
{
    fwrite(STDERR, "ERROR: Bot directory \"{$bot_dir}\" does not exists or is no directory!");
    exit(3);
}
