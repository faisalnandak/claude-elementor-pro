# Safe tooling

How to write a script that changes an Elementor site without losing anyone's work.

Every change goes through a script. Not the editor, not an ad-hoc `wp eval`, not a one-liner
in the shell. A script is reviewable, repeatable, and reversible; none of the alternatives
are.

---

## The three modes

```bash
php tools/<name>.php            # dry run — prints exactly what would change, writes nothing
php tools/<name>.php apply      # apply
php tools/<name>.php restore    # roll back from the backup this tool made
```

Dry run is the default so that running the wrong file by accident is harmless.

## Bootstrapping WordPress from the CLI

```php
<?php
/**
 * What this changes, and why the panel could not do it.
 *
 * Modes: (none) dry run | apply | restore
 */
$kt_root = dirname( __DIR__ );          // adjust to the site root
require $kt_root . '/wp-load.php';

$kt_mode = $argv[1] ?? 'dry';
```

**Prefix every variable.** A script at global scope shares scope with WordPress's own globals;
`$acf`, `$wp`, `$post`, `$l10n` and friends are live objects, and assigning to one of them
produces errors far from the line that caused them.

## Backups

Three rules, all learned the hard way:

1. **Per document, in post meta.** One site-wide option overflows MySQL's `max_allowed_packet`
   (1MB by default) and fails silently, leaving nothing to restore.
2. **Write the backup before the change**, not after. Obvious; still got it wrong once.
3. **One backup key per tool**, e.g. `_kt_backup_<toolname>`, so two tools never overwrite
   each other's rollback.

```php
if ( ! metadata_exists( 'post', $id, '_kt_backup_mytool' ) ) {
    update_post_meta( $id, '_kt_backup_mytool', get_post_meta( $id, '_elementor_data', true ) );
}
```

The `metadata_exists` guard means re-running `apply` never overwrites the original state with
an already-modified one.

## Idempotency

Compare against **the value you want**, never against "is it empty".

```php
if ( ( $el['settings']['columns_mobile'] ?? null ) !== '4' ) { … }   // right
if ( empty( $el['settings']['columns_mobile'] ) ) { … }              // wrong
```

Elementor's invisible defaults (container padding 10, gap 20) make the second form silently
skip elements that visibly need the change.

Running `apply` twice must produce the same database state as running it once, and the second
run should report zero changes. That report is your test.

## Walking `_elementor_data`

The document is a nested array of elements, each with `elements` children. Recurse by
reference:

```php
function kt_walk( array &$els, callable $fn ) {
    foreach ( $els as &$el ) {
        $fn( $el );
        if ( ! empty( $el['elements'] ) ) {
            kt_walk( $el['elements'], $fn );
        }
    }
    unset( $el );
}
```

**Do not rebind a by-reference parameter inside a function** — `$x = &$els;` breaks the
caller's link and you end up passing `null` to `array_splice()`. When you need to insert or
remove elements, find the target by **index path** and resolve the reference from the top:

```php
$path = kt_find_path( $doc, fn( $el ) => in_array( 'p-gallery', $el['settings']['_css_classes'] ?? [], true ) );
$ref  = &$doc;
foreach ( $path as $i ) { $ref = &$ref[ $i ]; }
```

## Saving

```php
update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $doc ) ) );
```

`wp_slash` matters: without it, backslashes in the JSON are eaten by `wp_unslash` on the way
in and the document becomes invalid.

Then, always, both of these:

```bash
php scripts/autosave-fix.php apply      # bump post_modified, clear stale autosaves
```

```php
\Elementor\Plugin::$instance->files_manager->clear_cache();
```

Skip the first and the editor will silently revert your work the next time it is opened. Skip
the second and the change is in the database but not in any served stylesheet.

`el_save()` in `scripts/elementor-lib.php` does the backup, the slashing, the autosave fix and
the cache flush in one call. Prefer it.

## Custom CSS blocks

A tool that writes CSS owns one marker pair and nothing else:

```php
$start = "/* KT mytool */";
$end   = "/* KT mytool end */";
```

- Both markers must be **complete comments**. A stray word outside a comment swallows the
  first rule of whatever follows.
- To update: remove everything between and including the markers, then append the new block.
  Never append a second copy; never truncate the string to the start marker, or the next
  tool's block disappears too.

## Verification, not assertion

A tool is not finished when it runs without an error. It is finished when:

1. The dry run's plan matches what actually changed.
2. A second `apply` reports zero changes.
3. The site sweep is clean:

   ```bash
   php scripts/site-sweep.php
   ```

   — every published page fetched, checked for PHP notices, fatal errors, empty renders and
   unbalanced markup.
4. Screenshots at 1280 / 768 / 390 look right. Not just measure right. Look.

## Reporting

Report what changed, with counts, and what you verified. If something was skipped, say which
and why. If a verification failed, show the output rather than describing it.
