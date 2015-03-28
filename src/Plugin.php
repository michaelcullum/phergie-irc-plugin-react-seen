<?php
/**
 * Phergie plugin that provides a command to display a user's last channel activity (https://github.com/Renegade334/phergie-irc-plugin-react-seen)
 *
 * @link https://github.com/Renegade334/phergie-irc-plugin-react-seen for the canonical source repository
 * @copyright Copyright (c) 2015 Renegade334 (http://www.renegade334.me.uk/)
 * @license http://phergie.org/license Simplified BSD License
 * @package Renegade334\Phergie\Plugin\React\Seen
 */

namespace Renegade334\Phergie\Plugin\React\Seen;

use Phergie\Irc\Bot\React\AbstractPlugin;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\Event\CtcpEventInterface as CtcpEvent;
use Phergie\Irc\Event\ServerEventInterface as ServerEvent;
use Phergie\Irc\Event\UserEventInterface as UserEvent;
use Phergie\Irc\Plugin\React\Command\CommandEvent;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\DriverManager;

/**
 * Plugin class.
 *
 * @category Renegade334
 * @package Renegade334\Phergie\Plugin\React\Seen
 */
class Plugin extends AbstractPlugin
{
    const TYPE_JOIN = 0;
    const TYPE_PART = 1;
    const TYPE_KICK = 2;
    const TYPE_QUIT = 3;
    const TYPE_NICK = 4;
    const TYPE_PRIVMSG = 5;
    const TYPE_NOTICE = 6;
    const TYPE_ACTION = 7;

    const SQL_UPDATE = '
        INSERT OR REPLACE INTO "seen"
        ("time", "server", "channel", "nick", "type", "text")
        VALUES (:time, :server, :channel, :nick, :type, :text)
    ';

    /**
     * Database handle.
     *
     * @var \Doctrine\DBAL\Connection
     */
    protected $db = null;

    /**
     * Stores channel userlists for QUIT handling.
     *
     * @var array
     */
    protected $channels = array();

    /**
     * Returns a crude time ago string.
     *
     * @param int $time Origin timestamp
     *
     * @return string
     */
    protected function ago($time)
    {
        $time = time() - $time;

        if ($time < 60) {
            return 'a moment ago';
        }

        $secs = array(
            'year'   => 365.25 * 24 * 60 * 60,
            'month'  => 30 * 24 * 60 * 60,
            'week'   => 7 * 24 * 60 * 60,
            'day'    => 24 * 60 * 60,
            'hour'   => 60 * 60,
            'minute' => 60,
        );

        foreach ($secs as $str => $s) {
            $d = $time / $s;

            if ($d >= 1) {
                $r = round($d);
                return sprintf('%d %s%s ago', $r, $str, ($r > 1) ? 's' : '');
            }
        }
    }

