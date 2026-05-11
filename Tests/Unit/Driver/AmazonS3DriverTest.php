<?php

declare(strict_types=1);

namespace MaxServ\FalS3\Tests\Unit\Driver;

/*
 * This file is part of the "fal_s3" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use MaxServ\FalS3\Driver\AmazonS3Driver;
use MaxServ\FalS3\Driver\S3Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AmazonS3Driver::class)]
final class AmazonS3DriverTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']);
        parent::tearDown();
    }

    private function setStorageConfigurations(array $configurations): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations'] = $configurations;
    }

    public static function invalidProcessConfigurationProvider(): array
    {
        return [
            'missing region' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'title' => 'Content',
                ]],
            ],
            'missing key' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'bucket' => 'bucket.example.com',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                ]],
            ],
            'missing secret' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                ]],
            ],
            'unknown configuration key' => [
                ['configurationKey' => 'contentStorage'],
                ['content' => [
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                ]],
            ],
            'empty configuration key' => [
                ['configurationKey' => ''],
                [],
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidProcessConfigurationProvider')]
    public function processConfigurationThrowsOnInvalidConfiguration(array $driverConfiguration, array $storageConfigurations): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->setStorageConfigurations($storageConfigurations);

        (new AmazonS3Driver($driverConfiguration))->processConfiguration();
    }

    public static function providerPresetProvider(): array
    {
        return [
            'hetzner derives endpoint and url template from region' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'hetzner',
                    'bucket' => 'mybucket',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'hel1',
                    'title' => 'Hetzner',
                ]],
                'images/photo.jpg',
                'https://mybucket.hel1.your-objectstorage.com/images/photo.jpg',
            ],
            'upcloud derives endpoint and url template from region' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'upcloud',
                    'bucket' => 'mybucket',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'fi-hel1',
                    'title' => 'UpCloud',
                ]],
                'photo.jpg',
                'https://mybucket.fi-hel1.upcloudobjects.com/photo.jpg',
            ],
            'digitalocean uses virtual-hosted url' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'digitalocean',
                    'bucket' => 'space',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'ams3',
                    'title' => 'DO',
                ]],
                'photo.jpg',
                'https://space.ams3.digitaloceanspaces.com/photo.jpg',
            ],
            'aws preset emits regional url' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'aws',
                    'bucket' => 'mybucket',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'AWS',
                ]],
                'photo.jpg',
                'https://mybucket.s3.eu-west-1.amazonaws.com/photo.jpg',
            ],
            'minio preset relies on user-supplied endpoint (path-style)' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'minio',
                    'bucket' => 'mybucket',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'endpoint' => 'http://minio:9000',
                    'title' => 'MinIO',
                ]],
                'photo.jpg',
                'http://minio:9000/mybucket/photo.jpg',
            ],
            'user publicBaseUrl wins over preset' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'hetzner',
                    'bucket' => 'mybucket',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'hel1',
                    'publicBaseUrl' => 'https://cdn.example.com',
                    'title' => 'Hetzner + CDN',
                ]],
                'images/photo.jpg',
                'https://cdn.example.com/images/photo.jpg',
            ],
            'unknown provider falls back to legacy aws url' => [
                ['configurationKey' => 'k'],
                ['k' => [
                    'provider' => 'whatever',
                    'bucket' => 'mybucket',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'us-east-1',
                    'title' => 'Custom',
                ]],
                'photo.jpg',
                'https://mybucket.s3.amazonaws.com/photo.jpg',
            ],
        ];
    }

    #[Test]
    #[DataProvider('providerPresetProvider')]
    public function providerPresetsProduceExpectedPublicUrl(
        array $driverConfiguration,
        array $storageConfigurations,
        string $identifier,
        string $expected,
    ): void {
        $this->setStorageConfigurations($storageConfigurations);

        $driver = new AmazonS3Driver($driverConfiguration);
        $driver->processConfiguration();

        self::assertSame($expected, $driver->getPublicUrl($identifier));
    }

    #[Test]
    public function customEndpointPathStyleProducesPathStyleUrl(): void
    {
        $this->setStorageConfigurations([
            'k' => [
                'bucket' => 'mybucket',
                'key' => 'KEY',
                'secret' => 'SECRET',
                'endpoint' => 'https://s3.example.com',
                'use_path_style_endpoint' => true,
                'title' => 'Custom path-style',
            ],
        ]);

        $driver = new AmazonS3Driver(['configurationKey' => 'k']);
        $driver->processConfiguration();

        self::assertSame(
            'https://s3.example.com/mybucket/photo.jpg',
            $driver->getPublicUrl('photo.jpg')
        );
    }

    #[Test]
    public function customEndpointVirtualHostedProducesSubdomainUrl(): void
    {
        $this->setStorageConfigurations([
            'k' => [
                'bucket' => 'mybucket',
                'key' => 'KEY',
                'secret' => 'SECRET',
                'endpoint' => 'https://s3.example.com',
                'use_path_style_endpoint' => false,
                'title' => 'Custom virtual-hosted',
            ],
        ]);

        $driver = new AmazonS3Driver(['configurationKey' => 'k']);
        $driver->processConfiguration();

        self::assertSame(
            'https://mybucket.s3.example.com/photo.jpg',
            $driver->getPublicUrl('photo.jpg')
        );
    }

    #[Test]
    public function s3ProviderTryFromNameAcceptsKnownNamesAndFallsBackToCustom(): void
    {
        self::assertSame(S3Provider::HETZNER, S3Provider::tryFromName('hetzner'));
        self::assertSame(S3Provider::HETZNER, S3Provider::tryFromName('HETZNER'));
        self::assertSame(S3Provider::CUSTOM, S3Provider::tryFromName(null));
        self::assertSame(S3Provider::CUSTOM, S3Provider::tryFromName(''));
        self::assertSame(S3Provider::CUSTOM, S3Provider::tryFromName('frobnicator'));
    }

    public static function publicUrlProvider(): array
    {
        return [
            'cloudfront base url without basePath' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'basePath' => '',
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                    'publicBaseUrl' => 'https://cdn.example.com',
                ]],
                'user_upload/photo.jpg',
                'https://cdn.example.com/user_upload/photo.jpg',
            ],
            'cloudfront base url with basePath' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'basePath' => 'fileadmin',
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                    'publicBaseUrl' => 'https://cdn.example.com',
                ]],
                'user_upload/photo.jpg',
                'https://cdn.example.com/fileadmin/user_upload/photo.jpg',
            ],
            'fallback to s3 url without basePath' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                ]],
                'user_upload/photo.jpg',
                'https://bucket.example.com.s3.amazonaws.com/user_upload/photo.jpg',
            ],
            'fallback to s3 url with basePath' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'basePath' => 'fileadmin',
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                ]],
                'user_upload/photo.jpg',
                'https://bucket.example.com.s3.amazonaws.com/fileadmin/user_upload/photo.jpg',
            ],
            'rawurlencodes path segments' => [
                ['configurationKey' => 'content'],
                ['content' => [
                    'bucket' => 'bucket.example.com',
                    'key' => 'KEY',
                    'secret' => 'SECRET',
                    'region' => 'eu-west-1',
                    'title' => 'Content',
                    'publicBaseUrl' => 'https://cdn.example.com',
                ]],
                'user_upload/foo bar.jpg',
                'https://cdn.example.com/user_upload/foo%20bar.jpg',
            ],
        ];
    }

    #[Test]
    #[DataProvider('publicUrlProvider')]
    public function getPublicUrlReturnsExpectedUrl(
        array $driverConfiguration,
        array $storageConfigurations,
        string $identifier,
        string $expected,
    ): void {
        $this->setStorageConfigurations($storageConfigurations);

        $driver = new AmazonS3Driver($driverConfiguration);
        $driver->processConfiguration();

        self::assertSame($expected, $driver->getPublicUrl($identifier));
    }

    #[Test]
    public function getRootLevelFolderReturnsSlash(): void
    {
        self::assertSame('/', (new AmazonS3Driver())->getRootLevelFolder());
    }

    public static function sanitizeFileNameProvider(): array
    {
        return [
            'plain ascii unchanged' => ['photo.jpg', 'photo.jpg'],
            'replaces forbidden characters' => ['foo$bar*baz.jpg', 'foo_bar_baz.jpg'],
            'replaces brackets and quotes' => ["it's [a] file.jpg", 'it_s _a_ file.jpg'],
            'trims trailing dots' => ['name...', 'name'],
            'normalizes utf8' => ['Ümlaut.jpg', 'Ümlaut.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('sanitizeFileNameProvider')]
    public function sanitizeFileNameSanitizesAsExpected(string $input, string $expected): void
    {
        self::assertSame($expected, (new AmazonS3Driver())->sanitizeFileName($input));
    }

    #[Test]
    public function sanitizeFileNameThrowsForEmptyResult(): void
    {
        $this->expectException(InvalidFileNameException::class);
        (new AmazonS3Driver())->sanitizeFileName('...');
    }

    #[Test]
    public function isCaseSensitiveFileSystemReturnsTrue(): void
    {
        self::assertTrue((new AmazonS3Driver())->isCaseSensitiveFileSystem());
    }

    #[Test]
    public function constructorRegistersExpectedCapabilities(): void
    {
        $driver = new AmazonS3Driver();

        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_BROWSABLE));
        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_PUBLIC));
        self::assertTrue($driver->hasCapability(Capabilities::CAPABILITY_WRITABLE));
    }

    #[Test]
    public function mergeConfigurationCapabilitiesIntersectsWithGivenCapabilities(): void
    {
        $driver = new AmazonS3Driver();
        $merged = $driver->mergeConfigurationCapabilities(
            new Capabilities(Capabilities::CAPABILITY_BROWSABLE | Capabilities::CAPABILITY_PUBLIC)
        );

        self::assertTrue($merged->hasCapability(Capabilities::CAPABILITY_BROWSABLE));
        self::assertTrue($merged->hasCapability(Capabilities::CAPABILITY_PUBLIC));
        self::assertFalse($merged->hasCapability(Capabilities::CAPABILITY_WRITABLE));
    }

    #[Test]
    public function getPublicUrlThrowsOnInvalidIdentifier(): void
    {
        $this->setStorageConfigurations([
            'content' => [
                'bucket' => 'bucket.example.com',
                'key' => 'KEY',
                'secret' => 'SECRET',
                'region' => 'eu-west-1',
                'title' => 'Content',
                'publicBaseUrl' => 'https://cdn.example.com',
            ],
        ]);

        $driver = new AmazonS3Driver(['configurationKey' => 'content']);
        $driver->processConfiguration();

        $this->expectException(InvalidPathException::class);
        $driver->getPublicUrl('../etc/passwd');
    }

    #[Test]
    public function getParentFolderIdentifierOfIdentifierReturnsParent(): void
    {
        $driver = new AmazonS3Driver();

        self::assertSame('/foo/', $driver->getParentFolderIdentifierOfIdentifier('/foo/bar.jpg'));
        self::assertSame('/', $driver->getParentFolderIdentifierOfIdentifier('/bar.jpg'));
    }
}