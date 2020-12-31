<?php

declare(strict_types=1);

namespace AOE\Crawler\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2020 AOE GmbH <dev@aoe.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use AOE\Crawler\Configuration\ExtensionConfigurationProvider;
use AOE\Crawler\Domain\Repository\ProcessRepository;
use AOE\Crawler\Domain\Repository\QueueRepository;
use AOE\Crawler\Exception\ProcessException;
use AOE\Crawler\Utility\PhpBinaryUtility;
use TYPO3\CMS\Core\Compatibility\PublicMethodDeprecationTrait;
use TYPO3\CMS\Core\Compatibility\PublicPropertyDeprecationTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class ProcessService
 *
 * @package AOE\Crawler\Service
 * @ignoreAnnotation("noRector")
 */
class ProcessService
{
    use PublicMethodDeprecationTrait;
    use PublicPropertyDeprecationTrait;

    /**
     * @var int
     */
    private $timeToLive;

    /**
     * @var \AOE\Crawler\Domain\Repository\ProcessRepository
     */
    private $processRepository;

    /**
     * @var array
     */
    private $extensionSettings;

    /**
     * the constructor
     */
    public function __construct()
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->processRepository = $objectManager->get(ProcessRepository::class);
        $this->queueRepository = $objectManager->get(QueueRepository::class);
        $this->extensionSettings = GeneralUtility::makeInstance(ExtensionConfigurationProvider::class)->getExtensionConfiguration();
        $this->timeToLive = (int) $this->extensionSettings['processMaxRunTime'];
        $this->countInARun = (int) $this->extensionSettings['countInARun'];
        $this->processLimit = (int) $this->extensionSettings['processLimit'];
        $this->verbose = (bool) $this->extensionSettings['processVerbose'];
    }

    /**
     * starts new process
     * @throws ProcessException if no crawler process was started
     */
    public function startProcess(): bool
    {
        $ttl = (time() + $this->timeToLive - 1);
        $current = $this->processRepository->countNotTimeouted($ttl);

        // Check whether OS is Windows
        if (Environment::isWindows()) {
            $completePath = 'start ' . $this->getCrawlerCliPath();
        } else {
            $completePath = '(' . $this->getCrawlerCliPath() . ' &) > /dev/null';
        }

        $fileHandler = CommandUtility::exec($completePath);
        if ($fileHandler === false) {
            throw new ProcessException('could not start process!');
        }
        for ($i = 0; $i < 10; $i++) {
            if ($this->processRepository->countNotTimeouted($ttl) > $current) {
                return true;
            }
            sleep(1);
        }
        throw new ProcessException('Something went wrong: process did not appear within 10 seconds.');
    }

    /**
     * Returns the path to start the crawler from the command line
     */
    public function getCrawlerCliPath(): string
    {
        $phpPath = PhpBinaryUtility::getPhpBinary();
        $typo3BinaryPath = ExtensionManagementUtility::extPath('core') . 'bin/';
        $cliPart = 'typo3 crawler:processQueue';
        // Don't like the spacing, but don't have an better idea for now
        $scriptPath = $phpPath . ' ' . $typo3BinaryPath . $cliPart;

        if (Environment::isWindows()) {
            $scriptPath = str_replace('/', '\\', $scriptPath);
        }

        return ltrim($scriptPath);
    }
}
