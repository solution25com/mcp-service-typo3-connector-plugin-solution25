..  _installation:

============
Installation
============

Install the extension with Composer:

..  code-block:: bash

    composer require solution25/typo3-ai-connector
    vendor/bin/typo3 extension:setup -e ai_connector

The setup command creates the database tables of the extension.

After the installation, administrators find the module under
:guilabel:`Administration > Integrations > AI connector` (TYPO3 14) or
:guilabel:`Admin Tools > AI connector` (TYPO3 13.4). The module is only
available to administrators.
