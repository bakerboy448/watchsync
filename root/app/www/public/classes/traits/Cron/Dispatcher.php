<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

trait Dispatcher
{
    protected $dispatchOpened = false;

    public function dispatch()
    {
        $lock = $this->setLockFile('dispatcher');
        if (!$lock) {
            return;
        }

        try {
            $this->queueAutomaticSync();
            $this->processQueue();
            $this->dispatchBackup();
            $this->dispatchHousekeeper();
            $this->dispatchWebhookQueue();
        } finally {
            $this->removeLockFile($lock);
            $this->dispatchLogClose();
        }
    }

    public function dispatchLog($msg)
    {
        if (!$this->dispatchOpened) {
            logger(CRON_DISPATCHER_LOG, 'dispatcher ->');
            $this->dispatchOpened = true;
        }
        logger(CRON_DISPATCHER_LOG, $msg);
    }

    public function dispatchLogClose()
    {
        if (!$this->dispatchOpened) {
            return;
        }

        logger(CRON_DISPATCHER_LOG, 'dispatcher <-');
        $this->dispatchOpened = false;
    }

    public function dispatchBackup()
    {
        $queued = $this->database->getSetting('backupQueued');
        if (!$queued && intval(date('i')) % 10 != 0) {
            return 'wait';
        }

        $time = normalizeBackupTime($this->database->getSetting('backupTime') ?: '03:00');
        if ($queued) {
            $result = $this->hasLockFile('backup') ? 'running' : 'manual';
        } else if (date('H:i') != $time) {
            $result = 'skip';
        } else if ($this->hasLockFile('backup')) {
            $result = 'running';
        } else if ($this->automaticBackupExists()) {
            $result = 'exists';
        } else {
            $result = 'automatic';
        }

        $this->dispatchLog('backup=' . $result);
        if ($result == 'manual' || $result == 'automatic') {
            $this->spawnScript('backup.php', '', CRON_DISPATCHER_LOG);
        }

        return $result;
    }

    public function dispatchHousekeeper()
    {
        if (intval(date('i')) % 10 != 0) {
            return 'wait';
        }
        $result = $this->hasLockFile('housekeeper') ? 'running' : 'started';
        $this->dispatchLog('housekeeper=' . $result);
        if ($result == 'started') {
            $this->spawnScript('housekeeper.php', '', CRON_DISPATCHER_LOG);
        }

        return $result;
    }

    public function dispatchWebhookQueue()
    {
        if (intval(date('i')) % 10 != 0) {
            return 'wait';
        }
        $result = $this->hasLockFile('webhookQueue') ? 'running' : 'started';
        $this->dispatchLog('webhookQueue=' . $result);
        if ($result == 'started') {
            $this->spawnScript('webhookQueue.php', '', CRON_DISPATCHER_LOG);
        }

        return $result;
    }

    public function runWebhookQueue()
    {
        logger(CRON_WEBHOOK_QUEUE_LOG, 'webhookQueue ->');
        $lock = $this->setLockFile('webhookQueue');
        try {
            if (!$lock) {
                logger(CRON_WEBHOOK_QUEUE_LOG, 'already running');
                return;
            }

            webhookReplayQueued();
        } finally {
            $this->removeLockFile($lock);
            logger(CRON_WEBHOOK_QUEUE_LOG, 'webhookQueue <-');
        }
    }

    public function runBackup($type = '')
    {
        logger(CRON_BACKUP_LOG, 'backup ->');
        $lock = false;
        try {
            if ($type == 'manual') {
                logger(CRON_BACKUP_LOG, 'type=manual');
                if (!is_dir(BACKUP_PATH)) {
                    mkdir(BACKUP_PATH, 0755, true);
                }
                $started = time();
                $path    = $this->database->mysqli_backup('manual');
                $success = !empty($path);
                logger(CRON_BACKUP_LOG, 'result=' . ($success ? 'success' : 'failed') . ($success ? ' path=' . $path : ''));
                $this->notifyBackup('manual', $success, $success ? $path : '', $started);
                return $success;
            }

            $lock = $this->setLockFile('backup');
            if (!$lock) {
                logger(CRON_BACKUP_LOG, 'already running');
                return false;
            }

            $queued = $this->database->getSetting('backupQueued');
            $time   = normalizeBackupTime($this->database->getSetting('backupTime') ?: '03:00');
            $type   = $queued ? 'manual' : 'automatic';
            logger(CRON_BACKUP_LOG, 'type=' . $type);
            if (!$queued && date('H:i') != $time) {
                logger(CRON_BACKUP_LOG, 'skip');
                return false;
            }
            if (!$queued && $this->automaticBackupExists()) {
                logger(CRON_BACKUP_LOG, 'already exists');
                return false;
            }

            if (!is_dir(BACKUP_PATH)) {
                mkdir(BACKUP_PATH, 0755, true);
            }

            $started = time();
            $path    = $this->database->mysqli_backup($type);
            $success = !empty($path);
            if ($success && $queued) {
                $this->database->setSetting('backupQueued', '');
            }
            logger(CRON_BACKUP_LOG, 'result=' . ($success ? 'success' : 'failed') . ($success ? ' path=' . $path : ''));
            $this->notifyBackup($type, $success, $success ? $path : '', $started);
            return $success;
        } finally {
            $this->removeLockFile($lock);
            logger(CRON_BACKUP_LOG, 'backup <-');
        }
    }

