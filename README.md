ClassicImporter (module for Omeka S)
===============================

[ClassicImporter] is a module for [Omeka S] and will allow an administrator to import item sets, items and media from a database dump of an Omeka Classic instance.

Installation
------------

See general end user documentation for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules).

Usage
-----

To use the module, you first need an Omeka (classic) instance of your choice, then you need to create a database dump from that.
The module is not made to create the dump; the administrator needs to do it themselves.

**Step 1 — Create the temporary database**

A dedicated MySQL database must be created to receive the Omeka Classic dump.
Here is what the SQL commands should look like, to be executed as root:
```bash
sudo mysql -uroot
[MySQL] > CREATE USER 'tempdb'@'%' IDENTIFIED BY 'password';
[MySQL] > CREATE DATABASE tempdb;
[MySQL] > GRANT ALL PRIVILEGES ON tempdb.* TO 'tempdb'@'%';
```

It is needed because the default Omeka-S user does not have enough permissions to do that.

**Step 2 — Configure the credentials**

Declare the temporary database credentials in `config/local.config.php` (not `module.config.php`, to keep secrets out of version control):
```php
    // This is in config/local.config.php
    'classicimporter' => [
        'tempdb_credentials' => [
            "username" => "tempdb",
            "password" => "password",
            "hostname" => "localhost",
            "database" => "tempdb",
        ]
    ]
```

**Step 3 — Load the dump into the temporary database**

Because SQL dumps can be very large, the module does not load the dump file itself.
The administrator must import it directly via the MySQL CLI:
```bash
mysql -u tempdb -ppassword -h localhost tempdb < /path/to/dump.sql
```

**Step 4 — Upload media files (optional)**

The media files contained in `omeka/files/original` must also be uploaded to the Omeka-S instance (anywhere Omeka-S can reach on disk) if you want media to be imported.

**Step 5 — Run the import from the interface**

In the "ClassicImporter" tab you will find a form with two optional fields:
- **Path to the original media files** — leave empty to skip media import
- **URL of the old Omeka Classic instance** — does not need to still work; it is used to detect and convert internal links (e.g. `my-omeka-classic.com/items/show/5`) into links to the corresponding imported resource in Omeka-S

Fill in the form and click **Next**. You will then be able to see what properties and resource classes can be mapped. All values set on properties that are **not** mapped will **not** be imported!

On the mapping screen:

- **Import collections** — check this to import Omeka Classic collections as Omeka-S item sets.
- **Import Item sets tree** — only appears if the dump contains CollectionsTree data and the [ItemSetsTree](https://github.com/biblibre/omeka-s-module-ItemSetsTree) module is active. Imports the collection hierarchy.
- **Update** — see the *Updating a previous import* section below.

For each property, you may select **Clean HTML** to strip HTML tags from the property values. For example, if `dcterms:title` is `<strong>Hello!</strong>`, "Clean HTML" will only keep `Hello!`.

For each property, you can also check **Map URIs**. This means that if a value contains a link to the old Omeka Classic instance (e.g. `https://my-omeka-classic.com/items/show/5`), the value will be converted into a link to the corresponding imported resource in Omeka-S. If unchecked, the link is kept as a plain URI.
This option also converts values like `<a href="https://example.com">My link!</a>` into a proper URI value rather than raw HTML content.

Finally, if no errors occur, all imported resources will be available in your Omeka-S instance.

Updating a previous import
---------------------------

If you have already run an import and want to re-import from a refreshed dump (e.g. the source Omeka Classic database has changed), you can update the previously imported resources instead of creating duplicates.

**Step 1** — Drop and reload the temporary database with the new dump:
```bash
# Drop all tables in the tempdb
mysql -u tempdb -ppassword -h localhost tempdb -e "SET FOREIGN_KEY_CHECKS=0; $(mysql -u tempdb -ppassword -h localhost -N -e "SELECT GROUP_CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`' SEPARATOR '; ') FROM information_schema.tables WHERE table_schema='tempdb';" 2>/dev/null); SET FOREIGN_KEY_CHECKS=1;"

# Or more simply: drop and recreate the database
sudo mysql -uroot -e "DROP DATABASE tempdb; CREATE DATABASE tempdb; GRANT ALL PRIVILEGES ON tempdb.* TO 'tempdb'@'%';"

# Then reload the new dump
mysql -u tempdb -ppassword -h localhost tempdb < /path/to/new_dump.sql
```

**Step 2** — In the **Past imports** tab, click **Update import** next to the import you wish to update. This will pre-fill the form with the parameters of the original import.

**Step 3** — On the mapping screen, the **Update** checkbox will be pre-checked. Click **Next** to confirm and start the update job.

During an update:
- Resources that still exist in the new dump are **updated in place**.
- Resources that no longer exist in the new dump are **deleted** from Omeka-S.
- New resources are **created**.

Past imports and Undo
---------------------

The **Past imports** tab lists all previous import and update jobs with their statistics (number of item sets, items, URIs imported or updated).

From this view you can:
- **Undo** a past import by checking its checkbox and clicking Submit. This will delete all resources that were created during that import. Update jobs cannot be undone (the checkbox is disabled).
- **Update import** — re-run an import against a refreshed dump (see above).
- Access the **Job details** and **Log** for each job for debugging purposes.

Example
-------

![Screenshot of the import form.](screenshots/loading_from_a_dump_file.png)
![Screenshot of the mapping screen.](screenshots/mapping_properties.png)

Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check your archives regularly so you can roll back if needed.

If you don't know what you're doing, you're probably not the target audience for this module. It should only be used by administrators.

Troubleshooting
---------------

See online issues on the [Omeka forum] and the [module issues] page on GitHub.


Contact
-------

Current maintainers:

* BibLibre
* [Abel B.]


All rights not expressly granted are reserved.

* Copyright Biblibre, 2026-present

[ClassicImporter]: https://github.com/biblibre/ClassicImporter
[Omeka S]: https://omeka.org/s
[Omeka forum]: https://forum.omeka.org/c/omeka-s/modules
[module issues]: https://github.com/omeka-s-modules/CSVImport/issues
[GNU/GPL v3]: https://www.gnu.org/licenses/gpl-3.0.html
[Abel B.]: https://github.com/Bebel00
