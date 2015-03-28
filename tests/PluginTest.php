<?php
/**
 * Phergie plugin that provides a command to display a user's last channel activity (https://github.com/Renegade334/phergie-irc-plugin-react-seen)
 *
 * @link https://github.com/Renegade334/phergie-irc-plugin-react-seen for the canonical source repository
 * @copyright Copyright (c) 2015 Renegade334 (http://www.renegade334.me.uk/)
 * @license http://phergie.org/license Simplified BSD License
 * @package Renegade334\Phergie\Plugin\React\Seen
 */

namespace Renegade334\Phergie\Tests\Plugin\React\Seen;

use Phake;
use Phergie\Irc\Bot\React\EventQueueInterface as Queue;
use Phergie\Irc\ConnectionInterface;
use Phergie\Irc\Plugin\React\Command\CommandEvent as CommandEvent;
use Renegade334\Phergie\Plugin\React\Seen\Plugin;

/**
 * Tests for the Plugin class.
 *
 * @category Renegade334
 * @package Renegade334\Phergie\Plugin\React\Seen
 */
class PluginTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Instance of the class under test
     *
     * @var \Renegade334\Phergie\Plugin\React\Seen\Plugin
     */
    protected $plugin;

    /**
     * Mock logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Mock connection
     *
     * @var \Phergie\Irc\Connection\ConnectionInterface
     */
    protected $connection;

    /**
     * Mock event queue
     *
     * @var \Phergie\Irc\Bot\React\EventQueueInterface
     */
    protected $queue;

    /**
     * Dummy database handle
     *
     * @var \Doctrine\DBAL\Connection
     */
    protected $db;

    /**
     * Creates a new mock event
     *
     * @param string $interface Fully qualified event interface name
     *
     * @return \Phergie\Irc\Event\EventInterface
     */
    protected function getMockEvent($interface)
    {
        $event = Phake::mock($interface);
        Phake::when($event)->getConnection()->thenReturn($this->connection);
        return $event;
    }

    /**
     * Simulates a JOIN, if any tests rely on a user being "in" a channel.
     *
     * @param string $channel
     * @param string $nick
     */
    protected function dummyJoin($channel, $nick)
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn($nick);
        Phake::when($event)->getParams()->thenReturn(array('channels' => $channel));
        $this->plugin->processJoin($event, $this->queue);
    }

    /**
     * Simulates a call to the seen command with the given parameters.
     *
     * @param string $channel
     * @param string $sender
     * @param string|null $target
     */
    protected function callSeenCommand($source, $sender, $target)
    {
        $event = $this->getMockEvent('\Phergie\Irc\Plugin\React\Command\CommandEvent');
        Phake::when($event)->getSource()->thenReturn($source);
        Phake::when($event)->getNick()->thenReturn($sender);
        Phake::when($event)->getCustomParams()->thenReturn(($target !== null) ? [$target] : []);

        $this->plugin->handleCommand($event, $this->queue);
    }

    /**
     * Initial setup.
     */
    protected function setUp()
    {
        $this->db = \Doctrine\DBAL\DriverManager::getConnection(
            array('driver' => 'pdo_sqlite', 'memory' => true),
            new \Doctrine\DBAL\Configuration
        );
        $this->db->connect();

        $this->plugin = new Plugin($this->db);
        $this->logger = Phake::mock('\Psr\Log\LoggerInterface');
        $this->plugin->setLogger($this->logger);

        $this->connection = Phake::mock('\Phergie\Irc\ConnectionInterface');
        Phake::when($this->connection)->getNickname()->thenReturn('TestNick');
        Phake::when($this->connection)->getUsername()->thenReturn('TestUser');
        Phake::when($this->connection)->getServerHostname()->thenReturn('Test.Server');

        $this->queue = Phake::mock('\Phergie\Irc\Bot\React\EventQueueInterface');
    }

    /**
     * Tests that getSubscribedEvents() returns an array.
     */
    public function testGetSubscribedEvents()
    {
        $this->assertInternalType('array', $this->plugin->getSubscribedEvents());
    }

    /**
     * Tests that getChannelStore() returns an array.
     */
    public function testGetChannelStore()
    {
        $this->assertInternalType('array', $this->plugin->getChannelStore());
    }

    /**
     * Tests that the database schema is initialised correctly.
     */
    public function testDatabaseSchema()
    {
        $sm = $this->db->getSchemaManager();
        $this->assertTrue($sm->tablesExist('seen'));
        $t = $sm->listTableDetails('seen');
        $this->assertSame(array_keys($t->getColumns()), ['time', 'server', 'channel', 'nick', 'type', 'text']);
        $this->assertTrue($t->hasIndex('seen_primary'));
    }

    /**
     * Tests the unique key constraint.
     *
     * @expectedException \Doctrine\DBAL\Exception\UniqueConstraintViolationException
     */
    public function testDatabaseUniqueKey()
    {
        $this->db->insert('seen', array(
            'time' => 1234567890,
            'server' => 'nonexistant.server',
            'channel' => '#nonexistant',
            'nick' => 'NonExistantNick',
            'type' => 0,
            'text' => null,
        ));
        $this->db->insert('seen', array(
            'time' => 135792468,
            'server' => 'NONEXISTANT.SERVER',
            'channel' => '#NonExistant',
            'nick' => 'NONEXISTANTNICK',
            'type' => 1,
            'text' => 'Dummy text',
        ));
    }

    /**
     * Tests parsing of RPL_NAMREPLY.
     */
    public function testLoadNames()
    {
        $data = array(
            // No prefixes
            array(
                'TestNick',
                '=',
                '#testchannel1',
                'TestNick',
                'ChannelNick1',
                'ChannelNick2',
            ),

            // With prefixes
            array(
                'TestNick',
                '@',
                '#testchannel2',
                'TestNick',
                '+ChannelNick1',
                '%ChannelNick2',
                '@ChannelNick3',
                '&@ChannelNick4',
                '!ChannelNick5',
            ),
        );

        $event = $this->getMockEvent('\Phergie\Irc\Event\ServerEventInterface');
        foreach ($data as $params) {
            Phake::when($event)->getParams()->thenReturn($params);
            $this->plugin->processNames($event, $this->queue);

            Phake::verify($this->logger)->debug('Adding names to channel', array(
                'server' => strtolower($this->connection->getServerHostname()),
                'channel' => $params[2],
            ));
        }

        $this->assertSame($this->plugin->getChannelStore(), array('test.server' => array(
            '#testchannel1' => array_fill_keys(['TestNick', 'ChannelNick1', 'ChannelNick2'], true),
            '#testchannel2' => array_fill_keys(['TestNick', 'ChannelNick1', 'ChannelNick2', 'ChannelNick3', 'ChannelNick4', 'ChannelNick5'], true),
        )));
    }

    /**
     * Tests JOIN parsing.
     */
    public function testProcessJoin()
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channels' => '#testchannel'));
        Phake::when($event)->getNick()->thenReturn('JoinTestUser');

        $this->plugin->processJoin($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming JOIN', array(
            'server' => 'test.server',
            'channel' => '#testchannel',
            'nick' => 'JoinTestUser',
        ));

        $this->assertSame(
            $this->db->fetchAssoc(
                'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                array(
                    ':server' => 'test.server',
                    ':channel' => '#testchannel',
                    ':nick' => 'JoinTestUser',
                )
            ),
            array('type' => (string) Plugin::TYPE_JOIN, 'text' => null)
        );

        $channels = $this->plugin->getChannelStore();
        $this->assertTrue(isset($channels['test.server']['#testchannel']['JoinTestUser']) && $channels['test.server']['#testchannel']['JoinTestUser'] === true);

        $this->callSeenCommand('#testchannel', 'TestUser', 'JoinTestUser');
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->logicalAnd(
            $this->stringStartsWith("\x02JoinTestUser\x02 is currently in the channel! Last seen "),
            $this->stringEndsWith(' joining the channel.')
        ));
    }

    /**
     * Data provider for testProcessPart
     *
     * @return array
     */
    public function dataProviderProcessPart()
    {
        return array(
            array('PartTestUser1', null),
            array('PartTestUser2', 'Test PART message'),
        );
    }

    /**
     * Tests PART parsing.
     *
     * @param string $nick
     * @param string|null $message
     * @dataProvider dataProviderProcessPart
     */
    public function testProcessPart($nick, $message)
    {
        $this->dummyJoin('#testchannel', $nick);

        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channels' => '#testchannel', 'message' => $message));
        Phake::when($event)->getNick()->thenReturn($nick);

        $this->plugin->processPart($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming PART', array(
            'server' => 'test.server',
            'channel' => '#testchannel',
            'nick' => $nick,
            'message' => $message,
        ));

        $this->assertSame(
            $this->db->fetchAssoc(
                'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                array(
                    ':server' => 'test.server',
                    ':channel' => '#testchannel',
                    ':nick' => $nick,
                )
            ),
            array('type' => (string) Plugin::TYPE_PART, 'text' => $message)
        );

        $channels = $this->plugin->getChannelStore();
        $this->assertFalse(isset($channels['test.server']['#testchannel'][$nick]));

        $this->callSeenCommand('#testchannel', 'TestUser', $nick);
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->logicalAnd(
            $this->stringStartsWith("\x02$nick\x02 was last seen "),
            $this->stringEndsWith(' leaving the channel' . (empty($message) ? '.' : " ($message)"))
        ));
    }

    /**
     * Tests parsing of own incoming PART.
     */
    public function testProcessPartSelf()
    {
        foreach (range(1, 10) as $n) {
            $this->dummyJoin('#testchannel', "TestUser$n");
        }

        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channels' => '#testchannel'));
        Phake::when($event)->getNick()->thenReturn('TestNick');

        $this->plugin->processPart($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing channel', array('server' => 'test.server', 'channel' => '#testchannel'));

        $channels = $this->plugin->getChannelStore();
        $this->assertFalse(isset($channels['test.server']['#testchannel']));
    }

    /**
     * Data provider for testProcessPart
     *
     * @return array
     */
    public function dataProviderProcessKick()
    {
        return array(
            array('KickTestUser1', null),
            array('KickTestUser2', 'Test KICK reason'),
        );
    }

    /**
     * Tests KICK parsing.
     *
     * @param string $nick
     * @param string|null $reason
     * @dataProvider dataProviderProcessKick
     */
    public function testProcessKick($nick, $reason)
    {
        $this->dummyJoin('#testchannel', $nick);

        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#testchannel', 'user' => $nick, 'comment' => $reason));

        $this->plugin->processKick($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming KICK', array(
            'server' => 'test.server',
            'channel' => '#testchannel',
            'nick' => $nick,
            'message' => $reason,
        ));

        $this->assertSame(
            $this->db->fetchAssoc(
                'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                array(
                    ':server' => 'test.server',
                    ':channel' => '#testchannel',
                    ':nick' => $nick,
                )
            ),
            array('type' => (string) Plugin::TYPE_KICK, 'text' => $reason)
        );

        $channels = $this->plugin->getChannelStore();
        $this->assertFalse(isset($channels['test.server']['#testchannel'][$nick]));

        $this->callSeenCommand('#testchannel', 'TestUser', $nick);
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->logicalAnd(
            $this->stringStartsWith("\x02$nick\x02 was last seen "),
            $this->stringEndsWith(' being kicked from the channel' . (empty($reason) ? '.' : " ($reason)"))
        ));
    }

    /**
     * Tests parsing of own incoming KICK.
     */
    public function testProcessKickSelf()
    {
        foreach (range(1, 10) as $n) {
            $this->dummyJoin('#testchannel', "TestUser$n");
        }

        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getParams()->thenReturn(array('channel' => '#testchannel', 'user' => 'TestNick'));

        $this->plugin->processKick($event, $this->queue);

        Phake::verify($this->logger)->debug('Removing channel', array('server' => 'test.server', 'channel' => '#testchannel'));

        $channels = $this->plugin->getChannelStore();
        $this->assertFalse(isset($channels['test.server']['#testchannel']));
    }

    /**
     * Data provider for testProcessQuit
     *
     * @return array
     */
    public function dataProviderProcessQuit()
    {
        return array(
            array('QuitTestUser1', null),
            array('QuitTestUser2', 'Test QUIT message'),
        );
    }

    /**
     * Tests QUIT parsing.
     *
     * @param string $nick
     * @param string|null $message
     * @dataProvider dataProviderProcessQuit
     */
    public function testProcessQuit($nick, $message)
    {
        $targetChannels = ['#testchannel1', '#testchannel2', '#testchannel3'];
        foreach ($targetChannels as $channel) {
            $this->dummyJoin($channel, $nick);
        }

        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn($nick);
        Phake::when($event)->getParams()->thenReturn(array('message' => $message));

        $this->plugin->processQuit($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming QUIT', array(
            'server' => 'test.server',
            'nick' => $nick,
            'message' => $message,
        ));

        $channels = $this->plugin->getChannelStore();
        foreach ($targetChannels as $channel) {
            Phake::verify($this->logger)->debug('Removing user from channel', array(
                'server' => 'test.server',
                'channel' => $channel,
                'nick' => $nick,
            ));

            $this->assertSame(
                $this->db->fetchAssoc(
                    'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                    array(
                        ':server' => 'test.server',
                        ':channel' => $channel,
                        ':nick' => $nick,
                    )
                ),
                array('type' => (string) Plugin::TYPE_QUIT, 'text' => $message)
            );

            $this->assertFalse(isset($channels['test.server'][$channel][$nick]));

            $this->callSeenCommand($channel, 'TestUser', $nick);
            Phake::verify($this->queue)->ircPrivmsg($channel, $this->logicalAnd(
                $this->stringStartsWith("\x02$nick\x02 was last seen "),
                $this->stringEndsWith(' disconnecting from IRC' . (empty($message) ? '.' : " ($message)"))
            ));
        }
    }

    /**
     * Tests PRIVMSG parsing.
     */
    public function testProcessPrivmsg()
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn('PrivmsgTestUser');
        Phake::when($event)->getSource()->thenReturn('#testchannel');
        Phake::when($event)->getParams()->thenReturn(array('text' => 'Test privmsg'));

        $this->plugin->processPrivmsg($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming PRIVMSG', array(
            'server' => 'test.server',
            'channel' => '#testchannel',
            'nick' => 'PrivmsgTestUser',
            'message' => 'Test privmsg',
        ));

        $this->assertSame(
            $this->db->fetchAssoc(
                'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                array(
                    ':server' => 'test.server',
                    ':channel' => '#testchannel',
                    ':nick' => 'PrivmsgTestUser',
                )
            ),
            array('type' => (string) Plugin::TYPE_PRIVMSG, 'text' => 'Test privmsg')
        );

        $this->callSeenCommand('#testchannel', 'TestUser', 'PrivmsgTestUser');
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->logicalAnd(
            $this->stringStartsWith("\x02PrivmsgTestUser\x02 was last seen "),
            $this->stringEndsWith(' saying: Test privmsg')
        ));
    }

    /**
     * Data provider for various not-in-channel tests
     *
     * @return array
     */
    public function dataProviderNotInChannel()
    {
        return array(
            [null, null],
            [null, 'CommandSource'],
            ['CommandSource', null],
            ['CommandSource', 'CommandSource'],
        );
    }

    /**
     * Tests ignoring of non-channel PRIVMSGs.
     *
     * @param string|null $source
     * @param string|null $nick
     * @dataProvider dataProviderNotInChannel
     */
    public function testProcessPrivmsgNotInChannel($source, $nick)
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn($nick);
        Phake::when($event)->getSource()->thenReturn($source);
        Phake::when($event)->getParams()->thenReturn(array('text' => 'Test privmsg'));

        $this->plugin->processPrivmsg($event, $this->queue);

        Phake::verify($this->logger)->debug('Incoming PRIVMSG not in channel, ignoring');
    }

    /**
     * Tests NOTICE parsing.
     */
    public function testProcessNotice()
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn('NoticeTestUser');
        Phake::when($event)->getSource()->thenReturn('#testchannel');
        Phake::when($event)->getParams()->thenReturn(array('text' => 'Test notice'));

        $this->plugin->processNotice($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming NOTICE', array(
            'server' => 'test.server',
            'channel' => '#testchannel',
            'nick' => 'NoticeTestUser',
            'message' => 'Test notice',
        ));

        $this->assertSame(
            $this->db->fetchAssoc(
                'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                array(
                    ':server' => 'test.server',
                    ':channel' => '#testchannel',
                    ':nick' => 'NoticeTestUser',
                )
            ),
            array('type' => (string) Plugin::TYPE_NOTICE, 'text' => 'Test notice')
        );

        $this->callSeenCommand('#testchannel', 'TestUser', 'NoticeTestUser');
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->logicalAnd(
            $this->stringStartsWith("\x02NoticeTestUser\x02 was last seen "),
            $this->stringEndsWith(' sending a notice: Test notice')
        ));
    }

    /**
     * Tests ignoring of non-channel NOTICEs.
     *
     * @param string|null $source
     * @param string|null $nick
     * @dataProvider dataProviderNotInChannel
     */
    public function testProcessNoticeNotInChannel($source, $nick)
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\UserEventInterface');
        Phake::when($event)->getNick()->thenReturn($nick);
        Phake::when($event)->getSource()->thenReturn($source);
        Phake::when($event)->getParams()->thenReturn(array('text' => 'Test notice'));

        $this->plugin->processNotice($event, $this->queue);

        Phake::verify($this->logger)->debug('Incoming NOTICE not in channel, ignoring');
    }

    /**
     * Tests CTCP ACTION parsing.
     */
    public function testProcessAction()
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\CtcpEventInterface');
        Phake::when($event)->getNick()->thenReturn('ActionTestUser');
        Phake::when($event)->getSource()->thenReturn('#testchannel');
        Phake::when($event)->getCtcpParams()->thenReturn(array('action' => 'Test action'));

        $this->plugin->processAction($event, $this->queue);

        Phake::verify($this->logger)->debug('Processing incoming CTCP ACTION', array(
            'server' => 'test.server',
            'channel' => '#testchannel',
            'nick' => 'ActionTestUser',
            'message' => 'Test action',
        ));

        $this->assertSame(
            $this->db->fetchAssoc(
                'SELECT "type", "text" FROM "seen" WHERE "server" = :server AND "channel" = :channel AND "nick" = :nick',
                array(
                    ':server' => 'test.server',
                    ':channel' => '#testchannel',
                    ':nick' => 'ActionTestUser',
                )
            ),
            array('type' => (string) Plugin::TYPE_ACTION, 'text' => 'Test action')
        );

        $this->callSeenCommand('#testchannel', 'TestUser', 'ActionTestUser');
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->logicalAnd(
            $this->stringStartsWith("\x02ActionTestUser\x02 was last seen "),
            $this->stringEndsWith(' saying: * ActionTestUser Test action')
        ));
    }

    /**
     * Tests ignoring of non-channel CTCP ACTIONs.
     *
     * @param string|null $source
     * @param string|null $nick
     * @dataProvider dataProviderNotInChannel
     */
    public function testProcessActionNotInChannel($source, $nick)
    {
        $event = $this->getMockEvent('\Phergie\Irc\Event\CtcpEventInterface');
        Phake::when($event)->getNick()->thenReturn($nick);
        Phake::when($event)->getSource()->thenReturn($source);
        Phake::when($event)->getParams()->thenReturn(array('text' => 'Test action'));

        $this->plugin->processAction($event, $this->queue);

        Phake::verify($this->logger)->debug('Incoming CTCP ACTION not in channel, ignoring');
    }

    /**
     * Tests ignoring of commands issued outside a channel.
     *
     * @param string|null $source
     * @param string|null $nick
     * @dataProvider dataProviderNotInChannel
     */
    public function testIgnoreSeenCommand($source, $nick)
    {
        $this->callSeenCommand($source, $nick, 'TargetUser');
        Phake::verify($this->logger)->debug('Command request not in channel, ignoring');
    }

    /**
     * Tests output if command called with no parameters.
     */
    public function testSeenCommandNoParameters()
    {
        $this->callSeenCommand('#testchannel', 'TestUser', null);
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', "\x02Usage:\x02 seen <nickname>");
    }

    /**
     * Tests output of command if target user never seen on channel.
     */
    public function testSeenCommandUserNeverSeen()
    {
        $this->callSeenCommand('#testchannel', 'TestUser', 'NonExistantUser');
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', "I haven't seen \x02NonExistantUser\x02 in #testchannel!");
    }

    /**
     * Tests seen command case canonicalisation.
     */
    public function testSeenCommandCaseCanon()
    {
        $this->dummyJoin('#testchannel', 'CaseTestUser');
        $this->callSeenCommand('#testchannel', 'TestUser', 'casetestuser');
        Phake::verify($this->queue)->ircPrivmsg('#testchannel', $this->stringStartsWith("\x02CaseTestUser\x02"));
    }
}