    public function runHousekeeper()
    {
        global $shell;

        logger(CRON_HOUSEKEEPER_LOG, 'housekeeper ->');
        $lock = $this->setLockFile('housekeeper');
        try {
            if (!$lock) {
                logger(CRON_HOUSEKEEPER_LOG, 'already running');
                return;
            }

            $dirs = [
                LOGS_PATH . 'crons',
                LOGS_PATH . 'system',
                LOGS_PATH . 'webhooks',
                JOBS_PATH,
                BACKUP_PATH,
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            $keep    = intval($this->database->getSetting('backupKeep') ?: 7);
            $removed = 0;
            $dir     = opendir(BACKUP_PATH);
            while ($backup = readdir($dir)) {
                if (!is_dir(BACKUP_PATH . $backup)) {
                    logger(CRON_HOUSEKEEPER_LOG, 'removing=' . BACKUP_PATH . $backup);
                    $shell->exec('rm -rf ' . BACKUP_PATH . $backup);
                    $removed++;
                } else if ($backup[0] != '.') {
                    $daysBetween = daysBetweenDates($backup, date('Ymd'));
                    if ($daysBetween >= $keep) {
                        logger(CRON_HOUSEKEEPER_LOG, 'removing=' . BACKUP_PATH . $backup);
                        $shell->exec('rm -rf ' . BACKUP_PATH . $backup);
                        $removed++;
                    }
                }
            }
            closedir($dir);
            if ($removed) {
                logger(CRON_HOUSEKEEPER_LOG, 'backups removed=' . $removed);
            }
            $this->purgeOldLogs();
            $this->pruneHistoryRetention();
            $this->purgeDeletedUsers();
        } finally {
            $this->removeLockFile($lock);
            logger(CRON_HOUSEKEEPER_LOG, 'housekeeper <-');
        }
    }

    public function purgeOldLogs()
    {
        global $shell;

        if (intval(date('H')) != 0 || intval(date('i')) > 5) {
            return;
        }

        $cleanup = [
            'crons' => [
                'message' => 'Cron log file cleanup (daily @ midnight)',
                'length'  => max(1, intval($this->database->getSetting('cronLogLength') ?: 1)),
            ],
            'system' => [
                'message' => 'System log file cleanup (daily @ midnight)',
                'length'  => max(1, intval($this->database->getSetting('systemLogLength') ?: 1)),
            ],
            'webhooks' => [
                'message' => 'Webhook log file cleanup (daily @ midnight)',
                'length'  => max(1, intval($this->database->getSetting('webhookLogLength') ?: 1)),
            ],
        ];

        foreach ($cleanup as $group => $settings) {
            $thisDir = LOGS_PATH . $group . '/';
            if (!is_dir($thisDir)) {
                continue;
            }

            logger(CRON_HOUSEKEEPER_LOG, $settings['message']);
            logger(CRON_HOUSEKEEPER_LOG, 'Allowed ' . $group . ' log age: ' . $settings['length']);

            $folder = opendir($thisDir);
            while ($log = readdir($folder)) {
                $path = $thisDir . $log;
                if ($log[0] == '.' || is_dir($path) || !isLogFile($log) || str_ends_with($log, '.lock')) {
                    continue;
                }
                if ($group == 'webhooks' && preg_match('/_queued_\d+\.log$/', $log) == 1) {
                    continue;
                }

                $daysBetween = daysBetweenDates(date('Ymd', filemtime($path)), date('Ymd'));
                logger(CRON_HOUSEKEEPER_LOG, 'logfile: ' . $path . ', days: ' . $daysBetween);
                if ($daysBetween > $settings['length']) {
                    logger(CRON_HOUSEKEEPER_LOG, 'removing logfile');
                    $shell->exec('rm -rf ' . escapeshellarg($path));
                }
            }
            closedir($folder);
        }
    }

    public function historyRetentionLimit($name)
    {
        $limit = intval($this->database->getSetting($name));
        if ($limit < 1) {
            $limit = 1000;
        }

        return $limit;
    }

    public function pruneHistoryRetention()
    {
        $syncRemoved = $this->pruneSyncHistory($this->historyRetentionLimit('syncHistoryRetention'));
        if ($syncRemoved) {
            logger(CRON_HOUSEKEEPER_LOG, 'sync history removed=' . $syncRemoved);
            loggerFlush(CRON_HOUSEKEEPER_LOG);
        }

        $webhookRemoved = $this->pruneWebhookHistory($this->historyRetentionLimit('webhookHistoryRetention'));
        if ($webhookRemoved) {
            logger(CRON_HOUSEKEEPER_LOG, 'webhook history removed=' . $webhookRemoved);
            loggerFlush(CRON_HOUSEKEEPER_LOG);
        }
    }

    public function pruneSyncHistory($keep)
    {
        $rows = $this->database->getSyncHistoryJobs();
        $extra = count($rows) - intval($keep);
        if ($extra < 1) {
            return 0;
        }

        usort($rows, function ($a, $b) {
            $aTime = intval($a['queued'] ?? 0);
            $bTime = intval($b['queued'] ?? 0);
            if ($aTime == $bTime) {
                return intval($a['id'] ?? 0) - intval($b['id'] ?? 0);
            }

            return $aTime - $bTime;
        });

        $removed = 0;
        foreach ($rows as $row) {
            if ($removed >= $extra) {
                break;
            }
            $status = strval($row['status'] ?? '');
            if ($status == 'running' || $status == 'queued') {
                continue;
            }

            $jobId = strval($row['job_id'] ?? '');
            $log   = strval($row['log_file'] ?? '');
            if ($log != '' && is_file($log)) {
                unlink($log);
            }
            if ($jobId != '') {
                $defaultLog = CRON_LOGS_PATH . $jobId . '.log';
                if (($log == '' || $log != $defaultLog) && is_file($defaultLog)) {
                    unlink($defaultLog);
                }
                $this->database->deleteSyncHistory($jobId);
            }
            $removed++;
        }

        return $removed;
    }

    public function pruneWebhookHistory($keep)
    {
        if (!function_exists('webhookLogRows')) {
            return 0;
        }

        $rows  = webhookLogRows();
        $extra = count($rows) - intval($keep);
        if ($extra < 1) {
            return 0;
        }

        $removed = 0;
        foreach (array_reverse($rows) as $row) {
            if ($removed >= $extra) {
                break;
            }
            if (strval($row['code'] ?? '') == 'queued') {
                continue;
            }

            $file = strval($row['file'] ?? '');
            if ($file == '' || str_contains($file, '/') || str_contains($file, '\\') || !str_ends_with($file, '.log')) {
                continue;
            }
            $path = WEBHOOK_LOGS_PATH . $file;
            if (!is_file($path)) {
                continue;
            }
            unlink($path);
            $removed++;
        }

        return $removed;
    }

    public function purgeDeletedUsers()
    {
        global $mediaApps;

        $mediaApps->purgeDeletedUsers();
    }

    public function spawnScript($script, $arg = '', $log = '')
    {
        global $shell;

        $log    = $log ?: ($this->logfile ?: CRON_DISPATCHER_LOG);
        $script = basename($script);
        $file   = str_replace('\\', '/', ABSOLUTE_PATH) . 'crons/' . $script;
        if (!str_ends_with($script, '.php') || !is_file($file)) {
            $this->cronLine($log, $script . ' not found');
            return;
        }

        $cmd = '/usr/bin/php ' . $file . ($arg != '' ? ' ' . $arg : '');
        $this->cronLine($log, 'spawn=' . $script . ($arg != '' ? ' ' . $arg : ''));
        $pid = $shell->background($cmd);
        $this->cronLine($log, 'pid=' . ($pid ?: '0'));
    }

    public function cronLine($log, $msg)
    {
        if ($log == CRON_DISPATCHER_LOG) {
            $this->dispatchLog($msg);
            return;
        }

        logger($log, $msg);
    }

    public function automaticBackupExists()
    {
        $dateDir = BACKUP_PATH . date('Ymd');
        if (!is_dir($dateDir)) {
            return false;
        }

        $dir = opendir($dateDir);
        while ($run = readdir($dir)) {
            if (str_ends_with($run, '_automatic')) {
                closedir($dir);
                return true;
            }
        }
        closedir($dir);

        return false;
    }

    public function getLockFile($name)
    {
        return CRON_LOGS_PATH . $name . '.lock';
    }

    public function setLockFile($name)
    {
        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $fp = fopen($this->getLockFile($name), 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                fclose($fp);
            }
            return false;
        }

        return $fp;
    }

    public function removeLockFile($fp)
    {
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function hasLockFile($name)
    {
        $fp = fopen($this->getLockFile($name), 'c');
        if (!$fp) {
            return false;
        }

        $locked = !flock($fp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return $locked;
    }
}
