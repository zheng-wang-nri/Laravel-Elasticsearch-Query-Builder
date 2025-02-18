<?php

namespace Shisun\LaravelElasticsearchQueryBuilder;
use Elasticsearch\ClientBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as Eloquent;

class LaravelElasticsearchQueryBuilder {

	/**
	 * All of the available clause operators.
	 *
	 * @var array
	 */
	public $operators = [
		'=', '<', '>', '<=', '>=', 'like', '!=', '*'
	];

	private $order = [];
	private $limit = 0;
	private $offset = 0;
	private $page = 0;
	private $es_hosts;
	private $es_client;
	private $records_per_page;
	private $raw_results;
	private $nested_queries = [];
	private $aggs = [];
	private $with = [];
	private $with_out = [];
	private $prepended_path = false;
	private $model;
	private $query;
	private $body;
	private $min_score;
	private $scroll_alive = false;
	private $scroll_size = false;
	// Options
	private $index_name = 'index_name';
	private $type_name = 'type_name';
	private $validation = 'strict';
	private $mapping_properties = false;
	private $key_name = 'id';

	/**
	 * @return string
	 */
	public function getKeyName(): string
	{
		return $this->key_name;
	}

	/**
	 * @param string $key_name
	 */
	public function setKeyName(string $key_name)
	{
		$this->key_name = $key_name;
	}

	/**
	 * @return bool|mixed
	 */
	public function getMappingProperties()
	{
		return $this->mapping_properties;
	}

	/**
	 * @param bool|mixed $mapping_properties
	 */
	public function setMappingProperties($mapping_properties)
	{
		$this->mapping_properties = $mapping_properties;
	}

	/**
	 * @return mixed
	 */
	public function getIndexName()
	{
		return $this->index_name;
	}

