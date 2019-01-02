<?php
// Copyright 1999-2018. Plesk International GmbH. All rights reserved.

use PleskExt\DiskspaceUsageViewer\Helper;

class IndexController extends pm_Controller_Action
{
    const MAX_PIE_CHART_SLICES = 10;

    private $client;
    private $fileManager;
    private $currentPath = '/';
    private $basePath = '/';

    protected $_accessLevel = ['admin', 'reseller', 'client'];

    public function init()
    {
        parent::init();

        $this->client = pm_Session::getClient();

        if ($this->_getParam('site_id')) {
            $siteId = $this->_getParam('site_id');

            if (!$this->client->hasAccessToDomain($siteId)) {
                throw new pm_Exception('Access denied');
            }

            $this->fileManager = new pm_FileManager($siteId);
            $this->basePath = pm_Domain::getByDomainId($siteId)->getDocumentRoot();

            $this->setCurrentPath($this->basePath);
        } elseif ($this->client->isAdmin()) {
            $this->fileManager = new pm_ServerFileManager;

            $this->setCurrentPath('/');
        } else {
            $this->fileManager = new pm_FileManager(pm_Session::getCurrentDomain()->getId());
            $this->basePath = pm_Session::getCurrentDomain()->getHomePath();

            $this->setCurrentPath($this->basePath);
        }

        $this->view->headLink()->appendStylesheet(pm_Context::getBaseUrl() . 'css/styles.css');

        $this->view->headScript()->appendFile('https://www.gstatic.com/charts/loader.js');
    }

    public function indexAction()
    {
        if ($this->_getParam('path')) {
            $this->setCurrentPath($this->_getParam('path'));
        }

        $usage = Helper::getDiskspaceUsage($this->currentPath);
        $chartData = [];

        if (count($usage) > self::MAX_PIE_CHART_SLICES)
        {
            $top = array_slice($usage, 0, self::MAX_PIE_CHART_SLICES);
            $other = array_slice($usage, self::MAX_PIE_CHART_SLICES);

            foreach ($top as $item) {
                $chartData[] = [$item['displayName'], $item['size'], $item['displayName']];
            }

            $otherSize = 0;

            foreach ($other as $item) {
                $otherSize += $item['size'];
            }

            $label = pm_Locale::lmsg('labelOtherFilesAndDirectories');
            $chartData[] = [$label, $otherSize, $label];
        }
        else
        {
            foreach ($usage as $item) {
                $chartData[] = [$item['displayName'], $item['size'], $item['displayName']];
            }
        }

        $runningTask = Helper::getRunningTask($this->currentPath);

        if (!$runningTask && Helper::needUpdateCache($this->currentPath)) {
            $runningTask = Helper::startTask($this->currentPath);
        }

        $dirSize = 0;

        foreach ($usage as $item) {
            $dirSize += $item['size'];
        }

        $this->view->pageTitle = $this->lmsg('pageTitle', ['path' => $this->getCurrentPathBreadcrumb()]);
        $this->view->chartData = $chartData;
        $this->view->list = $this->getUsageList($usage);
        $this->view->path = $this->currentPath;
        $this->view->runningTask = $runningTask;
        $this->view->isEmptyDir = empty($usage);
        $this->view->dirSize = $dirSize;
    }

    public function indexDataAction()
    {
        if ($this->_getParam('path')) {
            $this->setCurrentPath($this->_getParam('path'));
        }

        $usage = Helper::getDiskspaceUsage($this->currentPath);
        $list = $this->getUsageList($usage);

        $this->_helper->json($list->fetchData());
    }

    public function refreshAction()
    {
        if (!$this->_request->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $task = Helper::startTask($this->_getParam('path'));

        $this->_helper->json($task);
    }

    public function deleteSelectedAction()
    {
        if (!$this->_request->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $paths = (array) $this->_getParam('ids');
        $messages = [];

        foreach ($paths as $path) {
            $path = Helper::cleanPath($path);

            if (Helper::isSystemFile($path)) {
                $messages[] = pm_Locale::lmsg('messageCannotDeleteSystemFile', ['path' => $path]);

                continue;
            }

            try {
                if (Helper::isDir($path, $this->fileManager)) {
                    $this->fileManager->removeDirectory($path);
                } else {
                    $this->fileManager->removeFile($path);
                }
            } catch (\PleskUtilException $e) {
                $messages[] = pm_Locale::lmsg('messageDeleteInsufficientPermissions', ['path' => $path]);
            }
        }

        $parentPath = '/';

        if (!empty($paths)) {
            $path = trim(Helper::cleanPath($paths[0]), '/');

            if ($path != '') {
                $segments = explode('/', $path);

                array_pop($segments);

                if (count($segments) > 0) {
                    $parentPath = '/' . implode('/', $segments);
                }
            }
        }

        unlink(Helper::getCacheFile($parentPath));

        $this->_helper->json($messages);
    }

    private function setCurrentPath($path)
    {
        $path = trim(Helper::cleanPath($path));

        if (!$this->client->isAdmin()) {
            if (substr($path, 0, strlen($this->basePath)) !== $this->basePath) {
                $path = $this->basePath;
            }
        }

        if (!Helper::isDir($path, $this->fileManager)) {
            $path = $this->basePath;
        }

        $this->currentPath = $path;
    }

    private function getCurrentPathBreadcrumb()
    {
        $path = trim($this->currentPath, '/');

        if ($path == '') {
            return '<a href="' . Helper::getActionUrl('index', ['path' => '/']) . '">/</a>';
        }

        $names = explode('/', $path);
        $breadcrumbs = ['<a href="' . Helper::getActionUrl('index', ['path' => '/']) . '">/</a>'];
        $currentPath = '';

        foreach ($names as $name) {
            $currentPath .= '/' . $name;
            $breadcrumbs[] = '<a href="' . Helper::getActionUrl('index', ['path' => $currentPath]) . '">' . htmlspecialchars($name) . '</a> /';
        }

        return '<b>' . implode(' ', $breadcrumbs) . '</b>';
    }

    private function getUsageList(array $usage)
    {
        return new \PleskExt\DiskspaceUsageViewer\UsageList($this->view, $this->_request, $this->currentPath, $usage);
    }
}
