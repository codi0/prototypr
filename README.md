# prototypr
A micro php library to help develop apps quickly. Current version is 1.0.1.

## Quick Start
- Copy all files to a directory that can execute php
- Open index.php to review configuration options (config files can also be created in the "/data/config/" directory).

## App structure

```
/data/
  /cache/      # Any calls to $this->cache($key, $val) stored here
  /config/     # Any global config options can be stored here (php array in .php files)
  /logs/       # Any calls to $this->log($name, $data) stored here
  /schemas/    # Any .sql files stored here automatically executed on app install (by setting a 'version' config option)
/modules/
  [...]        # App logic stored in modules, loaded at run-time (each module can have its own /vendor/ folder)
/vendor/
  /Prototypr/  # Core class files for this library stored here
/index.php
```

## Core classes
```
\Prototypr\App       # Contains core API methods
\Prototypr\Composer  # Automatically syncs external dependencies defined in /package.json
\Prototypr\Db.php    # Extends the PDO class, with additional query helper methods (compatible with WPDB)
\Prototypr\Platform  # Automatically configures the app based on it's context (standalone, wordpress)
\Prototypr\View      # A simple php templating class, to help separate business and presentation logic
```