	/**
	 * @param mixed $index_name
	 * @return LaravelElasticsearchQueryBuilder
	 */
	public function setIndexName($index_name)
	{
		$this->index_name = $index_name;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getTypeName()
	{
		return $this->type_name;
	}

	/**
	 * @param mixed $type_name
	 * @return LaravelElasticsearchQueryBuilder
	 */
	public function setTypeName($type_name)
	{
		$this->type_name = $type_name;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getValidation()
	{
		return $this->validation;
	}

	/**
	 * @param mixed $validation
	 * @return LaravelElasticsearchQueryBuilder
	 */
	public function setValidation($validation)
	{
		$this->validation = $validation;
		return $this;
	}

	/**
	 * @param $options
	 * @return $this
	 */
	public function setOptions($options) {
		$settable_options = ['index_name', 'type_name', 'validation', 'key_name'];
		foreach ($settable_options as $settable_option) {
			if(isset($options[$settable_option])) {
				if($settable_option == 'validation' && in_array($options[$settable_option], [false, 'strict', 'column-existence-only'])) {
					$this->validation = $options[$settable_option];
				} else {
					$this->{$settable_option} = $options[$settable_option];
				}
			}
		}
		return $this;
	}

	/**
	 * Add a basic where clause to the query.
	 *
	 * @param string $column
	 * @param  mixed $operator
	 * @param  mixed $value
	 * @param bool $or
	 * @param bool $boost
	 * @return $this
	 * @throws \Exception
	 */
	public function where($column, $operator = null, $value = null, $or = false, $boost = false) {

		if(is_callable($column) && ! is_string($column)) {
			$builder = new LaravelElasticsearchQueryBuilder($this->model, $this->prepended_path);
			$column($builder);
			$query = $builder->getQuery();
			$query['bool']['minimum_should_match'] = 1;
			$this->query['bool']['must'][] = $query;
			return $this;
		}

		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		$column_bak = $column;
		$result = $this->getMappingProperty($column);

		/**
		 * prepend index name to column name		 
		 * $column = $result[0];
		 */
		$column = $this->getIndexName().'.'.$result[0];
		
		if($value == null && func_num_args() == 2) {
			$value = $operator;
			$operator = '=';
		}
		if ($this->invalidOperator($column_bak, $operator)) {
			list($value, $operator) = [$operator, '='];
		}
		$this->validateValue($column_bak, $value);
		switch($operator) {
			case '=':
				$this->query['bool'][$or ? 'should' : 'filter'][] = $value !== null ? ['term' => [$column => $value]] :
					['bool' => ['must_not' => [
						['exists' => [
							'field' => $column
						]]
					]]];
				break;
			case '<':
				$this->query['bool'][$or ? 'should' : 'filter'][] = ['range' => [$column => ['lt' => $value]]];
				break;
			case '>':
				$this->query['bool'][$or ? 'should' : 'filter'][] = ['range' => [$column => ['gt' => $value]]];
				break;
			case '<=':
				$this->query['bool'][$or ? 'should' : 'filter'][] = ['range' => [$column => ['lte' => $value]]];
				break;
			case '>=':
				$this->query['bool'][$or ? 'should' : 'filter'][] = ['range' => [$column => ['gte' => $value]]];
				break;
			case 'like':
//				if( ! isset($this->model->mappingProperties[$column]['analyzer']) || $this->model->mappingProperties[$column]['analyzer'] != 'snowball') {
//					throw new \Exception('Only the column with "snowball" analyzer is capable of the "like" operation.');
//				}
				$this->query['bool'][$or ? 'should' : 'must'][] = ['match' => [$column => $value]];
				break;
			case '!=':
				if($or) {
					if($value === null) {
						$this->query['bool']['should'][] = ['exists' => [
							'field' => $column
						]];
					} else {
						$this->query['bool']['should'][] = ['bool' => ['must_not' => [
							[(is_array($value) ? 'terms' : 'term') => [$column => $value]]
						]]];
					}
				} else {
					if($value === null) {
						$this->query['bool']['filter'][] = ['exists' => ['field' => $column]];
					} else {
						$this->query['bool']['must_not'][] = [(is_array($value) ? 'terms' : 'term') => [$column => $value]];
					}
				}
				break;
			case '*':
				$this->query['bool'][$or ? 'should' : 'filter'][] = ['wildcard' => [
					$column => $value
				]];
				break;
		}
		return $this;
	}

	public function whereNull($column) {
		return $this->where(snake_case($column), '=', null);
	}

	public function whereNotNull($column) {
		return $this->where(snake_case($column), '!=', null);
	}

	/**
	 * @param $column
	 * @param null $operator
	 * @param null $value
	 * @param bool $or
	 * @return $this|array
	 * @throws \Exception
	 * Validation-disabled version of where
	 */
	public function dirtyWhere($column, $operator = null, $value = null, $or = false) {
		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		$columns = explode('.', $column);
		foreach($columns as $index => $column) {
			$columns[$index] = snake_case($column);
		}
		$column = implode('.', $columns);
		if($value == null) {
			$value = $operator;
			$operator = '=';
		}
		if( ! is_string($value) && ! is_integer($value)) {
			throw new \Exception('String or Integer type value required.');
		}
		if ( ! in_array($operator, ['=', '<', '>', '<=', '>=', 'like'])) {
			list($value, $operator) = [$operator, '='];
		}
		switch($operator) {
			case '=':
				if(strpos($column, '.')) {
					$this->query['bool'][$or ? 'should' : 'filter'][] = ['term' => [$column => $value]];
				} else {
					$this->query['bool'][$or ? 'should' : 'filter'][] = ['term' => [$column => $value]];
				}
				break;
			case '<':
				$this->query['bool'][$or ? 'should' : 'filter']['range'][$column][] = ['lt' => $value];
				break;
			case '>':
				$this->query['bool'][$or ? 'should' : 'filter']['range'][$column][] = ['gt' => $value];
				break;
			case '<=':
				$this->query['bool'][$or ? 'should' : 'filter']['range'][$column][] = ['lte' => $value];
				break;
			case '>=':
				$this->query['bool'][$or ? 'should' : 'filter']['range'][$column][] = ['gte' => $value];
				break;
			case 'like':
				$this->query['bool'][$or ? 'should' : 'must'][] = ['match' => [$column => $value]];
				break;
			case '!=':
				if($or) {
					$builder = new LaravelElasticsearchQueryBuilder($this->model);
					$builder->where($column, '!=', $value);
					if(isset($this->query['bool']['should']['bool'])) {
						$this->query['bool']['should']['bool']['must_not'][] = [(is_array($value) ? 'terms' : 'term') => [$column => $value]];
					} else {
						$this->query['bool']['should'] = ['bool' => ['must_not' => [
							[(is_array($value) ? 'terms' : 'term') => [$column => $value]]
						]]];
					}
				} else {
					$this->query['bool']['must_not'][] = [(is_array($value) ? 'terms' : 'term') => [$column => $value]];
				}
		}
		return $this;
	}

	/**
	 * @param $column
	 * @param null $value
	 * @param $options
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function whereMatch($column, $value = null, $options = []) {
		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		$column_bak = $column;
		$result = $this->getMappingProperty($column);

		/**
		 * prepend index name to column
		 * $column = $result[0];
		 */
		$column = $this->getIndexName().'.'.$column;

		$this->validateValue($column_bak, $value);
		if($options) {
			if($value !== null) {
				$options['query'] = $value;
			} elseif( ! isset($options['query'])) {
				throw new \Exception('Either $value or $options["query"] is required.');
			}
			$match = [
				$column => $options
			];
		} else {
			$match = [$column => $value];
		}
		$this->query['bool']['must'][] = ['match' => $match];
		return $this;
	}

	/**
	 * @param $column
	 * @param null $value
	 * @param $options
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function whereDoesntMatch($column, $value = null, $options = []) {
		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		$column_bak = $column;
		$result = $this->getMappingProperty($column);
		$column = $result[0];
		$this->validateValue($column_bak, $value);
		if($options) {
			if($value !== null) {
				$options['query'] = $value;
			} elseif( ! isset($options['query'])) {
				throw new \Exception('Either $value or $options["query"] is required.');
			}
			$match = [
				$column => $options
			];
		} else {
			$match = [$column => $value];
		}
		$this->query['bool']['must_not'][] = ['match' => $match];
		return $this;
	}

	/**
	 * @param $column
	 * @param null $value
	 * @param $options
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function orWhereMatch($column, $value = null, $options = []) {
		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		$column_bak = $column;
		$result = $this->getMappingProperty($column);

		/**
		 * prepend index name to column
		 * $column = $result[0];
		 */
		$column = $this->getIndexName().'.'.$result[0];

		$this->validateValue($column_bak, $value);
		$match = [];
		if($options) {
			$options['query'] = $value;
			$match = [
				$column => $options
			];
		}
		$this->query['bool']['should'][] = ['match' => $match];
		return $this;
	}

