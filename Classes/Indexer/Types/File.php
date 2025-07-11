<?php

namespace Tpwd\KeSearch\Indexer\Types;

/* * *************************************************************
 *  Copyright notice
 *
 *  (c) 2011 Stefan Froemken
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
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
 * ************************************************************* */

use Tpwd\KeSearch\Indexer\Filetypes\FileIndexerInterface;
use Tpwd\KeSearch\Indexer\IndexerBase;
use Tpwd\KeSearch\Indexer\IndexerRunner;
use Tpwd\KeSearch\Lib\Fileinfo;
use Tpwd\KeSearch\Lib\SearchHelper;
use Tpwd\KeSearch\Utility\FileUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Resource\FileCollector;

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 * @author    Stefan Froemken
 * @author    Christian Bülter
 */
class File extends IndexerBase
{
    /**
     * saves the configuration of extension ke_search_hooks
     *
     * @var array
     */
    public array $extConf = [];

    /**
     * saves the path to the executables
     *
     * @var array
     */
    public array $app = [];

    /**
     * @var bool
     */
    public bool $isAppArraySet = false;

    /**
     * @var Fileinfo
     */
    public FileInfo $fileInfo;

    /**
     * @var ResourceStorage
     */
    public ResourceStorage $storage;

    /**
     * Initializes indexer for files
     *
     * @param IndexerRunner $pObj
     */
    public function __construct(IndexerRunner $pObj)
    {
        parent::__construct($pObj);
        $this->pObj = $pObj;
        $this->extConf = SearchHelper::getExtConf();
        $this->fileInfo = GeneralUtility::makeInstance(Fileinfo::class);
    }

    /**
     * This function was called from indexer object and saves content to index table
     * @return string content which will be displayed in backend
     */
    public function startIndexing(): string
    {
        $directories = $this->indexerConfig['directories'];
        $directoryArray = GeneralUtility::trimExplode(',', $directories, true);

        $fileCollections = $this->indexerConfig['file_collections'];

        if (empty($directoryArray) && empty($fileCollections)) {
            $errorMessage = 'No directories or file collections defined for indexing.';
            // @extensionScannerIgnoreLine
            $this->pObj->logger->error($errorMessage);
            $this->addError($errorMessage);
            return $errorMessage;
        }

        $files = [];
        if (count($directoryArray)) {
            if ($this->indexerConfig['fal_storage'] > 0) {
                /* @var $storageRepository StorageRepository */
                $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
                $this->storage = $storageRepository->findByUid($this->indexerConfig['fal_storage']);


                $this->getFilesFromFal($files, $directoryArray);
            } else {
                $files = $this->getFilesFromDirectories($directoryArray);
            }
        }

        if (!empty($fileCollections)) {
            $this->getFilesFromFileCollections($files, $fileCollections);
        }

        $counter = $this->extractContentAndSaveToIndex($files);

        if ($this->indexingMode === self::INDEXING_MODE_INCREMENTAL) {
            $resultMessage = count($files) . ' files have been found for indexing.' . chr(10)
                . $counter . ' new or updated files have been indexed.';
            if ($this->counterRemoved) {
                $resultMessage .= chr(10) . $this->counterRemoved . ' outdated file index record(s) have been removed.';
            }
        } else {
            $resultMessage = count($files) . ' files have been found for indexing.' . chr(10)
                . $counter . ' files have been indexed.';
        }
        return $resultMessage;
    }

    public function startIncrementalIndexing(): string
    {
        $this->indexingMode = self::INDEXING_MODE_INCREMENTAL;
        $content = $this->startIndexing();
        $content .= $this->removeDeleted();
        return $content;
    }

