# AGENTS.md

Project instructions for AI coding agents (GitHub Copilot, Claude Code, Codex, etc.).
Put this file in the repository root. Edit the `TODO` items for your project.

## Project overview

- Framework: **Phalcon 3.x** (C extension, `cphalcon` 3.x branch). **Not** Phalcon 4 or 5.
- Language: PHP — **TODO: exact version** (Phalcon 3.x supports PHP 5.5–7.3; do not use syntax newer than this).
- Architecture: **TODO: MVC / Micro / multi-module**
- DB: **TODO: MySQL / PostgreSQL**
- Templates: **TODO: Volt / PHP**

## Critical rule: Phalcon 3 API only

Phalcon 3 differs from Phalcon 4/5. Do **not** use APIs from newer versions.
If unsure whether a class exists in 3.x, say so and check the Phalcon 3 docs instead of guessing.

| Use (Phalcon 3) | Do NOT use (Phalcon 4/5) |
|---|---|
| `Phalcon\Loader` | `Phalcon\Autoload\Loader` |
| `Phalcon\Mvc\Model\Query\Builder` via `modelsManager` | Same, but new factory classes |
| `Phalcon\Session\Adapter\Files`, `Phalcon\Session\Bag` | `Phalcon\Session\Manager` |
| `Phalcon\Cache\Backend\*` + `Phalcon\Cache\Frontend\*` | `Phalcon\Cache\Adapter\*`, `Phalcon\Storage\*` |
| `Phalcon\Tag` | `Phalcon\Html\TagFactory` |
| `Phalcon\Config\Adapter\Php/Ini/Json/Yaml` | `Phalcon\Config\ConfigFactory` |
| `Phalcon\Logger\Adapter\File` | `Phalcon\Logger\Logger` + adapters |
| `Phalcon\Flash\Session` / `Phalcon\Flash\Direct` | Flash with `Phalcon\Session\Manager` |
| `Phalcon\Http\Request`, `Phalcon\Http\Response` | PSR-7 `Phalcon\Http\Message\*` |
| `Phalcon\Db\Adapter\Pdo\Mysql` | `Phalcon\Db\Adapter\PdoFactory` |
| `Phalcon\Validation` + `Phalcon\Validation\Validator\*` | New `Phalcon\Filter\Validation\*` |
| `Phalcon\Acl\Adapter\Memory`, `Phalcon\Acl\Resource` | `Phalcon\Acl\Component` |
| `Phalcon\Di\FactoryDefault` (registers session by default) | Assuming no default session |
| `Phalcon\Mvc\Dispatcher` | Dispatcher with `Phalcon\Dispatcher\Exception` changes |

## PHP constraints

- No typed properties, no arrow functions, no `match`, no named arguments, no union types.
- Scalar type hints and return types only if PHP >= 7.0 (check **TODO** version above).
- Use `array()` or `[]` (`[]` is fine on PHP >= 5.4).
- Do not add Composer packages that require a newer PHP than the project's.

## Project structure

```
app/
  config/        # config.php, services.php, loader.php, router.php
  controllers/
  models/
  views/
  library/
  migrations/
public/
  index.php      # bootstrap
tests/
```

**TODO:** adjust to the real layout.

## Code conventions

- PSR-4 style namespaces registered through `Phalcon\Loader::registerNamespaces()`.
- Controllers extend `Phalcon\Mvc\Controller`; actions are named `fooAction`.
- Services are registered in the DI container (`$di->setShared(...)`). Do not use globals or `new` for shared services.
- Inside controllers, models and `Injectable` classes, access services via `$this->db`, `$this->session`, `$this->request` etc.
- Models extend `Phalcon\Mvc\Model`. Define table in `initialize()` with `setSource()`. Define relations with `hasMany`, `belongsTo`, `hasOne`, `hasManyToMany` there.
- Always use **bound parameters** in queries:

  ```php
  $user = Users::findFirst([
      'conditions' => 'email = :email: AND active = 1',
      'bind'       => ['email' => $email],
  ]);
  ```

- Never concatenate user input into PHQL or SQL.
- Check `$model->save()` result and read `$model->getMessages()` on failure.
- Validate input with `Phalcon\Validation`; escape output in views (Volt escapes by default; use `|e` where needed in raw PHP views).
- Use `Phalcon\Security` for hashing passwords and CSRF tokens. Never store plain passwords.
- Do not use `$_GET` / `$_POST` directly; use `$this->request`.

## Build, run and test

- Requires the Phalcon 3.x extension (`php -m | grep phalcon`, version via `Phalcon\Version::get()`).
- Dev tools: `phalcon-devtools` 3.x (`phalcon migration`, `phalcon model`, `phalcon controller`).
- Install dependencies: `composer install`
- Run tests: **TODO** (e.g. `vendor/bin/codecept run`; Phalcon 3 era projects commonly use Codeception 2.x or PHPUnit 5–7)
- Lint: `find app -name '*.php' -print0 | xargs -0 -n1 php -l`

Run lint and tests before proposing a change as finished.

## Safety

- Do not edit generated files, vendor code, or `app/migrations/` history without being asked.
- Do not commit secrets, `.env` files or real DB credentials.
- Ask before running destructive DB operations or migrations.
- Keep changes small and focused; do not refactor unrelated code.

## Language

Reply to the developer in **Russian**. Keep code, comments and identifiers in English.
