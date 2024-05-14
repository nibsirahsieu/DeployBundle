<?php

/*
 * (c) Simone Fumagalli <simone @ iliveinperego.com> - http://www.iliveinperego.com/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Hpatoio\DeployBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Process\Process;

class DeployCommand extends Command
{
    private $configRootPath;
    private $deployConfig;

    public function __construct(string $configRootPath, array $deployConfig)
    {
        $this->configRootPath = $configRootPath;
        $this->deployConfig = $deployConfig;

        parent::__construct();
    }

    /**
     * @see Command
     */
    protected function configure(): void
    {
        $this
            ->setName('project:deploy')
            ->setDescription('Deploy your project via rsync')
            ->addArgument('env', InputArgument::REQUIRED, 'The environment where you want to deploy the project')
            ->addOption('go', null, InputOption::VALUE_NONE, 'Do the deployment')
            ->addOption('rsync-options', null, InputOption::VALUE_NONE, 'Options to pass to the rsync executable')
            ->addOption('force-vendor', null, InputOption::VALUE_NONE, 'Force sync of vendor dir.')
            ;
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $output->getFormatter()->setStyle('notice', new OutputFormatterStyle('red', 'yellow'));

        $env = $input->getArgument('env');

        if (!in_array($env, array_keys($this->deployConfig))) {
            throw new \InvalidArgumentException(sprintf('\'%s\' is no a valid environment. Valid environments: %s', $env, implode(",", array_keys($this->deployConfig))));
        }

        $environment = $this->deployConfig[$env];
        $port = $environment['port'];
        $host = $environment['host'];
        $dir = $environment['dir'];
        $user = $environment['user'];
        $timeout = (int) $environment['timeout'];
        $postDeployOperations = isset($environment['post_deploy_operations']) ? (array) $environment['post_deploy_operations'] : [];

        $command = ['rsync'];

        $dryRun = $input->getOption('go') ? '' : '--dry-run';
        if ($dryRun) {
            $command[] = $dryRun;
        }
        $command = array_merge($command, explode(' ', $environment['rsync_options']));

        if ($input->getOption('rsync-options')) {
            $command = array_merge($command, explode(" ", $input->getOption('rsync-options')));
        }
        if ($input->getOption('force-vendor')) {
            $command[] = "--include 'vendor'";
        }

        $excludeFileNotFound = false;

        if (file_exists($this->configRootPath.'rsync_exclude.txt')) {
            $command[] = sprintf('--exclude-from=%srsync_exclude.txt', $this->configRootPath);
            $excludeFileNotFound = true;
        }

        if (file_exists($this->configRootPath."rsync_exclude_{$env}.txt")) {
            $command[] = sprintf('--exclude-from=%srsync_exclude_%s.txt', $this->configRootPath, $env);
            $excludeFileNotFound = true;
        }

        if (!$excludeFileNotFound) {
            $output->writeln(sprintf('<notice>No rsync_exclude file found, nothing excluded.</notice> If you want an rsync_exclude.txt template get it here http://bit.ly/rsehdbsf2', $this->configRootPath."rsync_exclude.txt"));
            $output->writeln("");
        }

        if ($user) {
            $user = $user.'@';
        }

        $command[] = '-e';
        $command[] = 'ssh -p '.$port.'';
        $command[] = './';
        $command[] = sprintf('%s%s:%s', $user, $host, $dir);

        $output->writeln(sprintf('%s on <info>%s</info> server with <info>%s</info> command',
            ($dryRun) ? 'Fake deploying' : 'Deploying',
            $env,
            implode(" ", $command)));

        $process = new Process($command);
        $process->setTimeout(($timeout == 0) ? null : (float) $timeout);

        $output->writeln("\nSTART deploy\n--------------------------------------------");

        $process->run(function ($type, $buffer) use ($output) {
            if ('err' === $type) {
                $output->write( 'ERR > '.$buffer);
            } else {
                $output->write($buffer);
            }
        });

        $output->writeln("\nEND deploy\n--------------------------------------------\n");

        if ($dryRun) {

            $output->writeln('<notice>This was a simulation, --go was not specified. Post deploy operation not run.</notice>');
            $output->writeln(sprintf('<info>Run the command with --go for really copy the files to %s server.</info>', $env));

        } else {
            $postCommand = [];
            $output->writeln(sprintf("Deployed on <info>%s</info> server!\n", $env));

            if (count($postDeployOperations) > 0 ) {
                $output->writeln(sprintf("Running post deploy commands on <info>%s</info> server!\n", $env));

                $postCommand[] = 'ssh';
                $postCommand[] = sprintf('-p %s', $port);
                $postCommand[] = sprintf('%s%s', $user, $host);
                $postCommand[] = "cd";
                $postCommand[] = $dir. ';';
                foreach ($postDeployOperations as $postDeployOperation) {
                    $postCommand = array_merge($postCommand, explode(" ", $postDeployOperation . ';'));
                }

                $postProcess = new Process($postCommand);
                $postProcess->setTimeout(($timeout == 0) ? null : (float) $timeout);
                $postProcess->run(function ($type, $buffer) use ($output) {
                    if ('err' === $type) {
                        $output->write( 'ERR > '.$buffer);
                    } else {
                        $output->write($buffer);
                    }
                });

                $output->writeln("\nDone");

            }

        }

        $output->writeln("");

        return 0;
    }
}
