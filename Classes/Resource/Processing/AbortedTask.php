<?php

declare(strict_types=1);

namespace MaxServ\FalS3\Resource\Processing;

use TYPO3\CMS\Core\Resource\Processing\AbstractTask;

class AbortedTask extends AbstractTask {


    public function getType(): string
    {
        return 'Image';
    }

    public function getName(): string
    {
        return 'AbortedTask';
    }

    public function fileNeedsProcessing(): bool {
        return false;
    }
}
