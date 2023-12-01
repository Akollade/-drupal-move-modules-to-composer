<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MoveModulesToComposerCommand extends Command
{
    protected static $defaultName = 'app:move-modules-to-composer';
    protected static $defaultDescription = 'Move modules from sites/all/modules to composer';

    /**
     * @var string
     */
    private $projectPath;

    /**
     * @var string|null
     */
    private $webPath;

    /**
     * @var string|null
     */
    private $siteUri;

    protected function configure(): void
    {
        $this
            ->addArgument('project-path', InputArgument::OPTIONAL, 'Drupal project path')
            ->addOption('site-uri', null, InputOption::VALUE_REQUIRED, 'Site URI if the project is a multisite')
            ->addOption('web-root', null, InputOption::VALUE_REQUIRED, 'Web root path', '')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Move modules to composer');

        $this->projectPath = rtrim($input->getArgument('project-path'), '/');
        $this->webPath = rtrim(sprintf('%s/%s', $this->projectPath, $input->getOption('web-root')), '/');
        $this->siteUri = $input->getOption('site-uri');

        $modulesPath = sprintf('%s/sites/all/modules', $this->webPath);

        $modulesDirectories = glob(sprintf("%s/*", $modulesPath), GLOB_ONLYDIR);
        $io->note(sprintf('Found %d modules', count($modulesDirectories)));

        $filesystem = new Filesystem();

        foreach ($modulesDirectories as $moduleDirectory) {
            $moduleName = basename($moduleDirectory);
            $modulePathFromRoot = str_replace($this->projectPath . '/', '', $moduleDirectory);

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

            $isAlreadyInstalledWithComposer = $this->checkIfAlreadyInstalledWithComposer($moduleName);
            $moduleInfoFromDrush = $this->getModuleInfoFromDrush($moduleName);
            $moduleNotActivated = 'Disabled' === $moduleInfoFromDrush['status'];

            if ($isAlreadyInstalledWithComposer) {
                $io->warning(sprintf('The module is already required with Composer (version %s)', $moduleInfoFromDrush['version']));
            }

            if ($moduleNotActivated) {
                $io->warning('The module is not activated');
            }

            if ($io->confirm(sprintf('Do you want to delete %s ?', $modulePathFromRoot))) {
                $filesystem->remove($moduleDirectory);
                $this->commitChanges(
                    sprintf(':fire: Remove %s', $modulePathFromRoot),
                    $modulePathFromRoot
                );
                $io->note(sprintf('The module %s has been removed', $modulePathFromRoot));
            }

            if ($isAlreadyInstalledWithComposer) {
                if ($moduleNotActivated && $io->confirm('Do you want to uninstall it with composer ?')) {
                    $this->uninstallModuleWithComposer($moduleName);
                    $this->commitChanges(
                        sprintf(':fire: Uninstall %s', $moduleName),
                        sprintf('%s/composer.*', $this->projectPath),
                        sprintf('%s/libraries/*', $this->webPath),
                    );
                    $io->note(sprintf('The module %s has been uninstalled', $moduleName));
                }
            }
            else if (!$moduleNotActivated && $io->confirm('Do you want to install the module with composer ?')) {
                $this->installModuleWithComposer($moduleName, $moduleComposerVersion);
                $this->commitChanges(
                    sprintf('Install %s:%s', $this->getComposerPackageName($moduleName), $moduleComposerVersion),
                    sprintf('%s/composer.*', $this->projectPath),
                    sprintf('%s/libraries/*', $this->webPath),
                );
                $io->note(sprintf('The module %s:%s has been installed', $this->getComposerPackageName($moduleName), $moduleComposerVersion));
            }

            // Rebuild cache to check if everything is ok
            $this->clearDrupalCache();
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

    private function checkIfAlreadyInstalledWithComposer(string $moduleName): bool
    {
        $composerJson = file_get_contents(sprintf('%s/composer.json', $this->projectPath));
        $composerData = json_decode($composerJson, true);

        return array_key_exists($this->getComposerPackageName($moduleName), $composerData['require']);
    }

    private function getModuleInfoFromDrush(string $moduleName): array
    {
        $command = [
            'drush',
            'pm-list',
            sprintf('--filter=%s', $moduleName),
            '--format=json',
        ];
        if ($this->siteUri) {
            $command[] = sprintf('--uri=%s', $this->siteUri);
        }

        $process = new Process($command, $this->projectPath);

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

    private function installModuleWithComposer(string $moduleName, string $version): void
    {
        $command = [
            'composer',
            'require',
            sprintf('%s:%s', $this->getComposerPackageName($moduleName), $version),
        ];

        $process = new Process($command, $this->projectPath);
        $process->mustRun();
    }

    private function uninstallModuleWithComposer(string $moduleName): void
    {
        $command = [
            'composer',
            'remove',
            $this->getComposerPackageName($moduleName),
        ];

        $process = new Process($command, $this->projectPath);
        $process->mustRun();
    }

    private function getComposerPackageName(string $moduleName): string
    {
        return sprintf('drupal/%s', $moduleName);
    }

    private function clearDrupalCache(): void
    {
        $drushCommand = 'drush';
        if ($this->siteUri) {
            $drushCommand .= sprintf(' --uri=%s', $this->siteUri);
        }

        $command = sprintf(
            "%s sql-query \"SHOW TABLES LIKE 'cache%%'\" | xargs -L1 -I%% echo \"TRUNCATE TABLE %%;\" | $(%s sql-connect) -v && %s cr",
            $drushCommand,
            $drushCommand,
            $drushCommand
        );

        $process = Process::fromShellCommandline($command, $this->projectPath);
        $process->mustRun();
    }

    private function commitChanges(string $message, string ...$paths): void
    {
        $commands = [
            array_merge([
                'git',
                'add',
            ], $paths),
            [
                'git',
                'commit',
                '-m',
                $message
            ]
        ];

        foreach($commands as $command) {
            $process = new Process($command, $this->projectPath);
            $process->mustRun();
        }
    }
}
