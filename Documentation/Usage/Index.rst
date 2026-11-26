..  _usage:

=====
Usage
=====

..  _connect:

Connect a site
==============

#.  In the AI client, add the connector address shown in the
    :guilabel:`AI connector` module, for example in Claude under
    :guilabel:`Settings > Connectors > Add custom connector`.
#.  Sign in. The sign-in page of the gateway shows a one-time code such as
    ``S25-K7QM-4XTD``. The code is valid for 30 minutes and can be used once.
#.  In TYPO3, open the :guilabel:`AI connector` module, paste the code next to
    the site and click :guilabel:`Check code`.
#.  Check which AI client asks for access, choose the permissions and click
    :guilabel:`Connect`.
#.  Return to the AI client and continue there.

Each TYPO3 site can have one connection.

..  _change-permissions:

Change permissions
==================

The module lists the permissions of a connected site. Untick or tick
permissions and click :guilabel:`Save permissions`. You can only choose from
what the AI client asked for when it connected. A removed permission applies
on the site at once.

..  _disconnect:

Disconnect
==========

Click :guilabel:`Disconnect`. The extension deletes the connector user, group
and API token first, so access ends at once, even if the gateway cannot be
reached. Then it tells the gateway.

..  _audit-trail:

Audit trail
===========

Every connect, disconnect and permission change is recorded with its outcome
and shown at the bottom of the module.
