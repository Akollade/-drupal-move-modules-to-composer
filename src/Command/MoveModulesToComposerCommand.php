<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

class MoveModulesToComposerCommand extends Command
{
    protected static $defaultName = 'app:move-modules-to-composer';
    protected static $defaultDescription = 'Add a short description for your command';

    protected function configure(): void
    {
        $this
            ->addArgument('projectPath', InputArgument::OPTIONAL, 'Drupal project path')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectPath = $input->getArgument('projectPath');
        $modulesPath = sprintf('%s/sites/all/modules', $projectPath);

        foreach (glob(sprintf("%s/*", $modulesPath), GLOB_ONLYDIR) as $moduleDirectory) {
            $moduleName = basename($moduleDirectory);

            $moduleInfo = file_get_contents(sprintf("%s/%s/%s.info.yml", $modulesPath, $moduleName, $moduleName));

            try {
                $moduleInfoData = Yaml::parse($moduleInfo);
            } catch (ParseException $exception) {
                printf('Unable to parse the module info YAML: %s', $exception->getMessage());
            }

            if (false === array_key_exists('version', $moduleInfoData)) {
                throw new \LogicException('Version is missing in module info');
            }

            $moduleVersion = $moduleInfoData['version'];

            $io->note(sprintf(
                "%s - %s => %s",
                $moduleName,
                $moduleVersion,
                $this->getComposerConstraintFromVersion($moduleVersion)
            ));

        }


        return Command::SUCCESS;
    }

    private function getComposerConstraintFromVersion(string $version): string
    {
        if (str_contains($version, '8.x-')) {
            return str_replace('8.x-', '^', $version);
        }

        return $version;
    }
}
