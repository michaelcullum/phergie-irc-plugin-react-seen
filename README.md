# Renegade334/phergie-irc-plugin-react-seen

[Phergie](http://github.com/phergie/phergie-irc-bot-react/) plugin for Phergie plugin that provides a command to display a user's last channel activity.

[![Build Status](https://secure.travis-ci.org/Renegade334/phergie-irc-plugin-react-seen.png?branch=master)](http://travis-ci.org/Renegade334/phergie-irc-plugin-react-seen)

## Install

The recommended method of installation is [through composer](http://getcomposer.org).

```JSON
{
    "require": {
        "renegade334/phergie-irc-plugin-react-seen": "dev-master"
    }
}
```

See Phergie documentation for more information on
[installing and enabling plugins](https://github.com/phergie/phergie-irc-bot-react/wiki/Usage#plugins).

## Usage

```php
return [
    'plugins' => [
        new \Phergie\Irc\Plugin\React\Command\Plugin(['prefix' => '!']),
        new \Renegade334\Phergie\Plugin\React\Seen\Plugin,
    ]
];
```

To restrict use of the seen command to particular channels or connections, use the EventFilter plugin:

```php
use Phergie\Irc\Plugin\React\Command\Plugin as CommandPlugin;
use Phergie\Irc\Plugin\React\EventFilter\ChannelFilter;
use Phergie\Irc\Plugin\React\EventFilter\Plugin as EventFilterPlugin;
use Renegade334\Phergie\Plugin\React\Seen\Plugin as SeenPlugin;

return [
    'plugins' => [
        new CommandPlugin(['prefix' => '!']),
        new EventFilterPlugin([
            'filter' => new ChannelFilterPlugin(['#channel1', '#channel2']),
            'plugins' => [new SeenPlugin],
        ]),
    ],
];
```

## Tests

To run the unit test suite:

```
curl -s https://getcomposer.org/installer | php
php composer.phar install
./vendor/bin/phpunit
```

## License

Released under the BSD License. See `LICENSE`.
