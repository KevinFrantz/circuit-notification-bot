<?php
use ICanBoogie\Storage\FileStorage;

use Swagger\Client;
use Swagger\Client\Api;
use Swagger\Client\ApiClient;

/**
 * Keep in mind that on the server
 * PHP 7.0.19-1
 * is running.
 * Don't use functions which are higher then PHP 7.0
 */

if (! function_exists('circuit_bot')) {
    /**
     *
     * @see https://stackoverflow.com/questions/2447791/define-vs-const
     */
    define('ACTION_WAKEUP', 'wakeup');
    define('ACTION_WAKEUP_ADV', 'wakeup_advanced');
    define('ACTION_PLG_INIT', 'init_plugins');
    define('ACTION_PARENT_ID', 'parent_id');
    define('ACTION_SUCCESS', 'success');

    /**
     *
     * @param array $conv_item
     */
    function print_conv_item(array $conv_item)
    {
        echo 'Message...', PHP_EOL, 'ID      ', $conv_item['item_id'], PHP_EOL, 'Content ', $conv_item['text']['content'], PHP_EOL, PHP_EOL;
    }

    /**
     *
     * @param array $config
     * @return boolean
     */
    function hooks_only(array $config)
    {
        return isset($config['hooks_only']) && $config['hooks_only'];
    }

    /**
     *
     * @param array $the_config
     */
    function circuit_bot(array $the_config)
    {
        global $hooks;
        global $config;
        global $plugin_states;

        /**
         *
         * @todo Ask why this variable is passed on this way
         * @var array $config
         */
        $config = $the_config; // make config available in filters and actions
        
        $plugin_states = [];
        $hooks_only = hooks_only($config);

        if (isset($config['host'])) {
            echo 'Using custom host ', $config['host'], PHP_EOL;

            define('TOKEN_ENDPOINT', $config['host'] . '/oauth/token');
            Client\Configuration::getDefaultConfiguration()->setHost($config['host'] . '/rest/v2');
        } else {
            define('TOKEN_ENDPOINT', 'https://eu.yourcircuit.com/oauth/token');
        }

        if (isset($config['client']) && isset($config['client']['id']) && isset($config['client']['secret'])) {
            $storage = new FileStorage(__DIR__);
            $token_key = 'token_' . $config['client']['id'];

            // Try to reuse OAuth token, request new one if expired.
            if (($response = $storage->retrieve($token_key)) && verify_token($response['access_token'])) {
                echo 'Token loaded', PHP_EOL;
                $token = $response['access_token'];
            } elseif ($hooks_only) {
                echo "No token found, but skipping due to hooks_only", PHP_EOL;
            } else {
                echo 'No token found, requesting new one...', PHP_EOL;

                $response = (new OAuth2\Client($config['client']['id'], $config['client']['secret']))->getAccessToken(TOKEN_ENDPOINT, 'client_credentials', [
                    'scope' => 'ALL'
                ])['result'];

                $storage->store($token_key, $response, isset($response['expires_in']) ? $response['expires_in'] - 10 /* just to be sure */ : null);

                print_r($response);

                $token = $response['access_token'];
            }

            // Configure OAuth2 access token for authorization
            Client\Configuration::getDefaultConfiguration()->setAccessToken($token);
        } elseif (! $hooks_only) {
            die('Missing OAuth Client-ID and/or Client secret!');
        }

        $config['api.messaging.basic'] = new Api\MessagingBasicApi();

        if (! $hooks_only) {
            try {
                (new Api\UserManagementApi())->setUserPresence('AVAILABLE', null, 'Crunching data...');
            } catch (Exception $e) {
                die('Exception when setting presence: ' . $e->getMessage());
            }
        }

        echo 'Initializing plugins', PHP_EOL;

        $hooks->do_action(ACTION_PLG_INIT);

        echo 'Running hooks', PHP_EOL;

        $wakeup = $hooks->do_action(ACTION_WAKEUP);
        $wakeup_advanced = $hooks->do_action(ACTION_WAKEUP_ADV);

        if (! $hooks_only) {
            try {
                (new Api\UserManagementApi())->setUserPresence('AWAY', null, 'Sleeping');
            } catch (Exception $e) {
                die('Exception when setting presence: ' . $e->getMessage());
            }
        }

        echo 'Informing plugins about success...', PHP_EOL;

        $hooks->do_action(ACTION_SUCCESS);

        echo 'Done!', PHP_EOL;

        if ($hooks_only) {
            echo 'Ran only hooks, as requested by $config[\'hooks_only\']', PHP_EOL, 'hooks:', PHP_EOL;
            print_r($hooks);
            return;
        }
    }

    function circuit_message_truncate($msg)
    {
        /**
         * @var integer $maxlen
         */
        $maxlen = 2048;
        
        /**
         * @var string $toolongmsg
         */
        $toolongmsg = '...';
        
        /**
         * @var integer $maxlen_real
         */
        $maxlen_real = 2048 - strlen($toolongmsg);

        if (strlen($msg) > $maxlen) {
            $trunc = $msg;

            for ($i = $maxlen_real; $i > 0 && strlen($trunc) > 2048; $i --) {
                $trunc = Cake\Utility\Text::truncate($trunc, $i, [
                    'ellipsis' => $toolongmsg,
                    'html' => true,
                    'exact' => false
                ]);
            }
            return $trunc;
        }
        return $msg;
    }

    function circuit_send_message($content)
    {
        global $config;

        if (hooks_only($config)) {
            return;
        }

        $content = circuit_message_truncate($content);

        try {
            print_conv_item($config['api.messaging.basic']->addTextItem($config['conv_id'], $content));
        } catch (Exception $e) {
            die('Exception when calling MessagingBasicApi->addTextItem: ' . $e->getMessage());
        }
    }

    function circuit_send_message_adv(AdvancedMessage $msg_adv)
    {
        global $config;
        global $hooks;

        if (hooks_only($config)) {
            print_r($msg_adv);
            return;
        }

        $api_instance = $config['api.messaging.basic'];
        $conv_id = $msg_adv->conv_id ? $msg_adv->conv_id : $config['conv_id'];

        $content = circuit_message_truncate($msg_adv->message);

        try {
            if ($msg_adv->parent) {
                $result = $api_instance->addTextItemWithParent($conv_id, $msg_adv->parent, $content, [ /* attachments */
                ], $msg_adv->title);
            } else {
                $result = $api_instance->addTextItem($conv_id, $content, [ /* attachments */
                ], $msg_adv->title);

                $hooks->do_action(ACTION_PARENT_ID, $msg_adv->id, $result['item_id']);
            }
            print_conv_item($result);
        } catch (Exception $e) {
            echo 'Exception when calling MessagingBasicApi->addTextItem/addTextItemWithParent: ', $e->getMessage(), PHP_EOL;
            echo 'Message was: ', PHP_EOL, $content, PHP_EOL, PHP_EOL;
            exit(2);
        }
    }

    /**
     * This is mainly a structure, not an encapsulated container.
     */
    final class AdvancedMessage
    {

        public $parent;

        public $message;

        public $title;

        public $id;

        public $conv_id;

        private static $nextId = 0;

        public function __construct($message, $parent = null)
        {
            $this->id = AdvancedMessage::$nextId ++;
            $this->message = $message;
            $this->parent = $parent;
        }

        /**
         * Record an ID in plugins' state for later use.
         */
        public function record_id($plg, $key = 'msg_ids')
        {
            global $plugin_states;
            $plugin_states[$plg][$key][] = $this->id; // the array should be initialized by plugin
        }
    }

    function verify_token($token)
    {
        try {
            echo "Veryfing token...", PHP_EOL;

            $api_config = clone Client\Configuration::getDefaultConfiguration();
            $api_config->setAccessToken($token);

            (new Api\UserManagementApi(new ApiClient($api_config)))->getProfile();

            return true;
        } catch (Exception $e) {
            if ($e->getCode() == 401) {
                return false;
            }
            echo "Error accessing API: {$e->getMessage()}", PHP_EOL;
            print_r($e);
            exit(1);
        }
    }
}
