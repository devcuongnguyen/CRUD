<?php

namespace Backpack\CRUD\app\Library\CrudPanel;

use Illuminate\Database\Eloquent\Collection;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Read;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Tabs;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Query;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Views;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Access;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Create;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Delete;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Errors;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Fields;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Search;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Update;
use Backpack\CRUD\app\Library\CrudPanel\Traits\AutoSet;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Buttons;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Columns;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Filters;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Reorder;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Settings;
use Backpack\CRUD\app\Library\CrudPanel\Traits\AutoFocus;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Macroable;
use Backpack\CRUD\app\Library\CrudPanel\Traits\FakeFields;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Operations;
use Backpack\CRUD\app\Library\CrudPanel\Traits\Validation;
use Backpack\CRUD\app\Library\CrudPanel\Traits\FakeColumns;
use Backpack\CRUD\app\Library\CrudPanel\Traits\SaveActions;
use Backpack\CRUD\app\Library\CrudPanel\Traits\HeadingsAndTitles;
use Backpack\CRUD\app\Library\CrudPanel\Traits\ViewsAndRestoresRevisions;

class CrudPanel
{
    // load all the default CrudPanel features
    use Create, Read, Search, Update, Delete, Errors, Reorder, Access, Columns, Fields, Query, Buttons, AutoSet, FakeFields, FakeColumns, ViewsAndRestoresRevisions, AutoFocus, Filters, Tabs, Views, Validation, HeadingsAndTitles, Operations, SaveActions, Settings;
    // allow developers to add their own closures to this object
    use Macroable;

    // --------------
    // CRUD variables
    // --------------
    // These variables are passed to the CRUD views, inside the $crud variable.
    // All variables are public, so they can be modified from your EntityCrudController.
    // All functions and methods are also public, so they can be used in your EntityCrudController to modify these variables.

    public $model = "\App\Models\Entity"; // what's the namespace for your entity's model
    public $route; // what route have you defined for your entity? used for links.
    public $entity_name = 'entry'; // what name will show up on the buttons, in singural (ex: Add entity)
    public $entity_name_plural = 'entries'; // what name will show up on the buttons, in plural (ex: Delete 5 entities)

    public $entry;

    // The following methods are used in CrudController or your EntityCrudController to manipulate the variables above.

    public function __construct()
    {
        $this->request = \Request::instance();

        if ($this->getCurrentOperation()) {
            $this->setOperation($this->getCurrentOperation());
        }
    }

    // ------------------------------------------------------
    // BASICS - model, route, entity_name, entity_name_plural
    // ------------------------------------------------------

    /**
     * This function binds the CRUD to its corresponding Model (which extends Eloquent).
     * All Create-Read-Update-Delete operations are done using that Eloquent Collection.
     *
     * @param string $model_namespace Full model namespace. Ex: App\Models\Article
     *
     * @throws \Exception in case the model does not exist
     */
    public function setModel($model_namespace)
    {
        if (! class_exists($model_namespace)) {
            throw new \Exception('The model does not exist.', 500);
        }

        if (! method_exists($model_namespace, 'hasCrudTrait')) {
            throw new \Exception('Please use CrudTrait on the model.', 500);
        }

        $this->model = new $model_namespace();
        $this->query = $this->model->select('*');
        $this->entry = null;
    }

    /**
     * Get the corresponding Eloquent Model for the CrudController, as defined with the setModel() function.
     *
     * @return string|\Illuminate\Database\Eloquent\Model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Get the database connection, as specified in the .env file or overwritten by the property on the model.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    private function getSchema()
    {
        return \Schema::setConnection($this->getModel()->getConnection());
    }

    /**
     * Check if the database connection driver is using mongodb.
     *
     * @return bool
     */
    private function driverIsMongoDb()
    {
        return $this->getSchema()->getConnection()->getConfig()['driver'] === 'mongodb';
    }

    /**
     * Set the route for this CRUD.
     * Ex: admin/article.
     *
     * @param string $route Route name.
     */
    public function setRoute($route)
    {
        $this->route = $route;
    }

    /**
     * Set the route for this CRUD using the route name.
     * Ex: admin.article.
     *
     * @param string $route      Route name.
     * @param array  $parameters Parameters.
     *
     * @throws \Exception
     */
    public function setRouteName($route, $parameters = [])
    {
        $complete_route = $route.'.index';

        if (! \Route::has($complete_route)) {
            throw new \Exception('There are no routes for this route name.', 404);
        }

        $this->route = route($complete_route, $parameters);
        $this->initButtons();
    }

    /**
     * Get the current CrudController route.
     *
     * Can be defined in the CrudController with:
     * - $this->crud->setRoute(config('backpack.base.route_prefix').'/article')
     * - $this->crud->setRouteName(config('backpack.base.route_prefix').'.article')
     * - $this->crud->route = config('backpack.base.route_prefix')."/article"
     *
     * @return string
     */
    public function getRoute()
    {
        return $this->route;
    }

    /**
     * Set the entity name in singular and plural.
     * Used all over the CRUD interface (header, add button, reorder button, breadcrumbs).
     *
     * @param string $singular Entity name, in singular. Ex: article
     * @param string $plural   Entity name, in plural. Ex: articles
     */
    public function setEntityNameStrings($singular, $plural)
    {
        $this->entity_name = $singular;
        $this->entity_name_plural = $plural;
    }

    // -----------------------------------------------
    // ACTIONS - the current operation being processed
    // -----------------------------------------------

