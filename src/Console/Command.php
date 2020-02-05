<?php

namespace Resque\Console;

use Resque\Client\ClientInterface;
use Resque\Resque;
use Symfony\Component\Console\Command\Command as CommandComponent;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;

abstract class Command extends CommandComponent
{
    /**
     * @return ClientInterface
     */
    public function getRedis() {
        return $this->getHelper('redis')->getClient();
    }

    /**
     * @param OutputInterface $output
     * @return \Resque\Resque
     */
    public function getResque(OutputInterface $output) {
        $resque = new Resque($this->getRedis());
        $resque->setLogger(new ConsoleLogger($output));

        /*
        if (($helper = $this->getHelper('logger'))) {
            $resque->setLogger($helper->getLogger());
        } else {
        }
        */

        return $resque;
    }
}
