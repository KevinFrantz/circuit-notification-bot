<?php

if(!function_exists('wakeup_feed'))
{

    global $hooks;

    $hooks->add_action(ACTION_PLG_INIT, 'feed_init');

    function feed_init()
    {
        global $plugin_states;

        $plugin_states['ciis0.feed-poll'] = [
            'msg_ids' => [],
            'mtl' => [], // message to link
            'stor' => new ICanBoogie\Storage\FileStorage(__DIR__ . DIRECTORY_SEPARATOR . 'stor'), // stor = store (no typo here)
        ];
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
            }

            $storage = $my_state['stor'];
            $feed_mri_token = 'mri_' . sha1($feed_url); // mri most recent id; hash to sanitize

            $client_cfg = [];

            if(in_array('cookies', $feed_auth))
            {
                $cookies = $my_feed['cookies'];
                $client_cfg['defaults'] = [
                    'cookies' => GuzzleHttp\Cookie\CookieJar::fromArray($cookies, parse_url($auth_url, PHP_URL_HOST))
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

            if($id0 != $mri || hooks_only($config)) // same
            {
                for ($i = $feed->get_item_quantity()-1; $i >= 0; $i--)
                {
                    echo 'Item: ',$i, PHP_EOL;
                    $item = $feed->get_item($i);

                    if($skip && $item->get_id() == $mri)
                    {
                        $skip = false;
                        continue;
                    }
                    elseif($skip && $i == 0) // most recent item not found ...
                    {
                        $i = $feed->get_item_quantity();
                        $skip = false;

                        echo 'mri not found', PHP_EOL;
                        continue;
                    }
                    elseif($skip)
                    {
                        continue;
                    }

                    $link = $item->get_link(0);

                    $patterns = [
                        '/\n/', // circuit does not like line breaks
                        '/<del>(.*?)<\\/del><ins>(.*?)<\\/ins>/',
                        '/<ins>(.*?)<\\/ins>/',
                        '/\[(.+?)\]\((.+?)\)/', // revert html2text links
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
                        $storage->retrieve('ltp_' . sha1($link)) // ltp link to parent
                    );

                    $mes->title = $item->get_title();

                    $my_state['msg_ids'][] = $mes->id;
                    $my_state['mtl'][$mes->id] = $link;

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

            if(!hooks_only($config))
            {
                $storage->store($feed_mri_token, $mri);
            }
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
        }
        else
        {
            echo "Feed: Message {$msg_id} not ours!", PHP_EOL;
        }
    }

}
