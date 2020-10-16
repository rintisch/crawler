<?php

declare(strict_types=1);

namespace AOE\Crawler\Backend;

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
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use AOE\Crawler\Backend\RequestForm\RequestFormFactory;
use AOE\Crawler\Configuration\ExtensionConfigurationProvider;
use AOE\Crawler\Controller\CrawlerController;
use AOE\Crawler\Converter\JsonCompatibilityConverter;
use AOE\Crawler\Domain\Repository\QueueRepository;
use AOE\Crawler\Hooks\CrawlerHookInterface;
use AOE\Crawler\Service\ProcessService;
use AOE\Crawler\Value\CrawlAction;
use AOE\Crawler\Value\ModuleSettings;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Info\Controller\InfoModuleController;

/**
 * Function for Info module, containing three main actions:
 * - List of all queued items
 * - Log functionality
 * - Process overview
 */
class BackendModule
{
    /**
     * @var InfoModuleController Contains a reference to the parent calling object
     */
    protected $pObj;

    /**
     * The current page ID
     * @var int
     */
    protected $id;

    // Internal, dynamic:

    /**
     * @var array
     */
    protected $duplicateTrack = [];

    /**
     * @var bool
     */
    protected $submitCrawlUrls = false;

    /**
     * @var bool
     */
    protected $downloadCrawlUrls = false;

    /**
     * @var int
     */
    protected $scheduledTime = 0;

    /**
     * @var int
     */
    protected $reqMinute = 1000;

    /**
     * @var array holds the selection of configuration from the configuration selector box
     */
    protected $incomingConfigurationSelection = [];

    /**
     * @var CrawlerController
     */
    protected $crawlerController;

    /**
     * @var array
     */
    protected $CSVaccu = [];

    /**
     * If true the user requested a CSV export of the queue
     *
     * @var boolean
     */
    protected $CSVExport = false;

    /**
     * @var array
     */
    protected $downloadUrls = [];

    /**
     * Holds the configuration from ext_conf_template loaded by getExtensionConfiguration()
     *
     * @var array
     */
    protected $extensionSettings = [];

    /**
     * Indicate that an flash message with an error is present.
     *
     * @var boolean
     */
    protected $isErrorDetected = false;

    /**
     * @var ProcessService
     */
    protected $processManager;

    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var QueueRepository
     */
    protected $queueRepository;

    /**
     * @var StandaloneView
     */
    protected $view;

    /**
     * @var IconFactory
     */
    protected $iconFactory;

    /**
     * @var JsonCompatibilityConverter
     */
    protected $jsonCompatibilityConverter;

    /**
     * @var LanguageService
     */
    private $languageService;

    /**
     * @var ModuleSettings
     */
    private $moduleSettings;

    public function __construct()
    {
        $this->languageService = $GLOBALS['LANG'];
        $objectManger = GeneralUtility::makeInstance(ObjectManager::class);
        $this->processManager = $objectManger->get(ProcessService::class);
        $this->queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_crawler_queue');
        $this->queueRepository = $objectManger->get(QueueRepository::class);
        $this->initializeView();
        $this->extensionSettings = GeneralUtility::makeInstance(ExtensionConfigurationProvider::class)->getExtensionConfiguration();
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->jsonCompatibilityConverter = GeneralUtility::makeInstance(JsonCompatibilityConverter::class);
    }

    /**
     * Called by the InfoModuleController
     */
    public function init(InfoModuleController $pObj): void
    {

        $this->pObj = $pObj;
        $this->id = (int) GeneralUtility::_GP('id');
        // Setting MOD_MENU items as we need them for logging:
        $this->pObj->MOD_MENU = array_merge($this->pObj->MOD_MENU, $this->getModuleMenu());
    }

    private function getModuleMenu(): array
    {
        return [
            'depth' => [
                0 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_0'),
                1 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_1'),
                2 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_2'),
                3 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_3'),
                4 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_4'),
                99 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_infi'),
            ],
            'crawlaction' => [
                'start' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.start'),
                'log' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.log'),
                'multiprocess' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.multiprocess'),
            ],
            'log_resultLog' => '',
            'log_feVars' => '',
            'processListMode' => '',
            'log_display' => [
                'all' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.all'),
                'pending' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.pending'),
                'finished' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.finished'),
            ],
            'itemsPerPage' => [
                '5' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.itemsPerPage.5'),
                '10' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.itemsPerPage.10'),
                '50' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.itemsPerPage.50'),
                '0' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.itemsPerPage.0'),
            ],
        ];
    }