	/**
	 * @param $column
	 * @param callable|null $closure
	 * @param bool $or
	 * @param bool $boost
	 * @return LaravelElasticsearchQueryBuilder
	 */
	public function whereHas($column, $closure = null, $or = false, $boost = false) {
		if(is_callable($column) && ! is_string($column)) {
			$builder = new LaravelElasticsearchQueryBuilder($this->model, $this->prepended_path);
			$column($builder);
			$this->query['bool']['must'][] = $builder->getQuery();
			return $this;
		}
		$column_bak = $column;
		$this->getMappingProperty($column, true);
		$builder = $this->nested_queries[$column_bak] ?? new LaravelElasticsearchQueryBuilder($this->model, snake_case($column_bak));
		$closure($builder);
		$nested_query = $this->createNestedQuery($column_bak, $builder, '');
		$this->query['bool'][$or ? 'should' : 'filter'][] =
			$boost === false ? $nested_query : ['constant_score' => ['filter' => $nested_query, 'boost' => $boost]];
		return $this;
	}

	/**
	 * @param $column
	 * @param null $closure
	 * @param bool $or
	 * @return $this
	 */
	public function whereHasNull($column, $closure = null, $or = false) {
		if($closure === null) {

			/**
			 * Prepend index name to column name
			 */
			$column_field = $this->getIndexName().'.'.snake_case($column);

			$this->query['bool'][$or ? 'should' : 'filter'][] = [
				'bool' => [
					'must_not' => [
						[
							'nested' => [
								'path' => $column_field,
								'query' => [
									'exists' => [
										'field' => $column_field
									]
								]
							]
						]
					]
				]
			];
		} else {
			$column_bak = $column;
			$this->getMappingProperty($column, true);
			$builder = $this->nested_queries[$column_bak] ?? new LaravelElasticsearchQueryBuilder($this->model, snake_case($column_bak));
			$closure($builder);
			$nested_query = $this->createNestedQuery($column_bak, $builder, '');
			$this->query['bool'][$or ? 'should' : 'filter'][] = [
				'bool' => [
					'must_not' => $nested_query
				]
			];
		}

		return $this;
	}

	/**
	 * @param $column
	 * @param null $closure
	 * @return LaravelElasticsearchQueryBuilder
	 */
	public function orWhereHasNull($column, $closure = null) {
		return $this->whereHasNull($column, $closure, $or = true);
	}

	/**
	 * @param $column
	 * @param $closure
	 * @param bool $boost
	 * @return LaravelElasticsearchQueryBuilder
	 */
	public function orWhereHas($column, $closure, $boost = false) {
		return $this->whereHas($column, $closure, true, $boost);
	}

	/**
	 * @param $column
	 * @param LaravelElasticsearchQueryBuilder $builder
	 * @param $path
	 * @return array|bool
	 */
	public function createNestedQuery($column, $builder, $path) {
		if(strtolower($column) == $column) {
			return false;
		}
		$columns = explode('.', $column);
		$query = [];
		$path .= $path ? '.' . snake_case($columns[0]) : snake_case($columns[0]);
		$sub_query = $this->createNestedQuery(implode('.', array_slice($columns, 1)), $builder, $path);

		/**
		 * Prepend index name to path for nested query.
		 */
		$path = $this->getIndexName().'.'.$path;
		
		if($sub_query === false) {
			$query['nested'] = [
				'path'  => $path,
				'query' => $builder->getQuery()
			];
		} else {
			$query['nested'] = [
				'path'  => $path,
				'query' => $sub_query
			];
		}
		return $query;
	}

	/**
	 * @param string|callable $column
	 * @param null $operator
	 * @param null $value
	 * @param bool $boost
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function orWhere($column, $operator = null, $value = null, $boost = false) {
		if(is_callable($column) && ! is_string($column)) {
			$builder = new LaravelElasticsearchQueryBuilder($this->model, $this->prepended_path);
			$column($builder);
			$query = $builder->getQuery();
			$this->query['bool']['minimum_should_match'] = 1;
			$this->query['bool']['should'][] = $boost === false ? $query : ['filter' => $query, 'boost' => $boost];
			return $this;
		}
		$this->where($column, $operator, $value, true, $boost);
		return $this;
	}

	/**
	 * @param Array $column
	 * @return Array
	 */
	public function rawSearch($body) {
		$params = $this->constructParams();
		$params['body'] = $body;
		return $this->es_client->search($params);
	}

