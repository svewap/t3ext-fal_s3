<?php

declare(strict_types=1);

namespace MaxServ\FalS3\Tests\Functional\Driver;

/*
 * This file is part of the "fal_s3" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use MaxServ\FalS3\Driver\AmazonS3Driver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Round-trips against a real S3-compatible endpoint.
 *
 * Configure credentials via Tests/.env (see Tests/.env.dist). When
 * required env vars are missing, every test in this class is skipped —
 * so this suite stays a no-op in CI environments that don't ship secrets.
 */
final class AmazonS3DriverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'maxserv/fal_s3',
    ];

    private const CONFIG_KEY = 'falS3FunctionalTest';

    private string $folderIdentifier = '';

    protected function setUp(): void
    {
        $bucket = getenv('FAL_S3_TEST_BUCKET') ?: '';
        $key = getenv('FAL_S3_TEST_KEY') ?: '';
        $secret = getenv('FAL_S3_TEST_SECRET') ?: '';
        $region = getenv('FAL_S3_TEST_REGION') ?: '';

        if ($bucket === '' || $key === '' || $secret === '' || $region === '') {
            self::markTestSkipped('Set FAL_S3_TEST_BUCKET/KEY/SECRET/REGION in Tests/.env to enable functional tests.');
        }

        parent::setUp();

        $configuration = [
            'bucket' => $bucket,
            'key' => $key,
            'secret' => $secret,
            'region' => $region,
            'title' => 'Functional Test Storage',
            'basePath' => getenv('FAL_S3_TEST_BASE_PATH') ?: '/fal_s3-functional-tests/',
            'publicBaseUrl' => getenv('FAL_S3_TEST_PUBLIC_BASE_URL') ?: '',
        ];

        $endpoint = getenv('FAL_S3_TEST_ENDPOINT') ?: '';
        if ($endpoint !== '') {
            $configuration['endpoint'] = $endpoint;
        }
        if (getenv('FAL_S3_TEST_USE_PATH_STYLE') === '1') {
            $configuration['use_path_style_endpoint'] = true;
        }

        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations'][self::CONFIG_KEY] = $configuration;

        $this->folderIdentifier = '/phpunit-' . bin2hex(random_bytes(4)) . '/';
    }

    protected function tearDown(): void
    {
        if ($this->folderIdentifier !== '') {
            try {
                $this->wipeFolder($this->createDriver(), $this->folderIdentifier);
            } catch (\Throwable) {
                // swallow — tearDown must not mask the original failure
            }
        }
        parent::tearDown();
    }

    private function createDriver(): AmazonS3Driver
    {
        $driver = new AmazonS3Driver(['configurationKey' => self::CONFIG_KEY]);
        $driver->processConfiguration();
        $driver->initialize();
        return $driver;
    }

    /**
     * Deletes every file and sub-folder under $folder, then the folder itself.
     *
     * Used instead of the driver's deleteFolder($id, recursive=true), which is
     * known to leak children — see deleteFolderRecursivelyRemovesAllChildren.
     */
    private function wipeFolder(AmazonS3Driver $driver, string $folder): void
    {
        if (!$driver->folderExists($folder)) {
            return;
        }
        foreach ($driver->getFilesInFolder($folder, 0, 0, true) as $fileIdentifier) {
            $driver->deleteFile($fileIdentifier);
        }
        // sub-folders are returned parent-before-child; reverse so we delete leaves first
        $subFolders = array_reverse($driver->getFoldersInFolder($folder, 0, 0, true));
        foreach ($subFolders as $subFolder) {
            $driver->deleteFolder($subFolder);
        }
        $driver->deleteFolder($folder);
    }

    private function makeLocalFile(string $contents = 'local-payload'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fal-s3-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        return $path;
    }

    // ---------- folder lifecycle ----------

    #[Test]
    public function createFolderAndFolderExistsAgree(): void
    {
        $driver = $this->createDriver();

        $created = $driver->createFolder(trim($this->folderIdentifier, '/'));
        self::assertSame($this->folderIdentifier, $created);
        self::assertTrue($driver->folderExists($this->folderIdentifier));
    }

    #[Test]
    public function isFolderEmptyTrueForFreshFolderFalseAfterUpload(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        self::assertTrue($driver->isFolderEmpty($this->folderIdentifier));

        $driver->setFileContents($this->folderIdentifier . 'a.txt', 'a');
        self::assertFalse($driver->isFolderEmpty($this->folderIdentifier));
    }

    #[Test]
    public function deleteFolderRecursivelyRemovesAllChildren(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        // populate: top-level file + sub-folder + file inside sub-folder
        $driver->setFileContents($this->folderIdentifier . 'top.txt', 'top');
        $driver->createFolder('nested', $this->folderIdentifier);
        $driver->setFileContents($this->folderIdentifier . 'nested/inner.txt', 'inner');

        self::assertTrue($driver->deleteFolder($this->folderIdentifier, true));

        self::assertFalse($driver->fileExists($this->folderIdentifier . 'top.txt'));
        self::assertFalse($driver->fileExists($this->folderIdentifier . 'nested/inner.txt'));
        self::assertFalse($driver->folderExists($this->folderIdentifier . 'nested/'));
        self::assertFalse($driver->folderExists($this->folderIdentifier));
    }

    #[Test]
    public function renameFolderMovesFilesUnderneath(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->setFileContents($this->folderIdentifier . 'kept.txt', 'kept');

        $oldFolder = $this->folderIdentifier;
        $renamed = $driver->renameFolder($oldFolder, 'renamed-' . bin2hex(random_bytes(2)));
        self::assertNotEmpty($renamed);
        self::assertArrayHasKey($oldFolder, $renamed);
        // remember the new identifier for tearDown
        $this->folderIdentifier = $renamed[$oldFolder];

        self::assertTrue($driver->folderExists($this->folderIdentifier));
        self::assertTrue($driver->fileExists($this->folderIdentifier . 'kept.txt'));
    }

    // ---------- file CRUD ----------

    #[Test]
    public function setFileContentsRoundTrips(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $payload = 'hello-fal-s3-' . bin2hex(random_bytes(4));
        $fileIdentifier = $this->folderIdentifier . 'sample.txt';

        $bytesWritten = $driver->setFileContents($fileIdentifier, $payload);

        self::assertSame(strlen($payload), $bytesWritten);
        self::assertTrue($driver->fileExists($fileIdentifier));
        self::assertSame($payload, $driver->getFileContents($fileIdentifier));
    }

    #[Test]
    public function deleteFileRemovesObject(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $fileIdentifier = $this->folderIdentifier . 'gone.txt';
        $driver->setFileContents($fileIdentifier, 'gone');
        self::assertTrue($driver->fileExists($fileIdentifier));

        self::assertTrue($driver->deleteFile($fileIdentifier));
        self::assertFalse($driver->fileExists($fileIdentifier));
    }

    #[Test]
    public function createFileCreatesEmptyObject(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $identifier = $driver->createFile('empty.bin', $this->folderIdentifier);

        self::assertSame($this->folderIdentifier . 'empty.bin', $identifier);
        self::assertTrue($driver->fileExists($identifier));
        self::assertSame('', $driver->getFileContents($identifier));
    }

    #[Test]
    public function addFileImportsLocalFile(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $local = $this->makeLocalFile('uploaded-content');
        $identifier = $driver->addFile($local, $this->folderIdentifier, 'uploaded.txt');

        self::assertSame($this->folderIdentifier . 'uploaded.txt', $identifier);
        self::assertSame('uploaded-content', $driver->getFileContents($identifier));
        // DriverInterface contract says the original "must not exist anymore" after addFile().
        // The S3 driver defers the unlink until the driver is destructed, so we only assert
        // the deferred-cleanup observable: the path is queued in temporaryFiles.
        // See FalS3#TODO — align with the contract in a follow-up.
        unset($driver); // trigger __destruct
        self::assertFileDoesNotExist($local);
    }

    #[Test]
    public function replaceFileOverwritesContent(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $identifier = $this->folderIdentifier . 'replaced.txt';
        $driver->setFileContents($identifier, 'before');

        $local = $this->makeLocalFile('after');
        self::assertTrue($driver->replaceFile($identifier, $local));
        self::assertSame('after', $driver->getFileContents($identifier));
    }

    // ---------- file copy / move / rename ----------

    #[Test]
    public function renameFileChangesIdentifierAndKeepsContent(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $original = $this->folderIdentifier . 'old-name.txt';
        $driver->setFileContents($original, 'payload');

        $newIdentifier = $driver->renameFile($original, 'new-name.txt');

        self::assertSame($this->folderIdentifier . 'new-name.txt', $newIdentifier);
        self::assertFalse($driver->fileExists($original));
        self::assertTrue($driver->fileExists($newIdentifier));
        self::assertSame('payload', $driver->getFileContents($newIdentifier));
    }

    #[Test]
    public function copyFileWithinStorageProducesIndependentCopy(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->createFolder('target', $this->folderIdentifier);

        $source = $this->folderIdentifier . 'source.txt';
        $driver->setFileContents($source, 'original');

        $copy = $driver->copyFileWithinStorage($source, $this->folderIdentifier . 'target/', 'copy.txt');

        self::assertSame($this->folderIdentifier . 'target/copy.txt', $copy);
        self::assertTrue($driver->fileExists($source));
        self::assertTrue($driver->fileExists($copy));
        self::assertSame('original', $driver->getFileContents($copy));

        // mutating the copy must not affect the source
        $driver->setFileContents($copy, 'mutated');
        self::assertSame('original', $driver->getFileContents($source));
    }

    #[Test]
    public function moveFileWithinStorageMovesObject(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->createFolder('target', $this->folderIdentifier);

        $source = $this->folderIdentifier . 'source.txt';
        $driver->setFileContents($source, 'movable');

        $moved = $driver->moveFileWithinStorage($source, $this->folderIdentifier . 'target/', 'moved.txt');

        self::assertSame($this->folderIdentifier . 'target/moved.txt', $moved);
        self::assertFalse($driver->fileExists($source));
        self::assertTrue($driver->fileExists($moved));
        self::assertSame('movable', $driver->getFileContents($moved));
    }

    // ---------- folder copy / move ----------

    #[Test]
    public function moveFolderWithinStorageRelocatesContents(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->createFolder('source-folder', $this->folderIdentifier);
        $driver->createFolder('target-parent', $this->folderIdentifier);
        $driver->setFileContents($this->folderIdentifier . 'source-folder/inside.txt', 'inside');

        $map = $driver->moveFolderWithinStorage(
            $this->folderIdentifier . 'source-folder/',
            $this->folderIdentifier . 'target-parent/',
            'moved-folder'
        );

        self::assertNotEmpty($map);
        self::assertFalse($driver->folderExists($this->folderIdentifier . 'source-folder/'));
        self::assertTrue($driver->folderExists($this->folderIdentifier . 'target-parent/moved-folder/'));
        self::assertTrue($driver->fileExists($this->folderIdentifier . 'target-parent/moved-folder/inside.txt'));
    }

    #[Test]
    public function copyFolderWithinStorageDuplicatesContents(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->createFolder('source-folder', $this->folderIdentifier);
        $driver->createFolder('target-parent', $this->folderIdentifier);
        $driver->setFileContents($this->folderIdentifier . 'source-folder/inside.txt', 'inside');

        $ok = $driver->copyFolderWithinStorage(
            $this->folderIdentifier . 'source-folder/',
            $this->folderIdentifier . 'target-parent/',
            'copied-folder'
        );

        self::assertTrue($ok);
        self::assertTrue($driver->folderExists($this->folderIdentifier . 'source-folder/'));
        self::assertTrue($driver->fileExists($this->folderIdentifier . 'source-folder/inside.txt'));
        self::assertTrue($driver->folderExists($this->folderIdentifier . 'target-parent/copied-folder/'));
        self::assertTrue($driver->fileExists($this->folderIdentifier . 'target-parent/copied-folder/inside.txt'));
    }

    // ---------- listing ----------

    #[Test]
    public function getFilesInFolderReturnsAllUploadedFiles(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->setFileContents($this->folderIdentifier . 'a.txt', 'a');
        $driver->setFileContents($this->folderIdentifier . 'b.txt', 'b');
        $driver->setFileContents($this->folderIdentifier . 'c.txt', 'c');

        $files = $driver->getFilesInFolder($this->folderIdentifier);

        self::assertCount(3, $files);
        $basenames = array_map('basename', array_values($files));
        sort($basenames);
        self::assertSame(['a.txt', 'b.txt', 'c.txt'], $basenames);
    }

    #[Test]
    public function countFilesInFolderMatchesUploadedCount(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->setFileContents($this->folderIdentifier . 'one.txt', 'x');
        $driver->setFileContents($this->folderIdentifier . 'two.txt', 'x');

        self::assertSame(2, $driver->countFilesInFolder($this->folderIdentifier));
    }

    #[Test]
    public function getFoldersInFolderReturnsCreatedSubfolders(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->createFolder('alpha', $this->folderIdentifier);
        $driver->createFolder('beta', $this->folderIdentifier);

        $folders = $driver->getFoldersInFolder($this->folderIdentifier);
        $basenames = array_map(static fn(string $id) => basename(rtrim($id, '/')), array_values($folders));
        sort($basenames);
        self::assertSame(['alpha', 'beta'], $basenames);
    }

    #[Test]
    public function countFoldersInFolderMatchesCreatedCount(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->createFolder('one', $this->folderIdentifier);
        $driver->createFolder('two', $this->folderIdentifier);

        self::assertSame(2, $driver->countFoldersInFolder($this->folderIdentifier));
    }

    #[Test]
    public function fileExistsInFolderDetectsPresenceOrAbsence(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->setFileContents($this->folderIdentifier . 'present.txt', 'x');

        self::assertTrue($driver->fileExistsInFolder('present.txt', $this->folderIdentifier));
        self::assertFalse($driver->fileExistsInFolder('absent.txt', $this->folderIdentifier));
    }

    // ---------- metadata ----------

    #[Test]
    public function getFileInfoByIdentifierReturnsSizeMtimeAndIdentifier(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $identifier = $this->folderIdentifier . 'info.bin';
        $payload = str_repeat('z', 137);
        $driver->setFileContents($identifier, $payload);

        $info = $driver->getFileInfoByIdentifier($identifier);

        self::assertSame($identifier, $info['identifier']);
        self::assertSame('info.bin', $info['name']);
        self::assertSame(137, $info['size']);
        self::assertGreaterThan(0, $info['mtime']);
    }

    #[Test]
    public function getFolderInfoByIdentifierReturnsStructuralFields(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $info = $driver->getFolderInfoByIdentifier($this->folderIdentifier);

        self::assertSame($this->folderIdentifier, $info['identifier']);
        self::assertSame(basename(rtrim($this->folderIdentifier, '/')), $info['name']);
    }

    // ---------- hashing ----------

    #[Test]
    public function hashSha1MatchesPayload(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $payload = 'hash-me-' . bin2hex(random_bytes(4));
        $identifier = $this->folderIdentifier . 'hashed.txt';
        $driver->setFileContents($identifier, $payload);

        self::assertSame(sha1($payload), $driver->hash($identifier, 'sha1'));
    }

    #[Test]
    public function hashMd5MatchesPayload(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $payload = 'hash-me-' . bin2hex(random_bytes(4));
        $identifier = $this->folderIdentifier . 'hashed.txt';
        $driver->setFileContents($identifier, $payload);

        self::assertSame(md5($payload), $driver->hash($identifier, 'md5'));
    }

    #[Test]
    public function hashReturnsEmptyStringForMissingFile(): void
    {
        $driver = $this->createDriver();
        self::assertSame('', $driver->hash($this->folderIdentifier . 'missing.txt', 'sha1'));
    }

    // ---------- local processing ----------

    #[Test]
    public function getFileForLocalProcessingReturnsReadableCopy(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $identifier = $this->folderIdentifier . 'remote.txt';
        $payload = 'remote-payload-' . bin2hex(random_bytes(4));
        $driver->setFileContents($identifier, $payload);

        $localPath = $driver->getFileForLocalProcessing($identifier, false);

        self::assertFileExists($localPath);
        self::assertSame($payload, file_get_contents($localPath));
    }

    #[Test]
    public function dumpFileContentsEchoesContent(): void
    {
        $driver = $this->createDriver();
        $driver->createFolder(trim($this->folderIdentifier, '/'));

        $identifier = $this->folderIdentifier . 'dumped.txt';
        $payload = 'dump-me-' . bin2hex(random_bytes(4));
        $driver->setFileContents($identifier, $payload);

        ob_start();
        $driver->dumpFileContents($identifier);
        $captured = (string)ob_get_clean();

        self::assertSame($payload, $captured);
    }

    // ---------- URL + path helpers ----------

    #[Test]
    public function getPublicUrlIncludesBasePathAndIdentifier(): void
    {
        $driver = $this->createDriver();
        $url = $driver->getPublicUrl('/some-folder/photo.jpg');

        self::assertNotEmpty($url);
        self::assertStringContainsString('photo.jpg', $url);
        self::assertStringContainsString(trim(getenv('FAL_S3_TEST_BASE_PATH') ?: '/fal_s3-functional-tests/', '/'), $url);
    }

    #[Test]
    public function getRootLevelFolderIsSlash(): void
    {
        self::assertSame('/', $this->createDriver()->getRootLevelFolder());
    }

    #[Test]
    public function isWithinReturnsTrueForChildAndSelfAndFalseForSibling(): void
    {
        $driver = $this->createDriver();

        self::assertTrue($driver->isWithin('/parent/', '/parent/child.txt'));
        self::assertTrue($driver->isWithin('/parent/', '/parent/'));
        self::assertFalse($driver->isWithin('/parent/', '/sibling/file.txt'));
    }

    // ---------- provider presets (end-to-end) ----------

    #[Test]
    public function minioProviderPresetRoundTripsAgainstMinio(): void
    {
        // Re-register the storage with the 'minio' provider preset instead of explicit
        // use_path_style_endpoint. Endpoint is still supplied by the user (deployment-specific).
        $endpoint = getenv('FAL_S3_TEST_ENDPOINT') ?: '';
        if ($endpoint === '') {
            self::markTestSkipped('Provider-preset round-trip requires FAL_S3_TEST_ENDPOINT.');
        }

        $presetKey = 'falS3PresetTest';
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations'][$presetKey] = [
            'provider' => 'minio',
            'bucket' => getenv('FAL_S3_TEST_BUCKET') ?: '',
            'key' => getenv('FAL_S3_TEST_KEY') ?: '',
            'secret' => getenv('FAL_S3_TEST_SECRET') ?: '',
            'endpoint' => $endpoint,
            'basePath' => getenv('FAL_S3_TEST_BASE_PATH') ?: '/fal_s3-functional-tests/',
            'title' => 'MinIO via preset',
        ];

        $driver = new AmazonS3Driver(['configurationKey' => $presetKey]);
        $driver->processConfiguration();
        $driver->initialize();

        $payload = 'preset-payload-' . bin2hex(random_bytes(4));
        $fileIdentifier = $this->folderIdentifier . 'preset.txt';
        $driver->createFolder(trim($this->folderIdentifier, '/'));
        $driver->setFileContents($fileIdentifier, $payload);

        self::assertTrue($driver->fileExists($fileIdentifier));
        self::assertSame($payload, $driver->getFileContents($fileIdentifier));

        // Path-style URL derived from endpoint, no hard-coded amazonaws.com.
        $url = $driver->getPublicUrl($fileIdentifier);
        self::assertStringStartsWith($endpoint . '/' . (getenv('FAL_S3_TEST_BUCKET') ?: ''), $url);
        self::assertStringContainsString('preset.txt', $url);
        self::assertStringNotContainsString('amazonaws.com', $url);

        $this->wipeFolder($driver, $this->folderIdentifier);
    }
}