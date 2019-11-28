<?php

namespace Rareloop\Lumberjack\Installer;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    protected $installHatchet = false;
    protected $installTrellis = false;

    protected $rootPath;
    protected $projectPath;
    protected $trellisPath;

    protected $input;
    protected $output;

    protected $defaultFolderName = 'lumberjack-bedrock-site';
    protected $projectFolderName;
    protected $themeDirectory;

    protected $name = 'new';
    protected $description = 'Create a new Lumberjack project built on Bedrock';

    protected function configure()
    {
        $this->setName($this->name);
        $this->setDescription($this->description);

        $this->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'The name of the folder to create (defaults to `' . $this->defaultFolderName . '`)'
        );

        $this->addOption(
            '--with-trellis',
            null,
            InputOption::VALUE_NONE,
            'Also install Trellis for deployment'
        );

        $this->addOption(
            '--with-hatchet',
            null,
            InputOption::VALUE_NONE,
            'Also install Hatchet CLI'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->projectFolderName = $input->getArgument('name') ?? $this->defaultFolderName;

        $this->rootPath = getcwd() . '/' . $this->projectFolderName;
        $this->projectPath = getcwd() . '/' . $this->projectFolderName;

        $this->installHatchet |= $input->getOption('with-hatchet');
        $this->installTrellis |= $input->getOption('with-trellis');

        if ($this->installTrellis) {
            $this->projectPath = $this->rootPath . '/site';
            $this->trellisPath = $this->rootPath . '/trellis';
        }

        $this->themeDirectory = $this->projectPath . '/web/app/themes/lumberjack';

        if (file_exists($this->projectPath)) {
            $output->writeln('<error>Can\'t install to: ' . $this->projectPath . '. The directory already exists</error>');
            return;
        }

        try {
            $this->checkForUpdates();
            $this->install();
        } catch (Exception $e) {
            $output->writeln('<error>Install failed</error>');
            $output->writeln('<error>' . get_class($e) . ': ' . $e->getMessage() . '</error>');

            // print in debug mode -vvv
            $output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
        }
    }

    protected function checkForUpdates()
    {
        $this->output->writeln('<info>Checking for updates...</info>');
        try {
            $requiredPackagesThatHaveUpdates = $this->requiredPackagesThatHaveUpdates();
        } catch (Exception $e) {
            $this->output->writeln('<comment>Could not check for updates, maybe `composer` is not in your path?</comment>');
            return;
        }

        if (count($requiredPackagesThatHaveUpdates) === 0) {
            $this->output->writeln('<comment>You are using the latest version of the installer</comment>');
            return;
        }

        $this->output->writeln('<comment>The following updates are available for your installer:</comment>');

        foreach ($requiredPackagesThatHaveUpdates as $package) {
            $this->output->writeln('- ' . $package->name . ' <comment>' . $package->version . '</comment> => <info>' . $package->latest . '</info>');
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>Are you sure you want to continue with the install?</question> [y/n] ', false);

        $this->output->writeln('');

        if (!$helper->ask($this->input, $this->output, $question)) {
            $this->output->writeln('');
            $this->output->writeln('<comment>Install cancelled. To update your installer packages run:</comment>');
            $this->output->writeln('composer global update');
            $this->output->writeln('');

            exit;
        }
    }

    protected function requiredPackagesThatHaveUpdates() : array
    {
        $process = new Process('composer global outdated -D -f json');

        $output = null;

        $result = $process->mustRun(function ($type, $buffer) use (&$output) {
            if ($type === Process::OUT) {
                $output = $buffer;
            }
        });

        $globalPackagesThatHaveUpdates = @json_decode($output);

        if (!$globalPackagesThatHaveUpdates || !isset($globalPackagesThatHaveUpdates->installed)) {
            return [];
        }

        $packagesToCheck = $this->packagesToCheckForUpdates();
        $requiredPackagesThatHaveUpdates = [];

        foreach ($packagesToCheck as $packageName) {
            foreach ($globalPackagesThatHaveUpdates->installed as $updatablePackage) {
                if ($updatablePackage->name === $packageName) {
                    $requiredPackagesThatHaveUpdates[] = $updatablePackage;
                }
            }
        }

        return $requiredPackagesThatHaveUpdates;
    }

    protected function packagesToCheckForUpdates() : array
    {
        return [
            'rareloop/lumberjack-bedrock-installer',
        ];
    }

    protected function install()
    {
        $this->checkoutLatestBedrock();

        if ($this->installTrellis) {
            $this->checkoutLatestTrellis();
        }

        $this->installComposerDependencies();
        $this->checkoutLatestLumberjackTheme();
        $this->addAdditionalDotEnvKeys();
        $this->createLocalDotEnvFile();
        $this->registerServiceProviders();
        $this->removeGithubFolder();

        if ($this->installHatchet) {
            $this->copyHatchetScript();
        }
    }

    protected function copyHatchetScript()
    {
        $this->output->writeln('<info>Install Hatchet CLI</info>');

        $this->runCommands([
            'cp ' . escapeshellarg($this->projectPath . '/vendor/rareloop/hatchet/hatchet') . ' ' . escapeshellarg($this->themeDirectory . '/'),
        ]);
    }

    protected function removeGithubFolder()
    {
        $this->runCommands([
            'rm -rf ' . escapeshellarg($this->projectPath . '/.github'),
        ]);
    }

    protected function getDotEnvLines(): array
    {
        return [
            "\n",
            'APP_KEY=',
        ];
    }

    protected function getComposerDependencies(): array
    {
        $dependencies = [
            'rareloop/lumberjack-core',
        ];

        if ($this->installHatchet) {
            $dependencies[] = 'rareloop/hatchet:^1.0.1';
        }

        return $dependencies;
    }

    protected function getServiceProviders(): array
    {
        return [];
    }

    protected function checkoutLatestBedrock()
    {
        $this->output->writeln('<info>Checking out Bedrock</info>');

        $this->cloneGitRepository('https://github.com/roots/bedrock.git', $this->projectPath);
    }

    protected function checkoutLatestTrellis()
    {
        $this->output->writeln('<info>Checking out Trellis</info>');

        $this->cloneGitRepository('https://github.com/roots/trellis.git', $this->trellisPath);
    }

    protected function installComposerDependencies()
    {
        $this->output->writeln('<info>Installing Composer Dependencies</info>');

        $commands = [
            'cd ' . escapeshellarg($this->projectPath),
            'composer require ' . implode(' ', $this->getComposerDependencies()),
        ];

        $this->runCommands($commands, function ($type, $buffer) {
            // print when in verbose mode -v
            $this->output->write($buffer, false, OutputInterface::VERBOSITY_VERBOSE);
        });
    }

    protected function checkoutLatestLumberjackTheme()
    {
        $this->output->writeln('<info>Adding Lumberjack theme</info>');

        $this->cloneGitRepository('https://github.com/Rareloop/lumberjack.git', $this->themeDirectory);
    }

    protected function cloneGitRepository($gitRepo, $filePath)
    {
        $this->runCommands([
            'git clone --depth=1 ' . escapeshellarg($gitRepo) . ' ' . escapeshellarg($filePath),
            'rm -rf ' . escapeshellarg($filePath . '/.git'),
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

    protected function createLocalDotEnvFile()
    {
        $this->output->writeln('<info>Creating .env</info>');

        copy($this->projectPath . '/.env.example', $this->projectPath . '/.env');
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

        // Find the block of providers config
        // 'providers' => [
        //     ...
        // ],
        preg_match("/'providers' => \[.*?\]/s", $appConfig, $matches);
        $providersConfig = $matches[0];

        // Find all the classes inside the providers block
        preg_match_all('/[A-Za-z0-9\_\\\]+\:\:class/', $providersConfig, $currentProviders);

        // Get the last defined provider and add all new providers below it
        $lastProvider = $currentProviders[0][count($currentProviders[0]) - 1];
        $newProvidersConfig = str_replace($lastProvider, $lastProvider . ",\n        " . implode(",\n        ", $providers), $providersConfig);
        $appConfig = str_replace($providersConfig, $newProvidersConfig, $appConfig);

        file_put_contents($configPath, $appConfig);
    }

    protected function runCommands(array $commands, callable $callback = null)
    {
        foreach ($commands as $command) {
            // print when in debug mode -vvv
            $this->output->writeln('<comment>' . $command . '</comment>', OutputInterface::VERBOSITY_DEBUG);
        }

        $process = new Process(implode(' && ', $commands));

        return $process->mustRun($callback);
    }
}
