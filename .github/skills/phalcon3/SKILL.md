---
name: phalcon3
description: Use when writing, debugging, reviewing or migrating code in a Phalcon 3.x PHP project (controllers, models, PHQL, Volt, DI, router, ACL, forms, validation, sessions, cache). Applies to legacy Phalcon 3 only, not Phalcon 4 or 5.
---

# Phalcon 3 skill

Install location for Copilot: `.github/skills/phalcon3/SKILL.md`
(Claude Code: `.claude/skills/phalcon3/SKILL.md`).

## When to use

Any task in a project that runs the Phalcon **3.x** C extension. First confirm the version:

```php
echo Phalcon\Version::get();   // expect 3.x.x
```

If the project is 4.x or 5.x, stop using this skill.

## Rules

1. Use only classes and methods that exist in Phalcon 3. Never invent APIs; if unsure, say so.
2. Respect the project's PHP version (3.x supports PHP 5.5–7.3). No modern PHP syntax beyond that.
3. Prefer DI services (`$this->db`, `$this->modelsManager`, `$this->session`) over globals.
4. Always bind parameters. Never concatenate user input into SQL/PHQL.
5. Keep changes minimal and consistent with existing code style.

## Bootstrap pattern (public/index.php)

```php
use Phalcon\Loader;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

$loader = new Loader();
$loader->registerNamespaces([
    'App\Controllers' => APP_PATH . '/controllers/',
    'App\Models'      => APP_PATH . '/models/',
])->register();

$di = new FactoryDefault();
// register services: db, view, url, router, dispatcher, session, config...

$application = new Application($di);
echo $application->handle()->getContent();
```

## Services (typical)

```php
$di->setShared('db', function () use ($config) {
    return new Phalcon\Db\Adapter\Pdo\Mysql([
        'host'     => $config->database->host,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => 'utf8mb4',
    ]);
});

$di->setShared('view', function () {
    $view = new Phalcon\Mvc\View();
    $view->setViewsDir(APP_PATH . '/views/');
    $view->registerEngines([
        '.volt' => function ($view, $di) {
            $volt = new Phalcon\Mvc\View\Engine\Volt($view, $di);
            $volt->setOptions([
                'compiledPath'      => APP_PATH . '/cache/volt/',
                'compiledSeparator' => '_',
            ]);
            return $volt;
        },
    ]);
    return $view;
});
```

## Models

```php
namespace App\Models;

use Phalcon\Mvc\Model;

class Users extends Model
{
    public $id;
    public $email;

    public function initialize()
    {
        $this->setSource('users');
        $this->hasMany('id', Posts::class, 'user_id', ['alias' => 'posts']);
    }
}

// Queries
$user  = Users::findFirst(['email = :email:', 'bind' => ['email' => $email]]);
$count = Users::count(['active = 1']);

if (!$user->save()) {
    foreach ($user->getMessages() as $message) {
        // handle $message->getMessage()
    }
}
```

PHQL / Query Builder:

```php
$rows = $this->modelsManager->createBuilder()
    ->from(Users::class)
    ->where('active = :a:', ['a' => 1])
    ->orderBy('id DESC')
    ->limit(20)
    ->getQuery()
    ->execute();
```

Transactions: `Phalcon\Mvc\Model\Transaction\Manager`.

## Controllers

```php
class UsersController extends \Phalcon\Mvc\Controller
{
    public function indexAction()
    {
        $this->view->users = Users::find();
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect('users');
        }
        $email = $this->request->getPost('email', 'email');
        // ...
    }
}
```

## Validation

```php
$v = new Phalcon\Validation();
$v->add('email', new Phalcon\Validation\Validator\Email(['message' => 'Invalid email']));
$messages = $v->validate($_POST);   // or $this->request->getPost()
```

## Common Phalcon 3 vs 4/5 pitfalls

- Autoloader is `Phalcon\Loader` (not `Phalcon\Autoload\Loader`).
- Cache = Frontend + Backend (`Phalcon\Cache\Backend\Redis`, `Phalcon\Cache\Frontend\Data`).
- No `Phalcon\Session\Manager`; use `Phalcon\Session\Adapter\Files` and `$di->setShared('session', ...)`.
- ACL uses `Phalcon\Acl\Resource` (renamed to Component later).
- No `Phalcon\Html\TagFactory`; use `Phalcon\Tag` / `$this->tag`.
- Logger is `Phalcon\Logger\Adapter\File` with `$logger->error(...)`.
- Many methods have no return type hints; do not rely on strict typing from the framework.

## Debugging checklist

1. Phalcon extension loaded and version matches (`php -m`, `Phalcon\Version::get()`).
2. Loader namespaces / directories correct, class file name matches class name.
3. Volt cache dir writable; clear compiled templates after view changes.
4. DB: check `$model->getMessages()` and enable logging via `Phalcon\Db\Profiler` or `EventsManager`.
5. Dispatcher errors: check namespace, `setDefaultNamespace`, controller suffix and action suffix.
6. Router: confirm routes order; call `$router->handle()` and inspect `getControllerName()` / `getActionName()`.

## Tests

- Use the project's existing runner (Codeception 2.x or PHPUnit of the PHP 5/7 era).
- Do not upgrade test tooling unless asked.
- Mock DI services rather than hitting the real DB when possible.

## References

- Phalcon 3.4 docs: https://docs.phalcon.io/3.4/en/
- cphalcon 3.4.x: https://github.com/phalcon/cphalcon/tree/3.4.x
- Phalcon DevTools 3.x: https://github.com/phalcon/phalcon-devtools
