ClassicImporter (module for Omeka S)
===============================

[ClassicImporter] is a module for [Omeka S] and will allow an administrator to import item sets, items and media from a database dump of an Omeka instance.

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

Use "Import collections" if you want to import item sets.
Use "Update" if you want to update from your precedent dump import. Previously imported resources with matching ids will therefore be updated accordingly.
Use "Import Item sets tree" if the dump contains CollectionsTree information that can be imported.

For each property, you may select "Clean HTML" to clear HTML content from the property values. If `dcterms:title` is `<strong>Hello!</strong>`, "Clean HTML" will only keep `Hello !`.

For each property, you can also check "Map URIs". This means that if `dcterms:description` is `my-omeka-classic.com/show/items/5/`, the value will be imported as a URI to the corresponding imported item. If unchecked, the link will be kept like it was.
Checking this checkbox will also import values like `<a href="https://example.com">My link!</a>` as a link rather than HTML content.

Finally, if no errors, all the imported resources should be on your Omeka-S instance.

Example
-------

![Screenshot of loading from a dump file.](screenshots/loading_from_a_dump_file.png)
![Screenshot of mapping properties](screenshots/mapping_properties.png)

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