    /**
     * Checks whether a given nickname is currently on a channel.
     *
     * @param string $server
     * @param string $channel
     * @param string $user (case-insensitive)
     *
     * @return bool
     */
    protected function isInChannel($server, $channel, $user)
    {
        if (!isset($this->channels[$server][$channel]))
        {
            return false;
        }

        $names = array_keys($this->channels[$server][$channel], true, true);
        foreach ($names as $name) {
            if (!strcasecmp($name, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Initialise plugin handler.
     *
     * @param \Doctrine\DBAL\Connection $db Accept a dummy database handle, for use by the test suite
     */
    public function __construct(DBALConnection $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        }
        else {
            try {
                $this->db = DriverManager::getConnection(
                    array(
                        'driver' => 'pdo_sqlite',
                        'path' => __DIR__ . '/../data/seen.db',
                    ),
                    new \Doctrine\DBAL\Configuration
                );
                $this->db->connect();
            } catch (\Exception $e) {
                $this->db = null;
                return;
            }
        }

        // Initialise schema, if this is first run.
        foreach (array(
            'CREATE TABLE IF NOT EXISTS "seen" (
                "time" INTEGER NOT NULL,
                "server" TEXT NOT NULL COLLATE NOCASE,
                "channel" TEXT NOT NULL COLLATE NOCASE,
                "nick" TEXT NOT NULL COLLATE NOCASE,
                "type" INTEGER NOT NULL,
                "text" TEXT COLLATE NOCASE
            )',
            'CREATE UNIQUE INDEX IF NOT EXISTS "seen_primary" on "seen" ("server", "channel", "nick")'
        ) as $query) {
            $this->db->exec($query);
        }
    }

    /**
     * Returns event subscriptions.
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        // No point in listening if we can't do anything with it...
        if ($this->db === null) {
            return [];
        }

        return array(
            'command.seen' => 'handleCommand',
            'command.seen.help' => 'handleCommandHelp',
            'irc.received.join' => 'processJoin',
            'irc.received.part' => 'processPart',
            'irc.received.kick' => 'processKick',
            'irc.received.quit' => 'processQuit',
            'irc.received.nick' => 'processNick',
            'irc.received.privmsg' => 'processPrivmsg',
            'irc.received.notice' => 'processNotice',
            'irc.received.ctcp.action' => 'processAction',
            'irc.received.rpl_namreply' => 'processNames',
        );
    }

    /**
     * Handles the seen command.
     *
     * @param \Phergie\Irc\Plugin\React\Command\CommandEvent $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleCommand(CommandEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $server = strtolower($event->getConnection()->getServerHostname());
        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Command request not in channel, ignoring');
            return;
        }

        $params = $event->getCustomParams();
        if (empty($params)) {
            $this->handleCommandHelp($event, $queue);
            return;
        }

        $target = $params[0];
        try {
            $data = $this->db->fetchAssoc(
               'SELECT "time", "nick", "type", "text"
                FROM "seen"
                WHERE "server" = :server
                AND "channel" = :channel
                AND (
                    "nick" = :nick
                    OR (
                        "type" = ' . self::TYPE_NICK . '
                        AND "text" = :nick
                    )
                )
                ORDER BY "time" DESC
                LIMIT 1',
                array(
                    ':server' => $server,
                    ':channel' => $source,
                    ':nick' => $target,
                )
            );
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
            $queue->ircPrivmsg($source, "\x02Error:\x02 Could not retrieve data.");
            return;
        }

        if ($data === false) {
            $queue->ircPrivmsg($source, "I haven't seen \x02$target\x02 in $source!");
            return;
        }

        switch ($data['type']) {
            case (self::TYPE_JOIN):
                $message = 'joining the channel.';
                break;

            case (self::TYPE_PART):
                if ($data['text']) {
                    $message = "leaving the channel ({$data['text']})";
                }
                else {
                    $message = 'leaving the channel.';
                }
                break;

            case (self::TYPE_KICK):
                if ($data['text']) {
                    $message = "being kicked from the channel ({$data['text']})";
                }
                else {
                    $message = 'being kicked from the channel.';
                }
                break;

            case (self::TYPE_QUIT):
                if ($data['text']) {
                    $message = "disconnecting from IRC ({$data['text']})";
                }
                else {
                    $message = 'disconnecting from IRC.';
                }
                break;

            case (self::TYPE_PRIVMSG):
                $message = "saying: {$data['text']}";
                break;

            case (self::TYPE_NOTICE):
                $message = "sending a notice: {$data['text']}";
                break;

            case (self::TYPE_ACTION):
                $message = "saying: * {$data['nick']} {$data['text']}";
                break;

            case (self::TYPE_NICK):
                if (!strcasecmp($target, $data['nick'])) {
                    $target = $data['nick'];
                    $message = "changing nick to {$data['text']}.";
                }
                else {
                    $target = $data['text'];
                    $message = "changing nick from {$data['nick']}.";
                }
                break;

            default:
                $logger->error('Unknown parameter type retrieved from database', $data);
                $queue->ircPrivmsg($source, "\x02Error:\x02 A database error occurred.");
                return;
        }

        // Canonicalise capitalisation
        if ($data['type'] != self::TYPE_NICK) {
            $target = $data['nick'];
        }

        if ($this->isInChannel($server, $source, $target))
        {
            $prefix = "\x02$target\x02 is currently in the channel! Last seen";
        }
        else {
            $prefix = "\x02$target\x02 was last seen";
        }

        $queue->ircPrivmsg($source, "$prefix " . $this->ago($data['time']) . " $message");
    }

    /**
     * Handles help for the seen command.
     *
     * @param \Phergie\Irc\Plugin\React\Command\CommandEvent $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function handleCommandHelp(CommandEvent $event, Queue $queue)
    {
        $queue->ircPrivmsg($event->getSource(), "\x02Usage:\x02 seen <nickname>");
    }

    /**
     * Monitor channel joins.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processJoin(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $channel = $params['channels'];

        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Nickname of incoming JOIN is ours, ignoring');
            $this->channels[$server][$channel] = [];
            return;
        }

        $logger->debug('Processing incoming JOIN', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
        ));

        $this->channels[$server][$channel][$nick] = true;

        try {
            $this->db->fetchAssoc(self::SQL_UPDATE, array(
                ':time' => time(),
                ':server' => $server,
                ':channel' => $channel,
                ':nick' => $nick,
                ':type' => self::TYPE_JOIN,
                ':text' => null,
            ));
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel parts.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processPart(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $channel = $params['channels'];
        $message = isset($params['message']) ? $params['message'] : null;

        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Removing channel', array('server' => $server, 'channel' => $channel));
            unset($this->channels[$server][$channel]);
            return;
        }

        $logger->debug('Processing incoming PART', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        unset($this->channels[$server][$channel][$nick]);

        try {
            $this->db->fetchAssoc(self::SQL_UPDATE, array(
                ':time' => time(),
                ':server' => $server,
                ':channel' => $channel,
                ':nick' => $nick,
                ':type' => self::TYPE_PART,
                ':text' => $message,
            ));
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel kicks.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processKick(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $channel = $params['channel'];
        $nick = $params['user'];
        $message = isset($params['comment']) ? $params['comment'] : null;

        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Removing channel', array('server' => $server, 'channel' => $channel));
            unset($this->channels[$server][$channel]);
            return;
        }

        $logger->debug('Processing incoming KICK', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        unset($this->channels[$server][$channel][$nick]);

        try {
            $this->db->fetchAssoc(self::SQL_UPDATE, array(
                ':time' => time(),
                ':server' => $server,
                ':channel' => $channel,
                ':nick' => $nick,
                ':type' => self::TYPE_KICK,
                ':text' => $message,
            ));
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor quits.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processQuit(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Abandon ship!');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $message = isset($params['message']) ? $params['message'] : null;

        $logger->debug('Processing incoming QUIT', array(
            'server' => $server,
            'nick' => $nick,
            'message' => $message,
        ));

        foreach ($this->channels[$server] as $channel => $users) {
            if (isset($users[$nick])) {
                $logger->debug('Removing user from channel', array(
                    'server' => $server,
                    'channel' => $channel,
                    'nick' => $nick,
                ));

                unset($this->channels[$server][$channel][$nick]);

                try {
                    $this->db->fetchAssoc(self::SQL_UPDATE, array(
                        ':time' => time(),
                        ':server' => $server,
                        ':channel' => $channel,
                        ':nick' => $nick,
                        ':type' => self::TYPE_QUIT,
                        ':text' => $message,
                    ));
                } catch (\Exception $e) {
                    $logger->error($e->getMessage());
                }
            }
        }
    }

    /**
     * Monitor nick changes.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processNick(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $nick = $event->getNick();
        if ($nick == $event->getConnection()->getNickname()) {
            $logger->debug('Nickname of incoming NICK is ours, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = $event->getParams();
        $newnick = $params['nickname'];

        $logger->debug('Processing incoming NICK', array(
            'server' => $server,
            'nick' => $nick,
            'newnick' => $newnick,
        ));

        foreach ($this->channels[$server] as $channel => $users) {
            if (isset($users[$nick])) {
                $logger->debug('Processing channel nick change', array(
                    'server' => $server,
                    'channel' => $channel,
                    'nick' => $nick,
                    'newnick' => $newnick,
                ));

                unset($this->channels[$server][$channel][$nick]);
                $this->channels[$server][$channel][$newnick] = true;

                try {
                    $this->db->fetchAssoc(self::SQL_UPDATE, array(
                        ':time' => time(),
                        ':server' => $server,
                        ':channel' => $channel,
                        ':nick' => $nick,
                        ':type' => self::TYPE_NICK,
                        ':text' => $newnick,
                    ));
                } catch (\Exception $e) {
                    $logger->error($e->getMessage());
                }
            }
        }
    }

    /**
     * Monitor channel messages.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processPrivmsg(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Incoming PRIVMSG not in channel, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $source;
        $params = $event->getParams();
        $message = $params['text'];

        $logger->debug('Processing incoming PRIVMSG', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        try {
            $this->db->fetchAssoc(self::SQL_UPDATE, array(
                ':time' => time(),
                ':server' => $server,
                ':channel' => $channel,
                ':nick' => $nick,
                ':type' => self::TYPE_PRIVMSG,
                ':text' => $message,
            ));
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel notices.
     *
     * @param \Phergie\Irc\Event\UserEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processNotice(UserEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Incoming NOTICE not in channel, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $source;
        $params = $event->getParams();
        $message = $params['text'];

        $logger->debug('Processing incoming NOTICE', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        try {
            $this->db->fetchAssoc(self::SQL_UPDATE, array(
                ':time' => time(),
                ':server' => $server,
                ':channel' => $channel,
                ':nick' => $nick,
                ':type' => self::TYPE_NOTICE,
                ':text' => $message,
            ));
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    /**
     * Monitor channel actions.
     *
     * @param \Phergie\Irc\Event\CtcpEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processAction(CtcpEvent $event, Queue $queue)
    {
        $logger = $this->getLogger();

        $source = $event->getSource();
        $nick = $event->getNick();
        if ($source === null || $nick === null || $source == $nick) {
            $logger->debug('Incoming CTCP ACTION not in channel, ignoring');
            return;
        }

        $server = strtolower($event->getConnection()->getServerHostname());
        $channel = $event->getSource();
        $params = $event->getCtcpParams();
        $message = $params['action'];

        $logger->debug('Processing incoming CTCP ACTION', array(
            'server' => $server,
            'channel' => $channel,
            'nick' => $nick,
            'message' => $message,
        ));

        try {
            $this->db->fetchAssoc(self::SQL_UPDATE, array(
                ':time' => time(),
                ':server' => $server,
                ':channel' => $channel,
                ':nick' => $nick,
                ':type' => self::TYPE_ACTION,
                ':text' => $message,
            ));
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    /**
     * Populate channels with names on join.
     *
     * @param \Phergie\Irc\Event\ServerEventInterface $event
     * @param \Phergie\Irc\Bot\React\EventQueueInterface $queue
     */
    public function processNames(ServerEvent $event, Queue $queue)
    {
        $special = '\[\]\\`_\^\{\|\}';

        $server = strtolower($event->getConnection()->getServerHostname());
        $params = array_slice($event->getParams(), 2);
        $channel = array_shift($params);

        $this->getLogger()->debug('Adding names to channel', array('server' => $server, 'channel' => $channel));
        
        $names = (count($params) == 1) ? explode(' ', $params[0]) : $params;

        foreach (array_filter($names) as $name) {
            // Strip prefix characters
            $name = preg_replace("/^[^A-Za-z$special]+/", '', $name);

            $this->channels[$server][$channel][$name] = true;
        }
    }

    /**
     * For test suite
     */
    public function getChannelStore()
    {
        return $this->channels;
    }
}
