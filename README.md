# prototypr
A micro php library to help develop apps quickly.

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
  /schemas/     # Any .sql files stored here automatically executed on app install (by setting a 'version' config option)
/modules/
  [moduleName]  # App logic stored in modules, loaded at run-time (each module can have its own /vendor/ folder)
    /module.php
/vendor/
  /Prototypr/   # Core class files for this library stored here
/index.php
```

## Library classes
```
\Prototypr\App       # Contains core API methods
\Prototypr\Composer  # Automatically syncs external dependencies defined in /package.json
\Prototypr\Db        # Extends the PDO class, with additional query helper methods (compatible with WPDB)
\Prototypr\Platform  # Automatically configures the app based on its context (standalone, wordpress)
\Prototypr\View      # A simple php templating class, to help separate business and presentation logic
```

## App execution flow

1. App class is included, and initiated (see index.php)
2. Environment and config data is processed, with sensible defaults set
3. Error and exception handling is setup
4. Class autoloader is setup (handles both global and module /vendor/ paths)
5. Default services are defined, for lazy-loading when needed (Composer, Db, Platform, View)
6. External dependencies are synced, if required
7. Platform check is run, so that app can run seamlessly in multiple contexts (E.g. as a WordPress plugin)
8. Modules are loaded
9. app.loaded event is called
10. app.upgrade event is called (if version config value has changed)
11. App->run() is called (either immediately, on script termination or manually - depending on configuration)
12. app.init event is called
13. Cron check is run
14. Route is matched and executed
15. app.output event is called (allowd for output manipulation before being sent to the client)
16. app.shutdown event is called

## Use of modules

Most of your application code will live in modules, allowing you to break your app up into distinct parts. Modules follow a few conventions:

1. A module must contain a /module.php file, which acts as a gateway into the module.
2. If a module contains a /vendor/ directory, it will be added to the autoloading paths checked when loading classes.
3. Each /module.php file has access to $this (the main App class, and its API methods), without the need to define a class.
4. Any module template files (.tpl) should be in the module root or a /tpl/ directory, in order to be auto-discovered by the View engine.
5. Any module asset files (E.g. js, css, images) must live inside an /assets/ directory, in order to be directly accessible by a client.

## Theme modules

A module can be assigned as a theme, using a config option. An example theme is included as a reference:
```
$this->config('theme', '{moduleName}');
```

A theme module can also access the View engine, by defining php files in a /functions/ directory. This allows for view manipulation, such as:
```
//inject assets into the template head
//Inside theme functions, $this represents the View class, with the App class available by calling $this->app
$this->queue('css', 'assets/css/app.css');
$this->queue('js', 'assets/js/app.js');
```

## Core API methods

//TO-DO

