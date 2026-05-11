<?php

declare(strict_types=1);

namespace MaxServ\FalS3\Driver;

/*
 * This file is part of the "fal_s3" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/**
 * Presets for common S3-compatible storage providers.
 *
 * Each preset describes the AWS-SDK client options (endpoint, path-style) and
 * a public URL template that fal_s3 applies when the user only supplies the
 * minimum (bucket, key, secret, region). User-provided values in the storage
 * configuration always override the preset.
 *
 * Templates support the placeholders {region}, {bucket} and {key}.
 */
enum S3Provider: string
{
    case AWS = 'aws';
    case HETZNER = 'hetzner';
    case UPCLOUD = 'upcloud';
    case DIGITALOCEAN = 'digitalocean';
    case BACKBLAZE = 'backblaze';
    case LINODE = 'linode';
    case MINIO = 'minio';
    case CUSTOM = 'custom';

    /**
     * Map a free-form provider name to an enum case, falling back to CUSTOM.
     */
    public static function tryFromName(?string $name): self
    {
        if ($name === null || $name === '') {
            return self::CUSTOM;
        }
        return self::tryFrom(strtolower($name)) ?? self::CUSTOM;
    }

    /**
     * Preset client/URL defaults.
     *
     * - endpoint:           full endpoint URL ({region} substituted), or null when
     *                       the SDK should use its built-in AWS resolver.
     * - use_path_style:     whether the bucket goes into the path instead of the host.
     * - public_url:         template used by getPublicUrl() when the user has not
     *                       configured an explicit publicBaseUrl.
     * - region_required:    if true, processConfiguration() rejects a missing region.
     *
     * @return array{endpoint: ?string, use_path_style: bool, public_url: ?string, region_required: bool}
     */
    public function defaults(): array
    {
        return match ($this) {
            self::AWS => [
                'endpoint' => null,
                'use_path_style' => false,
                'public_url' => 'https://{bucket}.s3.{region}.amazonaws.com/{key}',
                'region_required' => true,
            ],
            self::HETZNER => [
                'endpoint' => 'https://{region}.your-objectstorage.com',
                'use_path_style' => true,
                'public_url' => 'https://{bucket}.{region}.your-objectstorage.com/{key}',
                'region_required' => true,
            ],
            self::UPCLOUD => [
                'endpoint' => 'https://{region}.upcloudobjects.com',
                'use_path_style' => true,
                'public_url' => 'https://{bucket}.{region}.upcloudobjects.com/{key}',
                'region_required' => true,
            ],
            self::DIGITALOCEAN => [
                'endpoint' => 'https://{region}.digitaloceanspaces.com',
                'use_path_style' => false,
                'public_url' => 'https://{bucket}.{region}.digitaloceanspaces.com/{key}',
                'region_required' => true,
            ],
            self::BACKBLAZE => [
                'endpoint' => 'https://s3.{region}.backblazeb2.com',
                'use_path_style' => false,
                'public_url' => 'https://{bucket}.s3.{region}.backblazeb2.com/{key}',
                'region_required' => true,
            ],
            self::LINODE => [
                'endpoint' => 'https://{region}.linodeobjects.com',
                'use_path_style' => false,
                'public_url' => 'https://{bucket}.{region}.linodeobjects.com/{key}',
                'region_required' => true,
            ],
            self::MINIO => [
                // MinIO endpoints are deployment-specific — the user must supply 'endpoint'.
                'endpoint' => null,
                'use_path_style' => true,
                'public_url' => null,
                'region_required' => false,
            ],
            self::CUSTOM => [
                'endpoint' => null,
                'use_path_style' => false,
                'public_url' => null,
                'region_required' => false,
            ],
        };
    }

    /**
     * Substitute {region}/{bucket}/{key} placeholders in a template string.
     */
    public static function expand(string $template, array $values): string
    {
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{' . $name . '}'] = (string)$value;
        }
        return strtr($template, $replacements);
    }
}