    /**
     * Additions to the function menu array
     *
     * @return array Menu array
     * @deprecated Using BackendModule->modMenu() is deprecated since 9.1.1 and will be removed in v11.x
     */
    public function modMenu(): array
    {
        return $this->getModuleMenu();
    }

    public function main(): string
    {
        if (empty($this->pObj->MOD_SETTINGS['processListMode'])) {
            $this->pObj->MOD_SETTINGS['processListMode'] = 'simple';
        }
        $this->view->assign('currentPageId', $this->id);

        $selectedAction = new CrawlAction($this->pObj->MOD_SETTINGS['crawlaction'] ?? 'start');

        // Type function menu:
        $actionDropdown = BackendUtility::getFuncMenu(
            $this->id,
            'SET[crawlaction]',
            $selectedAction,
            $this->pObj->MOD_MENU['crawlaction']
        );

        $theOutput = '<h2>' . htmlspecialchars($this->getLanguageService()->getLL('title')) . '</h2>' . $actionDropdown;
        $theOutput .= $this->renderForm($selectedAction);

        return $theOutput;
    }



    /**
     * Generates the configuration selectors for compiling URLs:
     */
    protected function generateConfigurationSelectors(): array
    {
        $selectors = [];
        $selectors['depth'] = $this->selectorBox(
            [
                0 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_0'),
                1 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_1'),
                2 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_2'),
                3 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_3'),
                4 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_4'),
                99 => $this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.depth_infi'),
            ],
            'SET[depth]',
            $this->pObj->MOD_SETTINGS['depth'],
            false
        );

        // Configurations
        $availableConfigurations = $this->crawlerController->getConfigurationsForBranch((int) $this->id, (int) $this->pObj->MOD_SETTINGS['depth'] ?: 0);
        $selectors['configurations'] = $this->selectorBox(
            empty($availableConfigurations) ? [] : array_combine($availableConfigurations, $availableConfigurations),
            'configurationSelection',
            $this->incomingConfigurationSelection,
            true
        );

        // Scheduled time:
        $selectors['scheduled'] = $this->selectorBox(
            [
                'now' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.time.now'),
                'midnight' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.time.midnight'),
                '04:00' => $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.time.4am'),
            ],
            'tstamp',
            GeneralUtility::_POST('tstamp'),
            false
        );

        return $selectors;
    }

