<?php
// Copyright 1999-2018. Plesk International GmbH. All rights reserved.

namespace PleskExt\DiskspaceUsageViewer;

class Helper
{
    public static function formatSize($kb)
    {
        if ($kb > 1048576) {
            return round($kb / 1048576, 1) . ' GB';
        } else if ($kb > 1024) {
            return round($kb / 1024, 1) . ' MB';
        } else {
            return round($kb, 1) . ' KB';
        }
    }

    public static function getParentPath($path)
    {
        if ($path != '/') {
            return pathinfo($path, PATHINFO_DIRNAME);
        }
    }

    public static function getDiskspaceUsage($path)
    {
        $list = [];
        $result = \pm_ApiCli::callSbin('diskspace_usage.sh', [$path]);
        $lines = explode("\n", $result['stdout']);

        foreach ($lines as $line) {
            $arr = explode(' ', $line);
            $size = (int)array_shift($arr);
            $name = trim(array_shift($arr));

            if ($name == '.') {
                continue;
            }

            $type = trim(implode(' ', $arr));
            $isDir = ($type == 'directory') ? true : false;

            $list[] = [
                'size' => $size,
                'name' => $name,
                'isDir' => $isDir,
                'displayName' => $isDir ? '/' . $name : $name,
            ];
        }

        return $list;
    }
}