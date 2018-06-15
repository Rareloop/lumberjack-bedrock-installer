<?php

namespace Rareloop\Lumberjack\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    protected $projectPath;
    protected $escapedProjectPath;
    protected $output;

    protected function configure()
    {
        $this->setName('new');
        $this->setDescription('Create a new Lumberjack project built on Bedrock');
        $this->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'The name of the folder to create (defaults to `lumberjack-bedrock-site`)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $projectFolderName = $input->getArgument('name') ?? 'lumberjack-bedrock-site';

        $this->projectPath = getcwd().'/'.$projectFolderName;
        $this->escapedProjectPath = escapeshellarg($this->projectPath);

        if (file_exists($this->projectPath)) {
            $output->writeln('<error>Can\'t install to: '.$this->projectPath.'. The directory already exists</error>');
            return;
        }

        try {
            $this->checkoutLatestBedrock();
            $this->installComposerDependencies();
            $this->checkoutLatestLumberjackTheme();
            $this->addAdditionalDotEnvKeys();
        } catch (\Exception $e) {
            $output->writeln('<error>Install failed</error>');
        }
    }

    protected function checkoutLatestBedrock()
    {
        $this->output->writeln('<info>Checking out Bedrock</info>');

        $commands = [
            'git clone --depth=1 git@github.com:roots/bedrock.git '.$this->escapedProjectPath,
            'rm -rf '.$this->escapedProjectPath.'/.git',
        ];

        $process = new Process(implode(' && ', $commands));

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    protected function installComposerDependencies()
    {
        $this->output->writeln('<info>Installing Composer Dependencies</info>');

        $commands = [
            'cd '.$this->escapedProjectPath,
            'composer require '.implode(' ', $this->getComposerDependencies()),
        ];

        $process = new Process(implode(' && ', $commands));

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    protected function getComposerDependencies()
    {
        return [
            'rareloop/lumberjack-core',
        ];
    }

    protected function checkoutLatestLumberjackTheme()
    {
        $this->output->writeln('<info>Adding Lumberjack theme</info>');

        $themeDirectory = escapeshellarg($this->projectPath.'/web/app/themes/lumberjack');

        $commands = [
            'git clone --depth=1 git@github.com:rareloop/lumberjack.git '.$themeDirectory,
            'rm -rf '.$themeDirectory.'/.git',
        ];

        $process = new Process(implode(' && ', $commands));

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    protected function addAdditionalDotEnvKeys()
    {
        $this->output->writeln('<info>Updating .env.example</info>');

        $file = fopen($this->projectPath.'/.env.example', 'a');

        foreach ($this->getDotEnvLines() as $line) {
            fwrite($file, $line);
        }
    }

    protected function getDotEnvLines()
    {
        return [
            "\n",
            'APP_KEY=',
        ];
    }
}