    /**
     * Removes index records for the file records which have been deleted since the last indexing.
     * Only needed in incremental indexing mode since there is a dedicated "cleanup" step in full indexing mode.
     *
     * @return string
     */
    public function removeDeleted(): string
    {
        $message = '';
        $outdatedFileIndexRecords = $this->indexRepository->findOutdatedFileRecordsByPidAndTimestamp(
            $this->indexerConfig['storagepid'],
            $this->lastRunStartTime
        );
        $countDeleted = 0;
        if (!empty($outdatedFileIndexRecords)) {
            foreach ($outdatedFileIndexRecords as $outdatedFileIndexRecord) {
                if (!file_exists($outdatedFileIndexRecord['directory'] . $outdatedFileIndexRecord['title'])) {
                    $this->indexRepository->deleteByUid($outdatedFileIndexRecord['uid']);
                    $countDeleted++;
                }
            }
            if ($countDeleted > 0) {
                $message .= chr(10) . 'Found and removed ' . $countDeleted . ' outdated file index record(s).';
            }
        }
        return $message;
    }

    /** * fetches files recurively using FAL
     * @param array $files
     * @param array $directoryArray
     */
    public function getFilesFromFal(array &$files, array $directoryArray)
    {
        foreach ($directoryArray as $directory) {
            if (!$this->storage->hasFolder($directory)) {
                $errorMessage = 'Folder "' . $directory . '" does not exist.';
                // @extensionScannerIgnoreLine
                $this->pObj->logger->error($errorMessage);
                $this->addError($errorMessage);
                continue;
            }

            /** @var Folder $folder */
            $folder = $this->storage->getFolder($directory);

            if ($folder->getName() != '_temp_') {
                $filesInFolder = $folder->getFiles();
                if (count($filesInFolder)) {
                    foreach ($filesInFolder as $file) {
                        if ($file instanceof \TYPO3\CMS\Core\Resource\File) {
                            if (FileUtility::isFileIndexable($file, $this->indexerConfig)) {
                                $files[] = $file;
                            } else {
                                if ($this->indexingMode == self::INDEXING_MODE_INCREMENTAL) {
                                    $this->removeFileFromIndex($file);
                                }
                            }
                        }
                    }
                }

                // do recursion
                $subfolders = $folder->getSubFolders();
                if (count($subfolders)) {
                    foreach ($subfolders as $subfolder) {
                        $this->getFilesFromFal($files, [$subfolder->getIdentifier()]);
                    }
                }
            }
        }
    }

    /**
     * Get files from given relative directory path array.
     * Returns the *absolute* paths on the local file system for each file.
     *
     * @param array $directoryArray
     * @return array An Array containing all files of all valid directories
     */
    public function getFilesFromDirectories(array $directoryArray): array
    {
        $directoryArray = $this->getAbsoluteDirectoryPath($directoryArray);
        if (is_array($directoryArray) && count($directoryArray)) {
            $files = [];
            foreach ($directoryArray as $directory) {
                $foundFiles = GeneralUtility::getAllFilesAndFoldersInPath(
                    [],
                    $directory,
                    $this->indexerConfig['fileext']
                );

                if (is_array($foundFiles) && count($foundFiles)) {
                    foreach ($foundFiles as $file) {
                        $files[] = $file;
                    }
                }
            }
            return $files;
        }
        return [];
    }

    public function getFilesFromFileCollections(&$files, $fileCollections): void
    {
        // Boot up the filecollector
        $fileCollector = GeneralUtility::makeInstance(FileCollector::class);

        // Extract the IDs of file collections
        $collectionsArray = GeneralUtility::trimExplode(',', $fileCollections, true);

        // Collect the files associated
        $fileCollector->addFilesFromFileCollections($collectionsArray);

        // If the file collection "type" is "static" then file references are returned
        $collectionFiles = array_map(
            fn ($item) => ($item instanceof FileReference) ? $item->getOriginalFile() : $item,
            $fileCollector->getFiles()
        );

        // Get the files & index them
        $files = array_merge($files, $collectionFiles);
    }

    /**
     * get absolute directory paths of given path in array
     * @param array $directoryArray
     * @return array An Array containing the absolute directory paths
     */
    public function getAbsoluteDirectoryPath(array $directoryArray): array
    {
        if (count($directoryArray)) {
            foreach ($directoryArray as $key => $directory) {
                $directory = rtrim($directory, '/');
                $directoryArray[$key] = Environment::getPublicPath() . '/' . $directory . '/';
            }
            return $directoryArray;
        }
        return [];
    }

