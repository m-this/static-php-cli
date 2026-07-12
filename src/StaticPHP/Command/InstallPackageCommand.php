<?php

declare(strict_types=1);

namespace StaticPHP\Command;

use StaticPHP\Artifact\ArtifactDownloader;
use StaticPHP\Artifact\DownloaderOptions;
use StaticPHP\DI\ApplicationContext;
use StaticPHP\Exception\WrongUsageException;
use StaticPHP\Package\PackageInstaller;
use StaticPHP\Registry\PackageLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand('install-pkg', 'Install additional package', ['i', 'install-package'])]
class InstallPackageCommand extends BaseCommand
{
    public function configure(): void
    {
        $this->addArgument(
            'package',
            InputArgument::REQUIRED,
            'The package to install (name or path)',
            suggestedValues: function (CompletionInput $input) {
                $packages = [];
                foreach (PackageLoader::getPackages(['target', 'virtual-target', 'tool']) as $name => $_) {
                    $packages[] = $name;
                }
                $val = $input->getCompletionValue();
                return array_filter($packages, fn ($name) => str_starts_with($name, $val));
            }
        );
        $this->addOption('skip-extract', null, null, 'Skip package extraction, just download the package archive');
        $this->getDefinition()->addOptions(DownloaderOptions::getConsoleOptions('dl'));
    }

    public function handle(): int
    {
        ApplicationContext::set('elephant', true);
        if ($this->getOption('skip-extract')) {
            $package = PackageLoader::getPackage($this->input->getArgument('package'));
            if (($artifact = $package->getArtifact()) === null) {
                throw new WrongUsageException("Package '{$package->getName()}' does not have an artifact to download.");
            }
            $downloader = new ArtifactDownloader([
                ...DownloaderOptions::extractFromConsoleOptions($this->input->getOptions(), 'dl'),
                'prefer-binary' => true,
            ]);
            $downloader->add($artifact)->download();
            return static::SUCCESS;
        }
        $installer = new PackageInstaller([...$this->input->getOptions(), 'dl-prefer-binary' => true], true);
        $installer->addInstallPackage($this->input->getArgument('package'));
        $installer->run(true);
        return static::SUCCESS;
    }
}
