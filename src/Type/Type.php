<?php

namespace Orchid\Type;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Orchid\Core\Models\Post;
use Orchid\Exceptions\TypeException;

abstract class Type implements TypeInterface
{
    /**
     * Name type.
     *
     * @var
     */
    public $name = '';

    /**
     * @var string
     */
    public $description = '';

    /**
     * @var string
     */
    public $slug = '';

    /**
     * @var string
     */
    public $icon = 'fa fa-folder-o';

    /**
     * Fields for content.
     *
     * @var array
     */
    public $fields = [];

    /**
     * Available templates.
     *
     * @var
     */
    public $templates = [];

    /**
     * @var Model
     */
    public $model = Post::class;

    /**
     * @var string
     */
    public $prefix = 'content';
    /**
     * @var bool
     */
    public $display = true;

    /**
     * @var bool
     */
    public $api = false;

    /**
     * @var null
     */
    private $cultivated = null;

    /**
     * @param string $language
     * @param null   $post
     *
     * @throws TypeException
     *
     * @return string
     */
    public function generateForm(string $language = 'en', $post = null): string
    {
        $this->fields = (array) $this->fields();
        $this->parseFields();

        $form = '';
        foreach ($this->fields as $field => $config) {
            $field = config('content.fields.'.$config['tag']);

            if (is_null($field)) {
                throw new TypeException('Field '.$config['tag'].' does not exist');
            }

            $field = new $field();
            $config['lang'] = $language;

            if (isset($config['prefix'])) {
                $prefixArray = array_filter(explode(' ', $config['prefix']));

                foreach ($prefixArray as $prefix) {
                    $config['prefix'] .= '['.$prefix.']';
                }
            } else {
                $config['prefix'] = $this->prefix;
            }

            if (isset($config['name'])) {
                $nameArray = array_filter(explode(' ', $config['name']));

                if (count($nameArray) > 1) {
                    $config['name'] = '';

                    if (!is_null($post)) {
                        $config['value'] = $post->getContent($nameArray[0], $language);
                    }

                    foreach ($nameArray as $name) {
                        $config['name'] .= '['.$name.']';
                        if (!is_null($post) && !is_null($config['value']) && is_array($config['value']) && array_key_exists($name,
                                $config['value'])
                        ) {
                            $config['value'] = $config['value'][$name];
                        }
                    }
                } else {
                    if (!is_null($post)) {
                        $config['value'] = $post->getContent($config['name'], $language);
                    }
                    $config['name'] = '['.$config['name'].']';
                }
            }

            $field = $field->create($config);
            $form .= $field->render();
        }

        return $form;
    }

    /**
     * @return mixed
     */
    abstract public function fields();

    /**
     * Parse the data fields.
     */
    protected function parseFields()
    {
        $data = $this->explodeFields($this->fields);

        foreach ($data as $name => $value) {
            $newField = collect();
            foreach ($value as $rule) {
                $newField[] = $this->parseStringFields($rule);
            }
            $this->fields[$name] = $newField->collapse();
        }
    }

    /**
     * Explode the rules into an array of rules.
     *
     * @param array $rules
     *
     * @return array
     */
    protected function explodeFields(array $rules): array
    {
        foreach ($rules as $key => $rule) {
            if (Str::contains($key, '*')) {
                $this->each($key, [$rule]);
                unset($rules[$key]);
            } else {
                if (is_string($rule)) {
                    $rules[$key] = explode('|', $rule);
                } elseif (is_object($rule)) {
                    $rules[$key] = [$rule];
                } else {
                    $rules[$key] = $rule;
                }
            }
        }

        return $rules;
    }

    /**
     * @param $rules
     *
     * @return array
     */
    protected function parseStringFields(string $rules): array
    {
        $parameters = [];
        // The format for specifying validation rules and parameters follows an
        // easy {rule}:{parameters} formatting convention. For instance the
        // rule "Max:3" states that the value may only be three letters.
        if (strpos($rules, ':') !== false) {
            list($rules, $parameter) = explode(':', $rules, 2);
            $parameters = $this->parseParameters($rules, $parameter);
        }

        return [
            $rules => empty($parameters) ? true : implode(' ', $parameters),
        ];
    }

    /**
     * Parse a parameter list.
     *
     * @param string $rule
     * @param string $parameter
     *
     * @return array
     */
    protected function parseParameters(string $rule, string $parameter): array
    {
        if (strtolower($rule) == 'regex') {
            return [$parameter];
        }

        return str_getcsv($parameter);
    }

    /**
     * @return array
     */
    public function generateGrid(): array
    {
        $fields = $this->grid();
        $model = new $this->model();
        $search = request('search');

        if (is_null($search) || empty($search)) {
            $data = $model->where('type', $this->slug)
                ->orderBy('id', 'Desc')
                ->paginate();
        } else {
            if (!is_null(config('scout.driver'))) {
                $data = $model->search($search)
                    ->where('type', $this->slug)
                    ->orderBy('id', 'Desc')
                    ->paginate();
            } else {
                $data = $model->where('content', 'LIKE', '%'.$search.'%')
                    ->where('type', $this->slug)
                    ->orderBy('id', 'Desc')
                    ->paginate();
            }
        }

        return [
            'data'   => $data,
            'fields' => $fields,
            'type'   => $this,
        ];
    }

    /**
     * @return mixed
     */
    abstract public function grid();

    /**
     * Request Validation.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        Validator::make(request()->all(), $this->rules())->validate();

        return true;
    }

    /**
     * Validation Request Rules.
     *
     * @return array
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Public Client Route Type.
     *
     * @return string
     */
    public function route(): string
    {
        return '';
    }

    /**
     * @return array
     */
    public function getModules(): array
    {
        if ($this->checkModules()) {
            return $this->modules();
        }

        return [];
    }

    /**
     * @return bool
     */
    public function checkModules(): bool
    {
        if (method_exists($this, 'modules') && !empty($this->modules())) {
            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    public function render()
    {
        if (!is_null($this->cultivated)) {
            return $this->cultivated;
        }

        $html = collect();
        $groups = $this->modules();

        $argc = array_values(request()->getRouteResolver()->call($this)->parameters());

        foreach ($groups as $form) {
            if (!is_object($form)) {
                $form = new $form();
            }
            if (method_exists($form, 'get')) {
                $html->put($form->name, $form->get(...$argc));
            }
        }

        $this->cultivated = $html;

        return $this->cultivated;
    }

    /**
     * Action save for sub form.
     */
    public function save()
    {
        $arg = func_get_args();

        foreach ($this->group as $form) {
            if (!is_object($form)) {
                $form = new $form();
            }

            if (method_exists($form, 'save')) {
                $form->save(...$arg);
            }
        }
    }

    /**
     * Action save for sub form.
     */
    public function update()
    {
        $arg = func_get_args();

        foreach ($this->group as $form) {
            if (!is_object($form)) {
                $form = new $form();
            }

            if (method_exists($form, 'update')) {
                $form->save(...$arg);
            }
        }
    }

    /**
     * Action save for sub form.
     */
    public function remove()
    {
        $arg = func_get_args();

        foreach ($this->group as $form) {
            if (!is_object($form)) {
                $form = new $form();
            }

            if (method_exists($form, 'delete')) {
                $form->delete(...$arg);
            }
        }
    }
}
