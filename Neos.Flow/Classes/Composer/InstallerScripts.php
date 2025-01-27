<?php
declare(strict_types=1);

namespace Neos\Flow\Composer;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Script\Event;
use Composer\Installer\PackageEvent;
use Neos\Flow\Package\PackageManager;
use Neos\Utility\Files;

/**
 * Class for Composer install scripts
 */
class InstallerScripts
{

    /**
     * @var bool
     */
    protected static $postPackageUpdateAndInstallAlreadyRun = false;

    /**
     * @var bool
     */
    protected static $postUpdateAndInstallAlreadyRun = false;

    /**
     * Make sure required paths and files are available outside of Package
     * Run on every Composer install or update - must be configured in root manifest
     *
     * @param Event $event
     * @return void
     * @throws Exception\InvalidConfigurationException
     * @throws \Neos\Flow\Package\Exception
     * @throws \Neos\Utility\Exception\FilesException
     */
    public static function postUpdateAndInstall(Event $event): void
    {
        if (self::$postUpdateAndInstallAlreadyRun) {
            return;
        }

        if (!defined('FLOW_PATH_ROOT')) {
            define('FLOW_PATH_ROOT', Files::getUnixStylePath(getcwd()) . '/');
        }

        if (!defined('FLOW_PATH_PACKAGES')) {
            define('FLOW_PATH_PACKAGES', Files::getUnixStylePath(getcwd()) . '/Packages/');
        }

        if (!defined('FLOW_PATH_CONFIGURATION')) {
            define('FLOW_PATH_CONFIGURATION', Files::getUnixStylePath(getcwd()) . '/Configuration/');
        }
        if (!defined('FLOW_PATH_TEMPORARY_BASE')) {
            define('FLOW_PATH_TEMPORARY_BASE', Files::getUnixStylePath(getcwd()) . '/Data/Temporary');
        }

        Files::createDirectoryRecursively('Configuration');
        Files::createDirectoryRecursively('Data');

        Files::copyDirectoryRecursively('Packages/Framework/Neos.Flow/Resources/Private/Installer/Distribution/Essentials', './', false, true);
        Files::copyDirectoryRecursively('Packages/Framework/Neos.Flow/Resources/Private/Installer/Distribution/Defaults', './', true, true);
        $packageManager = new PackageManager(PackageManager::DEFAULT_PACKAGE_INFORMATION_CACHE_FILEPATH, FLOW_PATH_PACKAGES);
        $packageManager->rescanPackages();

        chmod('flow', 0755);

        self::$postUpdateAndInstallAlreadyRun = true;
    }

    /**
     * Calls actions and install scripts provided by installed packages.
     *
     * @param PackageEvent $event
     * @return void
     * @throws Exception\InvalidConfigurationException
     * @throws Exception\UnexpectedOperationException
     * @throws \Neos\Utility\Exception\FilesException
     */
    public static function postPackageUpdateAndInstall(PackageEvent $event): void
    {
        if (self::$postPackageUpdateAndInstallAlreadyRun) {
            return;
        }

        $operation = $event->getOperation();
        if (!$operation instanceof InstallOperation && !$operation instanceof UpdateOperation) {
            throw new Exception\UnexpectedOperationException('Handling of operation of type "' . get_class($operation) . '" not supported', 1348750840);
        }
        $package = ($operation instanceof InstallOperation) ? $operation->getPackage() : $operation->getTargetPackage();
        $packageExtraConfig = $package->getExtra();
        $installPath = $event->getComposer()->getInstallationManager()->getInstallPath($package);

        if (isset($packageExtraConfig['neos']['installer-resource-folders'])) {
            foreach ($packageExtraConfig['neos']['installer-resource-folders'] as $installerResourceDirectory) {
                static::copyDistributionFiles($installPath . $installerResourceDirectory);
            }
        }

        if ($operation instanceof InstallOperation && isset($packageExtraConfig['neos/flow']['post-install'])) {
            self::runPackageScripts($packageExtraConfig['neos/flow']['post-install']);
        }

        if ($operation instanceof UpdateOperation && isset($packageExtraConfig['neos/flow']['post-update'])) {
            self::runPackageScripts($packageExtraConfig['neos/flow']['post-update']);
        }

        self::$postPackageUpdateAndInstallAlreadyRun = true;
    }

    /**
     * Copies any distribution files to their place if needed.
     *
     * @param string $installerResourcesDirectory Path to the installer directory that contains the Distribution/Essentials and/or Distribution/Defaults directories.
     * @return void
     * @throws \Neos\Utility\Exception\FilesException
     */
    protected static function copyDistributionFiles(string $installerResourcesDirectory): void
    {
        $essentialsPath = $installerResourcesDirectory . 'Distribution/Essentials';
        if (is_dir($essentialsPath)) {
            Files::copyDirectoryRecursively($essentialsPath, Files::getUnixStylePath(getcwd()) . '/', false, true);
        }

        $defaultsPath = $installerResourcesDirectory . 'Distribution/Defaults';
        if (is_dir($defaultsPath)) {
            Files::copyDirectoryRecursively($defaultsPath, Files::getUnixStylePath(getcwd()) . '/', true, true);
        }
    }

    /**
     * Calls a static method from it's string representation
     *
     * @param string $staticMethodReference
     * @return void
     * @throws Exception\InvalidConfigurationException
     */
    protected static function runPackageScripts(string $staticMethodReference): void
    {
        $className = substr($staticMethodReference, 0, strpos($staticMethodReference, '::'));
        $methodName = substr($staticMethodReference, strpos($staticMethodReference, '::') + 2);

        if (!class_exists($className)) {
            throw new Exception\InvalidConfigurationException('Class "' . $className . '" is not autoloadable, can not call "' . $staticMethodReference . '"', 1348751076);
        }
        if (!is_callable($staticMethodReference)) {
            throw new Exception\InvalidConfigurationException('Method "' . $staticMethodReference . '" is not callable', 1348751082);
        }
        $className::$methodName();
    }
}
