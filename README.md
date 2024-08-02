# File Import

This is the documentation of File Import, an application for the file importing to Nextcloud from the file system!  File Import contains a job used to import user files.

File Import contains a job importig user files. The Nextcloud job runs every 5 minutes.

The following steps describe the installation of File Import in Nextcloud.

## Prerequisite

- Nextcloud (version 27-28)

**Note:** Tested with Debian/Raspberry Pi OS

## Install the app

1. Copy the `file_import` into Nextcloud's `apps` folder.
2. Add the backup configuration to `config/config.php`:
   - `file_import_folder`: File import folder location.

## How to use

1. Scheduled job: Runs every 5 minutes when the `file_import_folder` is set to `false`.
2. The upload folder contains a folder having the same username as a user. In this folder, newly added files will be moved for the corresponding user to the same folder as the file was put.

## Authors

- [**Eli Nucknack**](mailto:eli.nucknack@gmail.com)