	public function getQuery() {
		return $this->array_remove_empty($this->query, 1);
	}

	/**
	 * @param String ...$relations
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function with(String ...$relations) {
		foreach($relations as $relation) {
			$tokens = explode('.', $relation);
			if(snake_case(end($tokens)) == end($tokens)) {
				throw new \Exception("Invalid relationship");
			}
			$result = $this->getMappingProperty($relation, true);
			$column = $result[0];
			$this->with[] = $column;
		}
		return $this;
	}

	/**
	 * @param String ...$relations
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function withOut(String ...$relations) {
		foreach($relations as $relation) {
			$tokens = explode('.', $relation);
			if(snake_case(end($tokens)) == end($tokens)) {
				throw new \Exception("Invalid relationship");
			}
			$result = $this->getMappingProperty($relation, true);
			$column = $result[0];
			$this->with_out[] = $column;
		}
		return $this;
	}

	/**
	 * @return mixed
	 * @throws \Exception
	 */
	public function count() {
		if( ! $this->raw_results) {
			return $this->get()->getTotal();
		}
		return $this->getTotal();
	}


	/**
	 * @return array
	 */
	public function getAggs() {
		return $this->aggs;
	}

	/**
	 * @param $agg_name
	 * @param null $agg
	 * @return bool|mixed
	 */
	public function getAggregationBuckets($agg_name, $agg = null) {
		if($agg_name === null || $agg_name === '') {
			return false;
		}
		$agg = $agg ?? $this->aggregations($agg_name);
		if($agg) {
			$keys = array_keys($agg);
			if(in_array('buckets', $keys)) {
				return $agg['buckets'];
			} elseif(count($keys) == 2) {
				return $this->getAggregationBuckets(0, $agg[$keys[1]]);
			} else {
				return false;
			}
		}
		return false;
	}

	/**
	 * @param string $column
	 * @param array $values
	 * @param bool $or
	 * @return $this
	 * @throws \Exception
	 */
	public function whereIn(string $column, array $values, $or = false) {
		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		if(empty($values)) {
			// $values should not be empty.
			return $this->where('id', -9999);
		}
		$result = $this->getMappingProperty($column);
		
		/**
		 * Prepend index name to column name
		 * $column = $result[0];
		 */
		$column = $this->getIndexName().'.'.$column;

		if($or) {
			$this->query['bool']['should'][] = ['terms' => [$column => $values]];
		} else {
			$this->query['bool']['filter'][] = ['terms' => [$column => $values]];
		}
		return $this;
	}

	/**
	 * @param string $column
	 * @param array $values
	 * @param bool $or
	 * @return $this
	 * @throws \Exception
	 */
	public function whereNotIn(string $column, array $values, $or = false) {
		$column = $this->prepended_path ? $this->prepended_path . '.' . $column : $column;
		if(empty($values)) {
			// $values should not be empty.
			return $this->where('id', -9999);
		}
		$result = $this->getMappingProperty($column);
		$column = $result[0];
		if($or) {
			$this->where($column, '!=', $values, true);
		} else {
			$this->where($column, '!=', $values);
		}
		return $this;
	}

	/**
	 * @param string $column
	 * @param array $values
	 * @return $this
	 * @throws \Exception
	 */
	public function orWhereNotIn(string $column, array $values) {
		return $this->whereNotIn($column, $values, true);
	}

