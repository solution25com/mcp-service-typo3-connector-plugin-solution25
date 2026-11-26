..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
================

The extension lets an AI client such as Claude work with the pages and
content of a TYPO3 site. The AI client talks to the Solution25 MCP gateway,
and the gateway talks to the site through a small JSON API that this
extension provides.

With the matching permissions, the AI client can:

*   read the page tree, pages and content elements of the connected site
*   search pages and content
*   create content elements, which are always saved hidden
*   edit the header, subheader and text of content elements
*   edit page titles, navigation titles, SEO titles and meta descriptions

Every change is shown to the user as a preview first and applied only after
the user confirms it in the AI client.

..  _requirements:

Requirements
============

*   TYPO3 13.4 or 14.3
*   PHP 8.2 or later
*   The site must be reachable over https on its own domain, because the
    gateway only connects to public https addresses.