    /**
     * Get the action being performed by the controller,
     * including middleware names, route name, method name,
     * namespace, prefix, etc.
     *
     * @return string The EntityCrudController route action array.
     */
    public function getAction()
    {
        return $this->request->route()->getAction();
    }

    /**
     * Get the full name of the controller method
     * currently being called (including namespace).
     *
     * @return string The EntityCrudController full method name with namespace.
     */
    public function getActionName()
    {
        return $this->request->route()->getActionName();
    }

    /**
     * Get the name of the controller method
     * currently being called.
     *
     * @return string The EntityCrudController method name.
     */
    public function getActionMethod()
    {
        return $this->request->route()->getActionMethod();
    }

    /**
     * Check if the controller method being called
     * matches a given string.
     *
     * @param string $methodName Name of the method (ex: index, create, update)
     *
     * @return bool Whether the condition is met or not.
     */
    public function actionIs($methodName)
    {
        return $methodName === $this->getActionMethod();
    }

    // ----------------------------------
    // Miscellaneous functions or methods
    // ----------------------------------

    /**
     * Return the first element in an array that has the given 'type' attribute.
     *
     * @param string $type
     * @param array  $array
     *
     * @return array
     */
    public function getFirstOfItsTypeInArray($type, $array)
    {
        return array_first($array, function ($item) use ($type) {
            return $item['type'] == $type;
        });
    }

    // ------------
    // TONE FUNCTIONS - UNDOCUMENTED, UNTESTED, SOME MAY BE USED IN THIS FILE
    // ------------
    //
    // TODO:
    // - figure out if they are really needed
    // - comments inside the function to explain how they work
    // - write docblock for them
    // - place in the correct section above (CREATE, READ, UPDATE, DELETE, ACCESS, MANIPULATION)

    public function sync($type, $fields, $attributes)
    {
        if (! empty($this->{$type})) {
            $this->{$type} = array_map(function ($field) use ($fields, $attributes) {
                if (in_array($field['name'], (array) $fields)) {
                    $field = array_merge($field, $attributes);
                }

                return $field;
            }, $this->{$type});
        }
    }

    /**
     * Get the Eloquent Model name from the given relation definition string.
     *
     * @example For a given string 'company' and a relation between App/Models/User and App/Models/Company, defined by a
     *          company() method on the user model, the 'App/Models/Company' string will be returned.
     * @example For a given string 'company.address' and a relation between App/Models/User, App/Models/Company and
     *          App/Models/Address defined by a company() method on the user model and an address() method on the
     *          company model, the 'App/Models/Address' string will be returned.
     *
     * @param string                              $relationString Relation string. A dot notation can be used to chain multiple relations.
     * @param int                                 $length         Optionally specify the number of relations to omit from the start of the relation string. If
     *                                                            the provided length is negative, then that many relations will be omitted from the end of the relation
     *                                                            string.
     * @param \Illuminate\Database\Eloquent\Model $model          Optionally specify a different model than the one in the crud object.
     *
     * @return string Relation model name.
     */
    public function getRelationModel($relationString, $length = null, $model = null)
    {
        $relationArray = explode('.', $relationString);

        if (! isset($length)) {
            $length = count($relationArray);
        }

        if (! isset($model)) {
            $model = $this->model;
        }

        $result = array_reduce(array_splice($relationArray, 0, $length), function ($obj, $method) {
            return $obj->$method()->getRelated();
        }, $model);

        return get_class($result);
    }

    /**
     * Get the given attribute from a model or models resulting from the specified relation string (eg: the list of streets from
     * the many addresses of the company of a given user).
     *
     * @param \Illuminate\Database\Eloquent\Model $model          Model (eg: user).
     * @param string                              $relationString Model relation. Can be a string representing the name of a relation method in the given
     *                                                            Model or one from a different Model through multiple relations. A dot notation can be used to specify
     *                                                            multiple relations (eg: user.company.address).
     * @param string                              $attribute      The attribute from the relation model (eg: the street attribute from the address model).
     *
     * @return array An array containing a list of attributes from the resulting model.
     */
    public function getModelAttributeFromRelation($model, $relationString, $attribute)
    {
        $endModels = $this->getRelationModelInstances($model, $relationString);
        $attributes = [];
        foreach ($endModels as $model) {
            if (is_array($model) && isset($model[$attribute])) {
                $attributes[] = $model[$attribute];
            } elseif ($model->{$attribute}) {
                $attributes[] = $model->{$attribute};
            }
        }

        return $attributes;
    }

    /**
     * Traverse the tree of relations for the given model, defined by the given relation string, and return the ending
     * associated model instance or instances.
     *
     * @param \Illuminate\Database\Eloquent\Model $model          The CRUD model.
     * @param string                              $relationString Relation string. A dot notation can be used to chain multiple relations.
     *
     * @return array An array of the associated model instances defined by the relation string.
     */
    private function getRelationModelInstances($model, $relationString)
    {
        $relationArray = explode('.', $relationString);
        $firstRelationName = array_first($relationArray);
        $relation = $model->{$firstRelationName};

        $results = [];
        if (! empty($relation)) {
            if ($relation instanceof Collection) {
                $currentResults = $relation->toArray();
            } else {
                $currentResults[] = $relation;
            }

            array_shift($relationArray);

            if (! empty($relationArray)) {
                foreach ($currentResults as $currentResult) {
                    $results = array_merge($results, $this->getRelationModelInstances($currentResult, implode('.', $relationArray)));
                }
            } else {
                $results = $currentResults;
            }
        }

        return $results;
    }
}