	/**
	 * @param string $column
	 * @param array $values
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function orWhereIn(string $column, array $values) {
		return $this->whereIn($column, $values, true);
	}

	/**
	 * @param string $column
	 * @param null $from
	 * @param null $to
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function whereBetween(string $column, $from = null, $to = null) {
		if(is_null($from) && is_null($to)) {
			throw new \Exception('Either from or to is required.');
		}
		if( ! is_null($from)) {
			$this->where($column, '>=', $from);
		}
		if( ! is_null($to)) {
			$this->where($column, '<=', $to);
		}
		return $this;
	}

	/**
	 * @param string $column
	 * @param null $from
	 * @param null $to
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function orWhereBetween(string $column, $from = null, $to = null) {
		if(is_null($from) && is_null($to)) {
			throw new \Exception('Either from or to is required.');
		}
		$this->orWhere(function(LaravelElasticsearchQueryBuilder $q) use($column, $from, $to) {
			if( ! is_null($from)) {
				$q->where($column, '>=', $from);
			}
			if( ! is_null($to)) {
				$q->where($column, '<=', $to);
			}
		});

		return $this;
	}

	/**
	 * @param string $column
	 * @param string $order
	 * @param bool $script
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception add order to the terms aggregation if there is no query and the column is either '_key' or '_count'.
	 * A snowball field is not sortable!
	 * script example: ['lang' => 'painless', 'source' => "if(doc['mode'].value == 'lot') {return doc['starting'].value;} else {return doc['listing_price'].value;}"]
	 */
	public function orderBy(string $column, $order = 'asc', $script = false) {
		if( ! in_array($order, ['asc', 'desc'])) {
			throw new \Exception("Invalid order '$order'.");
		}
		if(empty($this->array_remove_empty($this->query['bool'])) && in_array($column, ['_key', '_count']) && isset($this->query['terms'])) {
			$this->query['terms']['order'] = [$column => $order];
			return $this;
		}
		$result = $this->getMappingProperty($column);
		$property = $result[1];
		$name = $result[0];
		$is_relation = $name == $column ? false : true;
		$sub_field = '';
		if(($property['type'] == 'text' || (isset($property['index']) && $property['index'] == true)) && isset($property['fields'])) {
			foreach ($property['fields'] as $field => $value) {
				if($value['type'] == 'keyword') {
					$sub_field = '.' . $field;
					break;
				}
			}
		}
		if($script) {
			$this->order['_script'] = ['type' => 'number', 'script' => $script, 'order' => $order];
			return $this;
		}

		/**
		 * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/sort-search-results.html
		 * prepend index Name to column name
		 */
		$column_field = $this->getIndexName().'.'.snake_case($column) . $sub_field;

		if($is_relation) {
			$this->order[$column_field] = [
				'order' => $order,
				'nested_path'  => implode('.', array_slice(explode('.', $name), 0, count(explode('.', $name)) - 1))
			];
		} else {
			$this->order[$column_field] = ['order' => $order];
		}
		return $this;
	}

	/**
	 * @param $min_score
	 * @return $this
	 */
	public function minScore($min_score) {
		$this->min_score = $min_score;
		return $this;
	}

	/**
	 * @return $this
	 */
	public function get() {
		$this->query = $this->array_remove_empty($this->query, 1);
		$params = $this->constructParams();
		$this->raw_results = $this->es_client->search($params);
		return $this;
	}

	public function scroll($scroll_alive = '5m', $scroll_size = 500, $json = false) {
		$this->scroll_alive = $scroll_alive;
		$this->scroll_size = $scroll_size;
		$scroll_id = $this->get()->rawResults()['_scroll_id'];
		$results = $this->get()->toArray();
		if($json) {
			$results = [json_encode($results)];
		}
		while(true) {
			$response = $this->es_client->scroll([
					'scroll_id' => $scroll_id,
					'scroll' => $scroll_alive
				]
			);
			if (count($response['hits']['hits']) > 0) {
				if($json) {
					$results[] = json_encode(array_column($response['hits']['hits'], '_source'));
				} else {
					array_map(function ($value) use (&$results) {
						$results[] = $value['_source'];
					}, $response['hits']['hits']);
				}
				$scroll_id = $response['_scroll_id'];
			} else {
				break;
			}
		}
		return $results;
	}

	/**
	 * @param bool $return_eloquent
	 * @return null
	 */
	public function first($return_eloquent = false) {
		if( ! $return_eloquent) {
			return $this->limit(1)->get()->toArray()[0] ?? null;
		} else {
			return $this->limit(1)->get()->toEloquent()->first();
		}
	}

	/**
	 * @param $name
	 * @param $agg
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function aggregate($name, $agg) {
		$builder = new LaravelElasticsearchQueryBuilder($this->model, $this->prepended_path);
		$agg($builder);
		$aggregation = [];
		$query = $builder->getQuery();
		if(isset($builder->getQuery()['terms'])) {
			$aggregation['terms'] = $query['terms'];
			unset($query['terms']);
		}
		$aggregation['filter'] = $this->array_remove_empty($query, true);
		if(empty($aggregation['filter'])) {
			unset($aggregation['filter']);
		}
		if( ! empty($builder->getAggs())) {
			$aggregation['aggs'] = $this->array_remove_empty($builder->getAggs(), true);
		}
		if(isset($aggregation['terms']) && isset($aggregation['filter'])) {
			throw new \Exception("Using 'where' and 'groupBy' at the same level is illegal. Please use nested aggregate instead.");
		}
		if( ! isset($aggregation['terms']) && ! isset($aggregation['filter']) && isset($aggregation['aggs'])) {
			$this->aggs = array_merge($this->aggs, $aggregation['aggs']);
		} else {
			$this->aggs[$name] = $aggregation;
		}
		return $this;
	}

	/**
	 * @param $name
	 * @param $agg
	 * @return $this
	 * @throws \Exception
	 */
	public function aggregateAll($name, $agg) {
		$builder = new LaravelElasticsearchQueryBuilder($this->model, $this->prepended_path);
		$this->aggs['all_' . $name] = [
			'global' => new \stdClass(),
			'aggs' => $builder->aggregate($name, $agg)->getAggs()
		];
		return $this;
	}


