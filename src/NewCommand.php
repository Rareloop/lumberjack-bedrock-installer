<?php

namespace Rareloop\Lumberjack\Installer;

use Exception;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

class NewCommand extends Command
{
    protected $projectPath;
    protected $escapedProjectPath;
    protected $output;
    protected $defaultFolderName = 'lumberjack-bedrock-site';
    protected $themeDirectory;

    protected function configure()
    {
        $this->setName('new');
        $this->setDescription('Create a new Lumberjack project built on Bedrock');
        $this->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'The name of the folder to create (defaults to `' . $this->defaultFolderName . '`)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $projectFolderName = $input->getArgument('name') ?? $this->defaultFolderName;

        $this->projectPath = getcwd() . '/' . $projectFolderName;
        $this->escapedProjectPath = escapeshellarg($this->projectPath);

        $this->themeDirectory = $this->projectPath . '/web/app/themes/lumberjack';

        if (file_exists($this->projectPath)) {
            $output->writeln('<error>Can\'t install to: ' . $this->projectPath . '. The directory already exists</error>');
            return;
        }

        try {
            $this->install();
        } catch (Exception $e) {
            $output->writeln('<error>Install failed</error>');
        }
    }

    protected function install()
    {
        $this->checkoutLatestBedrock();
        $this->installComposerDependencies();
        $this->checkoutLatestLumberjackTheme();
        $this->addAdditionalDotEnvKeys();
        $this->registerServiceProviders();
    }

    protected function getDotEnvLines() : array
    {
        return [
            "\n",
            'APP_KEY=',
        ];
    }

    protected function getComposerDependencies() : array
    {
        return [
            'rareloop/lumberjack-core',
        ];
    }

    protected function getServiceProviders() : array
    {
        return [];
    }

    protected function checkoutLatestBedrock()
    {
        $this->output->writeln('<info>Checking out Bedrock</info>');

        $this->cloneGitRepository('git@github.com:roots/bedrock.git', $this->projectPath);
    }

    protected function installComposerDependencies()
    {
        $this->output->writeln('<info>Installing Composer Dependencies</info>');

        $commands = [
            'cd ' . $this->escapedProjectPath,
            'composer require ' . implode(' ', $this->getComposerDependencies()),
        ];

        $this->runCommands($commands, function ($type, $buffer) {
            $this->output->write($buffer);
        });
    }

    protected function checkoutLatestLumberjackTheme()
    {
        $this->output->writeln('<info>Adding Lumberjack theme</info>');

        $this->cloneGitRepository('git@github.com:rareloop/lumberjack.git', $this->themeDirectory);
    }

    protected function cloneGitRepository($gitRepo, $filePath)
    {
        $this->runCommands([
            'git clone --depth=1 ' . escapeshellarg($gitRepo) . ' ' . escapeshellarg($filePath),
            'rm -rf ' . $filePath . '/.git',
        ]);
    }

    protected function addAdditionalDotEnvKeys()
    {
        $this->output->writeln('<info>Updating .env.example</info>');

        $file = fopen($this->projectPath . '/.env.example', 'a');

        foreach ($this->getDotEnvLines() as $line) {
            fwrite($file, $line);
        }
    }

    protected function registerServiceProviders()
    {
        $providers = $this->getServiceProviders();

        if (empty($providers)) {
            return;
        }

        $this->output->writeln('<info>Registering ServiceProviders</info>');

        $configPath = $this->projectPath . '/web/app/themes/lumberjack/config/app.php';

        $appConfig = file_get_contents($configPath);
        $appConfig = str_replace("'providers' => [", "'providers' => [\n\t\t" . implode(",\n\t\t", $providers) . ",\n", $appConfig);

        file_put_contents($configPath, $appConfig);
    }

    protected function runCommands(array $commands, callable $callback = null)
    {
        $process = new Process(implode(' && ', $commands));

        return $process->mustRun($callback);
    }
}
