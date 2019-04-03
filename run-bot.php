#!/usr/bin/env php
<?php

/**
 * @var string The config file path
 */
const CONFIG_FILE_PATH = './config.php';

/**
 * @var string The url to the circuit sandbox
 */
const CIRCUIT_SANDBOX_HOST_URL = 'https://circuitsandbox.net';

/**
 * @todo Ask if the constant describes the use case right
 * @var string The circuit production url 
 */
const CIRCUIT_PRODUCTION_URL = 'https://eu.yourcircuit.com';

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

/**
 * @todo Describe this variable
 * @var Ambiguous $bot_dir
 */
$bot_dir=$argv[1];

/**
 * @todo Describe this variable
 * @var Ambiguous $test_plugin
 */
$test_plugin=isset($argv[2]) && $argv[2] == "test_plugin";

/**
 * @todo Describe this variable
 * @var Ambiguous $get_token
 */
$get_token=isset($argv[2]) && $argv[2] == "token";

if(is_dir($bot_dir))
{

    chdir($bot_dir);

    require_once('./vendor/autoload.php');

    /**
     * @var array $config Containes the configuration elements
     * @see config.php.example
     */
    $config = [];

    if(is_file(CONFIG_FILE_PATH))
    {
        require_once(CONFIG_FILE_PATH); // . is PWD not __DIR__ !
    }

    if($test_plugin)
    {
        $config['hooks_only'] = true;
        include('./index.php');
    }
    elseif($get_token)
    {
        $host = isset($argv[3]) ? $argv[3] : CIRCUIT_PRODUCTION_URL;

        if($host === "sandbox")
        {
            $host = CIRCUIT_SANDBOX_HOST_URL;
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
