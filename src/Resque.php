<?php

namespace Resque;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Resque\Client\ClientInterface;
use Resque\Failure\BackendInterface;
use Resque\Failure\RedisBackend;
use Resque\Job;
use Resque\Job\Status;
use \InvalidArgumentException;
use Resque\Job\StatusFactory;

/**
 * Base Resque class
 *
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Resque implements LoggerAwareInterface
{
    /**#@+
     * Protocol keys
     *
     * @var string
     */
    const QUEUE_KEY   = 'queue:';
    const QUEUES_KEY  = 'queues';
    const WORKERS_KEY = 'workers';
    /**#@-*/

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var array<string,mixed>
     */
    protected $options;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var BackendInterface
     */
    protected $failures;

    /**
     * @var StatusFactory
     */
    protected $statuses;

    /**
     * Constructor
     * @param ClientInterface $client
     */
    public function __construct($client, array $options = array())
    {
        $this->client = $client;
        $this->logger = new NullLogger();

        $this->configure($options);
    }

    /**
     * Configures the options of the resque background queue system
     *
     * @param array<string,mixed> $options
     * @return void
     */
    public function configure(array $options)
    {
        $this->options = array_merge(array(
            'pgrep'           => 'pgrep -f',
            'pgrep_pattern'   => '[r]esque[^-]',
            'prefix'          => 'resque:',
            'statistic_class' => 'Resque\Statistic'
        ), $options);
    }

    /**
     * Gets the underlying Redis client
     *
     * The Redis client can be any object that implements a suitable subset
     * of Redis commands.
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param \Resque\Failure\BackendInterface $backend
     */
    public function setFailureBackend(BackendInterface $backend)
    {
        $this->failures = $backend;
    }

    /**
     * @return BackendInterface
     */
    public function getFailureBackend()
    {
        if (!isset($this->failures)) {
            $this->failures = new RedisBackend();
        }

        return $this->failures;
    }

    /**
     * @param StatusFactory $factory
     */
    public function setStatusFactory(StatusFactory $factory)
    {
        $this->statuses = $factory;
    }

    /**
     * @return StatusFactory
     */
    public function getStatusFactory()
    {
        if (!isset($this->statuses)) {
            $this->statuses = new StatusFactory($this);
        }

        return $this->statuses;
    }

    /**
     * Causes the client to reconnect to the Redis server
     *
     * @return void
     */
    public function reconnect()
    {
        if ($this->client->isConnected()) {
            $this->client->disconnect();
        }
        $this->client->connect();
    }

    /**
     * Causes the client to connect to the Redis server
     */
    public function connect()
    {
        $this->client->connect();
    }

    /**
     * Disconnects the client from the Redis server
     */
    public function disconnect()
    {
        $this->client->disconnect();
    }

    /**
     * Logs a message
     *
     * @param string $message
     * @param string $priority
     * @return void
     */
    public function log($message, $priority = LogLevel::INFO)
    {
        $this->logger->log($message, $priority);
    }

    /**
     * Gets a namespaced/prefixed key for the given key suffix
     *
     * @param string $key
     * @return string
     */
    public function getKey($key)
    {
        return $this->options['prefix'] . $key;
    }

    /**
     * Push a job to the end of a specific queue. If the queue does not
     * exist, then create it as well.
     *
     * @param string $queue The name of the queue to add the job to.
     * @param array $item Job description as an array to be JSON encoded.
     */
    public function push($queue, $item)
    {
        // Add the queue to the list of queues
        $this->getClient()->sadd($this->getKey(self::QUEUES_KEY), $queue);

        // Add the job to the specified queue
        $this->getClient()->rpush($this->getKey(self::QUEUE_KEY . $queue), json_encode($item));
    }

    /**
     * Pop an item off the end of the specified queue, decode it and
     * return it.
     *
     * @param string $queue The name of the queue to fetch an item from.
     * @return array Decoded item from the queue.
     */
    public function pop($queue)
    {
        $item = $this->getClient()->lpop($this->getKey(self::QUEUE_KEY . $queue));

        if (!$item) {
            return null;
        }

        return json_decode($item, true);
    }

    /**
     * Clears the whole of a queue
     *
     * @param string $queue
     */
    public function clearQueue($queue)
    {
        $this->getClient()->del($this->getKey(self::QUEUE_KEY . $queue));
    }

    /**
     * Return the size (number of pending jobs) of the specified queue.
     *
     * @param $queue name of the queue to be checked for pending jobs
     *
     * @return int The size of the queue.
     */
    public function size($queue)
    {
        return $this->getClient()->llen($this->getKey(self::QUEUE_KEY . $queue));
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $trackStatus Set to true to be able to monitor the status of a job.
     * @throws \InvalidArgumentException
     * @return string
     */
    public function enqueue($queue, $class, $args = null, $trackStatus = false)
    {
        if ($args !== null && !is_array($args)) {
            throw new InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }

        $id = md5(uniqid('', true));

        $this->push($queue, array(
            'class' => $class,
            'args'  => $args,
            'id'    => $id,
        ));

        if ($trackStatus) {
            $status = new Status($id, $this);
            $status->create();
        }

        return $id;
    }

    /**
     * Get an array of all known queues.
     *
     * @return array<string>
     */
    public function queues()
    {
        return $this->getSetMembers(self::QUEUES_KEY);
    }

    /**
     * Gets an array of all known worker IDs
     *
     * @return array<string>
     */
    public function getWorkerIds()
    {
        return $this->getSetMembers(self::WORKERS_KEY);
    }

    /**
     * @param string $suffix Partial key (don't pass to getKey() - let this method do it for you)
     * @return array<string>
     */
    protected function getSetMembers($suffix)
    {
        $members = $this->getClient()->smembers($this->getKey($suffix));

        if (!is_array($members)) {
            $members = array();
        }

        return $members;
    }

    /**
     * @param string $id Worker ID
     * @return bool
     */
    public function workerExists($id)
    {
        return in_array($id, $this->getWorkerIds());
    }

    /**
     * Return an array of process IDs for all of the Resque workers currently
     * running on this machine.
     *
     * Expects pgrep to be in the path, and for it to inspect full argument
     * lists using -f
     *
     * @return array Array of Resque worker process IDs.
     */
    public function getWorkerPids()
    {
        $command = $this->options['pgrep'] . ' ' . escapeshellarg($this->options['pgrep_pattern']);
        echo "Command: $command\n";

        $pids = array();
        $output = null;
        $return = null;

        exec($command, $output, $return);
        var_dump($output);

        /*
         * Exit codes:
         *   0 One or more processes were matched
         *   1 No processes were matched
         *   2 Invalid options were specified on the command line
         *   3 An internal error occurred
         */
        if (($return !== 0 && $return !== 1) || empty($output) || !is_array($output)) {
            $this->logger->warning('Unable to determine worker PIDs');
            return array();
        }

        foreach ($output as $line) {
            $line = explode(' ', trim($line), 2);

            if (!$line[0] || !is_numeric($line[0])) {
                continue;
            }

            $pids[] = (int)$line[0];
        }

        return $pids;
    }

    /**
     * Gets a statistic
     *
     * @param string $name
     * @return \Resque\Statistic
     */
    public function getStatistic($name)
    {
        return new Statistic($this, $name);
    }

    /**
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }
}
