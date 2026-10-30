# Link Checker

Finds the broken links in the content of your Craft CMS 5 site and lists the pages that contain them in a dashboard widget, so
editors can fix them before visitors find them.

- **Broken links**: links to entries that were deleted, are in the trash, disabled, expired, not in the page's site or have no
  page of their own; links to deleted assets; links to pages of the site itself (`/nl/over-ons`, `https://example.be/…`)
  that no longer exist; links to a website whose domain doesn't exist (a typo, an expired domain).
- **Warnings**: links to entries that aren't published yet; links to a page that a redirect sends on (link to the page
  itself); anchors (`#team`) that aren't on the target page; invalid email addresses and phone numbers; a deleted entry whose
  saved URL still works.
- **Broken links** dashboard widget: the pages with broken links, with a link to edit each one and every broken link with
  its reason and where it is (*Content builder 2 (Content row) › Columns 1 (Text) › Text*). Each user only sees the pages
  they may edit.
- **Links panel** in the entry sidebar: the problems of the page as it's edited, updated after every autosave.
- **Check on publish**: a warning (or, with `'block'`, an error that stops the save) when a page has broken links, shown on
  the row, the block or column and the field, as for Craft's own errors.
- **Global sets** (a footer, site settings) are checked too.
- **Console**: `craft link-checker/rebuild` reads every page again; `craft link-checker/report` lists the problems.

It reads native Link fields, rich text fields (CKEditor, Redactor) and [Hyper](https://verbb.io/craft-plugins/hyper) fields,
also in nested entries: Matrix (any depth, also [Trinity](https://github.com/statikbe/craft-trinity) rows), Content Blocks
and entries in CKEditor. No other plugin is needed, and it works on existing sites.

## How it works

Every link of every page is stored in a table, per site, with where it is. A page is read again when it (or a block on it) is
saved. **Whether a link is broken isn't stored**: it's worked out from the target's current state each time the report is
read. Deleting, disabling or expiring an entry shows up in the widget straight away, without reading the pages that link to
it, and without cron.

Links to entries are stored by ID (`{entry:12@1:url}`), so renaming an entry or changing its URL doesn't break them. Only
links typed as a URL break when a page moves. Those are matched against the URIs of the live entries, the routes and the
templates of the site, and the redirects of [SEO Fields](https://plugins.craftcms.com/seo-fields) and
[Retour](https://plugins.craftcms.com/retour).

Of external links (to other websites) only the domain is checked: a DNS lookup in a queue job, cached per domain (a domain
that exists is looked up again after 7 days, one that doesn't after a day). The lookups never run in an editor's request, and
when even `example.com` can't be looked up (no network, a broken resolver) nothing is marked. Checking the pages themselves
over HTTP is planned for a later version.

## Requirements

Craft CMS 5.6 or later, PHP 8.2 or later.

## Installation

```bash
composer require statikbe/craft-link-checker
php craft plugin/install link-checker
```

Installing queues a job that reads every page. On a large site you can also run it yourself:

```bash
php craft link-checker/rebuild           # every page, now, with a progress bar
php craft link-checker/rebuild --queue   # as a queue job
```

Then add the **Broken links** widget to the dashboard.

## Configuration

Copy this to `config/link-checker.php` and change what you need. Multi-environment configs work as usual.

```php
<?php

return [
    // The sections whose entries are checked: '*' or a list of handles
    'sections' => '*',

    // Fields that are never read for links
    'excludeFields' => [],

    // Other hosts that are this website: the production domain on a local or staging install, an old domain.
    // www and non-www are already the same.
    'internalHosts' => ['www.example.be'],

    // Links to ignore: regular expressions matched against the stored value
    'ignorePatterns' => [],

    // A link to an entry in another site (language): 'ok' or 'warning'
    'crossSiteLinks' => 'ok',

    // Anchors (#…) that aren't on the target page, as warnings
    'checkAnchors' => true,

    // Anchors the templates render on every page (not in the content): strings, or regular expressions between # signs
    'knownAnchors' => ['main', 'contact-form'],

    // Invalid mailto: and tel: links, as warnings
    'checkEmailAndPhone' => true,

    // Also check the global sets
    'checkGlobals' => true,

    // Check that the domain of an external link exists (DNS)
    'checkDomains' => true,

    // When an entry with broken links is published: 'warn' (saved, with a warning), 'block' (not saved, the errors on the
    // fields) or false. Warnings never count. A link can break without the page being edited, so 'warn' is the default.
    'mode' => 'warn',

    // The Links panel in the entry sidebar
    'panel' => true,
];
```

## Anchors

A page's anchors are the ids in its rich text (`id` and `name` attributes) and the anchors of
[Entry Anchors](https://github.com/statikbe/craft-entry-anchors) fields. Ids that only your templates render aren't in the
content: list them in `knownAnchors`, or a link to them is reported as an anchor that's not found. A page in a section that
isn't checked has no known anchors, so links to its anchors aren't reported.

## The Links panel

The sidebar of an entry gets a **Links** panel under the notes: the broken links and warnings of the page as it's edited,
also the changes that aren't saved yet, with the reason and where each link is. It's collapsible (the browser remembers it
for every entry) and reloads after every autosave. The number of broken links shows next to its title, also when it's
collapsed.

## Check on publish

With `'warn'`, publishing a page with broken links shows a warning that lists them; the page is saved. With `'block'`, it
isn't saved: each broken link is shown where it is, on the row or block, the column (its card turns red) and the field. Only
broken links count, never warnings, and only when an entry is published in a web request: not for drafts, autosaves,
resaving or console commands.

## Widget

The widget lists the pages with broken links, broken ones first. Its settings: the number of pages shown (the rest is
counted) and whether to also list pages with only warnings.

## License

MIT, see [LICENSE.md](LICENSE.md).
