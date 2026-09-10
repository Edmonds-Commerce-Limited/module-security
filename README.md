# magento2-security

## By [Edmonds Commerce](https://www.edmondscommerce.co.uk)

[![CI](https://github.com/Edmonds-Commerce-Limited/module-security/actions/workflows/main.yml/badge.svg)](https://github.com/Edmonds-Commerce-Limited/module-security/actions/workflows/main.yml)
[![Packagist](https://img.shields.io/packagist/v/edmondscommerce/module-security.svg)](https://packagist.org/packages/edmondscommerce/module-security)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Helps to harden Magento 2 security by disabling certain features.

Magento ships with a number of features enabled by default that most stores never use and present a potential attack
surface.


## Features

### Customer file uploads through product custom options

A product custom option of type **File** lets a customer attach a file of their choosing to a cart
item, which Magento writes into `pub/media/custom_options/quote/`.

The extension check on that route is fail open. `Magento\Framework\File\Uploader::checkAllowedExtension()`
accepts any alphanumeric extension unless an allow list was set, and Magento never sets one for this
path — the only filter is the option's own `file_extension` field, which is optional and empty by
default. `catalog/custom_options/forbidden_extensions` (`php,exe`) applies only when the option has no
allow list of its own, and has no admin field at all. A polyglot payload — a file that is at once a
valid image and a valid script — satisfies the image checks regardless, so the option's `file_extension`
and `image_size_x` settings are not a defence.

There are two independent ways in, and this module closes both:

| Route | Where it is blocked |
| --- | --- |
| Storefront, wishlist and reorder multipart upload | `Catalog\Model\Product\Option\Type\File\ValidatorFile::validate()` |
| REST and SOAP base64 `file_info` extension attribute | `Catalog\Model\Webapi\Product\Option\Type\File\Processor::processFileContent()` |

Admin order creation is deliberately left working, and merchants can still define file options.

**Configuration:** *Stores > Configuration > Edmonds Commerce > Security > Custom Options >
Disable Customer File Uploads*, at `edmondscommerce_security/custom_options/disable_file_upload`.
**Blocked by default** — installing the module is the opt-in.

Products carrying a *required* file option cannot be bought while this is on. Products with an
*optional* file option still add to cart, without the file. Carts and orders placed before installing
are unaffected: revalidating a file already uploaded goes through a different code path.

### Customer address file uploads (SessionReaper)

`POST /customer/address_file/upload` (`Magento\Customer\Controller\Address\File\Upload`) uploads a file
for a file or image customer address attribute. It needs **no login** and moves the file into
`pub/media/customer_address/`.

That is the file-write half of SessionReaper (CVE-2025-54236, APSB25-88). The attacker uploads a file
crafted as a PHP session, then uses a nested deserialization bug in the Web API to make Magento load it
as a session, which ends in remote code execution when sessions are stored on disk. Adobe fixed the Web
API half in `Magento\Framework\Webapi\ServiceInputProcessor`, but the upload is still open on patched
releases.

This module answers the route with a `403` and a JSON error in core's own shape, without running the
upload. Nothing is thrown, so probes do not fill `var/report`. Admin uploads go through different
controllers and are not affected.

**This does not replace Adobe's fix.** Upgrade, or apply the APSB25-88 hotfix. If you applied the core
patch that throws at the top of `Upload::execute()`, you can drop it once this module is deployed.

**Configuration:** *Stores > Configuration > Edmonds Commerce > Security > Customer Addresses >
Disable Customer Address File Uploads*, at `edmondscommerce_security/customer_address/disable_file_upload`.
**Blocked by default.**

Only stores whose storefront address form has a file or image attribute need this switched off. Open
Source has no admin UI for creating such attributes, so on Open Source that usually means one added by a
data patch or an extension.