    /**
     * Loops through an array of files and stores their content to the index.
     * Files are either instances of \TYPO3\CMS\Core\Resource\File
     * or *absolute* paths to the files on the local file system.
     * Returns the number of files which have been indexed.
     *
     * @param array $files
     * @return int
     */
    public function extractContentAndSaveToIndex(array $files): int
    {
        $counter = 0;
        $totalCount = count($files);
        if ($totalCount > 0) {
            foreach ($files as $file) {
                $this->indexerStatusService->setRunningStatus($this->indexerConfig, $counter, $totalCount);
                if ($this->fileInfo->setFile($file)) {
                    if ($file instanceof \TYPO3\CMS\Core\Resource\File) {
                        $filePath = $file->getForLocalProcessing(false);
                    } else {
                        $filePath = $file;
                    }

                    // Check if if we have already up-to-date content for this file in the index by comparing the  timestamps.
                    // Todo: The index record also contains metadata which may have also changed, this needs to be checked also.
                    $fileContent = $this->getFileContentFromIndex($this->getUniqueHashForFile(), filemtime($filePath));

                    // in incremental indexing mode we can skip the further processing of this file now if we found
                    // a matching index record, because we do not need to store something to the index.
                    // In full indexing mode we need to store the already existing index record
                    // again because we want to update the timestamp.
                    if ($this->indexingMode === self::INDEXING_MODE_INCREMENTAL && $fileContent !== false) {
                        continue;
                    }

                    if ($fileContent === false) {
                        $fileContent = $this->getFileContent($filePath);
                    }

                    if ($fileContent !== false) {
                        $this->storeToIndex($file, $fileContent);
                        $counter++;
                    }
                }
            }
        }

        return $counter;
    }

    /**
     * Fetch content from a file. An indexer class for that filetype must exist.
     *
     * @param string $filePath absolute file path
     * @return false|string
     */
    public function getFileContent(string $filePath)
    {
        // we can continue only when given file is really file and not a directory
        if ($this->fileInfo->getIsFile()) {
            $className = 'Tpwd\KeSearch\Indexer\Filetypes\\' . ucfirst($this->fileInfo->getExtension());

            if (class_exists($className)) {
                $fileObj = GeneralUtility::makeInstance($className, $this->pObj);

                if ($fileObj instanceof FileIndexerInterface) {
                    $fileContent = $fileObj->getContent($filePath);
                    $fileContent = strip_tags($fileContent);
                    $this->addError($fileObj->getErrors());

                    // remove metadata separator if it appears in the content
                    $fileContent = str_replace(self::METADATASEPARATOR, ' ', $fileContent);

                    return $fileContent;
                }
                return false;
            }
            // if no indexer for this type of file exists, we do a fallback:
            // we return an empty content. Doing this at least the FAL metadata
            // can be indexed. So this makes only sense when using FAL.
            if ($this->indexerConfig['fal_storage'] > 0) {
                return '';
            }
            $errorMessage = 'No indexer for this type of file. (class ' . $className . ' does not exist).';
            // @extensionScannerIgnoreLine
            $this->pObj->logger->error($errorMessage);
            $this->addError($errorMessage);
            return false;
        }
        $errorMessage = $filePath . ' is not a file.';
        // @extensionScannerIgnoreLine
        $this->pObj->logger->error($errorMessage);
        $this->addError($errorMessage);
        return false;
    }

    /**
     * Checks if there's an entry in the index for the given file hash and file modification time.
     * Returns the content of that entry or false if no matching entry exists.
     *
     * @param string $hash
     * @param int $fileModificationTime
     * @return false|string
     */
    public function getFileContentFromIndex(string $hash, int $fileModificationTime)
    {
        $fileContent = false;
        $indexRow = $this->indexRepository->findOneByHashAndModificationTime($hash, $fileModificationTime);

        if (is_array($indexRow) && isset($indexRow['content'])) {
            $fileContent = $indexRow['content'];
        }

        return $fileContent;
    }

    /**
     * get a unique hash for current file
     * this is needed for a faster check if record already exists in indexer table
     *
     * @return string A 25 digit MD5 hash value of current file
     */
    public function getUniqueHashForFile(): string
    {
        $path = $this->fileInfo->getPath();
        $file = $this->fileInfo->getName();

        return md5($path . $file);
    }

