<?php

namespace React\Tests\Dns\Query;

use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\Query;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Promise\Deferred;
use React\Dns\Query\CancellationException;
use React\Tests\Dns\TestCase;

class TimeoutExecutorTest extends TestCase
{
    public function setUp()
    {
        $this->loop = $this->getMock('React\EventLoop\LoopInterface');

        $this->wrapped = $this->getMock('React\Dns\Query\ExecutorInterface');

        $this->executor = new TimeoutExecutor($this->wrapped, $this->loop);
    }

    public function testCancelWrappedWhenCancelled()
    {
        if (!interface_exists('React\Promise\CancellablePromiseInterface')) {
            $this->markTestSkipped('Skipped missing CancellablePromiseInterface');
        }

        $cancelled = 0;

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->will($this->returnCallback(function ($domain, $query) use (&$cancelled) {
                $deferred = new Deferred(function ($resolve, $reject) use (&$cancelled) {
                    ++$cancelled;
                    $reject(new CancellationException('Cancelled'));
                });

                return $deferred->promise();
            }));

        $timer = $this->getMock('React\EventLoop\Timer\TimerInterface');
        $timer
            ->expects($this->once())
            ->method('cancel');

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->with(5, $this->isInstanceOf('Closure'))
            ->will($this->returnValue($timer));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);


        $this->assertEquals(0, $cancelled);
        $promise->cancel();
        $this->assertEquals(1, $cancelled);

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testCancelTimerWhenWrappedResolves()
    {
        $deferred = new Deferred();

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->will($this->returnCallback(function ($domain, $query) use ($deferred) {
                return $deferred->promise();
            }));

        $timer = $this->getMock('React\EventLoop\Timer\TimerInterface');
        $timer
            ->expects($this->once())
            ->method('cancel');

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->with(5, $this->isInstanceOf('Closure'))
            ->will($this->returnValue($timer));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $promise = $this->executor->query('8.8.8.8:53', $query);

        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $deferred->resolve('0.0.0.0');
    }

    public function testWrappedWillBeCancelledOnTimeout()
    {
        if (!interface_exists('React\Promise\CancellablePromiseInterface')) {
            $this->markTestSkipped('Skipped missing CancellablePromiseInterface');
        }

        $cancelled = 0;

        $this->wrapped
            ->expects($this->once())
            ->method('query')
            ->will($this->returnCallback(function ($domain, $query) use (&$cancelled) {
                $deferred = new Deferred(function ($resolve, $reject) use (&$cancelled) {
                    ++$cancelled;
                    $reject(new CancellationException('Cancelled'));
                });

                return $deferred->promise();
            }));

        $timer = $this->getMock('React\EventLoop\Timer\TimerInterface');
        $timer
            ->expects($this->any())
            ->method('cancel');

        $this->loop
            ->expects($this->once())
            ->method('addTimer')
            ->with(5, $this->isInstanceOf('Closure'))
            ->will($this->returnCallback(function ($time, $callback) use (&$timerCallback, &$timer) {
                $timerCallback = $callback;
                return $timer;
            }));

        $this->loop
            ->expects($this->never())
            ->method('cancelTimer');

        $callback = $this->expectCallableNever();

        $errorback = $this->createCallableMock();
        $errorback
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->logicalAnd(
                $this->isInstanceOf('React\Dns\Query\TimeoutException'),
                $this->attribute($this->equalTo('DNS query for igor.io timed out'), 'message')
            ));

        $query = new Query('igor.io', Message::TYPE_A, Message::CLASS_IN, 1345656451);
        $this->executor->query('8.8.8.8:53', $query)->then($callback, $errorback);

        $this->assertNotNull($timerCallback);

        $this->assertEquals(0, $cancelled);
        $timerCallback();
        $this->assertEquals(1, $cancelled);
    }

    private function returnStandardResponse()
    {
        $callback = function ($data, $response) {
            $this->convertMessageToStandardResponse($response);
            return $response;
        };

        return $this->returnCallback($callback);
    }

    private function returnTruncatedResponse()
    {
        $callback = function ($data, $response) {
            $this->convertMessageToTruncatedResponse($response);
            return $response;
        };

        return $this->returnCallback($callback);
    }

    public function convertMessageToStandardResponse(Message $response)
    {
        $response->header->set('qr', 1);
        $response->questions[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN);
        $response->answers[] = new Record('igor.io', Message::TYPE_A, Message::CLASS_IN, 3600, '178.79.169.131');
        $response->prepare();

        return $response;
    }

    public function convertMessageToTruncatedResponse(Message $response)
    {
        $this->convertMessageToStandardResponse($response);
        $response->header->set('tc', 1);
        $response->prepare();

        return $response;
    }

    private function returnNewConnectionMock()
    {
        $conn = $this->createConnectionMock();

        $callback = function () use ($conn) {
            return $conn;
        };

        return $this->returnCallback($callback);
    }

    private function createConnectionMock()
    {
        $conn = $this->getMock('React\Socket\ConnectionInterface');
        $conn
            ->expects($this->any())
            ->method('on')
            ->with('data', $this->isInstanceOf('Closure'))
            ->will($this->returnCallback(function ($name, $callback) {
                $callback(null);
            }));

        return $conn;
    }

    private function createExecutorMock()
    {
        return $this->getMockBuilder('React\Dns\Query\Executor')
            ->setConstructorArgs(array($this->loop, $this->parser, $this->dumper))
            ->setMethods(array('createConnection'))
            ->getMock();
    }
}