	/**
	 * @param $column
	 * @param bool $agg_name
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function max($column, $agg_name = null) {
		$prepended_column = $this->prepended_path ? ($this->prepended_path . '.' . $column) : $column;
		list($prepended_column) = $this->getMappingProperty($prepended_column);
		$this->aggs[$agg_name ?? 'max_' . $column] = ['max' => ['field' => $prepended_column]];
		return $this;
	}

	/**
	 * @param null|string $column
	 * @param null|string $agg_name
	 * @param null|float|int $missing_value
	 * @return $this
	 */
	public function sum($column = '', $agg_name = null, $missing_value = null, $script = null) {
		if($script) {
			$this->aggs[$agg_name ?? 'sum_' . $column] = ['sum' => [
				'script' => $script
			]];
		}
		if($column) {
			$prepended_column = $this->prepended_path ? ($this->prepended_path . '.' . $column) : $column;
			list($prepended_column) = $this->getMappingProperty($prepended_column);
			$this->aggs[$agg_name ?? 'sum_' . $column]['sum']['field'] = $prepended_column;
		}

		if($missing_value) {
			$this->aggs[$agg_name ?? 'sum_' . $column]['sum']['missing'] = $missing_value;
		}
		return $this;
	}

	/**
	 * @param $column
	 * @param null|string $agg_name
	 * @param null|float|int $missing_value
	 * @return $this
	 */
	public function avg($column, $agg_name = null, $missing_value = null) {
		$prepended_column = $this->prepended_path ? ($this->prepended_path . '.' . $column) : $column;
		list($prepended_column) = $this->getMappingProperty($prepended_column);
		if($missing_value !== null) {
			$this->aggs[$agg_name ?? 'avg_' . $column] = ['avg' => [
				'field' => $prepended_column,
				'missing' => $missing_value
			]];
		} else {
			$this->aggs[$agg_name ?? 'avg_' . $column] = ['avg' => [
				'field' => $prepended_column
			]];
		}
		return $this;
	}

	/**
	 * @param $column
	 * @param bool $agg_name
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function min($column, $agg_name = null) {
		$prepended_column = $this->prepended_path ? ($this->prepended_path . '.' . $column) : $column;
		list($prepended_column) = $this->getMappingProperty($prepended_column);
		$this->aggs[$agg_name ?? 'min_' . $column] = ['min' => ['field' => $prepended_column]];
		return $this;
	}

	/**
	 * @param $relation
	 * @param $agg
	 * @param null $custom_name
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 */
	public function aggregateOn($relation, $agg, $custom_name = null) {
		$this->getMappingProperty($relation, true);
		$custom_name = $custom_name ?? snake_case($relation);
		$builder = new LaravelElasticsearchQueryBuilder($this->model, $relation);
		$this->aggs[$custom_name] = [
			'nested' => [
				'path' => snake_case($relation)
			],
			'aggs' => $builder->aggregate($relation, $agg)->getAggs()
		];
		return $this;
	}

