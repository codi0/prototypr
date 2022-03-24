# prototypr
A micro php library to help develop apps quickly.

Designed to run seamlessly in multiple contexts, with a single codebase and minimal configuration. Currently supported are "standalone" and "wordpress" contexts. This means you can run the code as a WordPress plugin or as a standalone app, with minimal duplication.

## Version
1.0.1

## Quick Start
- Copy all files to a directory that can execute php 7.2 or above
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
\Prototypr\Kernel    # Contains core application API methods
\Prototypr\Api       # Creates a standalone API server
\Prototypr\Composer  # Automatically syncs external dependencies defined in /composer.json
\Prototypr\Db        # Extends the PDO class to create an api compatible with $wpdb
\Prototypr\Model     # Provides a base model to deal with CRUD operations
\Prototypr\Orm       # A simple query store of models by ID or other WHERE conditions
\Prototypr\Platform  # Checks the platform the code is run on (E.g. in WordPress context, uses $wpdb)
\Prototypr\Proxy     # Wraps an existing object so that additional methods can be added
\Prototypr\Validator # Validate or filter input based on set rules
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

## Model annotations

If creating models by extending the \Prototypr\Model class, annotations can be used to automatically configure various behaviours and to track changes for optimised db updates (currently only hasOne and hasMany relations are supported). Any public property of a model (that is not marked as a relation or marked as ignored) is treated as a change-tracked property. 

```
/**
 * @id[ id ]
 * @table[ my_table_name ]
 * @ignore[ date_created, date_added ]
**/
class User extends \Prototypr\Model {

  public $id;
  
  /**
   * @null[ false ]
   * @rules[ email ]
   * @filters[ strtolower nowhitespace ]
  **/
  public $email = '';
  
  /**
   * Relations are defined using json syntax, inside the @json attribute
   * @relation[ { "model": "UserAddress", "type": "hasOne", "where": { "user_id": ":id" } ]
  **/
  public $address;
  
  public $date_created;
  
  public $date_updated;

}
```

## Core API methods

```
//TO-DO: Brief explanation and example for how to use each method

$this->isEnv($env)
$this->bind($fn, $thisObj = NULL)
$this->path($path = '', array $opts = [])
$this->url($path = '', array $opts = [])
$this->config($key = NULL, $val = NULL)
$this->platform($key = NULL, $val = NULL)
$this->module($name)
$this->service($name, $obj = NULL)  # Any services defined also accessible as $this->{serviceName}
$this->extend($method, $fn)  # Extension methods accessible as $this->{methodName}(...$args)
$this->event($name, $params = NULL, $remove = FALSE)
$this->route($route, $callback = NULL, $isPrimary = FALSE)
$this->log($name, $data = NULL)
$this->cache($path, $data = NULL, $append = FALSE)
$this->input($name, $clean = 'html')
$this->clean($value, $context = 'html')
$this->tpl($name, array $data = [], $code = NULL)
$this->json($data, $code = NULL)
$this->http($url, array $opts = [])
$this->model($name, array $data = [], $find = true)
$this->schedule($name, $fn = NULL, $interval = 3600, $reset = FALSE)
$this->cron($job = NULL)
$this->run()
$this->debug($asHtml = false)

$this->api->init(array $routes = [])  # Creates API routes
$this->api->auth()  # Auth callback, if auth parameter set on a route
$this->api->home()  # Default home route, used for endpoint discovery
$this->api->notFound()  # Default 404 route
$this->api->unauthorized()  # Default 401 route, if auth fails
$this->api->addData($key, $val)  # Adds to 'data' key of json response
$this->api->addError($key, $val)  # Adds to 'errors' key of json response
$this->api->respond(array $response, array $auditData = [])  # Creates json response
$this->api->formatResponse(array $response)  # Standardises json response
$this->api->auditLog(array $response, array $auditData = []))  # Creates audit log

$this->composer->sync()  # Automatically called from kernel on startup

$this->db->get_var($query)
$this->db->get_row($query, $row_offset = 0)
$this->db->get_col($query, $col_offset = 0)
$this->db->get_results($query)
$this->db->cache($method, $query, array $params = [])
$this->db->prepare($query, array $params = [])
$this->db->query($query, array $params = [])
$this->db->insert($table, array $data)
$this->db->replace($table, array $data)
$this->db->update($table, array $data, array $where = [])
$this->db->delete($table, array $where = [])
$this->db->schema($sqlSchemaOrFile)

$model->id()  # Returns value of ID field
$model->toArray()  # Get all model data as an array
$model->readOnly($readonly = true)  # Make model read only
$model->isValid()  # Check if model state currently valid
$model->errors()  # Get errors for invalid model state
$model->get(array $conditions = [])  # Hydrate model
$model->set(array $data)  # Set array of data (does not save)
$model->save()  # Save model state, if validation passed
$model->onConstruct(array $opts)  # Called at the end of the constructor
$model->onSet(array $data)  # Filters $data at the start of the set method
$model->onFilterVal($key, $value)  # Filters updated property $value
$model->onValidate()  # Called during validation, to define custom rules
$model->onSave()  # Called after model state successfully saved

$this->orm->create($modelNameOrClass, array $data = [])  # Creates new model
$this->orm->load($modelNameOrClass, array $conditions)  # Hydrates model with data
$this->orm->save($model)  # Saves model based on state changes
$this->orm->hydrate($model)  # Hydrates an existing model object that has no ID
$this->orm->onChange($model, $key, $val)  # Called by model class when state updates
$this->orm->dbTable($model)  # Gets db table for a model class

$this->platform->get($key)  # Valid keys are 'context' and 'loaded'
$this->platform->set($key, $val)  # Manually set platform vars
$this->platform->check()  # Automatically called from kernel on startup

$this->proxy->extend($method, $fn)  # Binds a new method to the target object, with $this set as target

$this->validator->addRule($name, $callback)  # Add a new validation rule callback
$this->validator->addFilter($name, $callback)  # Add a new filter callback
$this->validator->isValid($rule, $value, &$error = '')  # Check if value passed validation rule
$this->validator->filter($filter, $value)  # Filter a value

$this->view->queue($type, $content, array $dependencies = [])  # Add assets to template
$this->view->dequeue($type, $id)  # Remove asset from template
$this->view->tpl($name, array $data = [])  # Load template
$this->view->extend($method, $fn)  # Define helpers to use in templates (E.g. $tpl->myMethod(...$args))
$this->view->data($key, $clean = 'html')  # Get data in template (E.g. $tpl->data('meta.noindex'))
$this->view->url($url = '', $opts = [])  # Resolve url in template (E.g. $tpl->url('assets/img/a.png'))
```
