<?php


declare(strict_types=1);

namespace MaxServ\FalS3\Resource\Event;

use MaxServ\FalS3\Resource\Processing\AbortedProcessedFile;
use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;
use TYPO3\CMS\Core\Resource\FileType;
use TYPO3\CMS\Core\Resource\ProcessedFile;

class FileProcessingEvent {


    public function beforeFileProcessing(BeforeFileProcessingEvent $event): void
    {

        $processedFile = $event->getProcessedFile();

        $originalFile = $processedFile->getOriginalFile();
        $fileSize = $originalFile->getSize();
        $fileType = $originalFile->getFileType();
        if ($fileType === FileType::VIDEO) {
            $processedFile = new AbortedProcessedFile($processedFile);
            $event->setProcessedFile($processedFile);
        }
    }

}
