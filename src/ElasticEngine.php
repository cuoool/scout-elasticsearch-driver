<?php

namespace ScoutElastic;

use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use ScoutElastic\Builders\SearchBuilder;
use ScoutElastic\Facades\ElasticClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use ScoutElastic\Indexers\IndexerInterface;
use ScoutElastic\Payloads\TypePayload;
use stdClass;

class ElasticEngine extends Engine
{
    protected $indexer;

    protected $updateMapping;

    static protected $updatedMappings = [];

    public function __construct(IndexerInterface $indexer, $updateMapping)
    {
        $this->indexer = $indexer;

        $this->updateMapping = $updateMapping;
    }

    public function update($models)
    {
        if ($this->updateMapping) {
            $self = $this;

            $models->each(function ($model) use ($self) {
                $modelClass = get_class($model);

                if (in_array($modelClass, $self::$updatedMappings)) {
                    return true;
                }

                Artisan::call(
                    'elastic:update-mapping',
                    ['model' => $modelClass]
                );

                $self::$updatedMappings[] = $modelClass;
            });
        }

        $this->indexer->update($models);
    }

    public function delete($models)
    {
        $this->indexer->delete($models);
    }

    protected function buildSearchQueryPayload(Builder $builder, $queryPayload, array $options = [])
    {
        foreach ($builder->wheres as $clause => $filters) {
            if (count($filters) == 0) {
                continue;
            }

            if (!array_has($queryPayload, 'filter.bool.'.$clause)) {
                array_set($queryPayload, 'filter.bool.'.$clause, []);
            }

            $queryPayload['filter']['bool'][$clause] = array_merge(
                $queryPayload['filter']['bool'][$clause],
                $filters
            );
        }

        $payload = (new TypePayload($builder->model))
            ->setIfNotEmpty('body._source', $builder->select)
            ->setIfNotEmpty('body.query.bool', $queryPayload)
            ->setIfNotEmpty('body.collapse.field', $builder->collapse)
            ->setIfNotEmpty('body.sort', $builder->orders)
            ->setIfNotEmpty('body.explain', $options['explain'] ?? null)
            ->setIfNotEmpty('body.profile', $options['profile'] ?? null)
            ->setIfNotNull('body.from', $builder->offset)
            ->setIfNotNull('body.size', $builder->limit);

        return $payload->get();
    }

    public function buildSearchQueryPayloadCollection(Builder $builder, array $options = [])
    {
        $payloadCollection = collect();

        if ($builder instanceof SearchBuilder) {
            $searchRules = $builder->rules ?: $builder->model->getSearchRules();

            foreach ($searchRules as $rule) {
                if (is_callable($rule)) {
                    $queryPayload = call_user_func($rule, $builder);
                } else {
                    /** @var SearchRule $ruleEntity */
                    $ruleEntity = new $rule($builder);

                    if ($ruleEntity->isApplicable()) {
                        $queryPayload = $ruleEntity->buildQueryPayload();
                    } else {
                        continue;
                    }
                }

                $payload = $this->buildSearchQueryPayload(
                    $builder,
                    $queryPayload,
                    $options
                );

                $payloadCollection->push($payload);
            }
        } else {
            $payload = $this->buildSearchQueryPayload(
                $builder,
                ['must' => ['match_all' => new stdClass()]],
                $options
            );

            $payloadCollection->push($payload);
        }

        return $payloadCollection;
    }

    protected function performSearch(Builder $builder, array $options = []) {
        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                ElasticClient::getFacadeRoot(),
                $builder->query,
                $options
            );
        }

        $results = null;

        $this
            ->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function($payload) use (&$results) {
                $results = ElasticClient::search($payload);

                $results['_payload'] = $payload;

                if ($this->getTotalCount($results) > 0) {
                    return false;
                }
            });

        return $results;
    }

    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder
            ->from(($page - 1) * $perPage)
            ->take($perPage);

        return $this->performSearch($builder);
    }

    public function explain(Builder $builder)
    {
        return $this->performSearch($builder, [
            'explain' => true
        ]);
    }

    public function profile(Builder $builder)
    {
        return $this->performSearch($builder, [
            'profile' => true
        ]);
    }

    /**
     * @param Builder $builder
     * @return int
     */
    public function count(Builder $builder)
    {
        $count = 0;

        $this
            ->buildSearchQueryPayloadCollection($builder)
            ->each(function($payload) use (&$count) {
                $result = ElasticClient::count($payload);

                $count = $result['count'];

                if ($count > 0) {
                    return false;
                }
        });

        return $count;
    }

    public function searchRaw(Model $model, $query)
    {
        $payload = (new TypePayload($model))
            ->setIfNotEmpty('body', $query)
            ->get();

        return ElasticClient::search($payload);
    }

    public function mapIds($results)
    {
        return array_pluck($results['hits']['hits'], '_id');
    }

    /**
     * @inheritdoc
     */
    public function map($results, $model)
    {
        if ($this->getTotalCount($results) == 0) {
            return Collection::make();
        }

        $primaryKey = $model->getKeyName();

        $columns = array_get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $primaryKey;
        }

        $ids = $this->mapIds($results);

        $models = $model
            ->whereIn($primaryKey, $ids)
            ->get($columns)
            ->keyBy($primaryKey);

        return Collection::make($results['hits']['hits'])
            ->map(function($hit) use ($models) {
                $id = $hit['_id'];

                if (isset($models[$id])) {
                    return $models[$id];
                }
            })
            ->filter()
            ->values();
    }

    public function getTotalCount($results)
    {
        return $results['hits']['total'];
    }
}
