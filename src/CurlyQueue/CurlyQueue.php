<?php
/**
 * CurlyQueue class
 *
 * @package CurlyQueue
 */

namespace CurlyQueue;

use \Flow\Interruptable;

require_once __DIR__ . '/../../vendor/flow/src/Flow/Interruptable.php';

/**
 * Mutli-handle, asynchronous, chunked cURL queue with per-request callbacks
 * and context variables.
 */
class CurlyQueue implements Interruptable
{
  /**
   * @var array
   *
   *      array(array('url' => 'url1', 'requestObj' => 'requestObj1'),
   *            array('url' => 'url2', 'requestObj' => 'requestObj2'),
   *            ...);
   */
  protected $queue;

  /**
   * @var array Free-form variables sent to callback to associate
   *            async. responses with origin requests.
   *            Indexed by cURL handle. (Can't rely on URL keys since
   *            curl_getinfo() returns the last effective, not initial, URL).
   */
  protected $requestObjs;

  /**
   * @var callback Post-response callback.
   */
  protected $responseCallback;

  /**
   * @var callback Post-error callback.
   */
  protected $errorCallback;

  /**
   * @var callback Post-queue-completion callback.
   */
  protected $endCallback;

  /**
   * @var array cURL options.
   */
  protected $curlOpts;

  /**
   * @var callable See \Flow\Interruptable interface.
   */
  protected $interruptCheck;

  /**
   * @var resource $mh Handle from curl_multi_init().
   */
  protected $mh;

  /**
   * Inject dependencies.
   *
   * @param array $curlOpts See $this->curlOpts.
   * @return void
   */
  public function __construct($curlOpts)
  {
    $this->curlOpts = $curlOpts;
  }

  /**
   * For Interruptable.
   *
   * {@inheritdoc}
   */
  public function setInterruptCheck($check)
  {
    $this->interruptCheck = $check;
  }

  /**
   * For Interruptable.
   *
   * @inheritdoc
   */
  public function isInterrupted()
  {
    if (call_user_func($this->interruptCheck)) {
      $this->endExec();
      return true;
    }
    return false;
  }

  /**
   * Set the post-response callback.
   *
   * @param callback $callback
   * @return void
   */
  public function setResponseCallback($callback)
  {
    $this->responseCallback = $callback;
  }

  /**
   * Set the post-error callback.
   *
   * @param callback $callback
   * @return void
   */
  public function setErrorCallback($callback)
  {
    $this->errorCallback = $callback;
  }

  /**
   * Set the post-queue callback.
   *
   * @param callback $callback
   * @return void
   */
  public function setEndCallback($callback)
  {
    $this->endCallback = $callback;
  }

  /**
   * Add a URL to the queue.
   *
   * @param string $url
   * @param mixed $requestObj Request identifer(s). See $queue.
   * @return void
   */
  public function add($url, $requestObj)
  {
    $this->queue[] = array('url' => $url, 'requestObj' => $requestObj);
  }

  /**
   * Read a URL from the queue and add the normal cURL handle
   * to a multi-session handle
   *
   * @param int $queuePos Position in $queue.
   * @return int Returns 0 on success, or one of the CURLM_XXX errors code.
   */
  protected function multiAddHandle($queuePos)
  {
    $ch = curl_init();

    // Link handle and request ID data for reassociation in the callback.
    $requestKey = (string) $ch;
    $this->requestObjs[$requestKey] = $this->queue[$queuePos]['requestObj'];

    // Apply default cURL options.
    $options = $this->curlOpts;
    $options[CURLOPT_URL] = $this->queue[$queuePos]['url'];
    $options[CURLOPT_RETURNTRANSFER] = true;
    curl_setopt_array($ch, $options);

    return curl_multi_add_handle($this->mh, $ch);
  }

  /**
   * Fetch queue in asynchronous batches.
   *
   *  -   Uses Josh Fraser's rolling queue approach (see link).
   *  -   Callback and cURL options persist afterward.
   *
   * @link http://onlineaspect.com/2009/01/26/how-to-use-curl_multi-without-blocking/
   * @param int $maxSimul Max cURL handles executed simultaneously.
   * @return boolean True if all handles executed successfully.
   */
  public function exec($maxSimul = 4)
  {
    $this->mh = curl_multi_init();
    $queueRemain = count($this->queue);
    $queuePos = 0;

    // Modulate async. batch size based on limit and queue size.
    if ($maxSimul > $queueRemain) {
      $batchSize = min($maxSimul, $queueRemain);
    } else {
      $batchSize = min($maxSimul, $queueRemain);
    }

    // Seed initial batch.
    for ($b = 0; $b < $batchSize; $b++) {
      $this->multiAddHandle($queuePos);

      $queuePos++;
      $queueRemain--;
    }

    $startTime = time();
    $running = null;

    do {
      if ($this->isInterrupted()) {
        return;
      }

      // Still working on one of the handles.
      do {
        // @codeCoverageIgnoreStart
        if ($this->isInterrupted()) {
          return;
        }
        // @codeCoverageIgnoreEnd

        $execrun = curl_multi_exec($this->mh, $running);
      } while ($execrun == CURLM_CALL_MULTI_PERFORM);

      // Overall session error.
      // @codeCoverageIgnoreStart
      if($execrun != CURLM_OK) {
        $logMsg = 'cURL multi-exec failed with code: ' . $execrun;
        break;
      }
      // @codeCoverageIgnoreEnd

      // One response is ready.
      while($done = curl_multi_info_read($this->mh)) {
        // @codeCoverageIgnoreStart
        if ($this->isInterrupted()) {
          return;
        }
        // @codeCoverageIgnoreEnd

        $info = curl_getinfo($done['handle']);
        $requestKey = (string) $done['handle'];

        if (200 == $info['http_code']) {
          if ($this->responseCallback) {
            call_user_func(
              $this->responseCallback,
              $done['handle'],
              curl_multi_getcontent($done['handle']),
              $this->requestObjs[$requestKey]
            );
          }

          // Add one more request from the queue.
          if ($queueRemain) {
            $this->multiAddHandle($queuePos);

            $queuePos++;
            $queueRemain--;
            $running = true;
          }
        } else {
          if ($this->errorCallback) {
            call_user_func(
              $this->errorCallback,
              $done['handle'],
              $this->requestObjs[$requestKey]
            );
          }

          if ($queueRemain) {
            // Move on to another element
            $this->multiAddHandle($queuePos);

            $queuePos++;
            $queueRemain--;
            $running = true;
          }
        }

        curl_multi_remove_handle($this->mh, $done['handle']);
      }
    } while ($running);

    $this->endExec();
  }

  /**
   * Clean up after exec().
   *
   * @return void
   */
  protected function endExec()
  {
    curl_multi_close($this->mh);

    $this->queue = array();
    $this->requestObjs = array();

    if ($this->endCallback) {
      call_user_func($this->endCallback);
    }
  }
}