    /**
     * Create the rows for display of the page tree
     * For each page a number of rows are shown displaying GET variable configuration
     *
     * @param array $logEntriesOfPage Log items of one page
     * @param string $titleString Title string
     * @return string HTML <tr> content (one or more)
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function drawLog_addRows(array $logEntriesOfPage, string $titleString): string
    {
        $colSpan = 9
            + ($this->pObj->MOD_SETTINGS['log_resultLog'] ? -1 : 0)
            + ($this->pObj->MOD_SETTINGS['log_feVars'] ? 3 : 0);

        if (! empty($logEntriesOfPage)) {
            $setId = (int) GeneralUtility::_GP('setID');
            $refreshIcon = $this->iconFactory->getIcon('actions-system-refresh', Icon::SIZE_SMALL);
            // Traverse parameter combinations:
            $c = 0;
            $content = '';
            foreach ($logEntriesOfPage as $vv) {
                // Title column:
                if (! $c) {
                    $titleClm = '<td rowspan="' . count($logEntriesOfPage) . '">' . $titleString . '</td>';
                } else {
                    $titleClm = '';
                }

                // Result:
                $resLog = $this->getResultLog($vv);

                $resultData = $vv['result_data'] ? $this->jsonCompatibilityConverter->convert($vv['result_data']) : [];
                $resStatus = $this->getResStatus($resultData);

                // Compile row:
                $parameters = $this->jsonCompatibilityConverter->convert($vv['parameters']);

                // Put data into array:
                $rowData = [];
                if ($this->pObj->MOD_SETTINGS['log_resultLog']) {
                    $rowData['result_log'] = $resLog;
                } else {
                    $rowData['scheduled'] = ($vv['scheduled'] > 0) ? BackendUtility::datetime($vv['scheduled']) : ' ' . $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.immediate');
                    $rowData['exec_time'] = $vv['exec_time'] ? BackendUtility::datetime($vv['exec_time']) : '-';
                }
                $rowData['result_status'] = GeneralUtility::fixed_lgd_cs($resStatus, 50);
                $url = htmlspecialchars($parameters['url'] ?? $parameters['alturl']);
                $rowData['url'] = '<a href="' . $url . '" target="_newWIndow">' . $url . '</a>';
                $rowData['feUserGroupList'] = $parameters['feUserGroupList'] ?: '';
                $rowData['procInstructions'] = is_array($parameters['procInstructions']) ? implode('; ', $parameters['procInstructions']) : '';
                $rowData['set_id'] = (string) $vv['set_id'];

                if ($this->pObj->MOD_SETTINGS['log_feVars']) {
                    $resFeVars = $this->getResFeVars($resultData ?: []);
                    $rowData['tsfe_id'] = $resFeVars['id'] ?: '';
                    $rowData['tsfe_gr_list'] = $resFeVars['gr_list'] ?: '';
                    $rowData['tsfe_no_cache'] = $resFeVars['no_cache'] ?: '';
                }

                $trClass = '';
                $warningIcon = '';
                if ($rowData['exec_time'] !== 0 && $resultData === false) {
                    $trClass = 'class="bg-danger"';
                    $warningIcon = $this->iconFactory->getIcon('actions-ban', Icon::SIZE_SMALL);
                }

                // Put rows together:
                $content .= '
                    <tr ' . $trClass . ' >
                        ' . $titleClm . '
                        <td><a href="' . $this->getInfoModuleUrl(['qid_details' => $vv['qid'], 'setID' => $setId]) . '">' . htmlspecialchars((string) $vv['qid']) . '</a></td>
                        <td><a href="' . $this->getInfoModuleUrl(['qid_read' => $vv['qid'], 'setID' => $setId]) . '">' . $refreshIcon . '</a>&nbsp;&nbsp;' . $warningIcon . '</td>';
                foreach ($rowData as $fKey => $value) {
                    if ($fKey === 'url') {
                        $content .= '<td>' . $value . '</td>';
                    } else {
                        $content .= '<td>' . nl2br(htmlspecialchars(strval($value))) . '</td>';
                    }
                }
                $content .= '</tr>';
                $c++;

                if ($this->CSVExport) {
                    // Only for CSV (adding qid and scheduled/exec_time if needed):
                    $rowData['result_log'] = implode('// ', explode(chr(10), $resLog));
                    $rowData['qid'] = $vv['qid'];
                    $rowData['scheduled'] = BackendUtility::datetime($vv['scheduled']);
                    $rowData['exec_time'] = $vv['exec_time'] ? BackendUtility::datetime($vv['exec_time']) : '-';
                    $this->CSVaccu[] = $rowData;
                }
            }
        } else {
            // Compile row:
            $content = '
                <tr>
                    <td>' . $titleString . '</td>
                    <td colspan="' . $colSpan . '"><em>' . $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.noentries') . '</em></td>
                </tr>';
        }

        return $content;
    }

    /**
     * Find Fe vars
     */
    protected function getResFeVars(array $resultData): array
    {
        if (empty($resultData)) {
            return [];
        }
        $requestResult = $this->jsonCompatibilityConverter->convert($resultData['content']);
        return $requestResult['vars'] ?? [];
    }

    /**
     * Extract the log information from the current row and retrieve it as formatted string.
     *
     * @param array $resultRow
     * @return string
     */
    protected function getResultLog($resultRow)
    {
        $content = '';
        if (is_array($resultRow) && array_key_exists('result_data', $resultRow)) {
            $requestContent = $this->jsonCompatibilityConverter->convert($resultRow['result_data']) ?: ['content' => ''];
            if (! array_key_exists('content', $requestContent)) {
                return $content;
            }
            $requestResult = $this->jsonCompatibilityConverter->convert($requestContent['content']);

            if (is_array($requestResult) && array_key_exists('log', $requestResult)) {
                $content = implode(chr(10), $requestResult['log']);
            }
        }
        return $content;
    }

    protected function getResStatus($requestContent): string
    {
        if (empty($requestContent)) {
            return '-';
        }
        if (! array_key_exists('content', $requestContent)) {
            return 'Content index does not exists in requestContent array';
        }

        $requestResult = $this->jsonCompatibilityConverter->convert($requestContent['content']);
        if (is_array($requestResult)) {
            if (empty($requestResult['errorlog'])) {
                return 'OK';
            }
            return implode("\n", $requestResult['errorlog']);
        }

        if (is_bool($requestResult)) {
            return 'Error - no info, sorry!';
        }

        return 'Error: ' . substr(preg_replace('/\s+/', ' ', strip_tags($requestResult)), 0, 10000) . '...';
    }

