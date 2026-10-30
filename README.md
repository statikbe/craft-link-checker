# Link Checker

Finds the broken links in the content of your Craft CMS 5 site and lists the pages that contain them in a dashboard widget, so
editors can fix them before visitors find them.

- **Broken links**: links to entries that were deleted, are in the trash, disabled, expired, not in the page's site or have no
  page of their own; links to deleted assets; links to pages of the site itself (`/nl/over-ons`, `https://example.be/…`)
  that no longer exist.
- **Warnings**: links to entries that aren't published yet; links to a page that a redirect sends on (link to the page
  itself); anchors (`#team`) that aren't on the target page; invalid email addresses and phone numbers; a deleted entry whose
  saved URL still works.
- **Broken links** dashboard widget: the pages with broken links, with a link to edit each one and every broken link with
  its reason and where it is (*Content builder 2 (Content row) › Columns 1 (Text) › Text*). Each user only sees the pages
  they may edit.
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

External links (to other websites) are found but not checked yet: checking them over HTTP is planned for a later version.

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
];
```

## Anchors

A page's anchors are the ids in its rich text (`id` and `name` attributes) and the anchors of
[Entry Anchors](https://github.com/statikbe/craft-entry-anchors) fields. Ids that only your templates render aren't in the
content: list them in `knownAnchors`, or a link to them is reported as an anchor that's not found. A page in a section that
isn't checked has no known anchors, so links to its anchors aren't reported.

## Widget

The widget lists the pages with broken links, broken ones first. Its settings: the number of pages shown (the rest is
counted) and whether to also list pages with only warnings.

## License

MIT, see [LICENSE.md](LICENSE.md).
