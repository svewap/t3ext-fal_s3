.. _configuration:

=============
Configuration
=============

Add the following snippet to your :code:`config/system/settings.php` and adjust the values to meet your setup

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations']['contentStorage'] = [
        'basePath' => '/{$FOLDER_PREFIX}/',
        'bucket' => 's3://{$BUCKET_NAME}',
        'endpoint' => '{$S3_ENDPOINT}',
        'excludedFolders' => ['Secret/Stash', 'SomethingElse'],
        'key' => '{$IAM_KEY}',
        'publicBaseUrl' => 'https://{$CF_DISTRIBUTION_ID}.cloudfront.net',
        'region' => 'eu-west-1',
        'secret' => '{$IAM_SECRET}',
        'title' => 'TYPO3 content storage',
    ];

This adds a configuration fo the driver that is reference using **contentStorage**, this unique key is the only value
stored in the `File Storage` DB record so this record doesn't need to be changed for each environment.

`basePath`
    Used as a prefix (folder) to store files.

`bucket`
    The name of your S3 bucket.

`endpoint`
    The full URI of the S3 compatible storage service. This is only required if your provider is not AWS S3.

`excludedFolders`
    An array of folders that are present on S3, but should not be made available to TYPO3.

`key`
    The Access Key ID provided by AWS (see the IAM console).

`provider`
    Optional. Selects a built-in preset for endpoint, addressing style and public URL pattern.
    When set, you only need to supply `bucket`, `key`, `secret` and `region` — the preset
    fills in the rest. User-supplied values always override the preset. Supported values:
    `aws` (default), `hetzner`, `upcloud`, `digitalocean`, `backblaze`, `linode`, `minio`, `custom`.

`publicBaseUrl`
    Optional. Use this to override the public URL — typically when serving through a CDN.
    When omitted, fal_s3 derives the URL from the provider preset, the custom `endpoint`
    (path-style or virtual-hosted depending on `use_path_style_endpoint`), or falls back
    to the legacy AWS URL.

`region`
    Region to connect to. Required when no `endpoint` is configured (the AWS SDK uses
    the region to resolve an endpoint). When `endpoint` is set the region is still passed
    to the SDK for V4 signing — `us-east-1` is the de-facto default that most non-AWS
    providers accept.

`secret`
    Secret Access Key.

`title`
    The readable title you see as selectable option when editing a File Storage.

`use_path_style_endpoint`
    Boolean. Switches between virtual-hosted-style URLs (`bucket.host/key`, default for AWS,
    DigitalOcean, Backblaze, Linode) and path-style URLs (`host/bucket/key`, default for
    Hetzner, UpCloud, MinIO).

Examples
--------

AWS S3
~~~~~~

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations']['contentStorage'] = [
        'provider' => 'aws',
        'basePath' => '/',
        'bucket' => 's3://my-bucket',
        'key' => '{$IAM_KEY}',
        'secret' => '{$IAM_SECRET}',
        'region' => 'eu-west-1',
        // Optional CDN in front of the bucket:
        'publicBaseUrl' => 'https://abc123.cloudfront.net',
        'title' => 'AWS S3 Storage',
    ];

Hetzner Object Storage
~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations']['storage1'] = [
        'provider' => 'hetzner',
        'basePath' => '/',
        'bucket' => 'bucket1',
        'key' => '{$ACCESS_KEY}',
        'secret' => '{$SECRET_KEY}',
        'region' => 'nbg1',           // hel1 / nbg1 / fsn1
        'title' => 'Hetzner Storage',
    ];

The preset expands to endpoint `https://nbg1.your-objectstorage.com`, path-style URLs and
a public URL pattern of `https://bucket1.nbg1.your-objectstorage.com/{key}`.

.. note::

    The bucket needs to be configured as public if media should be accessible directly
    from the frontend.

UpCloud Object Storage
~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations']['storage1'] = [
        'provider' => 'upcloud',
        'basePath' => '/',
        'bucket' => 'mybucket',
        'key' => '{$ACCESS_KEY}',
        'secret' => '{$SECRET_KEY}',
        'region' => 'fi-hel1',
        'title' => 'UpCloud Storage',
    ];

DigitalOcean Spaces / Backblaze B2 / Linode Object Storage
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use `'provider' => 'digitalocean'`, `'backblaze'` or `'linode'` respectively. All three
follow the same pattern — supply `bucket`, `key`, `secret` and `region`.

MinIO / self-hosted S3
~~~~~~~~~~~~~~~~~~~~~~

For MinIO and other self-hosted providers the endpoint is deployment-specific, so the
preset only sets the addressing style; you supply the endpoint yourself.

.. code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['fal_s3']['storageConfigurations']['storage1'] = [
        'provider' => 'minio',
        'basePath' => '/',
        'bucket' => 'mybucket',
        'endpoint' => 'https://s3.internal.example.com',
        'key' => '{$ACCESS_KEY}',
        'secret' => '{$SECRET_KEY}',
        'title' => 'Self-hosted S3',
    ];

To expose files publicly through a reverse proxy or CDN, add a `publicBaseUrl`.