    /**
     * Returns a link for the panel to enable or disable the crawler
     *
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function getEnableDisableLink(bool $isCrawlerEnabled): string
    {
        if ($isCrawlerEnabled) {
            return $this->getLinkButton(
                'tx-crawler-stop',
                $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.disablecrawling'),
                $this->getInfoModuleUrl(['action' => 'stopCrawling'])
            );
        }
        return $this->getLinkButton(
            'tx-crawler-start',
            $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.enablecrawling'),
            $this->getInfoModuleUrl(['action' => 'resumeCrawling'])
        );
    }

    /**
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function getModeLink(string $mode): string
    {
        if ($mode === 'detail') {
            return $this->getLinkButton(
                'actions-document-view',
                $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.show.running'),
                $this->getInfoModuleUrl(['SET[\'processListMode\']' => 'simple'])
            );
        } elseif ($mode === 'simple') {
            return $this->getLinkButton(
                'actions-document-view',
                $this->getLanguageService()->sL('LLL:EXT:crawler/Resources/Private/Language/locallang.xlf:labels.show.all'),
                $this->getInfoModuleUrl(['SET[\'processListMode\']' => 'detail'])
            );
        }
        return '';
    }

    /**
     * Returns the singleton instance of the crawler.
     */
    protected function findCrawler(): CrawlerController
    {
        if (! $this->crawlerController instanceof CrawlerController) {
            $this->crawlerController = GeneralUtility::makeInstance(CrawlerController::class);
        }
        return $this->crawlerController;
    }

    /*****************************
     *
     * General Helper Functions
     *
     *****************************/

    /**
     * Create selector box
     *
     * @param array $optArray Options key(value) => label pairs
     * @param string $name Selector box name
     * @param string|array $value Selector box value (array for multiple...)
     * @param boolean $multiple If set, will draw multiple box.
     *
     * @return string HTML select element
     */
    protected function selectorBox($optArray, $name, $value, bool $multiple): string
    {
        if (! is_string($value) || ! is_array($value)) {
            $value = '';
        }

        $options = [];
        foreach ($optArray as $key => $val) {
            $selected = (! $multiple && ! strcmp($value, (string) $key)) || ($multiple && in_array($key, (array) $value, true));
            $options[] = '
                <option value="' . $key . '" ' . ($selected ? ' selected="selected"' : '') . '>' . htmlspecialchars($val) . '</option>';
        }

        return '<select class="form-control" name="' . htmlspecialchars($name . ($multiple ? '[]' : '')) . '"' . ($multiple ? ' multiple' : '') . '>' . implode('', $options) . '</select>';
    }

    /**
     * Activate hooks
     */
    protected function runRefreshHooks(): void
    {
        $crawlerLib = GeneralUtility::makeInstance(CrawlerController::class);
        foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['crawler']['refresh_hooks'] ?? [] as $objRef) {
            /** @var CrawlerHookInterface $hookObj */
            $hookObj = GeneralUtility::makeInstance($objRef);
            if (is_object($hookObj)) {
                $hookObj->crawler_init($crawlerLib);
            }
        }
    }

    protected function initializeView(): void
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setLayoutRootPaths(['EXT:crawler/Resources/Private/Layouts']);
        $view->setPartialRootPaths(['EXT:crawler/Resources/Private/Partials']);
        $view->setTemplateRootPaths(['EXT:crawler/Resources/Private/Templates/Backend']);
        $view->getRequest()->setControllerExtensionName('Crawler');
        $this->view = $view;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    protected function getLinkButton(string $iconIdentifier, string $title, UriInterface $href): string
    {
        $moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        return (string) $buttonBar->makeLinkButton()
            ->setHref((string) $href)
            ->setIcon($this->iconFactory->getIcon($iconIdentifier, Icon::SIZE_SMALL))
            ->setTitle($title)
            ->setShowLabelText(true);
    }

    /**
     * Returns the URL to the current module, including $_GET['id'].
     *
     * @param array $uriParameters optional parameters to add to the URL
     *
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    protected function getInfoModuleUrl(array $uriParameters = []): Uri
    {
        if (GeneralUtility::_GP('id')) {
            $uriParameters = array_merge($uriParameters, [
                'id' => GeneralUtility::_GP('id'),
            ]);
        }
        /** @var UriBuilder $uriBuilder */
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        return $uriBuilder->buildUriFromRoute('web_info', $uriParameters);
    }

    private function renderForm(CrawlAction $selectedAction): string
    {
        $requestForm = RequestFormFactory::create($selectedAction, $this->view);
        return $requestForm->render(
            $this->id,
            $this->pObj->MOD_SETTINGS['depth'],
            $this->pObj->MOD_MENU['depth']
        );
    }
}
