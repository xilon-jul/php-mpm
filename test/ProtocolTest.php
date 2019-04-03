<?php
require_once __DIR__.'/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class ProtocolTest extends TestCase {

    private function getProtoMock(): \Loop\Protocol\ProtocolMessage {
        \Loop\Protocol\Factory\ProtocolMessageFactory::getInstance()->clear();
        $proto = new \Loop\Protocol\ProcessResolutionProtocolMessage();
        $proto->getField('destination')->setValue(5454);
        $proto->getField('source')->setValue(12);
        $proto->getField('previous_pid')->setValue(123);
        $proto->getField('next_pid')->setValue(1234343);
        $proto->getField('data')->setValue("my data");
        $proto->getField('wrapped')->getField('another_field')->setValue("another dsqdqsdsqdsq");
        return $proto;
    }

    public function testProtocolRead(){

        $proto = $this->getProtoMock();

        $readCb = function(\Loop\Protocol\ProtocolMessage $p){
            $this->assertEquals(5454, $p->getField('destination')->getValue());
            $this->assertEquals("my data", $p->getField('data')->getValue());
            $this->assertEquals("another dsqdqsdsqdsq", $p->getField('wrapped')->getField("another_field")->getValue());
        };

        \Loop\Protocol\Factory\ProtocolMessageFactory::getInstance()->registerProtocol($proto);
        $builder = new \Loop\Protocol\ProtocolBuilder();
        $stream = $builder->setReadCb(get_class($proto), $readCb)->toByteStream($proto);


        $builder->read($stream);

    }

    public function testRecoverOnIncompleteStream(){
        $proto = $this->getProtoMock();
        $proto->getField('destination')->setValue(3222);

        $readCb = function(\Loop\Protocol\ProtocolMessage $p){
            $this->assertEquals(3222, $p->getField('destination')->getValue());
            $this->assertEquals("my data", $p->getField('data')->getValue());
            $this->assertEquals("another dsqdqsdsqdsq", $p->getField('wrapped')->getField("another_field")->getValue());
        };

        \Loop\Protocol\Factory\ProtocolMessageFactory::getInstance()->registerProtocol($proto);
        $builder = new \Loop\Protocol\ProtocolBuilder();
        $stream = $builder->setReadCb(get_class($proto), $readCb)->toByteStream($proto);

        $maxLen = strlen($stream) - 1;
        $step = 1;
        for($i = 1; $i < $maxLen; $i += $step){
            $stream1 = substr($stream, 0, $i);
            $stream2 = substr($stream, $i);
            try {
                $builder->read($stream1);
            }
            catch(\Loop\Protocol\Exception\ProtocolException $e){
                $reconstructedStream = $stream1 . $stream2;
                $builder->read($reconstructedStream);
            }
        }

    }
}