	/**
	 * @param $column
	 * @param int $size
	 * @return LaravelElasticsearchQueryBuilder
	 * @throws \Exception
	 * Warning: the default size is 10. This number is determined by ES
	 */
	public function groupBy($column, $size = 10) {
		$prepended_column = $this->prepended_path ? ($this->prepended_path . '.' . $column) : $column;
		$result = $this->getMappingProperty($prepended_column);

		/**
		 * Prepend index name to column name
		 * $column = $result[0];
		 */
		$column = $this->getIndexName().'.'.$result[0];
		$this->query['terms'] = [
			'field' => $column
		];
		if($size) {
			$this->query['terms']['size'] = $size;
		}
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function rawResults() {
		return $this->raw_results;
	}

	/**
	 * @param null $key
	 * @return bool|array
	 */
	public function aggregations($key = null) {
		if($key) {
			if( ! isset($this->raw_results['aggregations'][$key])) {
				return false;
			}
			return $this->raw_results['aggregations'][$key];
		}
		return $this->raw_results['aggregations'] ?? [];
	}

	/**
	 * @param int $limit
	 * @return $this
	 */
	public function limit(int $limit) {
		$this->limit = $limit;
		return $this;
	}

	/**
	 * @param int $offset
	 * @return $this
	 */
	public function offset(int $offset) {
		$this->offset = $offset;
		return $this;
	}

	/**
	 * @param int $page
	 * @param int $records_per_page
	 * @return $this
	 * Setup pagination parameters
	 */
	public function page(int $page, int $records_per_page) {
		$page = $page < 1 ? 1 : $page;
		$this->page = $page;
		$this->offset = ($page - 1) * $records_per_page;
		$this->records_per_page = $records_per_page;
		$this->limit($records_per_page);
		return $this;
	}

	/**
	 * @param $key
	 * @return null|Eloquent
	 */
	public function find($key) {
		return $this->where($this->key_name, $key)->first(true);
	}

	/**
	 * @param $key
	 * @return boolean
	 * @throws \Exception
	 */
	public function delete($key) {
		if( ! $this->model) {
			throw new \Exception('Model is missing.');
		}
		$params = [
			'index' => $this->index_name,
			'type'  => $this->type_name,
			'id'    => $key
		];
		try {
			$result = $this->es_client->delete($params);
		} catch(\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			return false;
		}

		return true;
	}

	/**
	 * LaravelElasticsearchQueryBuilder constructor.
	 * @param Eloquent|null $model
	 * @param bool $prepended_path
	 */
	public function __construct($model = null, $prepended_path = false) {
		$this->query = ['bool' => [
			'must'      => [],
			'filter'    => [
				'range' => []
			],
			'must_not'  => []
		]];
		$this->prepended_path = $prepended_path;
		$this->model = $model;
		$this->es_hosts = config('laravel-elasticsearch-query-builder.ES_HOSTS') ?? json_decode(env('ES_HOSTS', '["localhost:9200"]'), true);
		$this->es_client = $this->createClient();
		$this->mapping_properties = $this->model->mappingProperties ?? false;
		$this->index_name = method_exists($model, 'getIndexName') ? $model->getIndexName() : 'index_name';

		/**
		 * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/removal-of-types.html
		 */
		// $this->type_name = method_exists($model, 'getTypeName') ? $model->getTypeName() :
		// 	(method_exists($model, 'getIndexName') ? $model->getIndexName() : 'type_name');
		$this->type_name = '_doc';

		$this->validation = isset($model->mappingProperties) ? 'strict' : false;
		$this->key_name = method_exists($model, 'getKeyName') ? $model->getKeyName() : 'id';
	}

	/**
	 * @return array
	 */
	public function toArray() {
		$items = array();
		if( ! is_array($this->raw_results)) {
			return $items;
		}
		if((int)$this->raw_results['hits']['total']['value'] === 0) {
			return $items;
		}
		foreach($this->raw_results['hits']['hits'] as $index => $item) {
			$result = $item['_source'][$this->index_name];
			$result['_score'] = $item['_score'];
			$items[] = $result;
		}
		return $items;
	}

	/**
	 * @return Model|static
	 */
	public function toEloquent() {
		if( ! is_array($this->raw_results)) {
			return collect([]);
		}
		$models = [];
		foreach($this->toArray() as $model) {
			$models[] = $this->model->newFromBuilder($model);
		}
		return collect($models);
	}


	/**
	 * @param null $records_per_page
	 * @return array
	 * @throws \Exception
	 * Get pagination info after query gets executed
	 */
	public function paginate($records_per_page = null) {
		if( ! $this->raw_results) {
			throw new \Exception('Method invoked before get()');
		}
		if($records_per_page) {
			$this->records_per_page = $records_per_page;
		}
		if(is_null($this->records_per_page)) {
			throw new \Exception('Record per page required.');
		}
		$paginatelimit = 5; // at any given time only 5 pages can show in the widget
		$paginate = array();
		$paginate['pages'] = array();
		$paginate['rows'] = 0;
		$paginate['active'] = 0;
		$paginate['totalpages'] = 0;
		$paginate['prev'] = false;
		$paginate['next'] = false;
		$paginate['per_page'] = $this->records_per_page;

		if((int)$this->raw_results['hits']['total']['value'] === 0) {
			return $paginate;
		}

		$rows = $this->raw_results['hits']['total']['value'];
		/**
		 * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/search-search.html
		 * $page = ceil($this->raw_results['hits']['total'] / $this->records_per_page);
		 */
		$pages = ceil($rows / $this->records_per_page);
		$page = ($this->page < 1) ? 1: (($this->page > $pages) ? $pages : $this->page);
		$half = floor($paginatelimit / 2);

		if($page <= $half) {
			$left = 1;
			$right = ($pages > $paginatelimit) ? $paginatelimit : $pages;
		} elseif(($this->page + $half) > $pages) {
			$left = $pages - ($paginatelimit - 1);
			$left = ($left < 1) ? 1 : $left;
			$right = $pages;
		} else {
			$left = $this->page - $half;
			$right = $this->page + $half;
		}

		$paginate['prev'] = false;
		$paginate['next'] = false;

		if($page > $left) {
			$paginate['prev'] = true;
		}
		if($page < $right) {
			$paginate['next'] = true;
		}

		$paginate['pages'] = range($left,$right);
		$paginate['rows'] = $rows;
		$paginate['active'] = (int)$page;
		$paginate['totalpages'] = $pages;

		return $paginate;
	}

	/**
	 * @throws \Exception
	 */
	public function getTotal() {
		if( ! $this->raw_results) {
			throw new \Exception('Method invoked before get()');
		}
		return $this->raw_results['hits']['total'];
	}

	public function getBody() {
		$this->query = $this->array_remove_empty($this->query, 1);
		return $this->constructParams();
	}

	/**
	 * @param $column
	 * @param $value
	 * @return bool
	 * @throws \Exception
	 */
	private function validateValue($column, $value) {
		if(is_null($value)) {
			return true;
		}
		if($this->validation != 'strict') {
			return true;
		}
		$result = $this->getMappingProperty($column);
		$property = $result[1];
		$type = $property['type'];
		if($type == 'string') {
			return true;
		} elseif($type == 'integer' && ! (ctype_digit($value) || is_int($value)) && ! is_array($value)) {
			throw new \Exception("Integer value required for the column $column. Index name: {$this->index_name}");
		} elseif($type == 'date' && ! strtotime($value) && ! is_array($value)) {
			throw new \Exception("Date value required for the column $column. Index name: {$this->index_name}");
		}
		return true;
	}

	/**
	 * @param $column
	 * @param bool $is_relation
	 * @return array
	 * @throws \Exception
	 */
	private function getMappingProperty($column, $is_relation = false) {
		$columns = explode('.', $column);
		$snake_case = [];
		if($this->mapping_properties === false) {
			foreach ($columns as $index => $column) {
				$snake_case[] = snake_case($column);
				if(snake_case($column) == $column || ($is_relation && $index == count($columns) - 1)) {
					return array(implode('.', $snake_case), []);
				}
			}
			throw new \Exception("Invalid elasticsearch field '$column'");
		}
		$mapping_properties = $this->mapping_properties;
		if( ! $mapping_properties) {
			throw new \Exception("Mapping properties not accessible.");
		}
		foreach($columns as $index => $col) {
			if( ! in_array(snake_case($col), array_keys($mapping_properties))) {
				throw new \Exception("Invalid elasticsearch field '$column'");
			}
			$snake_case[] = snake_case($col);
			if(snake_case($col) == $col || ($is_relation && $index == count($columns) - 1)) {
				return array(implode('.', $snake_case), $mapping_properties[snake_case($col)]);
			}
			$mapping_properties = $mapping_properties[snake_case($col)]['properties'];
		}
		throw new \Exception("Invalid elasticsearch field '$column'");
	}

	/**
	 * @param $haystack
	 * @param int $allowzero
	 * @param array $exceptions
	 * @return mixed
	 */
	private function array_remove_empty($haystack, $allowzero = 0, $exceptions = []) {
		foreach ($haystack as $key => $value) {
			if(is_array($value)) {
				$haystack[$key] = $this->array_remove_empty($haystack[$key], $allowzero, $exceptions);
			}
			if(empty($haystack[$key]) && ($allowzero === 0 || $haystack[$key] != 0) && empty(in_array($key, $exceptions))) {
				unset($haystack[$key]);
			}
		}
		return $haystack;
	}

	/**
	 * Determine if the given operator is supported.
	 *
	 * @param $column
	 * @param  string $operator
	 * @return bool
	 * @throws \Exception
	 */
	protected function invalidOperator($column, $operator) {
		if($this->validation != 'strict') {
			return false;
		}
		if(str_contains($column, '.')) {
			$result = $this->getMappingProperty($column);
			$property = $result[1];
			$type = $property['type'];
		} else {
			$type = $this->mapping_properties[$column]['type'];
		}
		if($type == 'string' && in_array($operator, ['<', '<=', '>', '>='])) {
			throw new \Exception('Invalid range operator for string type field');
		}
		return ! in_array(strtolower($operator), $this->operators, true);
	}

	/**
	 * @return array
	 */
	private function constructParams() {
		$params = [
			'index' => $this->index_name,
			'type' => $this->type_name,
			'body' => [
				'query' => $this->query,
				'sort' => $this->order,
				'size' => $this->limit ?: 100,
				'from' => $this->page ? ($this->page - 1) * $this->records_per_page : $this->offset
			]
		];
		if($this->with) {
			$params['_source_include'] = $this->with;
		} elseif($this->with_out) {
			$params['_source_exclude'] = $this->with_out;
		}
		if($this->scroll_size && $this->scroll_alive) {
			$params['size'] = $this->scroll_size;
			$params['scroll'] = $this->scroll_alive;
		}
		if(empty($this->query)) {
			if($this->aggs) {
				$params['body']['size'] = 0;
				unset($params['body']['query']);
			} else {
				$params['body']['query']['match_all'] = (object)[];
			}
		}
		if($this->min_score) {
			$params['body']['min_score'] = $this->min_score;
		}
		if($this->aggs) {
			$params['body']['aggs'] = $this->aggs;
		}
		$this->body = $params;
		return $params;
	}


	/**
	 * @return \Elasticsearch\Client
	 */
	private function createClient() {
		$clientBuilder = ClientBuilder::create();   // Instantiate a new ClientBuilder
		$clientBuilder->setHosts($this->es_hosts);           // Set the hosts
		return $clientBuilder->build();          // Build the client object
	}
}