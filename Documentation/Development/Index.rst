.. _development:

===========
Development
===========

The extension comes with a ddev setup, thanks to Armin Vieweg for this
`example DDEV setup <https://github.com/a-r-m-i-n/ddev-for-typo3-extensions>`__
for TYPO3 extensions.

Beside a TYPO3 12 and 13 installation, it also contains a Minio docker container to test with a real S3 bucket.

Setting up the development environments
=======================================

Running `ddev start` will set up the basics for the development environments.

After this you'll have to execute a few commands to get the TYPO3 installations up and running:

.. code-block:: bash

    ddev install-all

This command will install both the TYPO3 v12 and v13 installations with the `fal_s3` extension and a small
sitepackage with the configuration for the Minio S3 bucket.

If you only want to one of the TYPO3 versions, you can run either of the following commands:

.. code-block:: bash

    ddev install-v12
    ddev install-v13


Creating the Minio S3 buckets
=============================

Through the `ddev mc` command you can create the Minio S3 buckets that are used in the TYPO3 installations.

The buckets used in the configuration are `typo3-12` and `typo3-13`.

.. code-block:: bash

    ddev mc mb minio/typo3-12
    ddev mc mb minio/typo3-13

By default, the buckets are created with the `private` policy, which means that the files are not publicly accessible.

These additional commands can be used to be able to view the files in the backend and frontend:

.. code-block:: bash

    ddev mc anonymous set download minio/typo3-12
    ddev mc anonymous set download minio/typo3-13

After creating the buckets, you can configure them in the backend (see :ref:`administration`).


Running the test suite
======================

The extension ships with unit tests and functional tests. The functional
tests round-trip against a real S3-compatible endpoint — the MinIO
container that ``ddev start`` already brings up.

One-time setup
--------------

.. code-block:: bash

    ddev start
    ddev mc mb minio/fal-s3-test
    ddev composer install
    cp Tests/.env.dist Tests/.env

The shipped ``Tests/.env.dist`` is pre-filled for the bundled MinIO
container (endpoint ``http://minio:10101``, credentials ``ddevminio``).
``Tests/.env`` is gitignored.

Running tests
-------------

.. code-block:: bash

    # Unit tests — fast, no S3 needed
    ddev exec .Build/bin/phpunit -c Build/phpunit/UnitTests.xml

    # Functional tests — hit the MinIO container; skip automatically when
    # FAL_S3_TEST_* env vars are absent.
    ddev exec .Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml

The functional bootstrap loads ``Tests/.env`` via ``symfony/dotenv``. In
CI the file is simply absent, so every functional test self-skips and
the suite stays green without secrets.
