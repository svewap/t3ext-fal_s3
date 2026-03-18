<?php
declare(strict_types=1);

namespace MaxServ\FalS3\Resource\Processing;

use TYPO3\CMS\Core\Resource\ProcessedFile;

class AbortedProcessedFile extends ProcessedFile
{

        public function __construct(
            protected ProcessedFile $originalProcessedFile
        ) {
            parent::__construct(
                $originalProcessedFile->getOriginalFile(),
                $originalProcessedFile->getTask()->getType(),
                $originalProcessedFile->getTask()->getConfiguration()
            );
            $this->task = new AbortedTask($this, []);
        }

}
