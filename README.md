# 🚀 Laravel System Builder

A powerful Laravel package that automatically generates:

- 📁 Database Migrations
- 🧬 Eloquent Models
- 🧠 Controllers
- 🎨 Blade Views

Simply define your system structure using a JSON file — and the builder does the rest.

---

## 📌 Installation

```bash
composer require arpan/laravel-system-builder
```

Place a JSON definition file anywhere in your Laravel project, for example:
```
storage/app/system.json
```

Then run:
```
php artisan system:build --json=storage/app/system.json

```
To force overwrite existing files:

```
php artisan system:build --json=storage/app/system.json --force
```

Example JSON Definition
```
{
"tables": [
{
"name": "users",
"columns": [
{ "name": "name", "type": "string", "nullable": false },
{ "name": "email", "type": "string", "unique": true },
{ "name": "password", "type": "string" }
]
},
{
"name": "workers",
"columns": [
{ "name": "first_name", "type": "string" },
{ "name": "last_name", "type": "string" },
{ "name": "phone", "type": "string", "nullable": true }
]
}
]
}
```
Dont Warry We Have Json File Help Function Just Run:

```
composer sys

OR

composer system:help

```

## ⚙️ Features

| Feature                                      | Status |
|----------------------------------------------|:------:|
| Automatic migrations                         | ✔      |
| Automatic model creation                     | ✔      |
| Automatic controller creation                | ✔      |
| View scaffolding                             | ✔      |
| Duplicate file detection & overwrite prompt  | ✔      |
| Foreign keys support                         | ✔      |
| Enum & JSON fields                           | ✔      |
| Future GUI builder support                   | ⏳ Coming Soon |


✨ Credits

This package is created and maintained by:
```
👨‍💻 Arpan Mandaviya

You are permitted to use this package freely for now.
Future versions may include paid plans, premium features or licensing changes.
Any such change will be announced ahead of enforcement.

If you're using this in a commercial product, a mention or sponsorship is appreciated (but not mandatory).
```

LICENCED:

```
Copyright (c) 2025-present
Owner: Arpan Mandaviya

Permission is hereby granted to use this software free of charge for personal
and commercial projects as long as:

1. You do NOT remove credits without explicit written permission.
2. You do NOT claim authorship or ownership of this package.
3. You do NOT resell or distribute modified versions as a competing product.

The author reserves the right to introduce paid licensing or additional terms
in future releases. Continued use of updated versions implies acceptance of
future license terms.

THIS SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED. USE AT YOUR OWN RISK.
```