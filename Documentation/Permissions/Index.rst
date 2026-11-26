..  _permissions:

===========
Permissions
===========

For each connection the extension creates a backend group with only the
chosen rights, mounted on the root page of the site, and a backend user in it.
The user is not an administrator, has no backend modules and has a random
password nobody knows. All changes go through the TYPO3 DataHandler as this
user, so TYPO3 checks table, field and content type rights and records the
history under this user.

..  list-table::
    :header-rows: 1

    *   -   Permission
        -   Allows
    *   -   ``page.viewer``
        -   Read the page tree and pages
    *   -   ``content.viewer``
        -   Read and search content elements
    *   -   ``page.editor``
        -   Change the page title and navigation title
    *   -   ``content.creator``
        -   Create content elements, always saved hidden
    *   -   ``content.editor``
        -   Change the header, subheader and text of content elements
    *   -   ``seo.editor``
        -   Change the SEO title and meta description of pages

The AI client may only create or edit these content types: ``header``,
``text``, ``textpic``, ``textmedia``, ``bullets`` and ``table``. Never raw
HTML, plugins, forms, shortcuts or file lists. The connector group never gets
file mounts, users, groups or system tables.

..  note::

    Only the default language and the live workspace are supported. Content
    types that the frontend theme of the site cannot render are not checked:
    choose the permissions to match your site.
