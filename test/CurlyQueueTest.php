<?php

require_once __DIR__. '/../src/CurlyQueue/CurlyQueue.php';
require_once __DIR__. '/../vendor/flow/src/Flow/Flow.php';

use \CurlyQueue\CurlyQueue;
use \Flow\Flow;

class CurlyQueueTest extends PHPUnit_Framework_TestCase
{
  public function setUp()
  {
    parent::setUp();

    $this->queueConfig = array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_FOLLOWLOCATION => 1
    );

    $this->queue = new CurlyQueue($this->queueConfig);

    $this->extUrlConfig = array(
      'http://www.google.com/' => uniqid(),
      'http://twitter.com/' => uniqid(),
      'http://www.youtube.com/' => uniqid(),
      'http://www.hulu.com/' => uniqid()
    );
  }

  /**
   * Merged testing of both events to verify their completion order.
   *
   * @group runsResponseAndEndCallback
   * @test
   */
  public function runsResponseAndEndCallback()
  {
    $responses = array();
    $that = $this;

    $this->queue->setResponseCallback(function ($ch, $content, $context) use (&$responses, $that) {
      $info = curl_getinfo($ch);
      $that->assertSame(200, $info['http_code']);
      $that->assertGreaterThanOrEqual(9000, $info['size_download']);
      $responses[$info['url']] = $context;
    });

    $this->queue->setEndCallback(function () use ($that, &$responses) {
      // Called after all response events
      $that->assertSame(count($that->extUrlConfig), count($responses), var_export($responses, true));
    });

    foreach ($this->extUrlConfig as $url => $context) {
      $this->queue->add($url, $context);
    }
    $this->queue->exec();

    $this->assertSame(count($this->extUrlConfig), count($responses));
    foreach ($this->extUrlConfig as $url => $context) {
      $this->assertSame($context, $responses[$url]);
    }
  }

  /**
   * @group runsErrorCallbackForNon200
   * @test
   */
  public function runsErrorCallbackForNon200()
  {
    $responses = array();

    $that = $this;
    $this->queue->setErrorCallback(function ($ch, $context) use (&$responses, $that) {
      $info = curl_getinfo($ch);
      $that->assertSame(404, $info['http_code']);
      $responses[$info['url']] = $context;
    });

    $urlConfig = array(
      'http://www.amazon.com/badUrl-0' => uniqid(),
      'http://www.yahoo.com/badUrl-1' => uniqid(),
      'http://www.google.com/badUrl-2' => uniqid(),
      'https://twitter.com/badUrl-3' => uniqid()
    );
    foreach ($urlConfig as $url => $context) {
      $this->queue->add($url, $context);
    }
    $this->queue->exec();

    $this->assertSame(count($urlConfig), count($responses), var_export(array_keys($responses), true));
    foreach ($urlConfig as $url => $context) {
      $this->assertSame($context, $responses[$url]);
    }
  }

  /**
   * @group runsErrorCallbackForLookups
   * @test
   */
  public function runsErrorCallbackForLookups()
  {
    $responses = array();

    $that = $this;
    $this->queue->setErrorCallback(function ($ch, $context) use (&$responses, $that) {
      $info = curl_getinfo($ch);
      $that->assertSame(0, $info['http_code']);
      $that->assertSame(0, $info['request_size']);
      $responses[$info['url']] = $context;
    });

    // Port which is most likely unused
    $urlConfig = array(
      'http://localhost:8070/badUrl-0' => uniqid(),
      'http://localhost:8070/badUrl-1' => uniqid(),
      'http://localhost:8070/badUrl-2' => uniqid(),
      'http://localhost:8070/badUrl-3' => uniqid(),
      'http://localhost:8070/badUrl-4' => uniqid()
    );
    foreach ($urlConfig as $url => $context) {
      $this->queue->add($url, $context);
    }
    $this->queue->exec();

    $this->assertSame(count($urlConfig), count($responses));
    foreach ($urlConfig as $url => $context) {
      $this->assertSame($context, $responses[$url]);
    }
  }

  /**
   * @group isInterruptable
   * @test
   */
  public function isInterruptable()
  {
    $responses = array();

    $this->queue->setResponseCallback(function ($ch, $content, $context) use (&$responses) {
      $info = curl_getinfo($ch);
      $responses[$info['url']] = $context;
    });

    foreach ($this->extUrlConfig as $url => $context) {
      $this->queue->add($url, $context);
    }

    // exec() should stop before any work
    Flow::setMaxRuntime($this->queue, 0);
    $this->queue->exec();

    $this->assertSame(0, count($responses));
  }

  /**
   * @group handlesBatchLargerThanQueue
   * @test
   */
  public function handlesBatchLargerThanQueue()
  {
    $responses = array();
    $this->queue->setResponseCallback(function ($ch, $content, $context) use (&$responses) {
      $info = curl_getinfo($ch);
      $responses[$info['url']] = $context;
    });
    foreach ($this->extUrlConfig as $url => $context) {
      $this->queue->add($url, $context);
    }

    $queueSize = count($this->extUrlConfig);

    $this->queue->exec($queueSize * 2);
    $this->assertSame($queueSize, count($responses));
  }

  /**
   * @group handlesBatchSmallerThanQueue
   * @test
   */
  public function handlesBatchSmallerThanQueue()
  {
    $responses = array();
    $that = $this;

    $this->queue->setResponseCallback(function ($ch, $content, $context) use (&$responses, $that) {
      $info = curl_getinfo($ch);
      $that->assertSame(200, $info['http_code']);
      $that->assertGreaterThanOrEqual(9000, $info['size_download']);
      $responses[$info['url']] = $context;
    });

    $queueSize = count($this->extUrlConfig);

    $this->queue->setEndCallback(function () use ($queueSize, $that, &$responses) {
      // Called after all response events
      $that->assertSame($queueSize, count($responses), var_export($responses, true));
    });

    foreach ($this->extUrlConfig as $url => $context) {
      $this->queue->add($url, $context);
    }
    $this->queue->exec($queueSize / 2);

    $this->assertSame($queueSize, count($responses));
    foreach ($this->extUrlConfig as $url => $context) {
      $this->assertSame($context, $responses[$url]);
    }
  }
}
