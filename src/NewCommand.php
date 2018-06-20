<?php

namespace Rareloop\Lumberjack\Installer;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    protected $projectPath;
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
        $this->addOption(
            'dev',
            'd',
            InputOption::VALUE_NONE,
            'If set, will use the latest development commits for Bedrock & Lumberjack Theme instead of the most recent stable releases'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $projectFolderName = $input->getArgument('name') ?? $this->defaultFolderName;

        $this->projectPath = getcwd() . '/' . $projectFolderName;

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
        $this->removeGithubFolder();
    }

    protected function removeGithubFolder()
    {
        $this->runCommands([
            'rm -rf ' . escapeshellarg($this->projectPath . '/.github'),
        ]);
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
        $version = null;

        if ($this->input->getOption('dev')) {
            $version = ':dev-master';
        }

        return [
            'rareloop/lumberjack-core' . $version,
        ];
    }

    protected function getServiceProviders() : array
    {
        return [];
    }

    protected function checkoutLatestBedrock()
    {
        $this->output->write('<info>Checking out Bedrock</info>');

        $installedVersion = $this->cloneGitRepository('git@github.com:roots/bedrock.git', $this->projectPath, $this->input->getOption('dev'));

        $this->output->writeln(' (' . $installedVersion . ')');
    }

    protected function installComposerDependencies()
    {
        $this->output->writeln('<info>Installing Composer Dependencies</info>');

        $commands = [
            'cd ' . escapeshellarg($this->projectPath),
            'composer require ' . implode(' ', $this->getComposerDependencies()),
        ];

        $this->runCommands($commands, function ($type, $buffer) {
            $this->output->write($buffer);
        });
    }

    protected function checkoutLatestLumberjackTheme()
    {
        $this->output->write('<info>Adding Lumberjack theme</info>');

        $installedVersion = $this->cloneGitRepository('git@github.com:rareloop/lumberjack.git', $this->themeDirectory, $this->input->getOption('dev'));

        $this->output->writeln(' (' . $installedVersion . ')');
    }

    protected function cloneGitRepository($gitRepo, $filePath, $useDevMaster = false)
    {
        $version = 'dev-master';
        $cloneCommand = 'git clone --depth=1 ' . escapeshellarg($gitRepo) . ' ' . escapeshellarg($filePath);

        if (!$useDevMaster) {
            $latestTag = $this->getLastestTagForGitRepository($gitRepo);

            if ($latestTag) {
                $version = $latestTag;
                $cloneCommand = 'git clone --depth=1 --branch ' . escapeshellarg($version) . ' ' . escapeshellarg($gitRepo) . ' ' . escapeshellarg($filePath);
            }
        }

        $this->runCommands([
            'git clone --depth=1 ' . escapeshellarg($gitRepo) . ' ' . escapeshellarg($filePath),
            'rm -rf ' . escapeshellarg($filePath . '/.git'),
        ]);

        return $version;
    }

    protected function getLastestTagForGitRepository($gitRepo)
    {
        // Get a list of all the tags
        $allTags = explode("\n", trim($this->runCommands(['git ls-remote --tags ' . escapeshellarg($gitRepo) . ' | awk -F/ \'{ print $3 }\''])));

        // Ensure that we only have valid semver tags in the list
        $parser = new VersionParser;

        $allTags = array_map(function ($tag) use ($parser) {
            try {
                // We're using normalise as a way of checking the string is valid. We only need
                // a go/no go decision and need to use the original tag name as we'll use this
                // to do the clone from the repo.
                $parser->normalize($tag);

                // It's a valid tag so it's ok to keep
                return $tag;
            } catch (\Exception $e) {
                // It threw an exception so it's no good
                return false;
            }
        }, $allTags);

        $allTags = array_filter($allTags, function ($tag) {
            return $tag !== false;
        });

        // Sort the tags so we can pick the most recent
        $allTags = Semver::sort($allTags);

        return count($allTags) > 0 ? $allTags[count($allTags) - 1] : false;
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

        $process->mustRun($callback);

        return $process->getOutput();
    }
}
