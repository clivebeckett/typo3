.. include:: /Includes.rst.txt

.. _feature-19856-1679091117:

=============================================================================
Feature: #19856 - Set special ATagParams for links to access restricted pages
=============================================================================

See :issue:`19856`

Description
===========

A new TypoScript option is introduced to allow to set additional tag attributes
to links of pages which are access restricted by a Frontend User Group
Restriction. Usually these links will not be generated, but it is possible to
link them to another page, e.g. a special login page:

.. code-block:: typoscript

    config.typolinkLinkAccessRestrictedPages = 13
    config.typolinkLinkAccessRestrictedPages_addParams = &originalPage=###PAGE_ID###

The resulting link to a access-restricted page (e.g. `22`) looks like this:
:html:`<a href="/login?originalPage=22">My page</a>`

The now introduced option
:typoscript:`config.typolinkLinkAccessRestrictedPages.ATagParams` allows
to additionally add custom attributes to the actual anchor tag.

.. code-block:: typoscript

    config.typolinkLinkAccessRestrictedPages.ATagParams = class="restricted"

This will results in
:html:`<a href="/login?originalPage=22" class="restricted">My page</a>`.

When generating menus via HMENU, the new :typoscript:`ATagParams` option is
also available for custom settings:

.. code-block:: typoscript

    page.10 = HMENU
    page.10.showAccessRestrictedPages = 13
    page.10.showAccessRestrictedPages.ATagParams = class="access-restricted"


Impact
======

Allowing integrators to set custom `ATagParams` such as class attributes or
arbitrary data attributes to use client-side styling via CSS or JavaScript event
listeners to handle such links differently.

.. index:: TypoScript, ext:frontend
