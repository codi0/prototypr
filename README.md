# prototypr
A micro php library to help develop apps quickly.

Designed to run seamlessly in multiple contexts, with a single codebase and minimal configuration. Currently supported are "standalone" and "wordpress" contexts. This means you can run the code as a WordPress plugin or as a standalone app, with minimal duplication.

## Version
1.0.1

## Quick Start
- Copy all files to a directory that can execute php
- Open index.php to review configuration options (config files can also be created in the "/data/config/" directory).
- Look at the examples modules, then create your own to define your app logic

## App structure

```
/data/
  /cache/       # Any calls to $this->cache($key, $val) stored here
  /config/      # Any global config options can be stored here (php array in .php files)
  /logs/        # Any calls to $this->log($name, $data) stored here
  /schemas/     # Any .sql files stored here executed on app install (if 'version' config set)
/modules/
  [moduleName]  # App logic stored in modules, loaded at run-time
    /module.php
/vendor/
  /Prototypr/   # Core class files for this library stored here
/index.php
```

## Library classes

```
\Prototypr\App       # Contains core API methods
\Prototypr\Composer  # Automatically syncs external dependencies defined in /composer.json
\Prototypr\Db        # Extends the PDO class to create an api compatible with $wpdb
\Prototypr\Platform  # Checks the platform the code is run on (E.g. in WordPress context, uses $wpdb)
\Prototypr\View      # A simple php templating class, to help separate business and presentation logic
```

## App execution flow

1. App class initiated (see index.php)
2. Environment and config data processed, with sensible defaults set
3. Error and exception handling setup
4. Class autoloader setup (handles both global and module /vendor/ paths)
5. Default services defined, to be lazy-loading when needed (Composer, Db, Platform, View)
6. External dependencies synced, if required
7. Platform analysed, to auto-configure app based on context (E.g. as a WordPress plugin)
8. Modules loaded
9. app.loaded event called
10. app.upgrade event called (if version config value has changed)
11. App->run() called (either immediately, on script termination or manually - depending on config)
12. app.init event called
13. Cron check run
14. Route matched and executed (if found, otherwise uses fallback 404)
15. app.output event called (allows for output manipulation before being sent to the client)
16. app.shutdown event called

## Use of modules

Most of your application code will live in modules, allowing you to break your app up into distinct parts. Modules follow a few conventions:

1. A module SHOULD contain a /module.php file, which acts as a gateway into the module.
2. If a module contains a /vendor/ directory, it WILL be added to clas autoloading paths.
3. Each /module.php file has access to $this (the main App class), without the need to define a class.
4. Any module template files (.tpl) MUST be in the module root or a /tpl/ directory, in order to be auto-discovered.
5. Any module asset files (E.g. js, css, images) MUST live inside an /assets/ directory, to be directly accessible.

## Theme modules

A module can be assigned as a theme, using a config option. An example theme (called theme!) is included as a reference.
```
//Set which module will act as the theme
$this->config('theme', '{moduleName}');
```

A theme module can also access the View engine, by defining php files in a /functions/ directory. This allows for view manipulation, such as:
```
//Inject assets into the template head
//Inside theme functions, $this represents the View class
$this->queue('css', 'assets/css/app.css');
$this->queue('js', 'assets/js/app.js');
```

## Core API methods

```
//TO-DO: Brief explanation and example for how to use each method

$this->bind($fn, $thisObj = NULL)
$this->path($path = '', array $opts = [])
$this->url($path = '', array $opts = [])
$this->config($key = NULL, $val = NULL)
$this->platform($key = NULL, $val = NULL)
$this->module($name)
$this->service($name, $obj = NULL)  # Any services defined also accessible as $this->{serviceName}
$this->helper($name, $fn = NULL)    # Any helpers defined also accessible as $this->{helperName}(...$args)
$this->event($name, $params = NULL, $remove = FALSE)
$this->route($route, $callback = NULL, $isPrimary = FALSE)
$this->log($name, $data = NULL)
$this->cache($path, $data = NULL, $append = FALSE)
$this->dbCache($method, $query, array $params = [], $expiry = NULL)
$this->dbSchema($schema)
$this->input($name, $clean = 'html')
$this->clean($value, $context = 'html')
$this->tpl($name, array $data = [], $code = NULL)
$this->json($data, $code = NULL)
$this->http($url, array $opts = [])
$this->schedule($name, $fn = NULL, $interval = 3600, $reset = FALSE)
$this->cron($job = NULL)
$this->run()

$this->view->tpl($name, array $data = [])
$this->view->data($key, $clean = 'html')
$this->view->url($url = '', $opts = [])
$this->view->clean($value, $context = 'html')
$this->view->queue($type, $content, array $dependencies = [])
$this->view->dequeue($type, $id)
$this->view->__call()  # Allows access to App class helpers (E.g. $this->view->{helperMethod){...$args)

$this->db->get_var($query)
$this->db->get_row($query, $row_offset = 0)
$this->db->get_col($query, $col_offset = 0)
$this->db->get_results($query)
$this->db->prepare($query, array $params = [])
$this->db->query($query, array $params = [])
$this->db->insert($table, array $data)
$this->db->replace($table, array $data)
$this->db->update($table, array $data, array $where = [])
$this->db->delete($table, array $where = [])

$this->composer->sync()
```