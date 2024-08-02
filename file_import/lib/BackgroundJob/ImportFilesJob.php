<?php

declare(strict_types = 1);

namespace OCA\FileImport\BackgroundJob;

use Exception;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Class ImportFilesJob is a background job used to import user files
 *
 * @package OCA\FileImport\BackgroundJob
 */
class ImportFilesJob extends TimedJob {
    
    const INTERVAL = 60 * 5;
    
    private IAppManager $appManager;
    private IConfig $config;
    private IDBConnection $connection;
    private LoggerInterface $loggerInterface;
    private IUserManager $userManager;
    
    /**
     * @param IAppManager $appManager
     * @param IConfig $config
     * @param IDBConnection $connection
     * @param LoggerInterface $loggerInterface
     * @param ITimeFactory $timeFactory
     * @param IUserManager $userManager
     */
    public function __construct(
        IAppManager $appManager,
        IConfig $config,
        IDBConnection $connection,
        LoggerInterface $loggerInterface,
        ITimeFactory $timeFactory,
        IUserManager $userManager
    ) {
        parent::__construct($timeFactory);
        
        $this->setInterval(self::INTERVAL);
        
        $this->appManager = $appManager;
        $this->config = $config;
        $this->connection = $connection;
        $this->loggerInterface = $loggerInterface;
        $this->userManager = $userManager;
    }
    
    /**
     * @param array $argument unused argument
     * @throws Exception
     */
    protected function run($argument) {
        $fileImportFolder = $this->config->getSystemValueString('file_import_folder');
        $dataDirectory = $this->config->getSystemValueString('datadirectory');
        if ($fileImportFolder === '') {
            $this->loggerInterface->info('Import folder is not defined.');
            return;
        }
        
        $users = $this->userManager->search('');
        foreach ($users as $user) {
            $userUid = $user->getUID();
            $importFolder = $fileImportFolder . DIRECTORY_SEPARATOR . $userUid;
            if (is_dir($importFolder)) {
                $importFolderPathArray = mb_split(preg_quote(DIRECTORY_SEPARATOR), $importFolder);
                $importFolderPathArraySize = count($importFolderPathArray);
                $userFilesScanPath = $userUid . DIRECTORY_SEPARATOR . 'files';
                $userFiles = $dataDirectory . DIRECTORY_SEPARATOR . $userFilesScanPath;
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($importFolder));
                $files = []; 
                foreach ($iterator as $file) {
                    if ($file->isDir()){
                        continue;
                    }
                    $files[] = ['path' => $file->getPathname(), 'size' => $file->getSize()];
                }
                while (true) {
                    sleep(5);
                    
                    $filesToMove = [];
                    $filesNotToMove = [];
                    foreach ($files as $file) {
                        $newSize = filesize($file['path']);
                        if ($newSize !== false) {
                            if ($newSize === $file['size']) {
                                $filesToMove[] = $file;
                            } else {
                                $file['size'] = $newSize;
                                $filesNotToMove[] = $file;
                            }
                        }
                    }
                    $files = $filesNotToMove;
                    
                    foreach ($filesToMove as $file) {
                        $filePathArray = mb_split(preg_quote(DIRECTORY_SEPARATOR), $file['path']);
                        $filePathArraySize = count($filePathArray);
                        for ($i = $importFolderPathArraySize + 1; $i < $filePathArraySize; $i++) {
                            $subPath = $userFiles . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($filePathArray, $importFolderPathArraySize, $i - $importFolderPathArraySize));
                            if (!file_exists($subPath)) {
                                mkdir($subPath, 0755);
                            } else if (!is_dir($subPath)) {
                                $this->loggerInterface->error("File '" . $file['path'] . "' transfer skipped. File '$subPath' already exists, directory expected.");
                                continue 2;
                            }
                        }
                        
                        $newFile = $userFiles . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($filePathArray, $importFolderPathArraySize));
                        if (file_exists($newFile)) {
                            if (is_dir($newFile)) {
                                $this->loggerInterface->error("File '" . $file['path'] . "' transfer skipped. Directory '$subPath' already exists.");
                                continue;
                            } else {
                                unlink($newFile);
                                $this->loggerInterface->info("File '" . $newFile . "' deleted.");
                            }
                        }
                        rename($file['path'], $newFile);
                        chmod($newFile, 0644);
                        $this->loggerInterface->info("File '" . $file['path'] . "' successfully transferred.");
                        
                        $newFileScanPath = $userFilesScanPath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($filePathArray, $importFolderPathArraySize));
                        $loggerOutput = new LoggerOutput($this->loggerInterface);
                        $application = new Application();
                        $application->add(Server::get('OCA\Files\Command\Scan'));
                        $application->setAutoExit(false);
                        $result = $application->run(new ArrayInput(['command' => 'files:scan', '-v' => true, '--path' => $newFileScanPath]), $loggerOutput);
                        if ($result !== 0 || $loggerOutput->hasErrors()) {
                            $this->loggerInterface->error("File '" . $file['path'] . "' scan failed.");
                            continue;
                        }
                        $this->loggerInterface->info("File '" . $file['path'] . "' successfully scanned.");
                        
                        if ($this->appManager->isInstalled('previewgenerator')) {
                            $storageId = "home::$userUid";
                            $newFileCachePath = 'files' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($filePathArray, $importFolderPathArraySize));
                            
                            $qb = $this->connection->getQueryBuilder();
                            $qb->select('c.fileid')
                                ->from('filecache', 'c')
                                ->innerJoin('c', 'storages', 's', 'c.storage = s.numeric_id')
                                ->where(
                                    $qb->expr()->andX(
                                        $qb->expr()->eq('s.id', $qb->createNamedParameter($storageId), IQueryBuilder::PARAM_STR),
                                        $qb->expr()->eq('c.path', $qb->createNamedParameter($newFileCachePath), IQueryBuilder::PARAM_STR)
                                    )
                                )->setMaxResults(1);
                            $cursor = $qb->execute();
                            $fileId = $cursor->fetchOne();
                            $cursor->closeCursor();
                            
                            if ($fileId !== false) {
                                $qb = $this->connection->getQueryBuilder();
                                $qb->select('id')
                                    ->from('preview_generation')
                                    ->where(
                                        $qb->expr()->andX(
                                            $qb->expr()->eq('uid', $qb->createNamedParameter($userUid, IQueryBuilder::PARAM_STR)),
                                            $qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
                                        )
                                    )->setMaxResults(1);
                                $cursor = $qb->execute();
                                $inTable = $cursor->fetch() !== false;
                                $cursor->closeCursor();
                                
                                if (!$inTable) {
                                    $qb = $this->connection->getQueryBuilder();
                                    $qb->insert('preview_generation')
                                        ->setValue('uid', $qb->createNamedParameter($userUid, IQueryBuilder::PARAM_STR))
                                        ->setValue('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT));
                                    $qb->execute();
                                    
                                    $this->loggerInterface->info("File '" . $file['path'] . "' successfully added to the preview generation list.");
                                } else {
                                    $this->loggerInterface->info("File '" . $file['path'] . "' adding to the preview generation list skipped, already here.");
                                }
                            }
                        } else {
                            $this->loggerInterface->info("File '" . $file['path'] . "' adding to the preview generation list skipped, Preview generator not installed.");
                        }
                    }
                    
                    if (count($files) === 0) {
                        break;
                    }
                }
            } else {
                $this->loggerInterface->info("Import folder for user '$userUid' not found. Skipped.");
            }
        }
    }
    
}
