# Circuit Bot

Project to aid in sending status messages to Unify's Circuit. It is thought to be really straight-forward, quick and easy to set up.

# Setup

## Prerequisites

 - PHP 5.5+
 - composer
 - jq

([Why?](#general-requirements))

## Install

Bootstrap your bot by cloning the repo and setting up the API client:

```bash
git clone git@github.com:atosorigin/circuit-notification-bot.git circuit-bot
cd circuit-bot
bash ./administration/fetch-api-client.sh
```

More about the API Client and an alternative way to install in [General Requirements](#general-requirements) below.

All the internal dependencies are not available publicly, so you should add your bot to this monolithic repo, albeit this project is not developed as a monolithic tree.
Updates are just a pulling and rebasing (or merging; whatever you prefer) upstream.

Monolithic repo (mono-repo) in this case means that all modules of the project are in one repository. Not using an monolithic tree (mono-tree) means that the modules are still in their own distinct folders.

## Development Environment
To start the development environment execute
```bash
bash ./administration/bootstrap.sh
```

# Theoretical Architecture

 - Bot: Instance of the bot implementation provided by this project
 - Plugin: Implemenatation for providing and/or processing status messages
 - Wakeup: Event that wakes up the bot or a plugin
    - (In)Direct: When the bot wakeup event represents a (no) status
 - Trigger: Changes plugin behaviour (TODO)

A Bot has plugins.

A Bot receives wakeups.

Wakeups have at least one trigger. (TODO)

Plugins receive wakeups from their corresponding bot.

Plugins may change their behaviour based on the presence of a trigger (e.g. whether they are enabled). (TODO)

# Paradigms

- Everything in this project is designed to work auto-magically.
  - just require your plugin; you do not need to activate it in any other place.
- <s>rather non-OOP, rather imperative, functional style</s>
  - later the project went to OOP but is not quite there yet
- do not repeat yourself
  - e.g. initially each bot required an `index.php` that actually executed the bot. This was replaced by `run-bot.php`.
- do not check-in generated code:
  - e.g. `fetch-api-client.sh`

# General Requirements

 - PHP 5.5
 - Composer
    - Dependency Management and plugins.
    - I do not recommend to use the curl-pipe command from getcomposer.org, use your distributions package manager instead. Package should be named `composer`.<br>
 - [`jq`](https://stedolan.github.io/jq)
    - install from package manager, package should be named `jq`.
    - alternatively see jq's [Download Page](https://stedolan.github.io/jq/download/).
 - `fetch-api-client.sh`
    - requires `jq`.
    - It's required to execute this to fetch the Client for the Circuit API. (Using it's Swagger definition and generator.swagger.io SaaSS.)
    - You also can generate it yourself using the [`swagger-generator-cli.jar`](https://github.com/swagger-api/swagger-codegen#prerequisites), just pass the `circuit-client-gen-conf.json` as the configuration. The resulting client must reside in `lib/circuit-api`.
    - Side note: It makes no sense to add the API-Client code into this repo; making an Git repo for usage with composer is TODO

# Bots

Initially the project was designed to handle multiple bot configurations but this use-case did not approve. Unfortunately the docs are not updated yet to address this, so this is a TODO.

## Create a Bot

Your bot should live in it's own directory, residing in `./bot`.

Automating this is todo.

 - create a dirctory for the bot and enter it
 - copy the `composer.json.bot-example` and `config.php.example` to `composer.json` and `config.php` inside your directory
     - in `composer.json`: Edit name, description and author, but leave everything else unchanged.
     - in `config.php`   : Insert your OAuth Client ID and Secret. Insert the conversation ID of the conversation the messages should appear in. (See Q&A below how to get it.)
 - `composer install` to install the dependencies.
 - Install plugins via `composer require author/plugin-name`, also see below.
 - Configure plugins in `config.php`.
 - Your bot is now ready!

## Run a Bot

    ./run-bot.php path/to/bot

# Plugins

## Installing plugins

Just `composer require` them!

For all the "core" plugins the name is the same as the directory they reside in, prefixed with their author, e.g. `ciis0/`. In case of doubt, check the name in the plugin's `composer.json`.

    composer require ciis0/circuit-bot-plugin-ex

You meight need to add configurations for those plugins to your `config.php`. Consult their README!

E.g. in this case you would need to add `$config['parent_id']`, which is the ID of an item to reply to.
For more information head over to [lib/plugin-ex/README.md](lib/plugin-ex/README.md)!

Adding those values interactively is a TODO.

## "Core" Plugins

Two plugins are included within this Repo.

  - [plugin-ex](lib/plugin-ex): Example plugin demonstrating how to use the Plugin API
  - [plugin-feed-poll](lib/plugin-feed-poll): A plugin that polls a feed and posts new items. Please not that this plugin is a little bit specific to Rational Team Concert, splitting it up into more generic parts is a TODO.

## Creating plugins

### Plugin "API"

The Plugin "API" is based on Hooks, like in Wordpress, consisting of filters and actions.

Filters are two-way, they are passed a value and return a value.
Actions are one-way, they are passed a value but cannot return a value.

Only actions are used, but more after a quick digression to Circuit conversations.

 - Conversations consist of items (messages)
 - Each item has an ID
 - Items can have a parent (message replies). If an item already has a parent, it cannot be a parent item.

And now: Ducks.

The API can send messages and message replies.
To get woken up by the bot, you will have to add a handler to the `wakeup` action.
From within these action handlers you can send messages by calling `circuit_send_msg(String)` or `circuit_send_msg_adv(AdvancedMessage)`.

(There also is an `wakeup_advanced` action, e.g. the feed-poll plugin still uses this action. This is because previously filters where used and you could send `AdvancedMessages` only from `wakup_advanced`.)


An `AdvancedMessage` is a simple container for a message, message ID and a parent ID.
The message ID is a runtime-unique ID for the message. It is generated by the container's constructor.
The parent ID is the ID of the item to respond to, you meight not know one yet so it can be unset.
Keep reading for how to get a parent ID.

The `parent_id` action is called after a message with an unset parent id was sent.
It is called with the ID of that message and the item ID it now has.

Your plugin cannot read messages from the conversation, so you will have to keep track of the messages you sent and meight want to reply to later. (e.g. sending "server x is down" and later replying "server x is still down!".)

`wakup_advanced`, `parent_id` and `AdvancedMessage` general flow (simplified; m: message, p: parent item ID, id: message id):

```
            | Bot |                     | Plugin
- wakeup -->|     |                     |
            |     |-- wakup_advanced -->| // wakup action would also work
            |     |                     |
            |     |<- send_adv ---------|
            |     |     {               |
            |     |       m : "..."     |
            |     |       p : null      |
            |     |       id: 42        |
            |     |     }               |
            |     |                     |
<- m: "..."-|     |                     |
            |     |                     |
- 7c2e ---->|     |                     |
            |     |                     |
            |     |-- parent_id ------->|
            |     |   id:42, p: 7c2e    |
            |     |                     |
```

### Create a Plugin

Like creating a bot, but name should start with `plugin-` and they should reside in `lib/`.

 - create a directory for your plugin and enter it
 - copy `composer.json.plg-example` to `composer.json` in your directory
    - Edit name, description and author. Leave everything else unchanged.
 - create an index.php (this file will be auto-loaded by Composer)
    - you _must not_ require Composer's autoloader (`vendor/autoload.php`) in your `index.php`, the bot will do that for you.
    - your plugin meight be included multiple times, use `function_exists` and similar means to prevent redefinitions. (Hint: require_once does not help.)

If you want to test your plugin you can use `run-bot.php` with `hooksonly`: `./run-bot.php path/to/bot hooksonly`.
This executes all the hooks only, without sending any messages.<br/>
(Side note: The feed-poll plugin won't update your feed states when run with `hooksonly`, so don't worry about that.)

You are now ready to develop your plugin!

Using hooks:

```php
global $hooks;

// attach to action
// callback is the name of the callback function
$hooks->add_filter($action_name, $callback);

// attach to filter
$hooks->add_filter($filter_name, $callback);

// Due to the library we use for the hooks, callbacks are by default only passed a single parameter.
// To receive more you have to add it to the add_filter/add_action call, but there is a fourth parameter
// you need to set: the priority. Unless you know what your are doing, keep it at 10 (the default value).
$hooks->add_action($filter_name, $callback, 10 /* priority */, $num_args);

// example action with 2 args

$hooks->add_action('parent_id', 'my_parent_id_callback', 10, 2);

function my_parent_id_callback($msg_id, $parent_id)
{

    // do work with $msg_id and $parent_id

    // remember actions returns do nothing
    // but also remember filters always need a return

}
```

# Files

The bot currently creates files in `lib/bot` storing the access-token for the Circuit API.

The feed-poll plugin stores it's state in `lib/bot/plugin-feed-poll/stor`.

Both apply name-spacing so multiple bots or multiple feeds do not clash.

Moving those files to the bot's directory is a TODO.

# Q&A

<dl>
<dt>Conversation ID</dt>
<dd>Goto Circuit, right-click the converstation, copy link to conversation. Everything behind the last `/` is the ID.<br>
Example: The link of the SDK Support Group is <code>https://eu.yourcircuit.com/#/conversation/119dd505-06f3-4848-ad4d-325724519c2e</code>, the ID then is <code>119dd505-06f3-4848-ad4d-325724519c2e</code>.</dd>
<dt>OAuth</dt>
<dd>Register a Circuit Application: <a href="https://yourcircuit.typeform.com/to/sxOjAg">Sandbox</a></dd>
</dl>

# Author

Christoph Schulz, <christoph.2.schulz@atos.net>

September 2017.
