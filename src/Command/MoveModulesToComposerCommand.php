<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MoveModulesToComposerCommand extends Command
{
    protected static $defaultName = 'app:move-modules-to-composer';
    protected static $defaultDescription = 'Add a short description for your command';

    protected function configure(): void
    {
        $this
            ->addArgument('project-path', InputArgument::OPTIONAL, 'Drupal project path')
            ->addOption('site-uri', null, InputOption::VALUE_REQUIRED, 'Site URI if the project is a multisite')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Move modules to composer');

        $projectPath = $input->getArgument('project-path');
        $siteUri = $input->getOption('site-uri');

        $modulesPath = sprintf('%s/sites/all/modules', $projectPath);

        $modulesDirectories = glob(sprintf("%s/*", $modulesPath), GLOB_ONLYDIR);
        $io->note(sprintf('Found %d modules', count($modulesDirectories)));

        foreach ($modulesDirectories as $moduleDirectory) {
            $moduleName = basename($moduleDirectory);
            $io->section(sprintf('Module %s', $moduleName));

            $moduleInfo = file_get_contents(sprintf("%s/%s/%s.info.yml", $modulesPath, $moduleName, $moduleName));

            try {
                $moduleInfoData = Yaml::parse($moduleInfo);
            } catch (ParseException $exception) {
                printf('Unable to parse the module info YAML: %s', $exception->getMessage());

                return self::FAILURE;
            }

            if (false === array_key_exists('version', $moduleInfoData)) {
                throw new \LogicException('Version is missing in module info');
            }

            $moduleVersion = $moduleInfoData['version'];
            $moduleReleasePage = sprintf('https://www.drupal.org/project/%s/releases/%s', $moduleName, $moduleVersion);
            $moduleComposerVersion = $this->getComposerVersionFromVersion($moduleVersion);

            $io->definitionList(
                ['Release page' => $moduleReleasePage],
                ['Version' => $moduleVersion],
                ['Composer version' => $moduleComposerVersion],
            );

            $isAlreadyInstalledWithComposer = $this->checkIfAlreadyInstalledWithComposer($projectPath, $moduleName);
            $moduleInfoFromDrush = $this->getModuleInfoFromDrush($projectPath, $moduleName, $siteUri);
            $moduleNotActivated = 'not installed' === $moduleInfoFromDrush['status'];

            if ($isAlreadyInstalledWithComposer) {
                $io->warning(sprintf('The module is already required with Composer (version %s)', $moduleInfoFromDrush['version']));
            }

            if ($moduleNotActivated) {
                $io->warning('The module is not activated, you can remove it');
            }

            $io->comment(sprintf(
                'Command to install the module with composer: composer require drupal/%s:%s',
                $moduleName,
                $moduleComposerVersion
            ));
        }

        return Command::SUCCESS;
    }

    private function getComposerVersionFromVersion(string $version): string
    {
        if (str_contains($version, '8.x-')) {
            $composerVersion = str_replace('8.x-', '^', $version);

            if (preg_match("/-(alpha|beta)\d+/", $composerVersion, $matches)) {
                $composerVersion = str_replace($matches[0], "@" . $matches[1], $composerVersion);
            }

            return $composerVersion;
        }

        return $version;
    }

    private function checkIfAlreadyInstalledWithComposer(string $projectPath, string $moduleName): bool
    {
        $composerJson = file_get_contents(sprintf('%s/composer.json', $projectPath));
        $composerData = json_decode($composerJson, true);

        return array_key_exists('drupal/' . $moduleName, $composerData['require']);
    }

    private function getModuleInfoFromDrush(string $projectPath, string $moduleName, ?string $siteUri): array
    {
        $command = [
            'drush',
            'pm-info',
            $moduleName,
            '--format=json',
        ];
        if ($siteUri) {
            $command[] = sprintf('--uri=%s', $siteUri);
        }

        $process = new Process($command, $projectPath);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            if (str_contains($process->getErrorOutput(), 'Command pm-info needs a higher bootstrap level to run')) {
                throw new \InvalidArgumentException('Your project is a multisite, you must use the option --site-uri=SITE_URI');
            }

            throw $exception;
        }

        return json_decode($process->getOutput(), true)[$moduleName];
    }
}
