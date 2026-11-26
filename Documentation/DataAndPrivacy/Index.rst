..  _data-and-privacy:

================
Data and privacy
================

The extension sends no data by itself. Data leaves the site only when a
connected AI client asks for it, within the permissions of the connection:

*   When connecting, the site URL, site name, TYPO3 version, extension version,
    the chosen permissions and a newly created API token are sent to the
    Solution25 MCP gateway.
*   After that, the gateway reads or changes pages and content through the
    JSON API of the site at ``https://<site>/ai-connector/api/``. The answers
    go through the gateway to the AI client and its language model.

Only the SHA-256 hash of the API token is stored in TYPO3. Disconnecting
deletes the token, so the gateway can no longer reach the site.

Content that the AI client reads, such as page titles and the text of content
elements, is processed by the AI client and its provider. Check that this
fits the privacy policy of your site before connecting, and do not grant
permissions for content that must not leave the site.

The gateway is operated by Solution25. Its address is shown in the
:guilabel:`AI connector` module.
