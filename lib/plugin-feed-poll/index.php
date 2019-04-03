<?php

if(!function_exists('wakeup_feed'))
{

    global $hooks;

    $hooks->add_action(ACTION_PLG_INIT, 'feed_init');

    function feed_init()
    {
        global $config;
        /**
         * @todo ask why it is defined global
         */
        global $plugin_states;

        $plugin_states['ciis0.feed-poll'] = [
            'msg_ids' => [],
            'mtl' => [], // message to link
            'mtc' => [], // message to conv_id
            'stor' => new ICanBoogie\Storage\FileStorage(__DIR__ . DIRECTORY_SEPARATOR . 'stor'), // stor = store (no typo here)
            'mails' => hooks_only($config) ? [] : get_conversation_participant_emails($config['conv_id']),
            'mris' => [] // most recent id's (by feed), key = sha1(feed), value = mri
        ];
    }

    /**
     * @return array, of mail addresses as strings
     */
    function get_conversation_participant_emails($conv_id)
    {

        $mails = [];

        $conv_api = new Swagger\Client\Api\ConversationQueriesApi();
        $user_api = new Swagger\Client\Api\UserManagementApi();

        try {

            foreach($conv_api->getConversationbyId($conv_id)['participants'] as $participant_id)
            {
                $user = $user_api->getUserById($participant_id);
                $mails[] = $user['email_address'];
            }

            return $mails;

        } catch (Exception $e) {
            echo 'Exception when retrieving conversation participants: ', $e->getMessage(), PHP_EOL;
        }
    }


    $hooks->add_action('wakeup_advanced', 'wakeup_feed');

    function wakeup_feed()
    {

        global $config;
        global $plugin_states;

        $my_config = $config['plugins']['feed_poll'];
        $my_state = &$plugin_states['ciis0.feed-poll'];

        foreach($my_config['feeds'] as $my_feed)
        {

            $feed_url = $my_feed['feed_url'];
            $feed_auth = $my_feed['feed_auth'];
            $auth_url = $my_feed['auth_url'];
            $conv_id = null;

            echo 'Feed: ' . $feed_url, PHP_EOL;

            if(isset($my_feed['conv_id']))
            {
                $conv_id = $my_feed['conv_id'];
                echo 'Using custom conv_id ', $conv_id, '.', PHP_EOL;
            }

            $storage = $my_state['stor'];
            $feed_mri_token = 'mri_' . sha1($feed_url); // mri most recent id; hash to sanitize

            $client_cfg = [ 'defaults' => array() ];

            if(in_array('cookies', $feed_auth))
            {
                $cookies = $my_feed['cookies'];
                $client_cfg['defaults']['cookies'] = GuzzleHttp\Cookie\CookieJar::fromArray($cookies, parse_url($auth_url, PHP_URL_HOST));
            }

            if(in_array('basic', $feed_auth))
            {
                $client_cfg['defaults']['auth'] = [
                    $my_feed['basic_user'],
                    $my_feed['basic_pass']
                ];
            }

            $client = new GuzzleHttp\Client($client_cfg);

            if(in_array('form', $feed_auth))
            {
                $client->post($auth_url, [
                    'body' => $my_feed['form_fields'],
                    'allow_redirects' => true
                ]);
            }

            $response = $client->get($feed_url);

            $feed = new SimplePie();

            $feed->set_raw_data((string) ($response->getBody()));
            $feed->init();

            if($feed->get_item_quantity() == 0)
            {
                echo 'Feed: Empty. Nothing to do.', PHP_EOL;
                continue;
            }

            $mri = $storage->retrieve($feed_mri_token);
            $id0 = $feed->get_item(0)->get_id();
            $skip = !hooks_only($config); // by default expands to true; for more output when hooks_only is enabled

            echo 'Feed has ' . $feed->get_item_quantity() . ' items.', PHP_EOL;
            echo "MRI ID: $feed_mri_token", PHP_EOL;

            if($id0 != $mri || hooks_only($config)) // same
            {
                for ($i = $feed->get_item_quantity()-1; $i >= 0; $i--)
                {
                    $item = $feed->get_item($i);

                    echo "Item: $i, id: {$item->get_id()}\n";

                    if($skip && $item->get_id() == $mri)
                    {
                        $skip = false;
                        continue;
                    }
                    elseif($skip && $i == 0) // most recent item not found ...
                    {
                        $i = $feed->get_item_quantity(); // restart from top
                        $skip = false;

                        echo "mri $mri not found\n";
                        continue;
                    }
                    elseif($skip)
                    {
                        continue;
                    }

                    $link = $item->get_link(0);
                    $parent = $storage->retrieve('ltp_' . sha1($link)); // ltp link to parent

                    echo 'Searching for parent...', PHP_EOL;
                    echo "$link: ltp_", sha1($link), PHP_EOL;

                    if(item_author_is_participant($item) &&  $parent != null)
                    {
                        echo 'Skipping item with contributor present in conversation', PHP_EOL;
                        continue;
                    }

                    $patterns = [
                        '/\n/', // circuit does not like line breaks
                        '/<del>(.*?)<\\/del><ins>(.*?)<\\/ins>/',
                        '/<ins>(.*?)<\\/ins>/',
                        '/\[([^\[\]]+?)\]\((.+?)\)/', // revert html2text links
                    ];

                    $replacements = [
                        '<br/>',
                        '-(\1)+(\2)',
                        '+(\1)',
                        '<a href="\2">\1</a>',
                    ];

                    libxml_use_internal_errors(true); // prevent "invalid entity" warnings in php

                    $content = Html2Text\Html2Text::convert($item->get_description());

                    if(count(libxml_get_errors()) > 0)
                    {
                        echo 'Feed: There where libxml errors/warnings.', PHP_EOL;

                        foreach(libxml_get_errors() as $error)
                        {
                            print_r($error);
                        }
                        echo PHP_EOL;
                    }

                    libxml_clear_errors();
                    libxml_use_internal_errors(false);

                    $mes = new AdvancedMessage(
                        preg_replace($patterns, $replacements, $content),
                        $parent
                    );

                    if($parent == null)
                    {
                        $mes->title = $item->get_title();
                    }

                    $my_state['msg_ids'][] = $mes->id;
                    $my_state['mtl'][$mes->id] = $link;
                    $my_state['mtc'][$mes->id] = $conv_id;

                    if(isset($conv_id))
                    {
                        $mes->conv_id = $conv_id;
                    }

                    circuit_send_message_adv($mes); // has no effect with hooks_only
                }
                $mri = $id0;
            }
            else
            {
                echo 'Feed: no new items, nothing to do.', PHP_EOL;
            }

            $my_state['mris'][$feed_mri_token] = $mri;

        }
    }

    $hooks->add_action(ACTION_PARENT_ID, 'parent_id_feed', 10 /* default priority */, 2);

    function parent_id_feed($msg_id, $item_id)
    {
        global $plugin_states;
        $my_state = $plugin_states['ciis0.feed-poll'];

        if(in_array($msg_id, $my_state['msg_ids']))
        {
            echo "Feed: Message {$msg_id} is ours!", PHP_EOL;
            $my_state['stor']->store('ltp_' .  sha1($my_state['mtl'][$msg_id]), $item_id); // ltp link to parent, hash to sanitize link (url)

            $msg = new AdvancedMessage("<a href=\"{$my_state['mtl'][$msg_id]}\">Link to ticket</a>", $item_id);

            if(isset($my_state['mtc'][$msg_id]))
            {
                $msg->conv_id = $my_state['mtc'][$msg_id];
            }

            circuit_send_message_adv($msg);
        }
        else
        {
            echo "Feed: Message {$msg_id} not ours!", PHP_EOL;
        }
    }

    $hooks->add_action(ACTION_SUCCESS, 'feed_success');

    function feed_success(){

        global $config;
        global $plugin_states;

        echo 'Bot confirmed success, saving most recent id\'s...', PHP_EOL;

        $my_state = $plugin_states['ciis0.feed-poll'];

        if(hooks_only($config))
        {
            foreach($my_state['mris'] as $feed_mri_token => $mri)
            {
                //$my_state['stor']->store($feed_mri_token, $mri);
                echo "Stored mri $mri for $feed_mri_token.", PHP_EOL;
            }
        } else {
            echo 'hooks_only: saving skipped.', PHP_EOL;
        }

    }

    function item_author_is_participant($item){

        global $plugin_states;

        foreach($item->get_authors() as $author)
        {
            if(in_array($author->get_email(), $plugin_states['ciis0.feed-poll']['mails']))
            {
                return true;
            }
        }
    }

}