    /**
     * creates a index entry for a given file
     *
     * @param string|\TYPO3\CMS\Core\Resource\File $file
     * @param string $content
     */
    public function storeToIndex($file, string $content)
    {
        $tags = '';

        // add tag "file" to all index records which represent a file
        SearchHelper::makeTags($tags, ['file']);

        // get data from FAL
        if ($file instanceof \TYPO3\CMS\Core\Resource\File) {
            // get file properties for this file, this information is merged from file record and meta information
            $fileProperties = $file->getProperties();
            $orig_uid = $file->getUid();
            $language_uid = $this->detectFileLanguage($fileProperties);

            // get raw metadata for this file
            /** @var MetaDataRepository $metaDataRepository */
            $metaDataRepository = GeneralUtility::makeInstance(MetaDataRepository::class);
            $metaDataProperties = $metaDataRepository->findByFile($file);
        } else {
            $fileProperties = false;
            $orig_uid = 0;
            $language_uid = -1;
            $metaDataProperties = false;
        }

        $indexRecordValues = [
            'storagepid' => $this->indexerConfig['storagepid'],
            'title' => $this->fileInfo->getName(),
            'type' => 'file:' . $this->fileInfo->getExtension(),
            'targetpid' => 1,
            'tags' => $tags,
            'params' => '',
            'abstract' => '',
            'language_uid' => $language_uid,
            'starttime' => 0,
            'endtime' => 0,
            'fe_group' => 0,
            'debug' => false,
        ];

        $additionalFields = [
            'sortdate' => $this->fileInfo->getModificationTime(),
            'orig_uid' => $orig_uid,
            'orig_pid' => 0,
            'directory' => $this->fileInfo->getAbsolutePath(),
            'hash' => $this->getUniqueHashForFile(),
        ];

        // add metadata content, frontend groups and catagory tags if FAL is used
        if ($this->indexerConfig['fal_storage'] > 0) {
            // index meta data from FAL: title, description, alternative
            if ($fileProperties) {
                $content = $this->addFileMetata($fileProperties, $content);
            }

            // use file description as abstract
            $indexRecordValues['abstract'] = $fileProperties['description'] ?? '';

            // respect groups from metadata
            $indexRecordValues['fe_group'] = $fileProperties['fe_groups'] ?? '';

            // get list of assigned system categories
            if (isset($metaDataProperties['uid'])) {
                $categories = SearchHelper::getCategories(
                    $metaDataProperties['uid'],
                    'sys_file_metadata'
                );

                // make Tags from category titles
                SearchHelper::makeTags(
                    $indexRecordValues['tags'],
                    $categories['title_list']
                );

                // assign categories as generic tags (eg. "syscat123")
                SearchHelper::makeSystemCategoryTags(
                    $indexRecordValues['tags'],
                    $metaDataProperties['uid'],
                    'sys_file_metadata'
                );
            }
        }

        // hook for custom modifications of the indexed data, e. g. the tags
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFileIndexEntry'] ?? null)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFileIndexEntry'] as $_classRef) {
                $_procObj = GeneralUtility::makeInstance($_classRef);
                $_procObj->modifyFileIndexEntry($file, $content, $additionalFields, $indexRecordValues, $this);
            }
        }

        // store record in index table
        $this->pObj->storeInIndex(
            $indexRecordValues['storagepid'],   // storage PID
            $indexRecordValues['title'],        // file name
            $indexRecordValues['type'],         // content type
            (string)$indexRecordValues['targetpid'], // target PID: where is the single view?
            $content,                           // indexed content, includes the title (linebreak after title)
            $indexRecordValues['tags'],         // tags
            $indexRecordValues['params'],       // typolink params for singleview
            $indexRecordValues['abstract'],     // abstract
            $indexRecordValues['language_uid'], // language uid
            $indexRecordValues['starttime'],    // starttime
            $indexRecordValues['endtime'],      // endtime
            $indexRecordValues['fe_group'],     // fe_group
            $indexRecordValues['debug'],        // debug only?
            $additionalFields                    // additional fields added by hooks
        );
    }
}
