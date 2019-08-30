<?php

use Loop\Core\Action\LoopAction;
use Loop\Core\Loop;
use Loop\Core\Message\RawSocketDescriptorMessageHandler;
use Loop\Protocol\Factory\ProtocolMessageFactory;
use Loop\Protocol\Field\VariableBytesField;
use Loop\Protocol\ProtocolMessage;
use Loop\Util\Logger;

require_once __DIR__.'/../vendor/autoload.php';

Logger::disable();
Logger::enableContexts('socket');

$loop = new \Loop\Core\Loop();

/**
 * Send a test + (incremented counter) message to an udp endpoint every 3 seconds. Echoes back to stdin messages sent back by the endpoint if any.
 *
 * To emulate an echo udp server do the following
 * <code>
 * apt-get install socat
 * socat -v UDP4-LISTEN:6969 exec:'/bin/cat'
 * </code>
 */
$sampleMessageClass = new class() extends ProtocolMessage {
    public function __construct()
    {
        parent::__construct();
        $this->addField((new VariableBytesField())->setName('data'));
    }

    public function setData(string $data): void {
        $this->getField('data')->setValue($data);
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getId(): int
    {
        return 2009;
    }
};


ProtocolMessageFactory::getInstance()->registerProtocol($sampleMessageClass);


// Create UDP client and 'connect' to 6969 at localhost (ipv4)
$fd = socket_create(AF_INET, SOCK_DGRAM, IPPROTO_IP);
socket_connect($fd, '127.0.0.1', 6969);

$clazz = get_class($sampleMessageClass);
$loop->addSocketEvent($fd, $clazz, new RawSocketDescriptorMessageHandler($fd));


$loop->addPeriodTimer(3, function(Loop $loop) use($exampleMessage, $sampleMessageClass) {
   //$loop->submit($exampleMessage);
    static $timer = 0;
    $sampleMessageClass->setData('test ' . (++$timer));
    $loop->submit($sampleMessageClass);
}, -1, true);

$loop->registerActionForTrigger(LoopAction::LOOP_ACTION_MESSAGE_RECEIVED, true, false, function(Loop $loop, ...$messages){
    foreach($messages as $m){
        fprintf(STDOUT, 'In process %5d : Message data : %s %s', posix_getpid(), $m, PHP_EOL);
    }
});

$loop->loop();
